<?php
declare(strict_types=1);
require __DIR__ . '/../src/Http.php';
require __DIR__ . '/../src/VCard.php';
require __DIR__ . '/../src/Dav.php';

use PoolProFlorida\Dav;
use PoolProFlorida\VCard;

$customer = ['id' => 'example-42', 'name' => 'Jane Example', 'phone' => '555-0100'];
$resource = hash('sha256', 'customer:example-42') . '.vcf';
$card = VCard::fromCustomer($customer);
$snapshot = [
    'updated_at' => '2026-10-12T16:00:00+00:00',
    'contacts' => [$resource => ['name' => 'Jane Example', 'vcard' => $card]]
];
$_SERVER['SCRIPT_NAME'] = '/pool-founder-contact-sync/carddav.php';
$root = '/pool-founder-contact-sync/carddav/';
$book = $root . 'addressbooks/testuser/contacts/';

function probe(string $path, string $method, array $snapshot): array
{
    $_SERVER['REQUEST_URI'] = $path;
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['HTTP_DEPTH'] = '1';
    http_response_code(200);
    ob_start();
    (new Dav('testuser', $snapshot))->serve();
    return [http_response_code(), (string)ob_get_clean()];
}

[$status, $xml] = probe($root, 'PROPFIND', $snapshot);
if ($status !== 207 || !str_contains($xml, 'current-user-principal')) {
    throw new RuntimeException('Root principal discovery failed');
}
[$status, $xml] = probe($root . 'addressbooks/testuser/', 'PROPFIND', $snapshot);
if ($status !== 207 || !str_contains($xml, 'contacts/')) {
    throw new RuntimeException('Address book discovery failed');
}
[$status, $xml] = probe($book, 'PROPFIND', $snapshot);
if ($status !== 207 || !str_contains($xml, $resource)) {
    throw new RuntimeException('Contact listing failed');
}
[$status, $receivedCard] = probe($book . $resource, 'GET', $snapshot);
if ($status !== 200 || $receivedCard !== $card) {
    throw new RuntimeException('Contact GET failed');
}
[$status] = probe($book . $resource, 'PUT', $snapshot);
if ($status !== 403) throw new RuntimeException('Write method was not forbidden');
[$status] = probe($book . 'missing.vcf', 'GET', $snapshot);
if ($status !== 404) throw new RuntimeException('Missing contact should return 404');
echo "CardDAV offline smoke test passed.\n";
