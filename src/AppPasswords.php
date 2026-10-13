<?php
declare(strict_types=1);
namespace PoolProFlorida;

/**
 * Per-client read-only CardDAV secrets. Only password hashes are persisted.
 * The one-time generated plaintext is returned only to the creating admin.
 */
final class AppPasswords
{
    private const MAX_ACTIVE = 20;

    public static function path(): string
    {
        // Offline unit tests may provide a temporary store. Web requests never can.
        if (PHP_SAPI === 'cli' && defined('PFCS_APP_PASSWORD_STORE')) {
            return (string)constant('PFCS_APP_PASSWORD_STORE');
        }
        return dirname(__DIR__) . '/storage/app-passwords.json';
    }

    /** @return array<int,array{id:string,label:string,created_at:string}> */
    public static function all(): array
    {
        return self::locked(static function (array $items): array {
            return array_map(
                static fn(array $app): array => [
                    'id' => $app['id'], 'label' => $app['label'], 'created_at' => $app['created_at']
                ],
                array_values(array_filter($items, static fn(array $app): bool => $app['revoked_at'] === null))
            );
        }, false);
    }

    /** @return array{id:string,label:string,password:string} */
    public static function create(string $label): array
    {
        $label = trim($label);
        if (!preg_match('/^[\pL\pN _.-]{2,60}$/uD', $label)) {
            throw new \InvalidArgumentException('Use 2–60 letters, numbers, spaces, dots, hyphens, or underscores.');
        }
        return self::locked(static function (array &$items) use ($label): array {
            $active = count(array_filter($items, static fn(array $app): bool => $app['revoked_at'] === null));
            if ($active >= self::MAX_ACTIVE) {
                throw new \RuntimeException('Maximum active app passwords reached; revoke an unused entry.');
            }
            $password = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $id = bin2hex(random_bytes(12));
            $items[] = [
                'id' => $id,
                'label' => $label,
                'hash' => password_hash($password, PASSWORD_DEFAULT),
                'created_at' => gmdate('c'),
                'revoked_at' => null,
            ];
            return ['id' => $id, 'label' => $label, 'password' => $password];
        }, true);
    }

    public static function revoke(string $id): bool
    {
        if (!preg_match('/^[0-9a-f]{24}$/D', $id)) return false;
        return self::locked(static function (array &$items) use ($id): bool {
            foreach ($items as &$app) {
                if (hash_equals($app['id'], $id) && $app['revoked_at'] === null) {
                    $app['revoked_at'] = gmdate('c');
                    return true;
                }
            }
            return false;
        }, true);
    }

    public static function verify(string $password): bool
    {
        if (strlen($password) < 32 || strlen($password) > 128) return false;
        return self::locked(static function (array $items) use ($password): bool {
            foreach ($items as $app) {
                if ($app['revoked_at'] === null && password_verify($password, $app['hash'])) {
                    return true;
                }
            }
            return false;
        }, false);
    }

    /**
     * Lock separately from the atomically replaced JSON file, so concurrent
     * creates/revokes cannot overwrite each other or read partial data.
     */
    private static function locked(callable $fn, bool $write): mixed
    {
        $dir = dirname(self::path());
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new \RuntimeException('Private storage is not writable.');
        }
        $lockPath = $dir . '/app-passwords.lock';
        $lock = fopen($lockPath, 'c');
        if ($lock === false) throw new \RuntimeException('Cannot lock private app-password store.');
        @chmod($lockPath, 0600);
        try {
            if (!flock($lock, LOCK_EX)) throw new \RuntimeException('App-password store lock failed.');
            if (!is_file(self::path())) {
                $items = [];
            } else {
                $raw = file_get_contents(self::path());
                if (!is_string($raw)) throw new \RuntimeException('Cannot read app-password store.');
                $items = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($items) || !array_is_list($items)) {
                    throw new \RuntimeException('App-password store is invalid.');
                }
                foreach ($items as $item) {
                    if (!is_array($item) || !is_string($item['id'] ?? null)
                        || !is_string($item['label'] ?? null) || !is_string($item['hash'] ?? null)
                        || !is_string($item['created_at'] ?? null)
                        || !(($item['revoked_at'] ?? null) === null || is_string($item['revoked_at']))) {
                        throw new \RuntimeException('App-password store contains invalid entries.');
                    }
                }
            }
            $result = $fn($items);
            if ($write) {
                $data = json_encode($items, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
                $temp = tempnam($dir, '.apps-');
                if ($temp === false) throw new \RuntimeException('Cannot create temporary password store.');
                try {
                    if (file_put_contents($temp, $data, LOCK_EX) === false) {
                        throw new \RuntimeException('Cannot write app-password store.');
                    }
                    @chmod($temp, 0600);
                    if (!rename($temp, self::path())) {
                        throw new \RuntimeException('Cannot replace app-password store.');
                    }
                } finally {
                    if (is_file($temp)) @unlink($temp);
                }
            }
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
