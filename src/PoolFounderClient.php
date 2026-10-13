<?php
declare(strict_types=1);
namespace PoolProFlorida;

final class PoolFounderClient
{
    /**
     * The optional transport is used by offline tests only. It must return
     * [HTTP status, response body] for the requested URL.
     */
    public function __construct(
        private string $apiKey,
        private string $baseUrl = 'https://api.poolfounder.com',
        private ?\Closure $transport = null
    ) {}

    public function customers(): array
    {
        return $this->paginate('/api/v1/customers');
    }

    /**
     * Fetch one small, current API page for the authenticated structure test.
     * The caller must redact ALL values before presenting the response.
     * This does not touch the saved customer snapshot.
     */
    public function customerSample(int $limit = 3): \stdClass
    {
        $limit = max(1, min(5, $limit));
        $url = rtrim($this->baseUrl, '/') . '/api/v1/customers?'
            . http_build_query(['limit' => $limit]);
        [$status, $body] = $this->request($url);
        if ($status !== 200 || !is_string($body)) {
            throw new \RuntimeException('Pool Founder request failed: HTTP ' . $status);
        }
        $response = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        if (!$response instanceof \stdClass || !isset($response->data) ||
            !is_array($response->data) || !property_exists($response, 'nextCursor')) {
            throw new \RuntimeException('Unexpected customer API response structure');
        }
        return $response;
    }

    public function paginate(string $path): array
    {
        $records = [];
        $cursor = null;
        $seen = [];
        do {
            $params = ['limit' => 100];
            if ($cursor !== null) $params['cursor'] = $cursor;
            $url = rtrim($this->baseUrl, '/') . $path . '?' . http_build_query($params);
            [$status, $body] = $this->request($url);
            if ($status !== 200 || !is_string($body)) {
                throw new \RuntimeException('Pool Founder request failed: HTTP ' . $status);
            }

            $page = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($page) || !isset($page['data']) || !is_array($page['data'])
                || !array_key_exists('nextCursor', $page)) {
                throw new \RuntimeException('Unexpected API response; refusing partial sync');
            }
            foreach ($page['data'] as $record) {
                if (!is_array($record)) throw new \RuntimeException('Invalid customer record');
                $records[] = $record;
            }
            $cursor = $page['nextCursor'];
            if ($cursor !== null) {
                if (!is_string($cursor) || $cursor === '' || isset($seen[$cursor])) {
                    throw new \RuntimeException('Invalid or repeated cursor');
                }
                $seen[$cursor] = true;
            }
        } while ($cursor !== null);
        return $records;
    }

    private function request(string $url): array
    {
        if ($this->transport !== null) {
            return ($this->transport)($url);
        }

        if (!function_exists('curl_init')) {
            throw new \RuntimeException('PHP cURL extension is required');
        }
        $ch = curl_init($url);
        if ($ch === false) throw new \RuntimeException('Could not initialize HTTP request');
        try {
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $this->apiKey,
                    'Accept: application/json',
                ],
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($body === false) throw new \RuntimeException('Pool Founder HTTP transport failed');
            return [$status, $body];
        } finally {
            curl_close($ch);
        }
    }
}
