<?php
declare(strict_types=1);

/**
 * Verify essential access restrictions from the public HTTPS side.
 * Prints only statuses, never URLs, credentials, files or response bodies.
 *
 * Usage: php scripts/check-deployment.php https://example.com/contact-sync/
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
if (!function_exists('curl_init')) {
    fwrite(STDERR, "The cURL extension is required.\n");
    exit(2);
}
$base = $argv[1] ?? '';
if (!is_string($base) || $base === '' || count($argv) !== 2) {
    fwrite(STDERR, "Usage: php scripts/check-deployment.php https://example.com/contact-sync/\n");
    exit(2);
}
$parsed = parse_url($base);
if (!is_array($parsed) || strtolower((string)($parsed['scheme'] ?? '')) !== 'https'
    || empty($parsed['host']) || isset($parsed['user']) || isset($parsed['pass'])
    || isset($parsed['query']) || isset($parsed['fragment'])) {
    fwrite(STDERR, "Use an HTTPS application URL with no credentials, query or fragment.\n");
    exit(2);
}
$base = rtrim($base, '/') . '/';

function statusFor(string $url): int
{
    $ch = curl_init($url);
    if ($ch === false) throw new RuntimeException('HTTP request could not start');
    try {
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_WRITEFUNCTION => static fn($handle, string $chunk): int => strlen($chunk),
            CURLOPT_HTTPHEADER => ['Cache-Control: no-store'],
        ]);
        if (curl_exec($ch) === false) throw new RuntimeException('HTTPS request failed');
        return (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    } finally {
        curl_close($ch);
    }
}

$private = [
    'Application secrets' => '.env',
    'Git metadata' => '.git/HEAD',
    'Saved contacts' => 'storage/contacts.json',
    'App-password hashes' => 'storage/app-passwords.json',
    'Source code' => 'src/Config.php',
    'CLI scripts' => 'scripts/sync.php',
    'Test helpers' => 'tests/http_router.php',
    'Previous diagnostics' => 'api-test.php',
];
$failures = [];
echo "Public access checks (no authentication, no data displayed)\n";
foreach ($private as $label => $path) {
    try {
        $status = statusFor($base . $path);
        $passed = in_array($status, [401, 403, 404, 410], true);
        echo ($passed ? 'PASS ' : 'FAIL ') . $label . ' (HTTP ' . $status . ")\n";
        if (!$passed) $failures[] = $label;
    } catch (Throwable $e) {
        echo "FAIL " . $label . " (connection failed)\n";
        $failures[] = $label;
    }
}

foreach ([
    'Dashboard requires login' => ['', [302, 303, 401]],
    'CardDAV requires login' => ['carddav/', [401]],
    'Dashboard stylesheet' => ['assets/admin.css', [200]],
] as $label => [$path, $expected]) {
    try {
        $status = statusFor($base . $path);
        $passed = in_array($status, $expected, true);
        echo ($passed ? 'PASS ' : 'FAIL ') . $label . ' (HTTP ' . $status . ")\n";
        if (!$passed) $failures[] = $label;
    } catch (Throwable $e) {
        echo 'FAIL ' . $label . " (connection failed)\n";
        $failures[] = $label;
    }
}
if ($failures !== []) {
    fwrite(STDERR, "Deployment checks failed. Do not consider this installation ready until every failure is addressed.\n");
    exit(1);
}
echo "All basic deployment access checks passed. This is not a full security audit.\n";
