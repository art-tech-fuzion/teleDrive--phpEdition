<?php
/**
 * Zero-Database Storage & Metadata Checkpointing Engine
 * 
 * Complete Lifecycle Architecture:
 * 1. Auto-Bootstrap: Empty/reset Index Channel automatically initializes and PINs an empty master_manifest.json.
 * 2. Delta Streaming: New file/folder creation logs single JSON messages below the pinned document.
 * 3. Queue-Aware 50-Message Compaction: At the 50th delta message, consolidates metadata, uploads and PINs
 *    a new master_manifest.json, unpins the old document, and flags pending purge so ongoing upload queues
 *    are NEVER blocked or delayed.
 * 4. Post-Upload Batch Purge: When upload queue completes, batch-deletes all old messages and unpinned documents
 *    above the new pin concurrently using multi-cURL.
 * 5. Pinned Document Sync:
 *    - Renames/Moves on items in pinned manifest append delta updates that replace old records on next compaction.
 *    - Deletions on items in pinned manifest post {"id": "...", "deleted": true} tombstones so they are immediately
 *      filtered out and permanently dropped on next compaction.
 *    - If all items in Drive are deleted, pinned manifest is reset to an empty state.
 * 6. Instant Caching: Local cache provides sub-millisecond dashboard loads and auto-syncs with Telegram.
 */

if (!defined('TELEDRIVE_INIT')) {
    exit('Direct access not permitted');
}

require_once __DIR__ . '/TelegramClient.php';
require_once __DIR__ . '/Helpers.php';

class StorageEngine {
    private TelegramClient $telegram;
    private string $indexChannel;
    private string $storageChannel;
    const COMPACTION_THRESHOLD = 50;
    const MANIFEST_FILENAME = 'master_manifest.json';
    const CACHE_FILE = 'index_cache.json';

    public function __construct(?TelegramClient $telegram = null) {
        $this->telegram = $telegram ?: new TelegramClient();
        $this->indexChannel = INDEX_CHANNEL_ID;
        $this->storageChannel = STORAGE_CHANNEL_ID;
    }

    /**
     * Check if channels are configured
     */
    public function isConfigured(): bool {
        return !empty(TELEGRAM_BOT_TOKEN) && !empty($this->indexChannel) && !empty($this->storageChannel);
    }

    /**
     * Path to local index cache to guarantee instant sub-second dashboard rendering
     */
    private function getCachePath(): string {
        return TEMP_CHUNK_DIR . '/' . self::CACHE_FILE;
    }

    /**
     * Load current filesystem index
     * Prioritizes fast local cache, falls back to pinned manifest from Index Channel + subsequent delta messages
     */
    public function getFileSystemIndex(bool $forceRemote = false): array {
        if (!$this->isConfigured()) {
            return [
                'items'            => [],
                'pinned_message_id'=> 0,
                'delta_count'      => 0,
                'total_items'      => 0
            ];
        }

        $cacheFile = $this->getCachePath();

        // 1. Check local cache first only if forceRemote is not requested
        if (!$forceRemote && file_exists($cacheFile)) {
            $cached = json_decode(@file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['items'])) {
                return $cached;
            }
        }

        $items = [];
        $pinnedMsgId = 0;
        $pinnedDocFileId = null;
        $deltaCount = 0;

        // 2. Fetch current pinned message from Telegram Index Channel
        try {
            $chatInfo = $this->telegram->getChat($this->indexChannel);
            if (isset($chatInfo['pinned_message'])) {
                $pinnedMsg = $chatInfo['pinned_message'];
                $pinnedMsgId = (int)$pinnedMsg['message_id'];
                
                if (isset($pinnedMsg['document']) && 
                    str_contains(strtolower($pinnedMsg['document']['file_name'] ?? ''), 'manifest')) {
                    $pinnedDocFileId = $pinnedMsg['document']['file_id'];
                }
            }
        } catch (Exception $e) {
            error_log("Failed to fetch getChat for index channel: " . $e->getMessage());
        }

        // 3. Download and parse pinned master_manifest.json (if present)
        if ($pinnedDocFileId) {
            try {
                $manifestJson = $this->telegram->downloadFileContent($pinnedDocFileId);
                $manifestData = json_decode($manifestJson, true);
                if (is_array($manifestData) && isset($manifestData['items'])) {
                    foreach ($manifestData['items'] as $item) {
                        $items[$item['id']] = $item;
                    }
                    $deltaCount = (int)($manifestData['delta_count'] ?? 0);
                }
            } catch (Exception $e) {
                error_log("Failed to load master manifest content: " . $e->getMessage());
            }
        }

