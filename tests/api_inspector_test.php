<?php
declare(strict_types=1);

require __DIR__ . '/../src/PoolFounderClient.php';
require __DIR__ . '/../src/ApiInspector.php';

use PoolProFlorida\ApiInspector;
use PoolProFlorida\PoolFounderClient;

$payload = [
    'data' => [
        [
            'id' => 'private-id-1',
            'name' => 'Private Customer',
            'phone' => '+15550000000',
            'serviceLocations' => [
                ['address' => ['street' => 'Confidential Street', 'city' => 'Private City', 'zipCode' => '12345']]
            ],
            'serviceDays' => ['Tuesday'],
            'active' => true,
            'optional' => null,
            'dynamic-map' => ['someone@example.com' => 'private item'],
        ]
    ],
    'nextCursor' => 'private-pagination-token'
];

$transport = static function (string $url) use ($payload): array {
    $query = [];
    parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
    if (($query['limit'] ?? null) !== '3') {
        throw new RuntimeException('Inspector must request only 3 customers');
    }
    return [200, json_encode($payload, JSON_THROW_ON_ERROR)];
};

$client = new PoolFounderClient('fake-test-key', 'https://example.invalid', $transport);
$response = $client->customerSample();
$masked = json_encode(ApiInspector::redact($response), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
if (!is_string($masked)) throw new RuntimeException('Missing masked JSON');
foreach (['private-id-1', 'Private Customer', 'Confidential Street', 'Private City', '12345',
    '+15550000000', 'someone@example.com', 'private-pagination-token'] as $secret) {
    if (str_contains($masked, $secret)) {
        throw new RuntimeException('Sensitive value was not masked');
    }
}
foreach (['"data"', '"id"', '"serviceLocations"', '"address"', '"street"', '"zipCode"',
    '"serviceDays"', '"nextCursor"', '"active"', '"optional"'] as $field) {
    if (!str_contains($masked, $field)) {
        throw new RuntimeException('Expected response field or structure missing: ' . $field);
    }
}
if (!str_contains($masked, '[redacted string]') || !str_contains($masked, '[redacted boolean]')) {
    throw new RuntimeException('Expected masked values were missing');
}
if (str_contains($masked, '"someone@example.com"')) {
    throw new RuntimeException('Unsafe dynamic object key leaked');
}
if (!str_contains($masked, '"redacted_key_1"')) {
    throw new RuntimeException('Sensitive dynamic property key was not masked');
}

$invalid = new PoolFounderClient(
    'fake-key',
    'https://example.invalid',
    static fn(string $url): array => [200, '{"data":[{}]}']
);
try {
    $invalid->customerSample();
    throw new RuntimeException('Invalid API response did not fail');
} catch (RuntimeException $e) {
    if ($e->getMessage() === 'Invalid API response did not fail') throw $e;
}
echo "Masked API diagnostic tests passed.\n";
