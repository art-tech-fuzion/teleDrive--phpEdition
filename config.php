<?php
/**
 * TeleDrive Configuration Loader & Session Manager
 * 
 * Handles .env parsing without third-party dependencies,
 * defines configuration constants, and initializes hardened native PHP sessions.
 */

// Prevent direct access if required
if (!defined('TELEDRIVE_INIT')) {
    define('TELEDRIVE_INIT', true);
}

// 1. Lightweight Custom .env Parser
function load_env_file(string $path): void {
    if (!file_exists($path)) {
        return;
    }
    
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        // Ignore comments
        if (empty($line) || str_starts_with($line, '#')) {
            continue;
        }
        
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            $val = trim($parts[1]);
            
            // Strip surrounding quotes if present
            if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
                (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
                $val = substr($val, 1, -1);
            }
            
            if (!array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $val;
                putenv("$key=$val");
            }
        }
    }
}

// Load environment from .env
load_env_file(__DIR__ . '/.env');

// Helper to get env variable with fallback
function env(string $key, mixed $default = null): mixed {
    return $_ENV[$key] ?? getenv($key) ?: $default;
}

// 2. Constants Definition
define('ADMIN_USER', env('ADMIN_USER', 'admin'));
define('ADMIN_PASSWORD_HASH', env('ADMIN_PASSWORD_HASH', ''));
define('SESSION_SECRET', env('SESSION_SECRET', 'teledrive_default_session_secret'));
define('TELEGRAM_BOT_TOKEN', env('TELEGRAM_BOT_TOKEN', ''));
define('STORAGE_CHANNEL_ID', env('STORAGE_CHANNEL_ID', ''));
define('INDEX_CHANNEL_ID', env('INDEX_CHANNEL_ID', ''));
define('TEMP_CHUNK_DIR', __DIR__ . '/' . env('TEMP_CHUNK_DIR', 'temp_chunks'));

// Ensure chunk directory exists
if (!is_dir(TEMP_CHUNK_DIR)) {
    @mkdir(TEMP_CHUNK_DIR, 0750, true);
}

// 3. Secure Session Initialization
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Strict');
    
    // Use secure cookie if HTTPS is detected
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        ini_set('session.cookie_secure', '1');
    }
    
    session_name('TELEDRIVE_SESSID');
    session_start();
}
