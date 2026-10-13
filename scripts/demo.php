<?php
declare(strict_types=1);
// Offline demo. Uses ONLY fake sample data. Refuses to overwrite any saved contacts.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/../src/VCard.php';

$folder = dirname(__DIR__) . '/storage';
$file = $folder . '/contacts.json';
if (file_exists($file)) {
    fwrite(STDERR, "A snapshot already exists. Refusing to replace it with demo data.\n");
    exit(1);
}
if (!is_dir($folder) && !mkdir($folder, 0700, true)) {
    fwrite(STDERR, "Cannot create storage.\n");
    exit(1);
}
$customer = [
    'id' => 'demo-only-001',
    'name' => 'Alex Example',
    'firstName' => 'Alex',
    'lastName' => 'Example',
    'phone' => '555-0100',
    'email' => 'alex@example.invalid',
    'serviceDays' => ['Wednesday'],
    'serviceLocations' => [
        ['address' => ['street' => '123 Example Court', 'city' => 'Example City', 'state' => 'FL', 'zipCode' => '00000']]
    ],
];
$resource = hash('sha256', 'customer:' . $customer['id']) . '.vcf';
$snapshot = [
    'updated_at' => gmdate(DATE_ATOM),
    'contacts' => [
        $resource => [
            'resource' => $resource,
            'name' => $customer['name'],
            'phone' => $customer['phone'],
            'email' => $customer['email'],
            'addresses' => ['123 Example Court, Example City, FL, 00000'],
            'service_days' => $customer['serviceDays'],
            'vcard' => \PoolProFlorida\VCard::fromCustomer($customer)
        ]
    ]
];
$json = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
if (file_put_contents($file, $json, LOCK_EX) !== strlen($json)) {
    fwrite(STDERR, "Could not write complete demo snapshot.\n");
    exit(1);
}
chmod($file, 0600);
echo "Created one FAKE demo contact. You can now test the protected dashboard and CardDAV.\n";
echo "Remove storage/contacts.json before production, or refresh it from Pool Founder.\n";
