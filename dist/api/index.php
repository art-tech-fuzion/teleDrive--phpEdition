<?php
/**
 * TeleDrive Unified REST API Endpoint
 * 
 * Routes actions:
 * - auth.login, auth.logout, auth.status
 * - files.list
 * - files.upload_chunk, files.complete_upload
 * - files.download, files.preview (stream_preview alias)
 * - files.rename, files.delete
 * - folder.create
 * - items.move
 * - system.status
 */

define('TELEDRIVE_INIT', true);
ob_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Helpers.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/TelegramClient.php';
require_once __DIR__ . '/../includes/StorageEngine.php';

// -----------------------------------------------------------------------
// 1. Security Headers
// -----------------------------------------------------------------------
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('X-XSS-Protection: 1; mode=block');
// Content-Security-Policy — tight policy: only same-origin resources allowed
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: blob:; media-src 'self' blob:; frame-src 'none'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self';");

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// -----------------------------------------------------------------------
// 2. CSRF Protection for all state-changing POST actions
//    (Login and auth.status are exempt since they don't mutate user data
//    or are protected by credentials themselves)
// -----------------------------------------------------------------------
$csrfExemptActions = ['auth.login', 'auth.logout', 'auth.status', 'system.status'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($action, $csrfExemptActions, true)) {
    // Expect token either in header (X-CSRF-Token) or POST body (_csrf)
    $submittedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '';
    if (!Auth::verifyCsrf($submittedToken)) {
        Helpers::error('Invalid or missing CSRF token.', 403);
    }
}

// Release PHP session lock immediately for non-auth requests
// This allows simultaneous parallel uploads/downloads without blocking or session timeouts
if (!in_array($action, ['auth.login', 'auth.logout'], true)) {
    session_write_close();
}

