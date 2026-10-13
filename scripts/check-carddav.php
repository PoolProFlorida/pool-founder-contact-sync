<?php
declare(strict_types=1);

/**
 * Read-only CardDAV protocol check for a deployed installation.
 *
 * Usage: php scripts/check-carddav.php https://example.com/contact-sync/carddav/
 *
 * Uses local .env CardDAV credentials. Never prints contact details,
 * authentication secrets, or response bodies. Makes no write requests.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require __DIR__ . '/../src/Config.php';

use PoolProFlorida\Config;

if (!function_exists('curl_init') || !class_exists(DOMDocument::class)) {
    fwrite(STDERR, "PHP cURL and DOM/XML extensions are required.\n");
    exit(2);
}

$endpoint = $argv[1] ?? '';
if (!is_string($endpoint) || $endpoint === '') {
    fwrite(STDERR, "Usage: php scripts/check-carddav.php https://example.com/contact-sync/carddav/\n");
    exit(2);
}
$parts = parse_url($endpoint);
if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
    || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
    || isset($parts['query']) || isset($parts['fragment'])) {
    fwrite(STDERR, "Supply an HTTPS CardDAV endpoint without embedded credentials or query parameters.\n");
    exit(2);
}
$endpoint = rtrim($endpoint, '/') . '/';
$origin = 'https://' . $parts['host']
    . (isset($parts['port']) ? ':' . (int)$parts['port'] : '');
$rootPath = (string)parse_url($endpoint, PHP_URL_PATH);
if (!str_ends_with($rootPath, '/')) {
    fwrite(STDERR, "CardDAV endpoint must end with /.\n");
    exit(2);
}
$user = Config::get('CARDDAV_USERNAME');
$password = Config::get('CARDDAV_PASSWORD');
if ($user === '' || strlen($password) < 16 || str_starts_with($password, 'replace-with-')) {
    fwrite(STDERR, "Configure CardDAV credentials in the local .env file.\n");
    exit(2);
}

/**
 * Only allow returned hrefs on the same origin, under the provided CardDAV path.
 */
function safeDavUrl(string $href, string $origin, string $rootPath): string
{
    $parts = parse_url($href);
    if ($parts === false) throw new RuntimeException('Invalid DAV href');
    $path = (string)($parts['path'] ?? '');
    if ($path === '' || !str_starts_with($path, $rootPath)
        || str_contains($path, '/../') || str_contains($path, '/./')
        || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])) {
        throw new RuntimeException('DAV href does not belong to the expected address book');
    }
    if (isset($parts['scheme']) || isset($parts['host'])) {
        if (strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
            throw new RuntimeException('DAV href has an unexpected scheme');
        }
        $returnedOrigin = 'https://' . ($parts['host'] ?? '')
            . (isset($parts['port']) ? ':' . (int)$parts['port'] : '');
        if (strcasecmp($returnedOrigin, $origin) !== 0) {
            throw new RuntimeException('DAV href points to a different server');
        }
    }
    return $origin . $path;
}

function requestDav(string $url, string $method, ?string $body, ?array $auth): array
{
    $headers = [];
    $curl = curl_init($url);
    if ($curl === false) throw new RuntimeException('Could not start HTTP request');
    try {
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Accept: application/xml, text/vcard',
                'Content-Type: application/xml; charset=utf-8',
                'Depth: ' . ($method === 'REPORT' ? '1' : '0'),
            ],
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
                if (preg_match('/^([a-z0-9-]+):\s*(.*)\r?\n$/i', $line, $match)) {
                    $headers[strtolower($match[1])] = trim($match[2]);
                }
                return strlen($line);
            },
        ]);
        if ($auth !== null) {
            curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($curl, CURLOPT_USERPWD, $auth[0] . ':' . $auth[1]);
        }
        if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        $content = curl_exec($curl);
        if ($content === false) throw new RuntimeException('CardDAV HTTP request failed');
        return [(int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE), (string)$content, $headers];
    } finally {
        curl_close($curl);
    }
}

function davXml(string $xml): DOMDocument
{
    if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
        throw new RuntimeException('DAV XML contains forbidden entities');
    }
    $doc = new DOMDocument();
    if (!$doc->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
        throw new RuntimeException('CardDAV returned invalid XML');
    }
    return $doc;
}

