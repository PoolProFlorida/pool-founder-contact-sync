<?php
declare(strict_types=1);
namespace PoolProFlorida;

/**
 * CardDAV uses independent, revocable client secrets by default.
 * Existing installations may explicitly opt into temporary legacy support
 * during migration; new installations never enable it implicitly.
 * All clients retain the same stable DAV principal and address-book paths.
 */
final class CardDavAuth
{
    public static function guard(): string
    {
        if (!Http::secure()) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            exit("HTTPS is required.\n");
        }
        $expectedUser = Config::get('CARDDAV_USERNAME');
        if ($expectedUser === '') {
            http_response_code(503);
            exit("CardDAV username is not configured.\n");
        }

        $user = (string)($_SERVER['PHP_AUTH_USER'] ?? '');
        $secret = (string)($_SERVER['PHP_AUTH_PW'] ?? '');
        if ($user === '') {
            $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
            if (stripos($header, 'Basic ') === 0) {
                $decoded = base64_decode(substr($header, 6), true);
                if (is_string($decoded) && str_contains($decoded, ':')) {
                    [$user, $secret] = explode(':', $decoded, 2);
                }
            }
        }
        if (!hash_equals($expectedUser, $user) || $secret === '') {
            self::challenge();
        }

        $legacyAllowed = Config::get('CARDDAV_LEGACY_ENABLED', '0') === '1';
        $legacy = Config::get('CARDDAV_PASSWORD');
        $legacyValid = $legacyAllowed && strlen($legacy) >= 16
            && $legacy !== 'change-me' && !str_starts_with($legacy, 'replace-with-')
            && hash_equals($legacy, $secret);
        try {
            $authorized = $legacyValid || AppPasswords::verify($secret);
        } catch (\Throwable $e) {
            error_log('CardDAV app-password lookup failed: ' . get_class($e));
            http_response_code(503);
            exit("CardDAV credentials temporarily unavailable.\n");
        }
        if (!$authorized) self::challenge();

        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('X-Frame-Options: DENY');
        return $expectedUser;
    }

    private static function challenge(): never
    {
        http_response_code(401);
        header('WWW-Authenticate: Basic realm="Pool Founder Contact Sync", charset="UTF-8"');
        header('Cache-Control: private, no-store');
        header('Content-Type: text/plain; charset=utf-8');
        exit("Authentication required.\n");
    }
}
