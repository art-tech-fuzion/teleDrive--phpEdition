<?php
/**
 * Authentication and Session Management
 * 
 * Security features:
 * - bcrypt password verification
 * - Constant-time username comparison
 * - Session fixation prevention
 * - HMAC session token binding
 * - Login rate limiting (5 attempts → 15-minute lockout)
 * - Session age timeout (12 hours of inactivity)
 */

if (!defined('TELEDRIVE_INIT')) {
    exit('Direct access not permitted');
}

class Auth {

    /** Maximum failed login attempts before lockout */
    const MAX_ATTEMPTS      = 5;
    /** Lockout duration in seconds (15 minutes) */
    const LOCKOUT_SECONDS   = 900;
    /** Session idle timeout in seconds (12 hours) */
    const SESSION_LIFETIME  = 43200;

    /**
     * Check if user is currently authenticated and session is still valid
     */
    public static function check(): bool {
        if (!isset($_SESSION['teledrive_authenticated']) || $_SESSION['teledrive_authenticated'] !== true) {
            return false;
        }

        // Enforce session idle timeout
        $lastActivity = (int)($_SESSION['teledrive_last_activity'] ?? 0);
        if ($lastActivity > 0 && (time() - $lastActivity) > self::SESSION_LIFETIME) {
            self::logout();
            return false;
        }

        // Verify session HMAC signature
        $expectedSignature = hash_hmac('sha256', $_SESSION['teledrive_user'] ?? '', SESSION_SECRET);
        if (!isset($_SESSION['teledrive_token']) || !hash_equals($_SESSION['teledrive_token'], $expectedSignature)) {
            return false;
        }

        // Refresh last-activity timestamp on each valid check
        $_SESSION['teledrive_last_activity'] = time();
        return true;
    }

    /**
     * Authenticate user with username and plain password.
     * Enforces rate limiting to mitigate brute-force attacks.
     */
    public static function login(string $username, string $password): bool {
        // --- Rate Limiting ---
        $attemptKey  = 'teledrive_login_attempts';
        $lockoutKey  = 'teledrive_lockout_until';

        $lockoutUntil = (int)($_SESSION[$lockoutKey] ?? 0);
        if ($lockoutUntil > 0 && time() < $lockoutUntil) {
            // Still within lockout window
            return false;
        }

        // Validate credentials
        $validUser = ADMIN_USER;
        $validHash = ADMIN_PASSWORD_HASH;

        // Constant-time comparison for username
        $userMatch = hash_equals($validUser, $username);
        // Verify bcrypt password hash (always run to avoid timing side-channel)
        $passMatch = password_verify($password, $validHash);

        if (!$userMatch || !$passMatch) {
            // Increment failed attempt counter
            $_SESSION[$attemptKey] = (int)($_SESSION[$attemptKey] ?? 0) + 1;

            if ($_SESSION[$attemptKey] >= self::MAX_ATTEMPTS) {
                // Trigger lockout
                $_SESSION[$lockoutKey] = time() + self::LOCKOUT_SECONDS;
                $_SESSION[$attemptKey] = 0;
            }
            return false;
        }

        // --- Success: clear rate-limit counters ---
        unset($_SESSION[$attemptKey], $_SESSION[$lockoutKey]);

        // Prevent session fixation
        session_regenerate_id(true);

        $_SESSION['teledrive_authenticated']  = true;
        $_SESSION['teledrive_user']           = $username;
        $_SESSION['teledrive_token']          = hash_hmac('sha256', $username, SESSION_SECRET);
        $_SESSION['teledrive_login_time']     = time();
        $_SESSION['teledrive_last_activity']  = time();

        return true;
    }

    /**
     * Check if the account is currently rate-limited.
     * Returns remaining lockout seconds or 0 if not locked.
     */
    public static function getLockoutRemaining(): int {
        $lockoutUntil = (int)($_SESSION['teledrive_lockout_until'] ?? 0);
        if ($lockoutUntil > 0 && time() < $lockoutUntil) {
            return $lockoutUntil - time();
        }
        return 0;
    }

    /**
     * Terminate user session completely
     */
    public static function logout(): void {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(), '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();
    }

    /**
     * Require authentication for API endpoints — sends 401 and exits if not authenticated
     */
    public static function requireAuth(): void {
        if (!self::check()) {
            Helpers::error('Unauthorized. Please login.', 401);
        }
    }

    /**
     * Generate a CSRF token bound to the current session and store it.
     * Returns an existing token if one is already set.
     */
    public static function getCsrfToken(): string {
        if (empty($_SESSION['teledrive_csrf_token'])) {
            $_SESSION['teledrive_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['teledrive_csrf_token'];
    }

    /**
     * Verify a submitted CSRF token against the session-bound token.
     */
    public static function verifyCsrf(string $submittedToken): bool {
        $expected = $_SESSION['teledrive_csrf_token'] ?? '';
        return !empty($expected) && hash_equals($expected, $submittedToken);
    }
}
