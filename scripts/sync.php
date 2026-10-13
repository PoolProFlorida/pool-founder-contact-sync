<?php
declare(strict_types=1);
/**
 * Standalone CLI-only snapshot refresh.
 * Use manually or from a trusted cron job. Does not print customer data.
 * Exit 0 on success, nonzero on failure; failed fetches preserve existing snapshot.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require __DIR__ . '/../src/Config.php';
require __DIR__ . '/../src/PoolFounderClient.php';
require __DIR__ . '/../src/VCard.php';
require __DIR__ . '/../src/Snapshot.php';

use PoolProFlorida\Config;
use PoolProFlorida\PoolFounderClient;
use PoolProFlorida\Snapshot;

$key = Config::get('POOL_FOUNDER_API_KEY');
if ($key === '' || str_starts_with($key, 'replace-with-')) {
    fwrite(STDERR, "Pool Founder API key is not configured.\n");
    exit(2);
}

try {
    $result = Snapshot::refresh(new PoolFounderClient($key));
    echo 'Customer snapshot refreshed successfully: '
        . count($result['contacts']) . " contacts.\n";
} catch (Throwable $error) {
    error_log('Pool Founder Contact Sync CLI refresh failed: ' . get_class($error));
    fwrite(STDERR, "Refresh failed. Previous snapshot retained.\n");
    exit(1);
}
