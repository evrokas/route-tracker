<?php
/**
 * auth.php — Route Tracker v3
 * Shared session authentication guard with optional remember-me cookie.
 *
 * Usage:
 *   require_once __DIR__ . '/auth.php';
 *   Auth::requireLogin();           // redirects to login.php if not authenticated
 *   Auth::requireLoginOrJson();     // returns JSON 401 if not authenticated (for api.php)
 *   Auth::setRememberMe($config);   // call after successful login if "remember me" checked
 *   Auth::clearRememberMe($config); // call on logout
 */

class Auth
{
    private static bool $started = false;

    /** Cookie name for the remember-me token. */
    private const COOKIE_NAME   = 'rt_remember';
    /** Token lifetime in seconds (30 days). */
    private const COOKIE_TTL    = 30 * 24 * 3600;

    public static function startSession(): void
    {
        if (!self::$started && session_status() === PHP_SESSION_NONE) {
            // Harden session cookie
            session_set_cookie_params([
                'lifetime' => 0,           // until browser closes
                'path'     => '/',
                'secure'   => isset($_SERVER['HTTPS']),
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
            session_start();
            self::$started = true;
        }
    }

    /**
     * Require login for a dashboard page.
     * Checks session first, then remember-me cookie.
     * Redirects to login.php if not authenticated.
     */
    public static function requireLogin(): void
    {
        self::startSession();

        if (!empty($_SESSION['rt_authed'])) {
            return;
        }

        // Try remember-me cookie
        if (self::checkRememberCookie()) {
            return;
        }

        header('Location: login.php');
        exit;
    }

    /**
     * Require login for an API endpoint.
     * Returns a JSON 401 response if not authenticated.
     */
    public static function requireLoginOrJson(): void
    {
        self::startSession();

        if (!empty($_SESSION['rt_authed'])) {
            return;
        }

        if (self::checkRememberCookie()) {
            return;
        }

        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Session expired. Please log in again.', 'login_url' => 'login.php']);
        exit;
    }

    /**
     * Set a 30-day remember-me cookie and persist the token hash in the DB.
     * Call after a successful password_verify() login.
     */
    public static function setRememberMe(object $config): void
    {
        $token    = bin2hex(random_bytes(32));   // 64-char hex, cryptographically random
        $hash     = hash('sha256', $token);
        $expiry   = time() + self::COOKIE_TTL;

        $config->setSetting('remember_token_hash',   $hash);
        $config->setSetting('remember_token_expiry', (string)$expiry);

        setcookie(self::COOKIE_NAME, $token, [
            'expires'  => $expiry,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    /**
     * Clear the remember-me cookie and invalidate the stored token.
     */
    public static function clearRememberMe(object $config): void
    {
        $config->setSetting('remember_token_hash',   '');
        $config->setSetting('remember_token_expiry', '0');

        setcookie(self::COOKIE_NAME, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    /**
     * Log out: destroy session and optionally clear remember-me.
     */
    public static function logout(?object $config = null): void
    {
        self::startSession();
        $_SESSION = [];
        session_destroy();

        if ($config !== null) {
            self::clearRememberMe($config);
        }
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Validate the remember-me cookie against the stored hash.
     * Hydrates the session on success.
     */
    private static function checkRememberCookie(): bool
    {
        $token = $_COOKIE[self::COOKIE_NAME] ?? '';
        if ($token === '') {
            return false;
        }

        // Need Config to read stored hash — load it without circular dependency
        try {
            $baseDir = __DIR__;
            // Config may not be loaded yet; require it if needed
            if (!class_exists('Config', false)) {
                require_once $baseDir . '/Config.php';
            }
            $config = Config::load($baseDir);
        } catch (Exception $e) {
            return false;
        }

        $storedHash = $config->getSetting('remember_token_hash', '');
        $expiry     = (int)$config->getSetting('remember_token_expiry', '0');

        if ($storedHash === '' || $expiry === 0) {
            return false;
        }

        if (time() > $expiry) {
            // Token expired — clean up
            self::clearRememberMe($config);
            return false;
        }

        if (!hash_equals($storedHash, hash('sha256', $token))) {
            return false;
        }

        // Valid — hydrate session
        session_regenerate_id(true);
        $_SESSION['rt_authed'] = true;
        return true;
    }
}
