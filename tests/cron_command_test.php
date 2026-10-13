<?php
declare(strict_types=1);
require __DIR__ . '/../src/Config.php';
require __DIR__ . '/../src/CronCommand.php';

use PoolProFlorida\CronCommand;

if (CronCommand::SCHEDULE !== '*/30 * * * *') {
    throw new RuntimeException('Expected the conservative 30-minute cron schedule');
}

$project = "/home/test user's site/public_html/pool-founder-contact-sync; echo UNSAFE";
$php = '/opt/cpanel/ea-php83/root/usr/bin/php';
$expected = escapeshellarg($php) . ' '
    . escapeshellarg($project . '/scripts/sync.php') . ' >/dev/null';
$actual = CronCommand::build($project, $php);
if ($actual !== $expected) {
    throw new RuntimeException('Cron command must safely shell-quote both executable and script');
}
if (!str_contains($actual, '/scripts/sync.php') || str_contains($actual, 'https://')) {
    throw new RuntimeException('Cron must invoke local CLI only, never a public HTTP URL');
}
if (str_contains($actual, 'POOL_FOUNDER_API_KEY') || str_contains($actual, 'CARDDAV_PASSWORD')) {
    throw new RuntimeException('Cron setup must not include credentials');
}

$old = getenv('CRON_PHP_BINARY');
putenv('CRON_PHP_BINARY=/opt/vendor/cli/php');
try {
    if (CronCommand::phpBinary() !== '/opt/vendor/cli/php') {
        throw new RuntimeException('Explicit PHP CLI override was ignored');
    }
} finally {
    if ($old === false) putenv('CRON_PHP_BINARY');
    else putenv('CRON_PHP_BINARY=' . $old);
}

echo "Cron command quoting and schedule tests passed.\n";
