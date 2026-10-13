<?php
declare(strict_types=1);
namespace PoolProFlorida;

/**
 * Small, read-only CardDAV responder for one private address book.
 * Supports principal discovery, PROPFIND, addressbook-query, multiget, GET, and HEAD.
 * Does not advertise sync-collection or write capabilities.
 */
final class Dav
{
    private string $base;
    private string $root;
    private string $principal;
    private string $home;
    private string $book;
    private array $contacts;

    public function __construct(private string $user, private array $snapshot)
    {
        $this->base = Http::base();
        $this->root = $this->base . '/carddav/';
        $safeUser = rawurlencode($user);
        $this->principal = $this->root . 'principals/' . $safeUser . '/';
        $this->home = $this->root . 'addressbooks/' . $safeUser . '/';
        $this->book = $this->home . 'contacts/';
        $this->contacts = $snapshot['contacts'] ?? [];
    }

    public function serve(): void
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if (!is_string($path) || $path === '') $path = $this->root;
        if ($path === rtrim($this->root, '/')) {
            header('Location: ' . $this->root, true, 308);
            return;
        }
        if ($method === 'OPTIONS') {
            header('Allow: OPTIONS, PROPFIND, REPORT, GET, HEAD');
            http_response_code(204);
            return;
        }
        if (in_array($method, ['PUT','PATCH','POST','DELETE','MKCOL','MKCALENDAR','MOVE','COPY','PROPPATCH','LOCK','UNLOCK'], true)) {
            header('Allow: OPTIONS, PROPFIND, REPORT, GET, HEAD');
            $this->plain(403, 'This address book is read-only.');
            return;
        }
        $kind = $this->kind($path);
        if ($kind === null) {
            $this->plain(404, 'Not found.');
            return;
        }
        if ($method === 'GET' || $method === 'HEAD') {
            if ($kind !== 'card') {
                header('Allow: OPTIONS, PROPFIND, REPORT, GET, HEAD');
                $this->plain(405, 'Use PROPFIND for collections.');
                return;
            }
            $name = substr($path, strlen($this->book));
            $card = (string)$this->contacts[$name]['vcard'];
            $etag = $this->etag($card);
            header('Content-Type: text/vcard; charset=utf-8');
            header('ETag: ' . $etag);
            header('Content-Length: ' . strlen($card));
            $updated = strtotime((string)($this->snapshot['updated_at'] ?? ''));
            if ($updated !== false) header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $updated) . ' GMT');
            if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
                http_response_code(304);
                header_remove('Content-Length');
                return;
            }
            if ($method !== 'HEAD') echo $card;
            return;
        }
        if ($method === 'PROPFIND') {
            $this->propfind($kind, $path);
            return;
        }
        if ($method === 'REPORT') {
            $this->report($kind);
            return;
        }
        header('Allow: OPTIONS, PROPFIND, REPORT, GET, HEAD');
        $this->plain(405, 'Method not supported.');
    }

    private function kind(string $path): ?string
    {
        if ($path === $this->root) return 'root';
        if ($path === $this->principal) return 'principal';
        if ($path === $this->home) return 'home';
        if ($path === $this->book) return 'book';
        if (str_starts_with($path, $this->book)) {
            $name = substr($path, strlen($this->book));
            if (preg_match('/^[0-9a-f]{64}\.vcf$/D', $name) && isset($this->contacts[$name])) return 'card';
        }
        return null;
    }

    private function propfind(string $kind, string $path): void
    {
        $depth = (string)($_SERVER['HTTP_DEPTH'] ?? '0');
        if (!in_array($depth, ['0','1'], true)) { $this->plain(403, 'Only depth 0 and 1 supported.'); return; }
        $doc = $this->parseRequest(false);
        if ($doc === false) return;
        $props = $this->requestedProps($doc, false);
        $items = [[$path, $kind]];
        if ($depth === '1') {
            if ($kind === 'root') $items[] = [$this->principal, 'principal'];
            if ($kind === 'home') $items[] = [$this->book, 'book'];
            if ($kind === 'book') {
                foreach (array_keys($this->contacts) as $resource) $items[] = [$this->book . $resource, 'card'];
            }
        }
        $this->multiStatus($items, $props, false);
    }

    private function report(string $kind): void
    {
        if ($kind !== 'book') { $this->plain(403, 'REPORT is only available on the address book.'); return; }
        $doc = $this->parseRequest(true);
        if (!$doc instanceof \DOMDocument) return;
        $root = $doc->documentElement;
        if (!$root || $root->namespaceURI !== 'urn:ietf:params:xml:ns:carddav'
            || !in_array($root->localName, ['addressbook-query','addressbook-multiget'], true)) {
            $this->plain(501, 'Only CardDAV addressbook-query and addressbook-multiget are supported.');
            return;
        }
        $props = $this->requestedProps($doc, true);
        $items = [];
        if ($root->localName === 'addressbook-query') {
            foreach (array_keys($this->contacts) as $resource) $items[] = [$this->book . $resource, 'card'];
        } else {
            foreach ($root->getElementsByTagNameNS('DAV:', 'href') as $hrefNode) {
                $href = (string)$hrefNode->textContent;
                $hrefPath = parse_url($href, PHP_URL_PATH);
                if (!is_string($hrefPath) || !str_starts_with($hrefPath, $this->book)) continue;
                if ($this->kind($hrefPath) === 'card') $items[] = [$hrefPath, 'card'];
                else $items[] = [$hrefPath, 'missing'];
            }
        }
        $this->multiStatus($items, $props, true);
    }

    /** @return \DOMDocument|false|null */
    private function parseRequest(bool $required): \DOMDocument|false|null
    {
        $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($length > 262144) { $this->plain(413, 'Request too large.'); return false; }
        $body = file_get_contents('php://input', false, null, 0, 262145);
        if (!is_string($body) || strlen($body) > 262144) { $this->plain(413, 'Request too large.'); return false; }
        if (trim($body) === '') {
            if ($required) { $this->plain(400, 'An XML request body is required.'); return false; }
            return null;
        }
        if (!class_exists(\DOMDocument::class)) { $this->plain(503, 'PHP DOM/XML extension required.'); return false; }
        if (stripos($body, '<!DOCTYPE') !== false || stripos($body, '<!ENTITY') !== false) {
            $this->plain(400, 'DTD and entities are prohibited.'); return false;
        }
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $ok = $doc->loadXML($body, LIBXML_NONET | LIBXML_NOBLANKS);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$ok) { $this->plain(400, 'Invalid XML.'); return false; }
        return $doc;
    }

    /** @return array<int,array{0:string,1:string}> */
    private function requestedProps(?\DOMDocument $doc, bool $report): array
    {
        if ($doc instanceof \DOMDocument) {
            foreach ($doc->getElementsByTagNameNS('DAV:', 'prop') as $node) {
                $props = [];
                foreach ($node->childNodes as $child) {
                    if ($child instanceof \DOMElement) $props[] = [(string)$child->namespaceURI, (string)$child->localName];
                }
                return $props;
            }
        }
        return $report
            ? [['DAV:','getetag'],['urn:ietf:params:xml:ns:carddav','address-data']]
            : [['DAV:','resourcetype'],['DAV:','displayname'],['DAV:','current-user-principal'],
               ['DAV:','principal-URL'],['urn:ietf:params:xml:ns:carddav','addressbook-home-set'],
               ['DAV:','getetag'],['DAV:','getcontenttype']];
    }

    private function multiStatus(array $items, array $props, bool $report): void
    {
        http_response_code(207);
        header('Content-Type: application/xml; charset=utf-8');
        $xml = '<?xml version="1.0" encoding="utf-8"?>'
            . '<D:multistatus xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:carddav" xmlns:CS="http://calendarserver.org/ns/">';
        foreach ($items as [$href, $kind]) {
            $xml .= '<D:response><D:href>' . self::esc($href) . '</D:href>';
            if ($kind === 'missing') {
                $xml .= '<D:status>HTTP/1.1 404 Not Found</D:status></D:response>';
                continue;
            }
            $good = '';
            $missing = '';
            foreach ($props as [$ns,$name]) {
                $element = $this->property($kind, $href, $ns, $name, $report);
                if ($element === null) $missing .= $this->emptyProperty($ns, $name);
                else $good .= $element;
            }
            if ($good !== '') $xml .= '<D:propstat><D:prop>' . $good . '</D:prop><D:status>HTTP/1.1 200 OK</D:status></D:propstat>';
            if ($missing !== '') $xml .= '<D:propstat><D:prop>' . $missing . '</D:prop><D:status>HTTP/1.1 404 Not Found</D:status></D:propstat>';
            $xml .= '</D:response>';
        }
        $xml .= '</D:multistatus>';
        echo $xml;
    }

    private function property(string $kind, string $href, string $ns, string $name, bool $report): ?string
    {
        if ($ns === 'DAV:') {
            switch ($name) {
                case 'resourcetype':
                    if ($kind === 'principal') return '<D:resourcetype><D:principal/></D:resourcetype>';
                    if ($kind === 'book') return '<D:resourcetype><D:collection/><C:addressbook/></D:resourcetype>';
                    return $kind === 'card' ? '<D:resourcetype/>' : '<D:resourcetype><D:collection/></D:resourcetype>';
                case 'displayname':
                    return '<D:displayname>' . self::esc(match ($kind) {
                        'root' => 'Pool Founder', 'principal' => $this->user,
                        'home' => 'Address books', 'book' => 'Pool Founder Contacts',
                        default => (string)($this->contacts[basename($href)]['name'] ?? 'Contact')
                    }) . '</D:displayname>';
                case 'current-user-principal': return '<D:current-user-principal><D:href>' . self::esc($this->principal) . '</D:href></D:current-user-principal>';
                case 'principal-URL': return '<D:principal-URL><D:href>' . self::esc($this->principal) . '</D:href></D:principal-URL>';
                case 'getetag':
                    if ($kind !== 'card') return null;
                    return '<D:getetag>' . self::esc($this->etag((string)$this->contacts[basename($href)]['vcard'])) . '</D:getetag>';
                case 'getcontenttype':
                    return $kind === 'card' ? '<D:getcontenttype>text/vcard; charset=utf-8</D:getcontenttype>' : null;
                case 'getcontentlength':
                    return $kind === 'card' ? '<D:getcontentlength>' . strlen((string)$this->contacts[basename($href)]['vcard']) . '</D:getcontentlength>' : null;
                case 'getlastmodified':
                    $timestamp = strtotime((string)($this->snapshot['updated_at'] ?? ''));
                    return $kind === 'card' && $timestamp !== false ? '<D:getlastmodified>' . gmdate('D, d M Y H:i:s', $timestamp) . ' GMT</D:getlastmodified>' : null;
                case 'supported-report-set':
                    return '<D:supported-report-set>'
                        . '<D:supported-report><D:report><C:addressbook-query/></D:report></D:supported-report>'
                        . '<D:supported-report><D:report><C:addressbook-multiget/></D:report></D:supported-report>'
                        . '</D:supported-report-set>';
                case 'current-user-privilege-set':
                    return '<D:current-user-privilege-set><D:privilege><D:read/></D:privilege></D:current-user-privilege-set>';
            }
        }
        if ($ns === 'urn:ietf:params:xml:ns:carddav') {
            if ($name === 'addressbook-home-set') {
                return '<C:addressbook-home-set><D:href>' . self::esc($this->home) . '</D:href></C:addressbook-home-set>';
            }
            if ($name === 'supported-address-data' && $kind === 'book') {
                return '<C:supported-address-data><C:address-data-type content-type="text/vcard" version="3.0"/></C:supported-address-data>';
            }
            if ($name === 'address-data' && $kind === 'card' && $report) {
                return '<C:address-data>' . self::esc((string)$this->contacts[basename($href)]['vcard']) . '</C:address-data>';
            }
        }
        if ($ns === 'http://calendarserver.org/ns/' && $name === 'getctag' && $kind === 'book') {
            return '<CS:getctag>' . hash('sha256', json_encode(array_map(
                static fn($c) => hash('sha256', $c['vcard']), $this->contacts
            ), JSON_THROW_ON_ERROR)) . '</CS:getctag>';
        }
        return null;
    }

    private function emptyProperty(string $ns, string $name): string
    {
        $tag = match ($ns) {
            'DAV:' => 'D:', 'urn:ietf:params:xml:ns:carddav' => 'C:',
            'http://calendarserver.org/ns/' => 'CS:', default => ''
        };
        if ($tag !== '') return '<' . $tag . $name . '/>';
        return '<X:unknown xmlns:X="' . self::esc($ns) . '"/>';
    }

    private function etag(string $content): string { return '"' . hash('sha256', $content) . '"'; }

    private function plain(int $status, string $message): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        echo $message . "\n";
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
