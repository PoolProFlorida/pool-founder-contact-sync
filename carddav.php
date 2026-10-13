<?php
declare(strict_types=1);
require __DIR__ . '/src/Config.php';
require __DIR__ . '/src/Http.php';
require __DIR__ . '/src/Snapshot.php';
require __DIR__ . '/src/Dav.php';

use PoolProFlorida\Http;
use PoolProFlorida\Snapshot;
use PoolProFlorida\Dav;

$user = Http::guard();
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('DAV: 1, addressbook');
header('MS-Author-Via: DAV');
try {
    $snapshot = Snapshot::load();
    (new Dav($user, $snapshot))->serve();
} catch (Throwable $e) {
    error_log('Pool Founder Contact Sync CardDAV request failed: ' . get_class($e));
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "CardDAV service error.\n";
}
