<?php
declare(strict_types=1);
require __DIR__ . '/../src/PoolFounderClient.php';

use PoolProFlorida\PoolFounderClient;

function assertTrue(bool $valid, string $message): void
{
    if (!$valid) throw new RuntimeException($message);
}
function mustThrow(callable $action, string $message): void
{
    try { $action(); } catch (Throwable $e) { return; }
    throw new RuntimeException($message);
}

$urls = [];
$transport = static function (string $url) use (&$urls): array {
    $urls[] = $url;
    $query = [];
    parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
    assertTrue(($query['limit'] ?? null) === '100', 'Default page limit missing');
    if (!isset($query['cursor'])) {
        return [200, json_encode(['data' => [['id' => 'one']], 'nextCursor' => 'next-page'], JSON_THROW_ON_ERROR)];
    }
    assertTrue($query['cursor'] === 'next-page', 'Second-page cursor not applied');
    return [200, json_encode(['data' => [['id' => 'two']], 'nextCursor' => null], JSON_THROW_ON_ERROR)];
};
$client = new PoolFounderClient('fake-test-only', 'https://example.invalid', $transport);
$customers = $client->customers();
assertTrue(count($customers) === 2, 'Must retrieve both pages');
assertTrue($customers[0]['id'] === 'one' && $customers[1]['id'] === 'two', 'Page order should be preserved');
assertTrue(count($urls) === 2, 'Only two pages should be requested');

mustThrow(static function (): void {
    (new PoolFounderClient('test', 'https://example.invalid',
        static fn(string $url): array => [200, json_encode(['data' => [], 'nextCursor' => 'repeat'], JSON_THROW_ON_ERROR)]
    ))->customers();
}, 'Repeated cursor must abort');

mustThrow(static function (): void {
    (new PoolFounderClient('test', 'https://example.invalid',
        static fn(string $url): array => [200, '{"data":[{"id":"incomplete"}]}']
    ))->customers();
}, 'Missing nextCursor must abort');

mustThrow(static function (): void {
    (new PoolFounderClient('test', 'https://example.invalid',
        static fn(string $url): array => [401, '{"error":"unauthorized"}']
    ))->customers();
}, 'Authentication failure must abort');

mustThrow(static function (): void {
    (new PoolFounderClient('test', 'https://example.invalid',
        static fn(string $url): array => [200, '{"data":[1],"nextCursor":null}']
    ))->customers();
}, 'Malformed records must abort');

echo "API pagination and error-handling tests passed.\n";