        // 4. Detect top message watermark in Index Channel
        //    Send a transient sync probe message, read its ID as topMsgId, then immediately delete it.
        //    Safe delete: retry once on failure. If delete still fails, store the orphan ID so it is
        //    excluded from the delta scan range and won't count toward compaction.
        $topMsgId = 0;
        $orphanSyncId = 0;
        try {
            $ping = $this->telegram->sendMessage($this->indexChannel, "<code>sync</code>");
            $topMsgId = (int)($ping['message_id'] ?? 0);
            if ($topMsgId > 0) {
                $deleted = $this->telegram->deleteMessage($this->indexChannel, $topMsgId);
                if (!$deleted) {
                    // Retry once after a short delay
                    usleep(300000);
                    $deleted = $this->telegram->deleteMessage($this->indexChannel, $topMsgId);
                    if (!$deleted) {
                        // Mark as orphan — exclude from delta scan so it never triggers compaction
                        $orphanSyncId = $topMsgId;
                        error_log("TeleDrive: sync probe message {$topMsgId} could not be deleted and will be excluded from delta scan.");
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Top watermark detection note: " . $e->getMessage());
        }

        // If no pinned manifest exists at all (fresh bootstrap or channel reset)
        if ($pinnedMsgId === 0 && empty($items)) {
            // Auto-Bootstrap: create and PIN an initial clean master_manifest.json
            $bootstrapPinnedId = $this->rebuildAndPinManifest([]);
            $pinnedMsgId = $bootstrapPinnedId;
            $items = [];
            $deltaCount = 0;
        }

        // 5. Determine scan range for delta messages (strictly below the pinned document)
        if ($topMsgId > 0 && $pinnedMsgId > 0) {
            $startMsgId = $pinnedMsgId + 1;
            $endMsgId   = $topMsgId - 1;
        } else {
            $startMsgId = 1;
            $endMsgId   = 0;
        }

        // Scan delta messages concurrently using high-speed multi-cURL
        // Exclude the orphan sync probe ID (if delete failed) so it never counts toward compaction
        if ($startMsgId <= $endMsgId) {
            $scanIds = range($startMsgId, $endMsgId);
            if ($orphanSyncId > 0) {
                $scanIds = array_values(array_filter($scanIds, fn($id) => $id !== $orphanSyncId));
            }
            $rawMessages = $this->telegram->fetchMessagesBatch($this->indexChannel, $this->indexChannel, $scanIds);

            foreach ($rawMessages as $scanId => $fwdMsg) {
                $text = $fwdMsg['text'] ?? $fwdMsg['caption'] ?? '';
                $cleanJson = strip_tags(html_entity_decode($text, ENT_QUOTES, 'UTF-8'));
                $parsed = json_decode($cleanJson, true);

                if (is_array($parsed)) {
                    // Skip batched tombstones
                    if (!empty($parsed['tombstones']) && is_array($parsed['tombstones'])) {
                        foreach ($parsed['tombstones'] as $tId) {
                            unset($items[$tId]);
                        }
                        $deltaCount++;
                        continue;
                    }
                    // Skip individual deleted / tombstone items
                    if (!empty($parsed['deleted']) && !empty($parsed['id'])) {
                        unset($items[$parsed['id']]);
                        $deltaCount++;
                        continue;
                    }
                    if (isset($parsed['id'], $parsed['type'])) {
                        $parsed['index_message_id'] = $scanId;
                        $items[$parsed['id']] = $parsed;
                        $deltaCount++;
                    }
                }
            }
        }

        $pendingPurge = null;

        // 6. If delta messages touch 50, merge, upload new document, PIN it, and schedule non-blocking purge
        if ($deltaCount >= self::COMPACTION_THRESHOLD) {
            $newPinnedId = $this->rebuildAndPinManifest($items);
            if ($newPinnedId > 0) {
                // Unpin old pinned manifest
                if ($pinnedMsgId > 0 && $pinnedMsgId !== $newPinnedId) {
                    $this->telegram->unpinChatMessage($this->indexChannel, $pinnedMsgId);
                    $pendingPurge = [
                        'old_pin' => $pinnedMsgId,
                        'from'    => $pinnedMsgId + 1,
                        'to'      => $newPinnedId - 1
                    ];
                }
                $pinnedMsgId = $newPinnedId;
                $deltaCount = 0;
            }
        }

        // 7. Update local cache with latest data
        $indexData = [
            'items'            => array_values($items),
            'pinned_message_id'=> $pinnedMsgId,
            'delta_count'      => $deltaCount,
            'total_items'      => count($items),
            'last_synced_at'   => time()
        ];

        if ($pendingPurge !== null) {
            $indexData['pending_purge'] = $pendingPurge;
        }

        @file_put_contents($cacheFile, json_encode($indexData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $indexData;
    }

    /**
     * Add a new folder
     */
    public function createFolder(string $name, string $parentId = 'root'): array {
        $folderId = Helpers::generateUUID();
        $name = Helpers::sanitizeFilename($name);

        $metadata = [
            'id'          => $folderId,
            'type'        => 'folder',
            'name'        => $name,
            'parent_id'   => empty($parentId) ? 'root' : $parentId,
            'created_at'  => time(),
            'updated_at'  => time()
        ];

        return $this->saveMetadataEntry($metadata);
    }

    /**
     * Add a newly uploaded file entry
     */
    public function createFileEntry(array $fileData): array {
        $fileId = Helpers::generateUUID();
        $metadata = [
            'id'          => $fileId,
            'type'        => 'file',
            'name'        => Helpers::sanitizeFilename($fileData['name']),
            'size'        => (int)$fileData['size'],
            'mime_type'   => $fileData['mime_type'] ?? 'application/octet-stream',
            'parent_id'   => empty($fileData['parent_id']) ? 'root' : $fileData['parent_id'],
            'chunks'      => $fileData['chunks'] ?? [],
            'created_at'  => time(),
            'updated_at'  => time()
        ];

        return $this->saveMetadataEntry($metadata);
    }

    /**
     * Post a single metadata JSON message to Index Channel & trigger 50-message compaction without blocking uploads
     */
    private function saveMetadataEntry(array $metadata): array {
        $jsonPayload = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        
        // 1. Post individual JSON message to Telegram Index Channel (lands below pinned document)
        $tgResponse = $this->telegram->sendMessage($this->indexChannel, "<code>" . htmlspecialchars($jsonPayload) . "</code>");
        $messageId = (int)$tgResponse['message_id'];
        $metadata['index_message_id'] = $messageId;

        // 2. Update filesystem index
        $index = $this->getFileSystemIndex();
        $items = $index['items'];
        $deltaCount = (int)($index['delta_count'] ?? 0) + 1;
        $pinnedMsgId = (int)($index['pinned_message_id'] ?? 0);
        $needsPurge = false;

        // Merge / Upsert item
        $found = false;
        foreach ($items as &$it) {
            if ($it['id'] === $metadata['id']) {
                $it = $metadata;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $items[] = $metadata;
        }

        // 3. Check 50-Message Compaction Threshold (Non-Blocking)
        if ($deltaCount >= self::COMPACTION_THRESHOLD) {
            $newPinnedId = $this->rebuildAndPinManifest($items);
            if ($newPinnedId > 0) {
                // Unpin old pinned manifest and record purge range for post-queue execution
                if ($pinnedMsgId > 0 && $pinnedMsgId !== $newPinnedId) {
                    $this->telegram->unpinChatMessage($this->indexChannel, $pinnedMsgId);
                    $index['pending_purge'] = [
                        'old_pin' => $pinnedMsgId,
                        'from'    => $pinnedMsgId + 1,
                        'to'      => $newPinnedId - 1
                    ];
                }
                $pinnedMsgId = $newPinnedId;
                $deltaCount = 0; // Reset delta counter after compaction
                $needsPurge = true;
            }
        }

        // Save updated cache
        $index['items'] = array_values($items);
        $index['pinned_message_id'] = $pinnedMsgId;
        $index['delta_count'] = $deltaCount;
        $index['total_items'] = count($items);
        @file_put_contents($this->getCachePath(), json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $metadata['needs_purge'] = $needsPurge;
        return $metadata;
    }

    /**
     * Batch purge old index messages and old unpinned documents after upload queue finishes
     */
    public function purgePendingIndexMessages(): array {
        $index = $this->getFileSystemIndex();
        $pending = $index['pending_purge'] ?? null;

        if (!$pending) {
            return ['purged' => false, 'count' => 0];
        }

        $purgedCount = 0;

        // 1. Delete old unpinned manifest document
        if (!empty($pending['old_pin']) && $pending['old_pin'] > 0) {
            $this->telegram->deleteMessage($this->indexChannel, (int)$pending['old_pin']);
            $purgedCount++;
        }

        // 2. Batch delete all delta messages in range concurrently using multi-cURL
        $from = (int)($pending['from'] ?? 0);
        $to   = (int)($pending['to'] ?? 0);
        if ($from > 0 && $to >= $from) {
            $ids = range($from, $to);
            $deleted = $this->telegram->deleteMessagesBatch($this->indexChannel, $ids);
            $purgedCount += $deleted;
        }

        // 3. Clear pending_purge flag from local cache
        unset($index['pending_purge']);
        @file_put_contents($this->getCachePath(), json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return [
            'purged' => true,
            'count'  => $purgedCount
        ];
    }

    /**
     * Rename a file or folder
     */
    public function renameItem(string $id, string $newName): array {
        $index = $this->getFileSystemIndex();
        $items = $index['items'];
        $target = null;
        $newName = Helpers::sanitizeFilename($newName);
        $pinnedMsgId = (int)($index['pinned_message_id'] ?? 0);

        foreach ($items as &$item) {
            if ($item['id'] === $id) {
                $item['name'] = $newName;
                $item['updated_at'] = time();
                $target = $item;
                break;
            }
        }

        if (!$target) {
            throw new Exception("Item with ID {$id} not found.");
        }

        // If item's message is in current delta range (> pinnedMsgId), edit it in-place
        $msgId = (int)($target['index_message_id'] ?? 0);
        if ($msgId > $pinnedMsgId) {
            try {
                $jsonPayload = json_encode($target, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $this->telegram->editMessageText($this->indexChannel, $msgId, "<code>" . htmlspecialchars($jsonPayload) . "</code>");
            } catch (Exception $e) {
                error_log("Failed to edit message text in place: " . $e->getMessage());
            }
        } else {
            // Otherwise write an update message in the delta stream (replaces old record on next compaction)
            $jsonPayload = json_encode($target, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $tgResponse = $this->telegram->sendMessage($this->indexChannel, "<code>" . htmlspecialchars($jsonPayload) . "</code>");
            $target['index_message_id'] = (int)($tgResponse['message_id'] ?? 0);
        }

        // Update local cache
        $index['items'] = array_values($items);
        @file_put_contents($this->getCachePath(), json_encode($index, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $target;
    }

    /**
     * Move a file or folder into a different destination folder
     */
    public function moveItem(string $id, string $destinationParentId): array {
        $index = $this->getFileSystemIndex();
        $items = $index['items'];
        $target = null;
        $destParentId = empty($destinationParentId) ? 'root' : $destinationParentId;
        $pinnedMsgId = (int)($index['pinned_message_id'] ?? 0);

        if ($id === $destParentId) {
            throw new Exception("Cannot move an item inside itself.");
        }

        if ($destParentId !== 'root') {
            $destExists = false;
            foreach ($items as $item) {
                if ($item['id'] === $destParentId && $item['type'] === 'folder') {
                    $destExists = true;
                    break;
                }
            }
            if (!$destExists) {
                throw new Exception("Destination folder not found.");
            }
        }

        foreach ($items as &$item) {
            if ($item['id'] === $id) {
                $item['parent_id'] = $destParentId;
                $item['updated_at'] = time();
                $target = $item;
                break;
            }
        }

        if (!$target) {
            throw new Exception("Item with ID {$id} not found.");
        }

        $msgId = (int)($target['index_message_id'] ?? 0);
        if ($msgId > $pinnedMsgId) {
            try {
                $jsonPayload = json_encode($target, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $this->telegram->editMessageText($this->indexChannel, $msgId, "<code>" . htmlspecialchars($jsonPayload) . "</code>");
            } catch (Exception $e) {
                error_log("Failed to edit message text for moveItem: " . $e->getMessage());
            }
        } else {
            $jsonPayload = json_encode($target, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $tgResponse = $this->telegram->sendMessage($this->indexChannel, "<code>" . htmlspecialchars($jsonPayload) . "</code>");
            $target['index_message_id'] = (int)($tgResponse['message_id'] ?? 0);
        }

        // Update local cache
        $index['items'] = array_values($items);
        @file_put_contents($this->getCachePath(), json_encode($index, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $target;
    }

    /**
     * Delete an item (File or Folder with recursive cascade)
     */
    public function deleteItem(string $id): array {
        return $this->deleteItems([$id]);
    }

    /**
     * Bulk delete multiple items (files and folders with cascade) with high-speed multi-cURL batching
     */
    public function deleteItems(array $ids): array {
        if (empty($ids)) {
            return ['deleted_count' => 0, 'remaining_count' => 0];
        }

        $index = $this->getFileSystemIndex();
        $items = $index['items'];
        $pinnedMsgId = (int)($index['pinned_message_id'] ?? 0);

        $toDelete = [];
        foreach ($ids as $id) {
            if (!empty($id)) {
                $this->collectCascadeIds((string)$id, $items, $toDelete);
            }
        }

        $deletedCount = 0;
        $remainingItems = [];
        $storageChunkMsgIds = [];
        $deltaIndexMsgIds = [];
        $tombstoneItemIds = [];

        foreach ($items as $item) {
            if (isset($toDelete[$item['id']])) {
                $deletedCount++;
                // 1. Collect chunk message IDs from Storage Channel for parallel batch delete
                if ($item['type'] === 'file' && !empty($item['chunks'])) {
                    foreach ($item['chunks'] as $chunk) {
                        if (!empty($chunk['message_id'])) {
                            $storageChunkMsgIds[] = (int)$chunk['message_id'];
                        }
                    }
                }

                // 2. Classify Index Channel deletion vs tombstone
                $msgId = (int)($item['index_message_id'] ?? 0);
                if ($msgId > $pinnedMsgId) {
                    // Message is in current delta stream -> queue for parallel batch deletion
                    $deltaIndexMsgIds[] = $msgId;
                } else {
                    // Item was in older pinned manifest -> queue for tombstoning
                    $tombstoneItemIds[] = $item['id'];
                }
            } else {
                $remainingItems[] = $item;
            }
        }

        // 3. Batch delete all storage chunk messages concurrently (ultra fast, non-blocking)
        if (!empty($storageChunkMsgIds)) {
            try {
                $this->telegram->deleteMessagesBatch($this->storageChannel, array_unique($storageChunkMsgIds));
            } catch (Exception $e) {
                error_log("Failed to batch delete storage chunks: " . $e->getMessage());
            }
        }

        // 4. Batch delete all delta index messages concurrently
        if (!empty($deltaIndexMsgIds)) {
            try {
                $this->telegram->deleteMessagesBatch($this->indexChannel, array_unique($deltaIndexMsgIds));
            } catch (Exception $e) {
                error_log("Failed to batch delete delta index messages: " . $e->getMessage());
            }
        }

        // 5. Handle channel state
        $deltaCount = (int)($index['delta_count'] ?? 0);

        if (empty($remainingItems)) {
            // If ALL items in the Drive are deleted, cleanly reset the pinned document to an empty manifest
            $oldPinnedId = $pinnedMsgId;
            $newPinnedId = $this->rebuildAndPinManifest([]);

            // Unpin old pinned manifest and batch delete EVERYTHING above the new pin (old pin, old deltas, tombstones)
            $messagesToPurge = [];
            if ($oldPinnedId > 0) {
                $this->telegram->unpinChatMessage($this->indexChannel, $oldPinnedId);
                $messagesToPurge[] = $oldPinnedId;
                if ($newPinnedId > $oldPinnedId + 1) {
                    $messagesToPurge = array_merge($messagesToPurge, range($oldPinnedId + 1, $newPinnedId - 1));
                }
            }
            if (!empty($messagesToPurge)) {
                try {
                    $this->telegram->deleteMessagesBatch($this->indexChannel, array_unique($messagesToPurge));
                } catch (Exception $e) {
                    error_log("Failed to purge old index messages on reset: " . $e->getMessage());
                }
            }

            $index['pinned_message_id'] = $newPinnedId;
            $index['delta_count'] = 0;
            unset($index['pending_purge']);
        } elseif (!empty($tombstoneItemIds)) {
            // Post ONE single batched tombstone message into the index channel
            try {
                $tombstonePayload = json_encode(['tombstones' => array_values($tombstoneItemIds)]);
                $tRes = $this->telegram->sendMessage($this->indexChannel, "<code>" . htmlspecialchars($tombstonePayload) . "</code>");
                $deltaCount++;

                // If tombstone pushes delta count to 50, compact and purge immediately
                if ($deltaCount >= self::COMPACTION_THRESHOLD) {
                    $oldPinnedId = $pinnedMsgId;
                    $newPinnedId = $this->rebuildAndPinManifest($remainingItems);
                    if ($newPinnedId > 0) {
                        $messagesToPurge = [];
                        if ($oldPinnedId > 0 && $oldPinnedId !== $newPinnedId) {
                            $this->telegram->unpinChatMessage($this->indexChannel, $oldPinnedId);
                            $messagesToPurge[] = $oldPinnedId;
                            if ($newPinnedId > $oldPinnedId + 1) {
                                $messagesToPurge = array_merge($messagesToPurge, range($oldPinnedId + 1, $newPinnedId - 1));
                            }
                        }
                        if (!empty($messagesToPurge)) {
                            $this->telegram->deleteMessagesBatch($this->indexChannel, array_unique($messagesToPurge));
                        }
                        $pinnedMsgId = $newPinnedId;
                        $deltaCount = 0;
                    }
                }
            } catch (Exception $e) {
                error_log("Failed to post batched tombstone message: " . $e->getMessage());
            }

            $index['pinned_message_id'] = $pinnedMsgId;
            $index['delta_count'] = $deltaCount;
        } else {
            $index['pinned_message_id'] = $pinnedMsgId;
            $index['delta_count'] = $deltaCount;
        }

        // 6. Update local cache atomically
        $index['items'] = array_values($remainingItems);
        $index['total_items'] = count($remainingItems);
        @file_put_contents($this->getCachePath(), json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return [
            'deleted_count' => $deletedCount,
            'remaining_count' => count($remainingItems)
        ];
    }

    /**
     * Helper to collect all item IDs recursively for cascade deletion
     */
    private function collectCascadeIds(string $targetId, array $items, array &$collector): void {
        $collector[$targetId] = true;

        foreach ($items as $item) {
            if (($item['parent_id'] ?? 'root') === $targetId) {
                $this->collectCascadeIds($item['id'], $items, $collector);
            }
        }
    }

    /**
     * Upload single raw file/chunk to Storage Channel
     */
    public function uploadStorageChunk(string $tempFilePath, string $chunkFilename, string $caption = ''): array {
        $response = $this->telegram->sendDocument($this->storageChannel, $tempFilePath, $chunkFilename, $caption);
        $messageId = (int)$response['message_id'];
        $document = $response['document'];

        return [
            'message_id' => $messageId,
            'file_id'    => $document['file_id'],
            'file_unique_id' => $document['file_unique_id'] ?? '',
            'file_name'  => $document['file_name'] ?? $chunkFilename,
            'file_size'  => $document['file_size'] ?? filesize($tempFilePath)
        ];
    }

    /**
     * Generate master_manifest.json, upload as document to Index Channel and PIN it
     */
    public function rebuildAndPinManifest(array $items): int {
        $manifest = [
            'version'      => 1,
            'generated_at' => time(),
            'total_items'  => count($items),
            'delta_count'  => 0,
            'items'        => array_values($items)
        ];

        $jsonStr = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tempPath = TEMP_CHUNK_DIR . '/' . self::MANIFEST_FILENAME;
        file_put_contents($tempPath, $jsonStr);

        $newMsgId = 0;
        try {
            // 1. Upload new manifest document to Index Channel
            $docRes = $this->telegram->sendDocument($this->indexChannel, $tempPath, self::MANIFEST_FILENAME, 'TeleDrive Master Manifest Checkpoint');
            $newMsgId = (int)$docRes['message_id'];

            // 2. Pin the new manifest document message
            $this->telegram->pinChatMessage($this->indexChannel, $newMsgId);
        } catch (Exception $e) {
            error_log("Failed to rebuild and pin manifest: " . $e->getMessage());
        } finally {
            @unlink($tempPath);
        }

        return $newMsgId;
    }
}