try {
    switch ($action) {

        // --- 1. Authentication ---

        case 'auth.login':
            $user = trim($_POST['username'] ?? '');
            $pass = trim($_POST['password'] ?? '');

            if (empty($user) || empty($pass)) {
                Helpers::error('Username and password are required.');
            }

            // Check for active lockout before attempting login
            $lockout = Auth::getLockoutRemaining();
            if ($lockout > 0) {
                $mins = ceil($lockout / 60);
                Helpers::error("Too many failed attempts. Please try again in {$mins} minute(s).", 429);
            }

            if (Auth::login($user, $pass)) {
                // Return fresh CSRF token so JS can store it for subsequent requests
                Helpers::success([
                    'username'   => $user,
                    'csrf_token' => Auth::getCsrfToken()
                ], 'Login successful.');
            } else {
                // Check again in case this attempt triggered a lockout
                $lockout = Auth::getLockoutRemaining();
                if ($lockout > 0) {
                    $mins = ceil($lockout / 60);
                    Helpers::error("Too many failed attempts. Account locked for {$mins} minute(s).", 429);
                }
                Helpers::error('Invalid credentials.', 401);
            }
            break;

        case 'auth.logout':
            Auth::logout();
            Helpers::success([], 'Logged out successfully.');
            break;

        case 'auth.status':
            $authenticated = Auth::check();
            // Only return username when authenticated — prevents information leakage
            $payload = ['authenticated' => $authenticated];
            if ($authenticated) {
                $payload['user']       = $_SESSION['teledrive_user'] ?? null;
                $payload['csrf_token'] = Auth::getCsrfToken();
            }
            Helpers::success($payload);
            break;

        // --- 2. System Status ---

        case 'system.status':
            Auth::requireAuth();
            $engine = new StorageEngine();
            Helpers::success([
                'configured'      => $engine->isConfigured(),
                'bot_token_set'   => !empty(TELEGRAM_BOT_TOKEN),
                'storage_channel' => !empty(STORAGE_CHANNEL_ID),
                'index_channel'   => !empty(INDEX_CHANNEL_ID),
                'php_version'     => PHP_VERSION,
                'max_upload_size' => ini_get('upload_max_filesize'),
                'post_max_size'   => ini_get('post_max_size'),
            ]);
            break;

        // --- 3. File Listing & Tree ---

        case 'files.list':
            Auth::requireAuth();
            $parentId = $_GET['parent_id'] ?? 'root';
            $search   = trim($_GET['search'] ?? '');
            $forceRefresh = !empty($_GET['refresh']) || !empty($_GET['force_remote']);

            $engine = new StorageEngine();
            $data   = $engine->getFileSystemIndex($forceRefresh);
            $items  = $data['items'];

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

            // Sort: folders first, then alphabetical
            usort($filtered, function($a, $b) {
                if ($a['type'] === $b['type']) {
                    return strcasecmp($a['name'], $b['name']);
                }
                return ($a['type'] === 'folder') ? -1 : 1;
            });

            Helpers::success([
                'current_folder_id' => $parentId,
                'items'             => $filtered,
                'total_count'       => count($filtered),
            ]);
            break;

        // --- 4. Chunked Upload ---

        case 'files.upload_chunk':
            Auth::requireAuth();
            @set_time_limit(0);

            if (isset($_FILES['chunk']['error']) && $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
                $errCode = (int)$_FILES['chunk']['error'];
                if ($errCode === UPLOAD_ERR_INI_SIZE || $errCode === UPLOAD_ERR_FORM_SIZE) {
                    Helpers::error("Chunk size exceeded server limit (upload_max_filesize: " . ini_get('upload_max_filesize') . ").", 400);
                }
                Helpers::error("Chunk upload error (code {$errCode}).", 400);
            }

            if (empty($_FILES['chunk']['tmp_name']) || !is_uploaded_file($_FILES['chunk']['tmp_name'])) {
                Helpers::error('No file chunk received.', 400);
            }

            $uploadId    = preg_replace('/[^\w\-]/', '', $_POST['upload_id'] ?? '');
            $chunkIndex  = (int)($_POST['chunk_index'] ?? 0);
            $totalChunks = (int)($_POST['total_chunks'] ?? 1);
            $filename    = Helpers::sanitizeFilename($_POST['filename'] ?? 'file');

            if (empty($uploadId)) {
                Helpers::error('Invalid upload session ID.', 400);
            }

            // Validate chunk index is within expected bounds
            if ($chunkIndex < 0 || $chunkIndex >= $totalChunks) {
                Helpers::error('Chunk index out of bounds.', 400);
            }

            $uploadSessionDir = TEMP_CHUNK_DIR . '/' . $uploadId;
            if (!is_dir($uploadSessionDir)) {
                @mkdir($uploadSessionDir, 0750, true);
            }

            // Verify the session dir stays within TEMP_CHUNK_DIR (path traversal guard)
            $realSession = realpath($uploadSessionDir) ?: $uploadSessionDir;
            $realBase    = realpath(TEMP_CHUNK_DIR) ?: TEMP_CHUNK_DIR;
            if (strpos($realSession, $realBase) !== 0) {
                Helpers::error('Invalid upload session path.', 400);
            }

            $chunkPath = "{$uploadSessionDir}/part_{$chunkIndex}";
            if (!move_uploaded_file($_FILES['chunk']['tmp_name'], $chunkPath)) {
                Helpers::error('Failed to save uploaded chunk locally.', 500);
            }

            // Continuous pipelined streaming to Telegram Cloud as chunks arrive
            $lockFile = "{$uploadSessionDir}/.stream.lock";
            $lockFp = fopen($lockFile, 'c+');
            if ($lockFp && flock($lockFp, LOCK_EX)) {
                $stateFile = "{$uploadSessionDir}/session_state.json";
                $state = file_exists($stateFile) ? (json_decode(file_get_contents($stateFile), true) ?: []) : [];
                $nextPartIndex = (int)($state['next_part_index'] ?? 0);
                $batchIndex    = (int)($state['batch_index'] ?? 1);

                $batchThreshold = 10 * 1024 * 1024; // 10MB per Telegram batch document

                while ($nextPartIndex < $totalChunks) {
                    $contiguousParts = [];
                    $accumulatedBytes = 0;
                    $scanIdx = $nextPartIndex;

                    while ($scanIdx < $totalChunks && file_exists("{$uploadSessionDir}/part_{$scanIdx}")) {
                        $pSize = filesize("{$uploadSessionDir}/part_{$scanIdx}");
                        $contiguousParts[] = $scanIdx;
                        $accumulatedBytes += $pSize;
                        $scanIdx++;

                        if ($accumulatedBytes >= $batchThreshold) {
                            break;
                        }
                    }

                    if ($accumulatedBytes >= $batchThreshold) {
                        $batchFilename = "batch_{$batchIndex}_{$filename}";
                        $batchPath = "{$uploadSessionDir}/{$batchFilename}";
                        $outHandle = fopen($batchPath, 'wb');

                        foreach ($contiguousParts as $pIdx) {
                            $pFilePath = "{$uploadSessionDir}/part_{$pIdx}";
                            $inHandle = fopen($pFilePath, 'rb');
                            stream_copy_to_stream($inHandle, $outHandle);
                            fclose($inHandle);
                            @unlink($pFilePath);
                        }
                        fclose($outHandle);

                        $engine = new StorageEngine();
                        $tgChunk = $engine->uploadStorageChunk(
                            $batchPath,
                            $batchFilename,
                            "TeleDrive File: {$filename} (Part {$batchIndex})"
                        );

                        $batchSize = filesize($batchPath);
                        @unlink($batchPath);

                        $chunksFile = "{$uploadSessionDir}/session_chunks.json";
                        $chunksList = file_exists($chunksFile) ? (json_decode(file_get_contents($chunksFile), true) ?: []) : [];
                        $chunksList[] = [
                            'part'       => $batchIndex,
                            'message_id' => $tgChunk['message_id'],
                            'file_id'    => $tgChunk['file_id'],
                            'size'       => $tgChunk['file_size'] ?: $batchSize,
                        ];
                        file_put_contents($chunksFile, json_encode($chunksList, JSON_PRETTY_PRINT));

                        $nextPartIndex = $scanIdx;
                        $batchIndex++;

                        $state['next_part_index'] = $nextPartIndex;
                        $state['batch_index']     = $batchIndex;
                        file_put_contents($stateFile, json_encode($state));
                    } else {
                        break;
                    }
                }

                flock($lockFp, LOCK_UN);
                fclose($lockFp);
            }

            Helpers::success([
                'chunk_index'  => $chunkIndex,
                'total_chunks' => $totalChunks,
                'received'     => true,
            ], 'Chunk uploaded successfully.');
            break;

        case 'files.complete_upload':
            Auth::requireAuth();
            @set_time_limit(0);

            $uploadId    = preg_replace('/[^\w\-]/', '', $_POST['upload_id'] ?? '');
            $filename    = Helpers::sanitizeFilename($_POST['filename'] ?? 'file');
            $parentId    = $_POST['parent_id'] ?? 'root';
            $fileSize    = (int)($_POST['size'] ?? 0);
            $totalChunks = (int)($_POST['total_chunks'] ?? 1);

            $uploadSessionDir = TEMP_CHUNK_DIR . '/' . $uploadId;
            if (!is_dir($uploadSessionDir)) {
                Helpers::error('Upload session directory not found.', 400);
            }

            $lockFile = "{$uploadSessionDir}/.stream.lock";
            $lockFp = fopen($lockFile, 'c+');
            if ($lockFp) {
                flock($lockFp, LOCK_EX);
            }

            $stateFile = "{$uploadSessionDir}/session_state.json";
            $state = file_exists($stateFile) ? (json_decode(file_get_contents($stateFile), true) ?: []) : [];
            $nextPartIndex = (int)($state['next_part_index'] ?? 0);
            $batchIndex    = (int)($state['batch_index'] ?? 1);

            // Upload any remaining tail parts (usually only 1 small final batch)
            if ($nextPartIndex < $totalChunks) {
                $batchThreshold = 10 * 1024 * 1024;
                $currentBatchPath = "{$uploadSessionDir}/batch_{$batchIndex}_{$filename}";
                $currentOut = fopen($currentBatchPath, 'wb');
                $currentBatchSize = 0;
                $batchDocs = [];

                for ($i = $nextPartIndex; $i < $totalChunks; $i++) {
                    $pPath = "{$uploadSessionDir}/part_{$i}";
                    if (file_exists($pPath)) {
                        $pSize = filesize($pPath);
                        $in = fopen($pPath, 'rb');
                        stream_copy_to_stream($in, $currentOut);
                        fclose($in);
                        @unlink($pPath);
                        $currentBatchSize += $pSize;

                        if ($currentBatchSize >= $batchThreshold && $i < ($totalChunks - 1)) {
                            fclose($currentOut);
                            $batchDocs[$batchIndex] = [
                                'path'     => $currentBatchPath,
                                'filename' => "batch_{$batchIndex}_{$filename}",
                                'caption'  => "TeleDrive File: {$filename} (Part {$batchIndex})"
                            ];
                            $batchIndex++;
                            $currentBatchPath = "{$uploadSessionDir}/batch_{$batchIndex}_{$filename}";
                            $currentOut = fopen($currentBatchPath, 'wb');
                            $currentBatchSize = 0;
                        }
                    }
                }
                fclose($currentOut);

                if ($currentBatchSize > 0) {
                    $batchDocs[$batchIndex] = [
                        'path'     => $currentBatchPath,
                        'filename' => "batch_{$batchIndex}_{$filename}",
                        'caption'  => "TeleDrive File: {$filename} (Part {$batchIndex})"
                    ];
                } else {
                    @unlink($currentBatchPath);
                }

                if (!empty($batchDocs)) {
                    $tgClient = new TelegramClient();
                    $uploadedTail = $tgClient->sendDocumentsBatch(STORAGE_CHANNEL_ID, $batchDocs);
                    foreach ($batchDocs as $bDoc) {
                        @unlink($bDoc['path']);
                    }

                    $chunksFile = "{$uploadSessionDir}/session_chunks.json";
                    $chunksList = file_exists($chunksFile) ? (json_decode(file_get_contents($chunksFile), true) ?: []) : [];
                    foreach ($uploadedTail as $uChunk) {
                        $chunksList[] = [
                            'part'       => $uChunk['part'] ?? $batchIndex,
                            'message_id' => $uChunk['message_id'],
                            'file_id'    => $uChunk['file_id'],
                            'size'       => $uChunk['file_size'],
                        ];
                    }
                    file_put_contents($chunksFile, json_encode($chunksList, JSON_PRETTY_PRINT));
                }
            }

            // Read all accumulated chunks from session_chunks.json
            $chunksFile = "{$uploadSessionDir}/session_chunks.json";
            $chunksList = file_exists($chunksFile) ? (json_decode(file_get_contents($chunksFile), true) ?: []) : [];

            if ($lockFp) {
                flock($lockFp, LOCK_UN);
                fclose($lockFp);
            }

            Helpers::removeDir($uploadSessionDir);

            if (empty($chunksList)) {
                Helpers::error('No chunks uploaded to Telegram Storage.', 400);
            }

            usort($chunksList, function($a, $b) {
                return ($a['part'] ?? 0) <=> ($b['part'] ?? 0);
            });

            // Sort and format final chunk records
            $finalChunks = [];
            $partSeq = 1;
            $totalUploadedBytes = 0;
            foreach ($chunksList as $uChunk) {
                $finalChunks[] = [
                    'part'       => $partSeq++,
                    'message_id' => $uChunk['message_id'],
                    'file_id'    => $uChunk['file_id'],
                    'size'       => $uChunk['size'],
                ];
                $totalUploadedBytes += (int)$uChunk['size'];
            }

            // Validate parent folder
            $engine = new StorageEngine();
            if ($parentId !== 'root') {
                $indexData = $engine->getFileSystemIndex();
                $validParent = false;
                foreach ($indexData['items'] as $it) {
                    if ($it['id'] === $parentId && $it['type'] === 'folder') {
                        $validParent = true;
                        break;
                    }
                }
                if (!$validParent) {
                    Helpers::error('Invalid destination folder.', 400);
                }
            }

            // Determine MIME type from filename extension or safe fallback
            $detectedMime = 'application/octet-stream';
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $extMap = [
                'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
                'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
                'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mkv' => 'video/webm',
                'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg', 'wav' => 'audio/wav',
                'pdf' => 'application/pdf', 'txt' => 'text/plain', 'json' => 'application/json',
                'zip' => 'application/zip', 'rar' => 'application/x-rar-compressed',
                'tar' => 'application/x-tar', 'gz' => 'application/gzip',
                'wpress' => 'application/octet-stream'
            ];
            if (isset($extMap[$ext])) {
                $detectedMime = $extMap[$ext];
            }

            // Register complete file in Index Channel (< 50ms)
            $newEntry = $engine->createFileEntry([
                'name'      => $filename,
                'size'      => $totalUploadedBytes > 0 ? $totalUploadedBytes : $fileSize,
                'mime_type' => $detectedMime,
                'parent_id' => $parentId,
                'chunks'    => $finalChunks,
            ]);

            Helpers::success(['item' => $newEntry], 'File uploaded and indexed successfully.');
            break;

        // --- 5. Download & Streaming ---

        case 'files.download':
        case 'files.preview':
        case 'files.stream_preview':
            Auth::requireAuth();
            @set_time_limit(0);
            @ini_set('max_execution_time', '0');
            ignore_user_abort(true);
            $id     = $_GET['id'] ?? '';
            $engine = new StorageEngine();
            $index  = $engine->getFileSystemIndex();
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

            $isDownload  = ($action === 'files.download');
            $disposition = $isDownload ? 'attachment' : 'inline';

            // Re-sanitize filename for Content-Disposition header to prevent HTTP header injection.
            // Strip any characters that could break the header value: quotes, newlines, carriage returns.
            $safeFilename = preg_replace('/["\r\n]/', '', $target['name']);
            $safeFilename = Helpers::sanitizeFilename($safeFilename);
            $encodedFilename = rawurlencode($safeFilename);

            // Use server-detected MIME type; fallback to stored value which was already validated at upload
            $mimeType = $target['mime_type'] ?: 'application/octet-stream';

            // Calculate exact total size across all chunks
            $exactChunkSize = 0;
            foreach ($target['chunks'] as $chunk) {
                if (!empty($chunk['size'])) {
                    $exactChunkSize += (int)$chunk['size'];
                }
            }
            $finalSize = $exactChunkSize > 0 ? $exactChunkSize : (int)($target['size'] ?? 0);

            header('Content-Type: ' . $mimeType);
            header("Content-Disposition: {$disposition}; filename=\"{$safeFilename}\"; filename*=UTF-8''{$encodedFilename}");
            header('Cache-Control: no-transform, private, max-age=3600');
            header('X-Content-Type-Options: nosniff');

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
            $name     = trim($_POST['name'] ?? '');
            $parentId = $_POST['parent_id'] ?? 'root';

            if (empty($name)) {
                Helpers::error('Folder name cannot be empty.');
            }

            $engine = new StorageEngine();
            $folder = $engine->createFolder($name, $parentId);
            Helpers::success(['folder' => $folder], 'Folder created successfully.');
            break;

        // --- 7. Rename ---

        case 'items.rename':
            Auth::requireAuth();
            $id      = $_POST['id'] ?? '';
            $newName = trim($_POST['name'] ?? '');

            if (empty($id) || empty($newName)) {
                Helpers::error('Item ID and new name are required.');
            }

            $engine  = new StorageEngine();
            $updated = $engine->renameItem($id, $newName);
            Helpers::success(['item' => $updated], 'Item renamed successfully.');
            break;

        // --- 8. Move ---

        case 'items.move':
            Auth::requireAuth();
            $id           = $_POST['id'] ?? '';
            $destParentId = $_POST['parent_id'] ?? 'root';

            if (empty($id)) {
                Helpers::error('Item ID is required for moving.');
            }

            $engine = new StorageEngine();
            $moved  = $engine->moveItem($id, $destParentId);
            Helpers::success(['item' => $moved], 'Item moved successfully.');
            break;

        // --- 9. Folders List (for move modal) ---

        case 'folders.list':
            Auth::requireAuth();
            $engine  = new StorageEngine();
            $index   = $engine->getFileSystemIndex();
            $folders = array_filter($index['items'] ?? [], function($it) {
                return ($it['type'] ?? '') === 'folder';
            });
            Helpers::success(['folders' => array_values($folders)]);
            break;

        // --- 10. Delete ---

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

        // --- 11. Bulk Delete ---

        case 'items.bulk_delete':
            Auth::requireAuth();
            $rawIds = $_POST['ids'] ?? [];

            if (is_string($rawIds)) {
                $decoded = json_decode($rawIds, true);
                $ids = is_array($decoded) ? $decoded : explode(',', $rawIds);
            } elseif (is_array($rawIds)) {
                $ids = $rawIds;
            } else {
                $ids = [];
            }

            $ids = array_filter(array_map('trim', $ids));

            if (empty($ids)) {
                Helpers::error('No item IDs provided for bulk deletion.');
            }

            $engine = new StorageEngine();
            $result = $engine->deleteItems($ids);
            Helpers::success($result, "Successfully deleted {$result['deleted_count']} item(s).");
            break;

        default:
            Helpers::error("Unknown action: {$action}", 404);
            break;
    }

} catch (Exception $e) {
    Helpers::error($e->getMessage(), 500);
}
