<?php
declare(strict_types=1);
require __DIR__ . '/src/Config.php';
require __DIR__ . '/src/Http.php';
require __DIR__ . '/src/PoolFounderClient.php';
require __DIR__ . '/src/VCard.php';
require __DIR__ . '/src/Snapshot.php';

use PoolProFlorida\Config;
use PoolProFlorida\Http;
use PoolProFlorida\PoolFounderClient;
use PoolProFlorida\Snapshot;

$user = Http::guard();
header('X-Robots-Tag: noindex, nofollow, noarchive');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

$error = '';
$notice = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $token = (string)($_POST['csrf'] ?? '');
    if (!Http::validCsrf($user, $token)) {
        http_response_code(403);
        exit('Invalid form token.');
    }
    if ((Config::get('POOL_FOUNDER_API_KEY') === '' || str_starts_with(Config::get('POOL_FOUNDER_API_KEY'), 'replace-with-'))) {
        $error = 'Set POOL_FOUNDER_API_KEY in .env before refreshing.';
    } else {
        try {
            $snapshot = Snapshot::refresh(new PoolFounderClient(Config::get('POOL_FOUNDER_API_KEY')));
            $notice = 'Customer snapshot refreshed: ' . count($snapshot['contacts']) . ' vCards.';
        } catch (Throwable $e) {
            error_log('Pool Founder Contact Sync refresh failed: ' . get_class($e));
            $error = 'Refresh failed. Previous snapshot preserved. Check API access, PHP cURL, and storage permissions.';
        }
    }
} elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') {
    http_response_code(405);
    header('Allow: GET, HEAD, POST');
    exit('Method not allowed.');
}

try {
    $snapshot = Snapshot::load();
} catch (Throwable $e) {
    $snapshot = ['updated_at' => null, 'contacts' => []];
    $error = 'Saved snapshot cannot be read. Check storage permissions and file contents.';
}
$contacts = $snapshot['contacts'];
$view = isset($_GET['view']) ? (string)$_GET['view'] : '';
$download = isset($_GET['download']) ? (string)$_GET['download'] : '';
$requested = $download !== '' ? $download : $view;

