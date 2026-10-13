<?php
declare(strict_types=1);
namespace PoolProFlorida;

final class Config
{
    private static ?array $vars = null;

    public static function get(string $key, string $default = ''): string
    {
        $env = getenv($key);
        if ($env !== false) {
            return (string)$env;
        }
        if (self::$vars === null) {
            self::$vars = [];
            $filename = dirname(__DIR__) . '/.env';
            if (is_file($filename) && is_readable($filename)) {
                foreach (file($filename, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#')) continue;
                    if (!preg_match('/^([A-Z][A-Z0-9_]*)\s*=\s*(.*)$/', $line, $m)) continue;
                    $value = trim($m[2]);
                    if (strlen($value) >= 2 && $value[0] === $value[strlen($value)-1] && ($value[0] === '"' || $value[0] === "'")) {
                        $value = substr($value, 1, -1);
                    }
                    self::$vars[$m[1]] = $value;
                }
            }
        }
        return self::$vars[$key] ?? $default;
    }
}