function davHref(DOMDocument $xml, string $propertyName): string
{
    $xpath = new DOMXPath($xml);
    $xpath->registerNamespace('D', 'DAV:');
    $prefix = $propertyName === 'addressbook-home-set' ? 'C' : 'D';
    $xpath->registerNamespace('C', 'urn:ietf:params:xml:ns:carddav');
    $list = $xpath->query('//' . $prefix . ':' . $propertyName . '/D:href');
    if ($list === false || $list->length === 0) throw new RuntimeException('Missing DAV ' . $propertyName);
    return trim((string)$list->item(0)?->textContent);
}

$auth = [$user, $password];
$propfind = '<?xml version="1.0" encoding="utf-8"?>'
    . '<D:propfind xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:carddav">'
    . '<D:prop><D:current-user-principal/><C:addressbook-home-set/></D:prop></D:propfind>';

try {
    [$status] = requestDav($endpoint, 'PROPFIND', $propfind, null);
    if ($status !== 401) throw new RuntimeException('Unauthenticated access was not rejected (expected 401)');
    echo "Authentication challenge: PASS\n";

    [$status, $xml] = requestDav($endpoint, 'PROPFIND', $propfind, $auth);
    if ($status !== 207) throw new RuntimeException('CardDAV root PROPFIND returned HTTP ' . $status);
    $principalHref = davHref(davXml($xml), 'current-user-principal');
    $principalUrl = safeDavUrl($principalHref, $origin, $rootPath);
    echo "Principal discovery: PASS\n";

    [$status, $xml] = requestDav($principalUrl, 'PROPFIND', $propfind, $auth);
    if ($status !== 207) throw new RuntimeException('Principal PROPFIND returned HTTP ' . $status);
    $homeHref = davHref(davXml($xml), 'addressbook-home-set');
    $homeUrl = safeDavUrl($homeHref, $origin, $rootPath);
    echo "Address-book home discovery: PASS\n";

    [$status, $xml] = requestDav($homeUrl, 'PROPFIND', $propfind, $auth);
    if ($status !== 207) throw new RuntimeException('Home PROPFIND returned HTTP ' . $status);
    $bookUrl = rtrim($homeUrl, '/') . '/contacts/';
    // Probe the advertised address-book collection itself.
    [$status, $xml] = requestDav($bookUrl, 'PROPFIND', $propfind, $auth);
    if ($status !== 207 || !str_contains($xml, 'addressbook')) {
        throw new RuntimeException('Address-book PROPFIND failed');
    }
    echo "Address-book discovery: PASS\n";

    $report = '<?xml version="1.0" encoding="utf-8"?>'
        . '<C:addressbook-query xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:carddav">'
        . '<D:prop><D:getetag/><C:address-data/></D:prop></C:addressbook-query>';
    [$status, $reportXml] = requestDav($bookUrl, 'REPORT', $report, $auth);
    if ($status !== 207) throw new RuntimeException('CardDAV address-book REPORT returned HTTP ' . $status);
    $document = davXml($reportXml);
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('D', 'DAV:');
    $xpath->registerNamespace('C', 'urn:ietf:params:xml:ns:carddav');
    $cards = $xpath->query('//D:response[D:propstat/D:prop/C:address-data]');
    if ($cards === false) throw new RuntimeException('Unexpected CardDAV multistatus response');
    $count = $cards->length;
    echo 'Address-book REPORT: PASS (' . $count . " cards)\n";

    if ($count > 0) {
        $href = $xpath->query('./D:href', $cards->item(0))?->item(0)?->textContent;
        if (!is_string($href) || trim($href) === '') throw new RuntimeException('CardDAV report omitted contact href');
        $cardUrl = safeDavUrl(trim($href), $origin, $rootPath);
        [$status, $vcard, $headers] = requestDav($cardUrl, 'GET', null, $auth);
        if ($status !== 200 || !str_contains($vcard, 'BEGIN:VCARD')
            || !str_contains($vcard, 'FN:') || !str_contains($vcard, 'END:VCARD')
            || !isset($headers['etag'])) {
            throw new RuntimeException('Individual vCard GET failed validation');
        }
        echo "Individual vCard GET and ETag: PASS\n";
    }
    echo "All non-destructive CardDAV checks passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "CardDAV check failed: " . $e->getMessage() . "\n");
    exit(1);
}
