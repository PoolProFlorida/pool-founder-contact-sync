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
    'URL;TYPE=WORK:https://www.poolfounder.com/dashboard/customers/cust-123',
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
// Long Unicode names must fold without splitting UTF-8 characters.
$longName = str_repeat('Zoë', 40);
$unicodeCard = VCard::fromCustomer([
    'id' => 'unicode-example',
    'name' => $longName,
    'serviceAddress' => '123 Example Avenue, Example City, FL 00000'
]);
foreach (explode("\r\n", trim($unicodeCard)) as $line) {
    if (strlen($line) > 75) throw new RuntimeException('vCard line exceeds 75 octets');
}
$unfolded = str_replace("\r\n ", '', $unicodeCard);
if (!str_contains($unfolded, 'FN:' . $longName)) {
    throw new RuntimeException('Unicode name was corrupted while folding');
}
if (!str_contains($unfolded, 'ADR;TYPE=WORK:;;123 Example Avenue')) {
    throw new RuntimeException('String service address was omitted');
}

$unsafeId = 'customer/a?tab=private#section';
$encodedCard = VCard::fromCustomer(['id' => $unsafeId, 'name' => 'Safe URL Fixture']);
$unfoldedUrlCard = str_replace("\r\n ", '', $encodedCard);
$expectedUrl = 'URL;TYPE=WORK:https://www.poolfounder.com/dashboard/customers/' . rawurlencode($unsafeId);
if (!str_contains($unfoldedUrlCard, $expectedUrl . "\r\n")) {
    throw new RuntimeException('The customer URL must encode the ID as one path component');
}
if (substr_count($unfoldedUrlCard, 'URL;TYPE=WORK:') !== 1) {
    throw new RuntimeException('Exactly one website field should be included');
}

echo "vCard fixture and UTF-8 folding tests passed.\n";
