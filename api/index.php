<?php
/**
 * TeleDrive Unified REST API Endpoint
 * 
 * Routes actions:
 * - auth.login, auth.logout, auth.status
 * - files.list
 * - files.upload_chunk, files.complete_upload
 * - files.download, files.stream_preview
 * - files.rename, files.delete
 * - folder.create
 * - system.test_connection
 */

define('TELEDRIVE_INIT', true);
ob_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Helpers.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/TelegramClient.php';
require_once __DIR__ . '/../includes/StorageEngine.php';

// Set CORS & Security Headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        // --- 1. Authentication Actions ---
        case 'auth.login':
            $user = trim($_POST['username'] ?? '');
            $pass = trim($_POST['password'] ?? '');

            if (empty($user) || empty($pass)) {
                Helpers::error('Username and password are required.');
            }

            if (Auth::login($user, $pass)) {
                Helpers::success(['username' => $user], 'Login successful.');
            } else {
                Helpers::error('Invalid credentials.', 401);
            }
            break;

        case 'auth.logout':
            Auth::logout();
            Helpers::success([], 'Logged out successfully.');
            break;

        case 'auth.status':
            Helpers::success([
                'authenticated' => Auth::check(),
                'user'          => $_SESSION['teledrive_user'] ?? null
            ]);
            break;

        // --- 2. System Verification ---
        case 'system.status':
            Auth::requireAuth();
            $engine = new StorageEngine();
            $configured = $engine->isConfigured();
            $status = [
                'configured'      => $configured,
                'bot_token_set'   => !empty(TELEGRAM_BOT_TOKEN),
                'storage_channel' => !empty(STORAGE_CHANNEL_ID),
                'index_channel'   => !empty(INDEX_CHANNEL_ID),
                'php_version'     => PHP_VERSION,
                'max_upload_size' => ini_get('upload_max_filesize'),
                'post_max_size'   => ini_get('post_max_size')
            ];
            Helpers::success($status);
            break;

        // --- 3. File Listing & Tree ---
        case 'files.list':
            Auth::requireAuth();
            $parentId = $_GET['parent_id'] ?? 'root';
            $search = trim($_GET['search'] ?? '');
            
            $engine = new StorageEngine();
            $data = $engine->getFileSystemIndex();
            $items = $data['items'];

            // Filter by parent_id or search query
            $filtered = [];
            foreach ($items as $item) {
                if (!empty($search)) {
                    if (stripos($item['name'], $search) !== false) {
                        $filtered[] = $item;
                    }
                } else {
                    $itemParent = $item['parent_id'] ?? 'root';
                    if ($itemParent === $parentId) {
                        $filtered[] = $item;
                    }
                }
            }

            // Sort: Folders first, then by name
            usort($filtered, function($a, $b) {
                if ($a['type'] === $b['type']) {
                    return strcasecmp($a['name'], $b['name']);
                }
                return ($a['type'] === 'folder') ? -1 : 1;
            });

            Helpers::success([
                'current_folder_id' => $parentId,
                'items'             => $filtered,
                'total_count'       => count($filtered)
            ]);
            break;

        // --- 4. Chunked Upload Handling ---
        case 'files.upload_chunk':
            Auth::requireAuth();
            if (empty($_FILES['chunk']['tmp_name'])) {
                Helpers::error('No file chunk received.');
            }

            $uploadId   = preg_replace('/[^\w\-]/', '', $_POST['upload_id'] ?? '');
            $chunkIndex = (int)($_POST['chunk_index'] ?? 0);
            $totalChunks= (int)($_POST['total_chunks'] ?? 1);
            $filename   = Helpers::sanitizeFilename($_POST['filename'] ?? 'file');

            if (empty($uploadId)) {
                Helpers::error('Invalid upload session ID.');
            }

            $uploadSessionDir = TEMP_CHUNK_DIR . '/' . $uploadId;
            if (!is_dir($uploadSessionDir)) {
                @mkdir($uploadSessionDir, 0777, true);
            }

            $chunkPath = "{$uploadSessionDir}/part_{$chunkIndex}";
            if (!move_uploaded_file($_FILES['chunk']['tmp_name'], $chunkPath)) {
                Helpers::error('Failed to save uploaded chunk.');
            }

            Helpers::success([
                'chunk_index'  => $chunkIndex,
                'total_chunks' => $totalChunks,
                'received'     => true
            ], 'Chunk uploaded successfully.');
            break;

        case 'files.complete_upload':
            Auth::requireAuth();
            $uploadId    = preg_replace('/[^\w\-]/', '', $_POST['upload_id'] ?? '');
            $filename    = Helpers::sanitizeFilename($_POST['filename'] ?? 'file');
            $parentId    = $_POST['parent_id'] ?? 'root';
            $fileSize    = (int)($_POST['size'] ?? 0);
            $mimeType    = $_POST['mime_type'] ?? 'application/octet-stream';
            $totalChunks = (int)($_POST['total_chunks'] ?? 1);

            $uploadSessionDir = TEMP_CHUNK_DIR . '/' . $uploadId;
            if (!is_dir($uploadSessionDir)) {
                Helpers::error('Upload session not found.');
            }

            // Assemble / Upload chunks to Telegram Storage Channel
            // Telegram Bot API limit is 2GB per file. If total size is small or multipart chunks,
            // we send each assembled part (up to 1.9GB) to Telegram Storage Channel.
            $engine = new StorageEngine();
            $telegramChunks = [];

            // Case A: Single small file or merged parts
            $assembledFile = "{$uploadSessionDir}/assembled_{$filename}";
            $outHandle = fopen($assembledFile, 'wb');
            for ($i = 0; $i < $totalChunks; $i++) {
                $partPath = "{$uploadSessionDir}/part_{$i}";
                if (!file_exists($partPath)) {
                    fclose($outHandle);
                    Helpers::error("Missing chunk part {$i}.");
                }
                $inHandle = fopen($partPath, 'rb');
                stream_copy_to_stream($inHandle, $outHandle);
                fclose($inHandle);
            }
            fclose($outHandle);

            // Upload assembled file to Telegram Storage Channel
            $tgChunk = $engine->uploadStorageChunk($assembledFile, $filename, "TeleDrive File: {$filename}");
            $telegramChunks[] = [
                'part'       => 1,
                'message_id' => $tgChunk['message_id'],
                'file_id'    => $tgChunk['file_id'],
                'size'       => filesize($assembledFile)
            ];

            // Cleanup local temp files
            Helpers::removeDir($uploadSessionDir);

            // Register File entry in Index Channel
            $newEntry = $engine->createFileEntry([
                'name'      => $filename,
                'size'      => $fileSize ?: filesize($assembledFile),
                'mime_type' => $mimeType,
                'parent_id' => $parentId,
                'chunks'    => $telegramChunks
            ]);

            Helpers::success(['item' => $newEntry], 'File uploaded and indexed successfully.');
            break;

        // --- 5. Download & Streaming ---
        case 'files.download':
        case 'files.preview':
            Auth::requireAuth();
            $id = $_GET['id'] ?? '';
            $engine = new StorageEngine();
            $index = $engine->getFileSystemIndex();
            $target = null;

            foreach ($index['items'] as $item) {
                if ($item['id'] === $id && $item['type'] === 'file') {
                    $target = $item;
                    break;
                }
            }

            if (!$target || empty($target['chunks'])) {
                Helpers::error('File not found or has no chunks.', 404);
            }

            $isDownload = ($action === 'files.download');
            $disposition = $isDownload ? 'attachment' : 'inline';
            $filename = rawurlencode($target['name']);

            // Headers for streaming download
            header('Content-Type: ' . ($target['mime_type'] ?: 'application/octet-stream'));
            header("Content-Disposition: {$disposition}; filename=\"{$target['name']}\"; filename*=UTF-8''{$filename}");
            if (!empty($target['size'])) {
                header('Content-Length: ' . $target['size']);
            }
            header('Cache-Control: private, max-age=3600');
            header('Accept-Ranges: none');

            // Clean output buffers
            while (ob_get_level()) {
                ob_end_clean();
            }

            $tgClient = new TelegramClient();
            foreach ($target['chunks'] as $chunk) {
                $tgClient->streamFileToOutput($chunk['file_id']);
            }
            exit;

        // --- 6. Folder Creation ---
        case 'folder.create':
            Auth::requireAuth();
            $name = trim($_POST['name'] ?? '');
            $parentId = $_POST['parent_id'] ?? 'root';

            if (empty($name)) {
                Helpers::error('Folder name cannot be empty.');
            }

            $engine = new StorageEngine();
            $folder = $engine->createFolder($name, $parentId);
            Helpers::success(['folder' => $folder], 'Folder created successfully.');
            break;

        // --- 7. Rename & Delete Operations ---
        case 'items.rename':
            Auth::requireAuth();
            $id = $_POST['id'] ?? '';
            $newName = trim($_POST['name'] ?? '');

            if (empty($id) || empty($newName)) {
                Helpers::error('Item ID and new name are required.');
            }

            $engine = new StorageEngine();
            $updated = $engine->renameItem($id, $newName);
            Helpers::success(['item' => $updated], 'Item renamed successfully.');
            break;

        case 'items.delete':
            Auth::requireAuth();
            $id = $_POST['id'] ?? '';

            if (empty($id)) {
                Helpers::error('Item ID is required for deletion.');
            }

            $engine = new StorageEngine();
            $result = $engine->deleteItem($id);
            Helpers::success($result, 'Item(s) deleted successfully.');
            break;

        default:
            Helpers::error("Unknown action: {$action}", 404);
            break;
    }
} catch (Exception $e) {
    Helpers::error($e->getMessage(), 500);
}
