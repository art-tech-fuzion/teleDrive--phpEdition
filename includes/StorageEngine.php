<?php
/**
 * Zero-Database Storage & Metadata Checkpointing Engine
 * 
 * Implements the core architecture:
 * 1. Metadata storage as JSON messages in Index Channel
 * 2. 50-Message Compaction / Checkpointing with pinned master_manifest.json
 * 3. Fast sub-second index loading
 * 4. Cascade folder deletion & in-place updates
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
     * Prioritizes fast local cache, falls back to pinned manifest from Index Channel
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

        // 1. Check local cache first only if it has valid items
        if (!$forceRemote && file_exists($cacheFile)) {
            $cached = json_decode(@file_get_contents($cacheFile), true);
            if (is_array($cached) && !empty($cached['items'])) {
                return $cached;
            }
        }

        $items = [];
        $pinnedMsgId = 0;
        $pinnedDocFileId = null;
        $deltaCount = 0;

        // 2. Fetch pinned message from Telegram Index Channel
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

        // 3. Download and parse pinned master_manifest.json
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

        // 4. If remote fetch succeeded or returned items, update local cache
        $indexData = [
            'items'            => array_values($items),
            'pinned_message_id'=> $pinnedMsgId,
            'delta_count'      => $deltaCount,
            'total_items'      => count($items)
        ];

        if (!empty($items) || $pinnedDocFileId) {
            @file_put_contents($cacheFile, json_encode($indexData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

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
     * Post a single metadata JSON message to Index Channel & trigger 50-message compaction
     */
    private function saveMetadataEntry(array $metadata): array {
        $jsonPayload = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        
        // 1. Post individual JSON message to Telegram Index Channel
        $tgResponse = $this->telegram->sendMessage($this->indexChannel, "<code>" . htmlspecialchars($jsonPayload) . "</code>");
        $messageId = (int)$tgResponse['message_id'];
        $metadata['index_message_id'] = $messageId;

        // 2. Update filesystem index
        $index = $this->getFileSystemIndex();
        $items = $index['items'];
        $deltaCount = (int)($index['delta_count'] ?? 0) + 1;

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

        // 3. Check 50-Message Compaction Threshold
        // Strictly only compact when reaching the 50th delta message
        if ($deltaCount >= self::COMPACTION_THRESHOLD) {
            // Merge all items, upload master_manifest.json document and PIN it
            $newPinnedId = $this->rebuildAndPinManifest($items);
            if ($newPinnedId > 0) {
                $index['pinned_message_id'] = $newPinnedId;
                $deltaCount = 0; // Reset delta counter after 50-message checkpoint
            }
        }

        // Save updated cache
        $index['items'] = array_values($items);
        $index['delta_count'] = $deltaCount;
        $index['total_items'] = count($items);
        @file_put_contents($this->getCachePath(), json_encode($index, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $metadata;
    }

    /**
     * Rename a file or folder
     */
    public function renameItem(string $id, string $newName): array {
        $index = $this->getFileSystemIndex();
        $items = $index['items'];
        $target = null;
        $newName = Helpers::sanitizeFilename($newName);

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

        // In-place edit of the individual index message in Telegram Index Channel
        if (isset($target['index_message_id']) && $target['index_message_id'] > 0) {
            try {
                $jsonPayload = json_encode($target, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $this->telegram->editMessageText($this->indexChannel, (int)$target['index_message_id'], "<code>" . htmlspecialchars($jsonPayload) . "</code>");
            } catch (Exception $e) {
                error_log("Failed to edit message text in place: " . $e->getMessage());
            }
        }

        // Update local cache without re-uploading manifest document for single renames
        $index['items'] = array_values($items);
        @file_put_contents($this->getCachePath(), json_encode($index, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $target;
    }

    /**
     * Delete an item (File or Folder with recursive cascade)
     */
    public function deleteItem(string $id): array {
        $index = $this->getFileSystemIndex();
        $items = $index['items'];

        $toDelete = [];
        $this->collectCascadeIds($id, $items, $toDelete);

        $deletedCount = 0;
        $remainingItems = [];

        foreach ($items as $item) {
            if (isset($toDelete[$item['id']])) {
                $deletedCount++;
                // 1. Delete chunk messages from Storage Channel (safely caught)
                if ($item['type'] === 'file' && !empty($item['chunks'])) {
                    foreach ($item['chunks'] as $chunk) {
                        if (isset($chunk['message_id'])) {
                            try {
                                $this->telegram->deleteMessage($this->storageChannel, (int)$chunk['message_id']);
                            } catch (Exception $e) {
                                error_log("Failed to delete storage message: " . $e->getMessage());
                            }
                        }
                    }
                }

                // 2. Delete individual metadata message from Index Channel (safely caught)
                if (isset($item['index_message_id']) && $item['index_message_id'] > 0) {
                    try {
                        $this->telegram->deleteMessage($this->indexChannel, (int)$item['index_message_id']);
                    } catch (Exception $e) {
                        error_log("Failed to delete index message: " . $e->getMessage());
                    }
                }
            } else {
                $remainingItems[] = $item;
            }
        }

        // Update local cache without creating new document on every single delete
        $index['items'] = array_values($remainingItems);
        $index['total_items'] = count($remainingItems);
        @file_put_contents($this->getCachePath(), json_encode($index, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

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
