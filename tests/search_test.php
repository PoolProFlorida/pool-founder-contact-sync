<?php
declare(strict_types=1);

require __DIR__ . '/../src/ContactSearch.php';
use PoolProFlorida\ContactSearch;

$contacts = [
    'a.vcf' => [
        'name' => 'Alex Example',
        'phone' => '+1 (555) 0100',
        'email' => 'alex@example.invalid',
        'addresses' => ['123 Example Road'],
        'service_days' => ['Monday']
    ],
    'b.vcf' => [
        'name' => 'Casey Sample',
        'phone' => '',
        'email' => '',
        'addresses' => [],
        'service_days' => ['Thursday']
    ],
    'c.vcf' => [
        'name' => 'Jordan Sample',
        'phone' => '555-0102',
        'email' => 'jordan@example.invalid',
        'addresses' => [],
        'service_days' => ['Wednesday']
    ]
];

function assertCountEqual(int $expected, array $data, string $message): void
{
    if (count($data) !== $expected) throw new RuntimeException($message);
}

$stats = ContactSearch::stats($contacts);
if ($stats !== ['total' => 3, 'with_phone' => 2, 'with_address' => 1, 'with_email' => 2]) {
    throw new RuntimeException('Coverage summary is incorrect');
}
assertCountEqual(3, ContactSearch::filter($contacts), 'All contacts expected');
assertCountEqual(1, ContactSearch::filter($contacts, 'ALEX'), 'Case-insensitive name search failed');
assertCountEqual(1, ContactSearch::filter($contacts, '123 Example'), 'Address search failed');
assertCountEqual(1, ContactSearch::filter($contacts, 'Thursday'), 'Service day search failed');
assertCountEqual(1, ContactSearch::filter($contacts, '', 'missing-phone'), 'Missing phone filter failed');
assertCountEqual(2, ContactSearch::filter($contacts, '', 'missing-address'), 'Missing address filter failed');
assertCountEqual(1, ContactSearch::filter($contacts, '', 'missing-email'), 'Missing email filter failed');
assertCountEqual(1, ContactSearch::filter($contacts, '', 'with-address'), 'With address filter failed');

$page = ContactSearch::page($contacts, 2, 1);
if ($page['total'] !== 3 || $page['page'] !== 2 || $page['pages'] !== 3
    || array_keys($page['contacts']) !== ['b.vcf']) {
    throw new RuntimeException('Second page or resource identity is incorrect');
}
$overPage = ContactSearch::page($contacts, 999, 1);
if ($overPage['page'] !== 3 || array_keys($overPage['contacts']) !== ['c.vcf']) {
    throw new RuntimeException('Out-of-range page should clamp safely');
}
echo "Contact search, filtering and pagination tests passed.\n";
