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

    public static function base(): string
    {
        $path = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
        return $path === '/' || $path === '.' ? '' : rtrim($path, '/');
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
