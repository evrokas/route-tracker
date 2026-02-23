<?php
/**
 * auth.php — Route Tracker v2
 * Shared session authentication guard.
 * Include this at the top of any file that requires login.
 *
 * Usage:
 *   require_once __DIR__ . '/auth.php';
 *   Auth::requireLogin();           // redirects to login.php if not authenticated
 *   Auth::requireLoginOrJson();     // returns JSON 401 if not authenticated (for api.php)
 */

class Auth
{
    private static bool $started = false;

    public static function startSession(): void
    {
        if (!self::$started && session_status() === PHP_SESSION_NONE) {
            // Harden session cookie
            session_set_cookie_params([
                'lifetime' => 0,           // until browser closes
                'path'     => '/',
                'secure'   => isset($_SERVER['HTTPS']),
                'httponly' => true,        // no JS access to cookie
                'samesite' => 'Strict',
            ]);
            session_start();
            self::$started = true;
        }
    }

    /**
     * Require login for a dashboard page.
     * Redirects to login.php if not authenticated.
     */
    public static function requireLogin(): void
    {
        self::startSession();
        if (empty($_SESSION['rt_authed'])) {
            header('Location: login.php');
            exit;
        }
    }

    /**
     * Require login for an API endpoint.
     * Returns a JSON 401 response if not authenticated.
     */
    public static function requireLoginOrJson(): void
    {
        self::startSession();
        if (empty($_SESSION['rt_authed'])) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Session expired. Please log in again.', 'login_url' => 'login.php']);
            exit;
        }
    }

    /**
     * Log out: destroy the session.
     */
    public static function logout(): void
    {
        self::startSession();
        $_SESSION = [];
        session_destroy();
    }
}
