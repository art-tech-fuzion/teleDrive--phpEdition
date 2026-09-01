<?php
/**
 * Telegram Bot API Client Wrapper
 * 
 * Handles all direct HTTP communication with Telegram servers,
 * supporting JSON requests, document uploads, message editing,
 * pin/unpin operations, and chunk streaming downloads.
 */

if (!defined('TELEDRIVE_INIT')) {
    exit('Direct access not permitted');
}

class TelegramClient {
    private string $botToken;
    private string $apiUrl;
    private mixed $ch = null;

    public function __construct(?string $token = null) {
        $this->botToken = $token ?: TELEGRAM_BOT_TOKEN;
        $this->apiUrl = "https://api.telegram.org/bot{$this->botToken}/";
    }

    public function __destruct() {
        if ($this->ch && is_resource($this->ch)) {
            curl_close($this->ch);
        }
    }

    /**
     * Execute generic Telegram API method
     */
    public function request(string $method, array $params = [], string $httpMethod = 'POST'): mixed {
        if (empty($this->botToken)) {
            throw new Exception('Telegram Bot Token is not configured.');
        }

        $url = $this->apiUrl . $method;
        
        if (!$this->ch || !is_resource($this->ch)) {
            $this->ch = curl_init();
            curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($this->ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($this->ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($this->ch, CURLOPT_TIMEOUT, 45);
            curl_setopt($this->ch, CURLOPT_TCP_KEEPALIVE, 1);
            curl_setopt($this->ch, CURLOPT_FORBID_REUSE, 0);
        }

        curl_setopt($this->ch, CURLOPT_URL, $url);

        if ($httpMethod === 'POST') {
            curl_setopt($this->ch, CURLOPT_POST, true);
            // If params contain CURLFile or binary, send as multipart form-data
            $hasFile = false;
            foreach ($params as $param) {
                if ($param instanceof CURLFile) {
                    $hasFile = true;
                    break;
                }
            }

            if ($hasFile) {
                curl_setopt($this->ch, CURLOPT_TIMEOUT, 180); // 3 minutes timeout for chunk uploads
                curl_setopt($this->ch, CURLOPT_POSTFIELDS, $params);
            } else {
                curl_setopt($this->ch, CURLOPT_TIMEOUT, 45);
                curl_setopt($this->ch, CURLOPT_POSTFIELDS, http_build_query($params));
            }
        } else {
            curl_setopt($this->ch, CURLOPT_TIMEOUT, 45);
            curl_setopt($this->ch, CURLOPT_HTTPGET, true);
        }

        $response = curl_exec($this->ch);
        $httpCode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($this->ch);

        if ($response === false) {
            throw new Exception("Telegram cURL Error: {$curlError}");
        }

        $result = json_decode($response, true);
        if (!$result || !isset($result['ok'])) {
            throw new Exception("Invalid response from Telegram API (HTTP {$httpCode}): {$response}");
        }

        if ($result['ok'] !== true) {
            $desc = $result['description'] ?? 'Unknown Telegram error';
            throw new Exception("Telegram API Error ({$result['error_code']}): {$desc}");
        }

        return $result['result'];
    }

    /**
     * Send a text message to a channel
     */
    public function sendMessage(string|int $chatId, string $text): array {
        return $this->request('sendMessage', [
            'chat_id' => $chatId,
            'text'    => $text,
            'parse_mode' => 'HTML'
        ]);
    }

    /**
     * Forward a message from one chat to another (used to read message contents)
     */
    public function forwardMessage(string|int $toChatId, string|int $fromChatId, int $messageId): array {
        return $this->request('forwardMessage', [
            'chat_id'      => $toChatId,
            'from_chat_id' => $fromChatId,
            'message_id'   => $messageId
        ]);
    }

    /**
     * High-speed parallel concurrent fetch of multiple messages using curl_multi
     * Forwards all requested IDs concurrently and cleans up temporary forwarded copies in parallel.
     */
    public function fetchMessagesBatch(string|int $targetChatId, string|int $sourceChatId, array $messageIds): array {
        if (empty($messageIds) || empty($this->botToken)) {
            return [];
        }

        $fwdUrl = $this->apiUrl . 'forwardMessage';
        $mh = curl_multi_init();
        $handles = [];

        foreach ($messageIds as $id) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $fwdUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                'chat_id'      => $targetChatId,
                'from_chat_id' => $sourceChatId,
                'message_id'   => $id
            ]));
            curl_multi_add_handle($mh, $ch);
            $handles[$id] = $ch;
        }

        $running = null;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 0.2);
        } while ($running > 0);

        $messages = [];
        $delHandles = [];
        $delMh = curl_multi_init();
        $delUrl = $this->apiUrl . 'deleteMessage';

        foreach ($handles as $id => $ch) {
            $content = curl_multi_getcontent($ch);
            curl_multi_remove_handle($mh, $ch);
            if (PHP_VERSION_ID < 80000) {
                curl_close($ch);
            }
            $data = json_decode($content, true);
            if (!empty($data['ok']) && isset($data['result']['message_id'])) {
                $messages[$id] = $data['result'];

                // Enqueue parallel deletion of the forwarded copy in target chat
                $dch = curl_init();
                curl_setopt($dch, CURLOPT_URL, $delUrl);
                curl_setopt($dch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($dch, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($dch, CURLOPT_CONNECTTIMEOUT, 8);
                curl_setopt($dch, CURLOPT_TIMEOUT, 15);
                curl_setopt($dch, CURLOPT_POST, true);
                curl_setopt($dch, CURLOPT_POSTFIELDS, http_build_query([
                    'chat_id'    => $targetChatId,
                    'message_id' => $data['result']['message_id']
                ]));
                curl_multi_add_handle($delMh, $dch);
                $delHandles[] = $dch;
            }
        }
        curl_multi_close($mh);

        // Execute parallel cleanup deletions
        if (!empty($delHandles)) {
            $delRunning = null;
            do {
                curl_multi_exec($delMh, $delRunning);
                curl_multi_select($delMh, 0.2);
            } while ($delRunning > 0);

            foreach ($delHandles as $dch) {
                curl_multi_remove_handle($delMh, $dch);
                if (PHP_VERSION_ID < 80000) {
                    curl_close($dch);
                }
            }
        }
        curl_multi_close($delMh);

        return $messages;
    }

    /**
     * Edit an existing message text (for rename / in-place updates)
     */
    public function editMessageText(string|int $chatId, int $messageId, string $text): array {
        return $this->request('editMessageText', [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'text'       => $text,
            'parse_mode' => 'HTML'
        ]);
    }

    /**
     * Delete a message from a channel
     */
    public function deleteMessage(string|int $chatId, int $messageId): bool {
        try {
            $res = $this->request('deleteMessage', [
                'chat_id'    => $chatId,
                'message_id' => $messageId
            ]);
            return (bool)$res;
        } catch (Exception $e) {
            // If already deleted or missing, log and return false
            error_log("Delete message error ($chatId / $messageId): " . $e->getMessage());
            return false;
        }
    }

    /**
     * Upload a local file as document to a channel
     */
    public function sendDocument(string|int $chatId, string $filePath, string $filename, string $caption = ''): array {
        if (!file_exists($filePath)) {
            throw new Exception("File not found for upload: {$filePath}");
        }

        $cFile = new CURLFile($filePath, mime_content_type($filePath) ?: 'application/octet-stream', $filename);
        $params = [
            'chat_id'  => $chatId,
            'document' => $cFile,
        ];
        if (!empty($caption)) {
            $params['caption'] = $caption;
        }

        return $this->request('sendDocument', $params, 'POST');
    }

    /**
     * Pin a message in a channel
     */
    public function pinChatMessage(string|int $chatId, int $messageId, bool $disableNotification = true): bool {
        try {
            $res = $this->request('pinChatMessage', [
                'chat_id'              => $chatId,
                'message_id'           => $messageId,
                'disable_notification' => $disableNotification
            ]);
            return (bool)$res;
        } catch (Exception $e) {
            error_log("Pin message error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Unpin a message or all messages in a channel
     */
    public function unpinChatMessage(string|int $chatId, ?int $messageId = null): bool {
        try {
            $params = ['chat_id' => $chatId];
            if ($messageId !== null) {
                $params['message_id'] = $messageId;
                $this->request('unpinChatMessage', $params);
            } else {
                $this->request('unpinAllChatMessages', $params);
            }
            return true;
        } catch (Exception $e) {
            error_log("Unpin error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get Chat Information (e.g. pinned_message)
     */
    public function getChat(string|int $chatId): array {
        return $this->request('getChat', ['chat_id' => $chatId]);
    }

    /**
     * Get Telegram File Path from file_id
     */
    public function getFile(string $fileId): array {
        return $this->request('getFile', ['file_id' => $fileId]);
    }

    /**
     * Get Direct Download URL for a file
     */
    public function getFileDownloadUrl(string $fileId): string {
        $fileInfo = $this->getFile($fileId);
        $filePath = $fileInfo['file_path'];
        return "https://api.telegram.org/file/bot{$this->botToken}/{$filePath}";
    }

    /**
     * Fetch document contents into memory (e.g. for master_manifest.json)
     */
    public function downloadFileContent(string $fileId): string {
        $url = $this->getFileDownloadUrl($fileId);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);

        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }

        if ($content === false || $httpCode !== 200) {
            throw new Exception("Failed to download file from Telegram (HTTP {$httpCode})");
        }

        return $content;
    }

    /**
     * Stream binary content directly to client output (for proxying chunk downloads)
     */
    public function streamFileToOutput(string $fileId): void {
        $url = $this->getFileDownloadUrl($fileId);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 0); // No timeout for binary streaming
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) {
            echo $data;
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
            return strlen($data);
        });

        curl_exec($ch);
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }
    }
}
