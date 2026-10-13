<?php
// CLI-only smoke test; does not print customer information.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
require __DIR__ . '/../src/PoolFounderClient.php';
require __DIR__ . '/../src/VCard.php';
$key = getenv('POOL_FOUNDER_API_KEY');
if (!$key) { fwrite(STDERR, "Missing POOL_FOUNDER_API_KEY\n"); exit(1); }
try {
    $client = new \PoolProFlorida\PoolFounderClient($key);
    $customers = $client->customers();
    foreach ($customers as $customer) {
        \PoolProFlorida\VCard::fromCustomer($customer);
    }
    echo 'Validated ' . count($customers) . " customer vCards.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "Preview failed.\n");
    exit(1);
}