if ($requested !== '') {
    if (!preg_match('/^[0-9a-f]{64}\.vcf$/', $requested) || !isset($contacts[$requested])) {
        http_response_code(404);
        exit('vCard not found.');
    }
    $card = (string)$contacts[$requested]['vcard'];
    if ($download !== '') {
        header('Content-Type: text/vcard; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $requested . '"');
        header('Content-Length: ' . strlen($card));
        echo $card;
        exit;
    }
}
$base = Http::base();
$updated = isset($snapshot['updated_at']) && is_string($snapshot['updated_at'])
    ? $snapshot['updated_at'] : 'Never';
$apiConfigured = Config::get('POOL_FOUNDER_API_KEY') !== '' && !str_starts_with(Config::get('POOL_FOUNDER_API_KEY'), 'replace-with-');
function h(string $value): string { return Http::escape($value); }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Pool Founder Contact Sync — Preview</title>
<style>
:root{color-scheme:light}*{box-sizing:border-box}
body{margin:0;background:#f3f7fa;color:#173141;font:15px/1.5 system-ui,-apple-system,Segoe UI,sans-serif}
main{max-width:1080px;margin:0 auto;padding:38px 20px 70px}
h1{font-size:clamp(26px,4vw,36px);margin:0 0 4px}h2{font-size:19px;margin:0 0 12px}
p{margin:8px 0 16px}.eyebrow{font-size:12px;letter-spacing:.12em;text-transform:uppercase;font-weight:800;color:#22799a}
.muted{color:#536a78}.panel{background:#fff;border:1px solid #dce7ed;border-radius:14px;padding:22px;margin:18px 0;box-shadow:0 6px 20px #17314108}
.info{display:flex;gap:28px;flex-wrap:wrap}.stat{min-width:150px}.stat b{display:block;font-size:25px}
button,.btn{border:0;border-radius:9px;background:#006e9c;color:white;padding:11px 16px;font:inherit;font-weight:700;cursor:pointer}
button:disabled{opacity:.5;cursor:not-allowed}a{color:#075f88}a:hover{text-decoration:underline}
.notice{background:#ecfbef;border-left:4px solid #27994e;padding:11px 14px}.error{background:#fff0ee;border-left:4px solid #b54133;padding:11px 14px}
.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;text-align:left;font-size:14px}
th,td{padding:12px 10px;border-bottom:1px solid #e5eef3;vertical-align:top}
th{font-size:12px;text-transform:uppercase;color:#526b7c;letter-spacing:.05em}
.actions{white-space:nowrap}code,pre{font-family:ui-monospace,SFMono-Regular,Consolas,monospace}
pre{white-space:pre-wrap;overflow-wrap:anywhere;background:#102b39;color:#e9f7ff;border-radius:10px;padding:20px;max-height:520px;overflow:auto}
small{font-size:12px}@media(max-width:650px){main{padding:24px 12px}.panel{padding:16px}td,th{padding:9px 6px}}
</style>
</head>
<body><main>
<div class="eyebrow">Pool Pro Florida · Private administration</div>
<h1>Pool Founder Contact Sync</h1>
<p class="muted">Protected customer preview. This app is independent of your existing <code>/sync/</code> installation.</p>
<?php if ($error !== ''): ?><p class="error"><?=h($error)?></p><?php endif; ?>
<?php if ($notice !== ''): ?><p class="notice"><?=h($notice)?></p><?php endif; ?>
<div class="panel">
  <h2>Connection &amp; snapshot</h2>
  <div class="info">
    <div class="stat"><span class="muted">API key</span><b><?= $apiConfigured ? 'Configured' : 'Missing' ?></b></div>
    <div class="stat"><span class="muted">Saved contacts</span><b><?=count($contacts)?></b></div>
    <div class="stat"><span class="muted">Last refresh (UTC)</span><b style="font-size:16px"><?=h($updated)?></b></div>
  </div>
  <form method="post" action="<?=h($base . '/index.php')?>">
    <input type="hidden" name="csrf" value="<?=h(Http::csrf($user))?>">
    <button type="submit" <?=$apiConfigured ? '' : 'disabled'?>>Fetch customers &amp; rebuild preview</button>
  </form>
  <p class="muted"><small>Read-only Pool Founder API request. Snapshot is replaced only after a successful complete fetch. Customer records only until a read-only leads endpoint is verified. Addresses appear only if returned by the public API.</small></p>
</div>
<div class="panel">
  <h2>Server readiness</h2>
  <div class="info">
    <div><strong>PHP 8.1+</strong><br><?=version_compare(PHP_VERSION, '8.1.0', '>=') ? 'Ready' : 'Upgrade required'?> <span class="muted">(<?=h(PHP_VERSION)?>)</span></div>
    <div><strong>cURL</strong><br><?=function_exists('curl_init') ? 'Ready' : 'PHP cURL extension missing'?></div>
    <div><strong>DOM/XML</strong><br><?=class_exists('DOMDocument') ? 'Ready' : 'PHP DOM extension missing'?></div>
    <div><strong>Private storage</strong><br><?=is_writable(__DIR__ . '/storage') ? 'Writable' : 'Not writable'?></div>
    <div><strong>HTTPS</strong><br><?=Http::secure() ? 'Enabled' : 'Required'?></div>
  </div>
  <p class="muted"><small>Also verify from outside that <code>/.git/HEAD</code>, <code>/.env</code>, and <code>/storage/contacts.json</code> cannot be downloaded. This panel cannot verify Apache access rules.</small></p>
</div>
<?php if ($view !== ''): ?>
<div class="panel">
  <h2>vCard preview: <?=h((string)$contacts[$view]['name'])?></h2>
  <p><a href="<?=h($base . '/?download=' . rawurlencode($view))?>">Download .vcf</a> · <a href="<?=h($base . '/')?>">Back to contacts</a></p>
  <pre><?=h((string)$contacts[$view]['vcard'])?></pre>
</div>
<?php endif; ?>
<div class="panel">
  <h2>Contacts <span class="muted">(<?=count($contacts)?>)</span></h2>
  <?php if (!$contacts): ?>
  <p class="muted">No saved customer snapshot yet. Add your API key and fetch customers to inspect the vCards.</p>
  <?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Name</th><th>Phone / Email</th><th>Service address</th><th>Day</th><th>vCard</th></tr></thead>
    <tbody>
    <?php foreach (array_slice($contacts, 0, 250, true) as $resource => $contact): ?>
      <tr>
        <td><?=h((string)$contact['name'])?></td>
        <td><?=h((string)$contact['phone'])?><br><span class="muted"><?=h((string)$contact['email'])?></span></td>
        <td><?=h(implode(' | ', $contact['addresses'] ?? []))?></td>
        <td><?=h(implode(', ', $contact['service_days'] ?? []))?></td>
        <td class="actions"><a href="<?=h($base . '/?view=' . rawurlencode((string)$resource))?>">View</a> · <a href="<?=h($base . '/?download=' . rawurlencode((string)$resource))?>">VCF</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if (count($contacts) > 250): ?><p class="muted">Showing first 250 contacts. All saved contacts are available through CardDAV.</p><?php endif; ?>
  <?php endif; ?>
</div>
<div class="panel"><h2>CardDAV</h2>
<p>Read-only endpoint: <code><?=h($base . '/carddav/')?> </code></p>
<p class="muted">Requires HTTPS and the same Basic Auth credentials as this dashboard. Verify with a DAV client before connecting a production phone.</p></div>
<p class="muted"><small>Community Over Competition · <a href="https://github.com/PoolProFlorida/pool-founder-contact-sync">Project on GitHub</a></small></p>
</main></body></html>
