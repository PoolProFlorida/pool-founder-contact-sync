<?php
declare(strict_types=1);
namespace PoolProFlorida;

final class PoolFounderClient {
    public function __construct(private string $apiKey, private string $baseUrl = 'https://api.poolfounder.com') {}
    public function customers(): array { return $this->paginate('/api/v1/customers'); }
    public function paginate(string $path): array {
        $records = []; $cursor = null; $seen = [];
        do {
            $params = ['limit' => 100];
            if ($cursor !== null) $params['cursor'] = $cursor;
            $url = rtrim($this->baseUrl, '/') . $path . '?' . http_build_query($params);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->apiKey, 'Accept: application/json'],
                CURLOPT_FOLLOWLOCATION => false
            ]);
            $body = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            if ($body === false || $status !== 200) throw new \RuntimeException('Pool Founder request failed: HTTP ' . $status . ($error ? ' (' . $error . ')' : ''));
            $page = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            if (!isset($page['data']) || !is_array($page['data']) || !array_key_exists('nextCursor', $page)) throw new \RuntimeException('Unexpected API response; refusing partial sync');
            foreach ($page['data'] as $record) $records[] = $record;
            $cursor = $page['nextCursor'];
            if ($cursor !== null) {
                if (!is_string($cursor) || isset($seen[$cursor])) throw new \RuntimeException('Invalid or repeated cursor');
                $seen[$cursor] = true;
            }
        } while ($cursor !== null);
        return $records;
    }
}
