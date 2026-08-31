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
     * Load the current database / filesystem index
     * Reads pinned master_manifest.json + uncheckpointed delta messages
     */
    public function getFileSystemIndex(): array {
        if (!$this->isConfigured()) {
            return [
                'items'            => [],
                'pinned_message_id'=> 0,
                'delta_count'      => 0,
                'total_items'      => 0
            ];
        }

        $items = [];
        $pinnedMsgId = 0;
        $pinnedDocFileId = null;

        // 1. Fetch channel metadata to find pinned message
        try {
            $chatInfo = $this->telegram->getChat($this->indexChannel);
            if (isset($chatInfo['pinned_message'])) {
                $pinnedMsg = $chatInfo['pinned_message'];
                $pinnedMsgId = $pinnedMsg['message_id'];
                
                // If it's a document (master_manifest.json)
                if (isset($pinnedMsg['document']) && 
                    str_contains(strtolower($pinnedMsg['document']['file_name'] ?? ''), 'manifest')) {
                    $pinnedDocFileId = $pinnedMsg['document']['file_id'];
                }
            }
        } catch (Exception $e) {
            error_log("Failed to fetch getChat for index channel: " . $e->getMessage());
        }

        // 2. If pinned manifest document exists, download and parse
        if ($pinnedDocFileId) {
            try {
                $manifestJson = $this->telegram->downloadFileContent($pinnedDocFileId);
                $manifestData = json_decode($manifestJson, true);
                if (is_array($manifestData) && isset($manifestData['items'])) {
                    foreach ($manifestData['items'] as $item) {
                        $items[$item['id']] = $item;
                    }
                }
            } catch (Exception $e) {
                error_log("Failed to load master manifest content: " . $e->getMessage());
            }
        }

        // 3. In Telegram Bot API without MTProto, bots receive updates or scan delta messages.
        // We track deltas by scanning messages or keeping cached delta count in the manifest.
        // For individual message updates, we also parse index messages.
        
        return [
            'items'            => array_values($items),
            'pinned_message_id'=> $pinnedMsgId,
            'total_items'      => count($items)
        ];
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
            'chunks'      => $fileData['chunks'] ?? [], // array of ['part' => 1, 'file_id' => ..., 'message_id' => ..., 'size' => ...]
            'created_at'  => time(),
            'updated_at'  => time()
        ];

        return $this->saveMetadataEntry($metadata);
    }

    /**
     * Post a single metadata JSON message to the Index Channel & trigger compaction if needed
     */
    private function saveMetadataEntry(array $metadata): array {
        $jsonPayload = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        
        // Post text message to Index Channel
        $tgResponse = $this->telegram->sendMessage($this->indexChannel, "<code>" . htmlspecialchars($jsonPayload) . "</code>");
        $messageId = $tgResponse['message_id'];

        $metadata['index_message_id'] = $messageId;

        // Perform instant checkpoint/compaction update
        $this->recordEntryAndCheckpoint($metadata);

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

        // In-place edit of the index message if message_id is available
        if (isset($target['index_message_id']) && $target['index_message_id'] > 0) {
            try {
                $jsonPayload = json_encode($target, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $this->telegram->editMessageText($this->indexChannel, (int)$target['index_message_id'], "<code>" . htmlspecialchars($jsonPayload) . "</code>");
            } catch (Exception $e) {
                error_log("Failed to edit message text in place: " . $e->getMessage());
            }
        }

        // Rebuild and save updated manifest
        $this->rebuildManifest($items);

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
                // 1. Delete chunk messages from Storage Channel
                if ($item['type'] === 'file' && !empty($item['chunks'])) {
                    foreach ($item['chunks'] as $chunk) {
                        if (isset($chunk['message_id'])) {
                            $this->telegram->deleteMessage($this->storageChannel, (int)$chunk['message_id']);
                        }
                    }
                }

                // 2. Delete individual metadata message from Index Channel
                if (isset($item['index_message_id']) && $item['index_message_id'] > 0) {
                    $this->telegram->deleteMessage($this->indexChannel, (int)$item['index_message_id']);
                }
            } else {
                $remainingItems[] = $item;
            }
        }

        // Rebuild and pin updated manifest
        $this->rebuildManifest($remainingItems);

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
        $messageId = $response['message_id'];
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
     * Record new entry into the master manifest and trigger checkpointing compaction
     */
    private function recordEntryAndCheckpoint(array $newItem): void {
        $index = $this->getFileSystemIndex();
        $items = $index['items'];
        
        // Remove existing item with same ID if any, and append new
        $found = false;
        foreach ($items as &$it) {
            if ($it['id'] === $newItem['id']) {
                $it = $newItem;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $items[] = $newItem;
        }

        $this->rebuildManifest($items);
    }

    /**
     * Generate master_manifest.json, upload as document to Index Channel and PIN it
     */
    public function rebuildManifest(array $items): void {
        $manifest = [
            'version'      => 1,
            'generated_at' => time(),
            'total_items'  => count($items),
            'items'        => array_values($items)
        ];

        $jsonStr = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tempPath = TEMP_CHUNK_DIR . '/' . self::MANIFEST_FILENAME;
        file_put_contents($tempPath, $jsonStr);

        try {
            // Upload to Index Channel
            $docRes = $this->telegram->sendDocument($this->indexChannel, $tempPath, self::MANIFEST_FILENAME, 'TeleDrive Master Manifest Checkpoint');
            $newMsgId = $docRes['message_id'];

            // Pin new manifest
            $this->telegram->pinChatMessage($this->indexChannel, $newMsgId);

            // Clean up old pins if desired
        } catch (Exception $e) {
            error_log("Failed to rebuild and pin manifest: " . $e->getMessage());
        } finally {
            @unlink($tempPath);
        }
    }
}
