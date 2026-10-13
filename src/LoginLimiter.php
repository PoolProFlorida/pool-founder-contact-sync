<?php
declare(strict_types=1);
namespace PoolProFlorida;

/**
 * Shared, server-side login throttle. Client IP addresses are never stored.
 * Five failed attempts per origin in a rolling fifteen-minute window.
 */
final class LoginLimiter
{
    private const WINDOW = 900;
    private const LIMIT = 5;

    public static function blocked(): bool
    {
        return self::update('check');
    }

    public static function failed(): void
    {
        self::update('fail');
    }

    public static function clear(): void
    {
        self::update('clear');
    }

    private static function update(string $operation): bool
    {
        $file = PHP_SAPI === 'cli' && defined('PFCS_LOGIN_LIMITER_STORE')
            ? (string)constant('PFCS_LOGIN_LIMITER_STORE')
            : dirname(__DIR__) . '/storage/login-attempts.json';
        $key = hash_hmac('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), Config::get('DASHBOARD_PASSWORD_HASH'));
        $handle = fopen($file, 'c+');
        if ($handle === false) throw new \RuntimeException('Unable to open private login protection store.');
        @chmod($file, 0600);
        try {
            if (!flock($handle, LOCK_EX)) throw new \RuntimeException('Login protection lock failed.');
            rewind($handle);
            $raw = stream_get_contents($handle);
            $entries = ($raw === '' || $raw === false) ? [] : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($entries)) throw new \RuntimeException('Invalid login protection store.');
            $now = time();
            foreach ($entries as $identity => $attempts) {
                if (!is_array($attempts)) throw new \RuntimeException('Invalid login attempt data.');
                $entries[$identity] = array_values(array_filter(
                    $attempts, static fn($when) => is_int($when) && $when > $now - self::WINDOW
                ));
                if ($entries[$identity] === []) unset($entries[$identity]);
            }
            $attempts = $entries[$key] ?? [];
            $blocked = count($attempts) >= self::LIMIT;
            if ($operation === 'fail' && !$blocked) {
                $attempts[] = $now;
                $entries[$key] = $attempts;
            } elseif ($operation === 'clear') {
                unset($entries[$key]);
            }
            // Bound the storage even when attacked by many unique addresses.
            if (count($entries) > 500) {
                uasort($entries, static fn(array $a, array $b): int => max($b) <=> max($a));
                $entries = array_slice($entries, 0, 500, true);
            }
            rewind($handle);
            if (!ftruncate($handle, 0) || fwrite($handle, json_encode($entries, JSON_THROW_ON_ERROR)) === false) {
                throw new \RuntimeException('Login protection store could not be updated.');
            }
            fflush($handle);
            return $blocked;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
