<?php
declare(strict_types=1);
namespace PoolProFlorida;

/**
 * Session-only dashboard login. Both DASHBOARD_USERNAME and
 * DASHBOARD_PASSWORD_HASH must be configured; missing values fail closed.
 * CardDAV credentials and authentication remain separate.
 */
final class DashboardAuth
{
    private const IDLE_SECONDS = 1800;
    private const MAX_SECONDS = 43200;

    public static function enabled(): bool
    {
        $name = Config::get('DASHBOARD_USERNAME');
        $hash = Config::get('DASHBOARD_PASSWORD_HASH');
         if ($name === '' || $hash === '' || password_get_info($hash)['algo'] === null) {
            // PHP versions disagree whether unknown hash algo is null or 0.
            self::unconfigured();
        }
        if (!in_array((string)(password_get_info($hash)['algoName'] ?? ''), ['bcrypt', 'argon2i', 'argon2id'], true)) {
            self::unconfigured();
        }
        return true;
    }

    private static function unconfigured(): never
    {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        exit("Dashboard login configuration is incomplete. Set both DASHBOARD_USERNAME and DASHBOARD_PASSWORD_HASH.\n");
    }

    public static function start(): void
    {
        if (!Http::secure()) {
            http_response_code(403);
            exit("HTTPS is required.\n");
        }
        if (session_status() === PHP_SESSION_ACTIVE) return;
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', '1');
        $path = Http::base();
        session_name('PFCS_' . substr(hash('sha256', $path), 0, 14));
        session_set_cookie_params([
            'lifetime' => 0, 'path' => $path === '' ? '/' : $path . '/',
            'secure' => true, 'httponly' => true, 'samesite' => 'Strict',
        ]);
        session_start();
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('X-Frame-Options: DENY');
    }

    public static function signedIn(): bool
    {
        self::start();
        $name = Config::get('DASHBOARD_USERNAME');
        $signature = hash('sha256', Config::get('DASHBOARD_PASSWORD_HASH'));
        $now = time();
        if (!isset($_SESSION['admin_user'], $_SESSION['admin_hash'], $_SESSION['login_at'], $_SESSION['last_at'])
            || !is_string($_SESSION['admin_user']) || !is_string($_SESSION['admin_hash'])
            || !hash_equals($name, $_SESSION['admin_user'])
            || !hash_equals($signature, $_SESSION['admin_hash'])
            || !is_int($_SESSION['login_at']) || !is_int($_SESSION['last_at'])
            || $now - $_SESSION['last_at'] > self::IDLE_SECONDS
            || $now - $_SESSION['login_at'] > self::MAX_SECONDS) {
            unset($_SESSION['admin_user'], $_SESSION['admin_hash'], $_SESSION['login_at'], $_SESSION['last_at']);
            return false;
        }
        $_SESSION['last_at'] = $now;
        return true;
    }

    public static function guard(): string
    {
        self::enabled();
        if (!self::signedIn()) {
            header('Location: ' . Http::base() . '/login.php', true, 303);
            exit;
        }
        return Config::get('DASHBOARD_USERNAME');
    }

    public static function logIn(string $name, string $password): bool
    {
        self::enabled();
        self::start();
        $expectedName = Config::get('DASHBOARD_USERNAME');
        $valid = hash_equals($expectedName, $name)
            && password_verify($password, Config::get('DASHBOARD_PASSWORD_HASH'));
        if (!$valid) return false;
        session_regenerate_id(true);
        $_SESSION['admin_user'] = $expectedName;
        $_SESSION['admin_hash'] = hash('sha256', Config::get('DASHBOARD_PASSWORD_HASH'));
        $_SESSION['login_at'] = time();
        $_SESSION['last_at'] = time();
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return true;
    }

    public static function logOut(): void
    {
        self::start();
        $_SESSION = [];
        session_regenerate_id(true);
        session_destroy();
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 3600, 'path' => $params['path'],
            'secure' => true, 'httponly' => true, 'samesite' => 'Strict',
        ]);
    }

    public static function csrf(string $user): string
    {
        self::enabled();
        self::start();
        if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function validCsrf(string $user, string $candidate): bool
    {
        self::enabled();
        self::start();
        return $candidate !== '' && isset($_SESSION['csrf'])
            && is_string($_SESSION['csrf'])
            && hash_equals($_SESSION['csrf'], $candidate);
    }
}
