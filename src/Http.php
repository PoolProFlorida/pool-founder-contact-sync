<?php
declare(strict_types=1);
namespace PoolProFlorida;

final class Http
{
    public static function secure(): bool
    {
        return (strtolower((string)($_SERVER['HTTPS'] ?? '')) === 'on')
            || ((string)($_SERVER['SERVER_PORT'] ?? '') === '443');
    }

    public static function guard(): string
    {
        if (!self::secure()) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            exit("HTTPS is required.\n");
        }

        $expectedUser = Config::get('CARDDAV_USERNAME');
        $expectedPass = Config::get('CARDDAV_PASSWORD');
        if ($expectedUser === '' || strlen($expectedPass) < 16 || $expectedPass === 'change-me') {
            http_response_code(503);
            header('Content-Type: text/plain; charset=utf-8');
            exit("Application not configured. Set CARDDAV_USERNAME and a strong CARDDAV_PASSWORD.\n");
        }

        $user = (string)($_SERVER['PHP_AUTH_USER'] ?? '');
        $pass = (string)($_SERVER['PHP_AUTH_PW'] ?? '');
        if ($user === '') {
            $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
            if (stripos($header, 'Basic ') === 0) {
                $decoded = base64_decode(substr($header, 6), true);
                if ($decoded !== false && str_contains($decoded, ':')) {
                    [$user, $pass] = explode(':', $decoded, 2);
                }
            }
        }

        if (!hash_equals($expectedUser, $user) || !hash_equals($expectedPass, $pass)) {
            http_response_code(401);
            header('WWW-Authenticate: Basic realm="Pool Founder Contact Sync", charset="UTF-8"');
            header('Content-Type: text/plain; charset=utf-8');
            exit("Authentication required.\n");
        }
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('X-Frame-Options: DENY');
        return $user;
    }

    public static function base(): string
    {
        $path = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
        return $path === '/' || $path === '.' ? '' : rtrim($path, '/');
    }

    public static function csrf(string $user): string
    {
        return hash_hmac('sha256', gmdate('Y-m-d') . "\n" . $user, Config::get('CARDDAV_PASSWORD'));
    }

    public static function validCsrf(string $user, string $candidate): bool
    {
        foreach ([0, -1] as $day) {
            $expected = hash_hmac('sha256', gmdate('Y-m-d', time() + 86400*$day) . "\n" . $user, Config::get('CARDDAV_PASSWORD'));
            if (hash_equals($expected, $candidate)) return true;
        }
        return false;
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
