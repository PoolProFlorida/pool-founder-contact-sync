<?php
declare(strict_types=1);

// On-demand, read-only public API structure test. Never writes a snapshot.
require __DIR__ . '/src/Config.php';
require __DIR__ . '/src/Http.php';
require __DIR__ . '/src/DashboardAuth.php';
require __DIR__ . '/src/PoolFounderClient.php';
require __DIR__ . '/src/ApiInspector.php';

use PoolProFlorida\ApiInspector;
use PoolProFlorida\Config;
use PoolProFlorida\Http;
use PoolProFlorida\DashboardAuth;
use PoolProFlorida\PoolFounderClient;

$user = DashboardAuth::guard();
header('X-Robots-Tag: noindex, nofollow, noarchive');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'");
header('Cache-Control: private, no-store, max-age=0');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: text/plain; charset=utf-8');
    exit("Run this test using the button in the protected dashboard.\n");
}
if (!DashboardAuth::validCsrf($user, is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : '')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Invalid form token.\n");
}

$maskedJson = null;
$problem = null;
$count = 0;
$key = Config::get('POOL_FOUNDER_API_KEY');
if ($key === '' || str_starts_with($key, 'replace-with-')) {
    $problem = 'Pool Founder API key is not configured.';
} else {
    try {
        $sample = (new PoolFounderClient($key))->customerSample(3);
        $count = count($sample->data);
        $maskedJson = json_encode(
            ApiInspector::redact($sample),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    } catch (Throwable $error) {
        // Do not emit HTTP response bodies, tokens, or customer details in errors.
        $problem = 'The test could not read a customer API page. Verify the API key and PHP cURL.';
    }
}
$base = Http::base();
function safe(string $value): string { return Http::escape($value); }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>API structure test — Pool Founder Contact Sync</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f3f7fa;color:#163342;font:15px/1.5 system-ui,-apple-system,Segoe UI,sans-serif}
main{max-width:950px;margin:36px auto;padding:0 18px}
section{background:white;padding:24px;border:1px solid #dce7ed;border-radius:14px}
h1{font-size:26px;margin:0 0 12px}a{color:#08648b}
p{margin:12px 0}pre{white-space:pre-wrap;overflow-wrap:anywhere;background:#122d3e;color:#edf8ff;border-radius:9px;padding:20px;overflow:auto;max-height:75vh}
.muted{color:#586b77}.error{border-left:4px solid #aa3128;padding:12px;background:#fff2f1}
</style>
</head>
<body><main><section>
<p><a href="<?=safe($base . '/')?>">← Back to protected dashboard</a></p>
<h1>Live API response structure</h1>
<p class="muted">Fetched up to 3 records from the documented customer-list endpoint. All string, numeric, and boolean values are masked. JSON field names, nested objects, arrays, and null values are shown to help identify address fields. No response is stored or sent to GitHub.</p>
<?php if ($problem !== null): ?>
<p class="error"><?=safe($problem)?></p>
<?php else: ?>
<p>Response received. Sample customer records: <strong><?=$count?></strong>.</p>
<pre><?=safe((string)$maskedJson)?></pre>
<p class="muted">You can copy this <strong>masked structure</strong> to discuss field mapping. Do not paste an unredacted API response containing customer records.</p>
<?php endif; ?>
</section></main></body></html>
