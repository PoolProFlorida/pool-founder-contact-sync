<?php
declare(strict_types=1);
namespace PoolProFlorida;

/**
 * Builds a private, local CLI cron command for a deployed checkout.
 * Never embeds API keys, passwords, HTTP endpoints, or customer data.
 */
final class CronCommand
{
    public const SCHEDULE = '*/30 * * * *';

    public static function phpBinary(): string
    {
        // Hosting can choose a different CLI PHP from the web runtime.
        // A server owner may override it in their private .env if needed.
        $configured = trim(Config::get('CRON_PHP_BINARY'));
        if ($configured !== '') return $configured;

        $candidates = [
            '/opt/cpanel/ea-php' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION . '/root/usr/bin/php',
            '/usr/local/bin/php',
            '/usr/bin/php',
        ];
        foreach ($candidates as $path) {
            if (is_file($path) && is_executable($path)) return $path;
        }

        // Common cPanel command on hosts where web PHP cannot inspect CLI binaries.
        return '/usr/local/bin/php';
    }

    public static function build(string $installationDirectory, ?string $phpBinary = null): string
    {
        $script = rtrim($installationDirectory, '/\\') . '/scripts/sync.php';
        $binary = $phpBinary ?? self::phpBinary();
        // Quote both paths to avoid shell interpretation of spaces/metacharacters.
        // Discard successful output; failed runs still write to stderr for cron email.
        return escapeshellarg($binary) . ' ' . escapeshellarg($script) . ' >/dev/null';
    }
}
