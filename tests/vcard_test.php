<?php
declare(strict_types=1);
require __DIR__ . '/../src/VCard.php';

use PoolProFlorida\VCard;

$fixture = [
    'id' => 'cust-123',
    'name' => 'Jane Smith',
    'firstName' => 'Jane',
    'lastName' => 'Smith',
    'email' => 'jane@example.com',
    'phone' => '+15551234567',
    'serviceDays' => ['Tuesday'],
    'serviceLocations' => [
        ['address' => [
            'street' => '123 Pool Lane', 'city' => 'Port St. Lucie',
            'state' => 'FL', 'zipCode' => '34987', 'country' => 'United States'
        ]]
    ]
];

$card = VCard::fromCustomer($fixture);
$expected = [
    'BEGIN:VCARD', 'VERSION:3.0', 'UID:poolfounder-customer-cust-123',
    'FN:Jane Smith', 'N:Smith;Jane;;;', 'EMAIL:jane@example.com',
    'TEL:+15551234567',
    'ADR;TYPE=WORK:;;123 Pool Lane;Port St. Lucie;FL;34987;United States',
    'NOTE:Service days: Tuesday', 'END:VCARD'
];
foreach ($expected as $line) {
    if (!in_array($line, explode("\r\n", trim($card)), true)) {
        fwrite(STDERR, "Missing expected vCard line: $line\n");
        exit(1);
    }
}
if (!str_ends_with($card, "\r\n")) {
    fwrite(STDERR, "vCard must end with CRLF\n");
    exit(1);
}
echo "vCard fixture test passed.\n";
