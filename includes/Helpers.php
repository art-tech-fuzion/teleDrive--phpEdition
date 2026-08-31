<?php
/**
 * Global Helper Functions & Response Formatters
 */

if (!defined('TELEDRIVE_INIT')) {
    exit('Direct access not permitted');
}

class Helpers {
    /**
     * Send JSON response and terminate script
     */
    public static function jsonResponse(array $data, int $statusCode = 200): void {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Send Error JSON response
     */
    public static function error(string $message, int $statusCode = 400, array $extra = []): void {
        self::jsonResponse(array_merge([
            'success' => false,
            'error'   => $message
        ], $extra), $statusCode);
    }

    /**
     * Send Success JSON response
     */
    public static function success(array $data = [], string $message = 'Success'): void {
        self::jsonResponse(array_merge([
            'success' => true,
            'message' => $message
        ], $data), 200);
    }

    /**
     * Generate standard UUID v4
     */
    public static function generateUUID(): string {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // set version to 0100
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // set bits 6-7 to 10
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Format byte sizes into human readable units
     */
    public static function formatBytes(int $bytes, int $precision = 2): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Sanitize filename to prevent path traversal or injection
     */
    public static function sanitizeFilename(string $filename): string {
        // Remove null bytes to prevent injection
        $filename = str_replace("\0", '', $filename);
        // Use basename to strip any path traversal component
        $filename = basename($filename);
        // Allow only safe characters; replace anything else with underscore
        $filename = preg_replace('/[^\w\s\d\-_~,;:\[\]\(\)\.]/u', '_', $filename);
        // Strip leading dots to prevent hidden-file creation (e.g. .htaccess)
        $filename = ltrim($filename, '.');
        // Guarantee a non-empty name and cap length
        $filename = trim($filename);
        if ($filename === '') {
            $filename = 'file_' . time();
        }
        return substr($filename, 0, 200);
    }

    /**
     * Clean directory recursively
     */
    public static function removeDir(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = "$dir/$file";
            is_dir($path) ? self::removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
