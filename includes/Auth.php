<?php
/**
 * Authentication and Session Management
 */

if (!defined('TELEDRIVE_INIT')) {
    exit('Direct access not permitted');
}

class Auth {
    /**
     * Check if user is currently logged in
     */
    public static function check(): bool {
        if (!isset($_SESSION['teledrive_authenticated']) || $_SESSION['teledrive_authenticated'] !== true) {
            return false;
        }

        // Verify session signature binding
        $expectedSignature = hash_hmac('sha256', $_SESSION['teledrive_user'] ?? '', SESSION_SECRET);
        return isset($_SESSION['teledrive_token']) && hash_equals($_SESSION['teledrive_token'], $expectedSignature);
    }

    /**
     * Authenticate user with username and plain password
     */
    public static function login(string $username, string $password): bool {
        $validUser = ADMIN_USER;
        $validHash = ADMIN_PASSWORD_HASH;

        // Constant time comparison for username
        if (!hash_equals($validUser, $username)) {
            return false;
        }

        // Verify password hash
        if (!password_verify($password, $validHash)) {
            return false;
        }

        // Prevent session fixation
        session_regenerate_id(true);

        $_SESSION['teledrive_authenticated'] = true;
        $_SESSION['teledrive_user'] = $username;
        $_SESSION['teledrive_token'] = hash_hmac('sha256', $username, SESSION_SECRET);
        $_SESSION['teledrive_login_time'] = time();

        return true;
    }

    /**
     * Terminate user session
     */
    public static function logout(): void {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();
    }

    /**
     * Require authentication for API endpoints
     */
    public static function requireAuth(): void {
        if (!self::check()) {
            Helpers::error('Unauthorized access. Please login.', 401);
        }
    }
}
