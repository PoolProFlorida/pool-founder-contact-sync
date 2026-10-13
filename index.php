<?php
declare(strict_types=1);
require __DIR__ . '/src/Config.php';
require __DIR__ . '/src/Http.php';
require __DIR__ . '/src/DashboardAuth.php';
require __DIR__ . '/src/PoolFounderClient.php';
require __DIR__ . '/src/VCard.php';
require __DIR__ . '/src/Snapshot.php';
require __DIR__ . '/src/ContactSearch.php';
require __DIR__ . '/src/CronCommand.php';

use PoolProFlorida\Config;
use PoolProFlorida\Http;
use PoolProFlorida\DashboardAuth;
use PoolProFlorida\PoolFounderClient;
use PoolProFlorida\Snapshot;
use PoolProFlorida\ContactSearch;
use PoolProFlorida\CronCommand;

$user = DashboardAuth::guard();
header('X-Robots-Tag: noindex, nofollow, noarchive');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('Cache-Control: private, no-store, max-age=0');

$base = Http::base();
$error = '';
$notice = '';
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'POST') {
    $token = is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : '';
    if (!DashboardAuth::validCsrf($user, $token)) {
        http_response_code(403);
        exit('Invalid form token.');
    }
    if (($_POST['action'] ?? '') !== 'refresh') {
        http_response_code(400);
        exit('Invalid action.');
    }
    $apiKey = Config::get('POOL_FOUNDER_API_KEY');
    if ($apiKey === '' || str_starts_with($apiKey, 'replace-with-')) {
        $error = 'The Pool Founder API key has not been configured.';
    } else {
        try {
            $snapshot = Snapshot::refresh(new PoolFounderClient($apiKey));
            $notice = 'Contacts updated successfully. ' . count($snapshot['contacts']) . ' customer records are available.';
        } catch (Throwable $e) {
            error_log('Pool Founder Contact Sync refresh failed: ' . get_class($e));
            $error = 'Unable to refresh right now. Your previously saved contacts are unchanged.';
        }
    }
} elseif ($method !== 'GET' && $method !== 'HEAD') {
    http_response_code(405);
    header('Allow: GET, HEAD, POST');
    exit('Method not allowed.');
}

try {
    $snapshot = Snapshot::load();
} catch (Throwable $e) {
    $snapshot = ['updated_at' => null, 'contacts' => []];
    $error = 'Saved contact data could not be loaded. Check private storage permissions.';
}
$contacts = $snapshot['contacts'];
$view = is_string($_GET['view'] ?? null) ? $_GET['view'] : '';
$download = is_string($_GET['download'] ?? null) ? $_GET['download'] : '';
$requested = $download !== '' ? $download : $view;

if ($requested !== '') {
    if (!preg_match('/^[0-9a-f]{64}\.vcf$/D', $requested) || !isset($contacts[$requested])) {
        http_response_code(404);
        exit('Contact not found.');
    }
    $card = (string)$contacts[$requested]['vcard'];
    if ($download !== '') {
        header('Content-Type: text/vcard; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $requested . '"');
        header('Content-Length: ' . strlen($card));
        if ($method !== 'HEAD') echo $card;
        exit;
    }
}

$stats = ContactSearch::stats($contacts);
$hasAddresses = $stats['with_address'] > 0;
$filterOptions = [
    'all' => 'All contacts',
    'missing-phone' => 'Missing phone',
    'missing-email' => 'Missing email',
];
if ($hasAddresses) {
    $filterOptions['missing-address'] = 'Missing address';
    $filterOptions['with-address'] = 'With address';
}
$search = is_string($_GET['q'] ?? null) ? substr(trim($_GET['q']), 0, 120) : '';
$only = is_string($_GET['only'] ?? null) ? $_GET['only'] : 'all';
if (!array_key_exists($only, $filterOptions)) $only = 'all';
$pageParam = is_scalar($_GET['page'] ?? null) ? (string)$_GET['page'] : '1';
$pageNumber = ctype_digit($pageParam) ? min(100000, (int)$pageParam) : 1;
$listing = ContactSearch::page(ContactSearch::filter($contacts, $search, $only), $pageNumber, 50);
$apiConfigured = Config::get('POOL_FOUNDER_API_KEY') !== ''
    && !str_starts_with(Config::get('POOL_FOUNDER_API_KEY'), 'replace-with-');
$lastUpdated = is_string($snapshot['updated_at'] ?? null) ? strtotime($snapshot['updated_at']) : false;
$lastSyncLabel = $lastUpdated !== false ? gmdate('M j, Y · g:i A', $lastUpdated) . ' UTC' : 'Not synced';
$cardavUrl = $base . '/carddav/';
$cronCommand = CronCommand::build(__DIR__);
$hasSearch = $search !== '' || $only !== 'all';

function h(string $value): string { return Http::escape($value); }
function pageUrl(string $base, string $search, string $only, int $page): string
{
    return $base . '/?' . http_build_query(['q' => $search, 'only' => $only, 'page' => $page]);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Dashboard | Pool Founder Contact Sync</title>
<link rel="stylesheet" href="<?=h($base . '/assets/admin.css')?>">
<script src="<?=h($base . '/assets/admin.js')?>" defer></script>
</head>
<body>
<header class="topbar">
  <div class="topbar-inner">
    <a class="identity" href="<?=h($base . '/')?>" aria-label="Pool Founder Contact Sync dashboard">
      <span class="brand-mark" aria-hidden="true">PF</span>
      <span class="identity-text"><strong>Pool Founder Contact Sync</strong><small>Independent project by Pool Pro Florida</small></span>
    </a>
    <nav class="nav-links" aria-label="Main navigation">
      <a class="nav-link active" aria-current="page" href="<?=h($base . '/')?>">Dashboard</a>
      <?php if (DashboardAuth::enabled()): ?>
      <a class="nav-link" href="<?=h($base . '/app-passwords.php')?>">Manage CardDAV app passwords</a>
      <form method="post" action="<?=h($base . '/logout.php')?>" class="nav-form">
        <input type="hidden" name="csrf" value="<?=h(DashboardAuth::csrf($user))?>">
        <button type="submit" class="nav-button">Sign out</button>
      </form>
      <?php endif; ?>
    </nav>
  </div>
</header>
<main class="shell">
  <div class="page-heading">
    <div>
      <span class="eyebrow">Overview</span>
      <h1>Contacts dashboard</h1>
      <p class="lead">Your customer directory, available through a private, read-only CardDAV address book.</p>
    </div>
    <?php if (!$apiConfigured): ?>
      <span class="status-tag warning">API key missing</span>
    <?php elseif ($lastUpdated === false): ?>
      <span class="status-tag warning">First sync needed</span>
    <?php else: ?>
      <span class="status-tag good">Snapshot available</span>
    <?php endif; ?>
  </div>

  <?php if ($error !== ''): ?><div class="alert error" role="alert"><?=h($error)?></div><?php endif; ?>
  <?php if ($notice !== ''): ?><div class="alert success" role="status"><?=h($notice)?></div><?php endif; ?>

  <section class="summary-grid" aria-label="Contact summary">
    <div class="metric">
      <span class="metric-label">Customer contacts</span>
      <strong class="metric-value"><?=number_format($stats['total'])?></strong>
      <span class="metric-foot">Saved in your address book</span>
    </div>
    <div class="metric">
      <span class="metric-label">With phone number</span>
      <strong class="metric-value"><?=number_format($stats['with_phone'])?></strong>
      <span class="metric-foot">Available for caller identification</span>
    </div>
    <div class="metric">
      <span class="metric-label">With email</span>
      <strong class="metric-value"><?=number_format($stats['with_email'])?></strong>
      <span class="metric-foot">Included when provided by the API</span>
    </div>
    <div class="metric">
      <span class="metric-label">Last successful refresh</span>
      <strong class="metric-value"><?= $lastUpdated !== false ? h(gmdate('M j', $lastUpdated)) : '—' ?></strong>
      <span class="metric-foot"><?=h($lastSyncLabel)?></span>
    </div>
  </section>

  <section class="card" aria-labelledby="sync-heading">
    <div class="card-head">
      <div>
        <h2 id="sync-heading">Customer sync</h2>
        <p class="subtle">Pull the latest customer contacts from Pool Founder. Your previous snapshot is retained if a refresh fails.</p>
      </div>
      <form method="post" action="<?=h($base . '/index.php')?>">
        <input type="hidden" name="csrf" value="<?=h(DashboardAuth::csrf($user))?>">
        <input type="hidden" name="action" value="refresh">
        <button type="submit" class="btn" <?= $apiConfigured ? '' : 'disabled' ?>>Refresh contacts <span aria-hidden="true">↻</span></button>
      </form>
    </div>
    <div class="endpoint">
      <span class="endpoint-label">CardDAV address book</span>
      <code><?=h($cardavUrl)?></code>
      <span class="status-tag good">Read-only</span>
    </div>
    <?php if (!$hasAddresses && $stats['total'] > 0): ?>
      <p class="card-caption">Service addresses are not available in the current customer API response. Existing contact details are unaffected.</p>
    <?php endif; ?>
  </section>

  <section class="card cron-card" aria-labelledby="cron-heading">
    <div class="card-head">
      <div>
        <h2 id="cron-heading">Automatic refresh</h2>
        <p class="subtle">Optional: have your server check for new customers every 30 minutes. Copy these into cPanel &rarr; Cron Jobs.</p>
      </div>
      <span class="status-tag">Setup required</span>
    </div>
    <div class="cron-fields">
      <div class="field">
        <label class="field-label" for="cron-schedule">Schedule (every 30 minutes)</label>
        <div class="copy-field">
          <input id="cron-schedule" type="text" value="<?=h(CronCommand::SCHEDULE)?>" readonly spellcheck="false" aria-describedby="cron-schedule-help">
          <button class="btn secondary" type="button" data-copy-target="cron-schedule">Copy</button>
        </div>
        <p class="helper" id="cron-schedule-help">For cPanel's separate fields: Minute = <code>*/30</code>; Hour, Day, Month and Weekday = <code>*</code>.</p>
      </div>
      <div class="field">
        <label class="field-label" for="cron-command">Command (paste into the Command box)</label>
        <div class="copy-field">
          <input id="cron-command" type="text" value="<?=h($cronCommand)?>" readonly spellcheck="false" aria-describedby="cron-command-help">
          <button class="btn secondary" type="button" data-copy-target="cron-command">Copy</button>
        </div>
        <p class="helper" id="cron-command-help">Runs the local read-only sync script using the server's installation path. No passwords or public URLs.</p>
      </div>
    </div>
    <p id="cron-copy-status" class="small-print" role="status" aria-live="polite"></p>
    <p class="card-caption">This only shows setup instructions; <strong>the cron job is not installed yet.</strong> The PHP CLI path can vary by host, so adjust it in cPanel if needed. Successful runs stay quiet; failed runs can send cron email when hosting notifications are enabled.</p>
  </section>

  <?php if ($view !== ''): ?>
  <section class="card preview-card" aria-labelledby="contact-detail-heading">
    <div class="card-head">
      <div>
        <span class="eyebrow">Contact details</span>
        <h2 id="contact-detail-heading"><?=h((string)$contacts[$view]['name'])?></h2>
      </div>
      <div class="action-row">
        <a class="btn secondary" href="<?=h($base . '/?download=' . rawurlencode($view))?>">Download vCard</a>
        <a href="<?=h($base . '/')?>">Close</a>
      </div>
    </div>
    <pre><?=h((string)$contacts[$view]['vcard'])?></pre>
  </section>
  <?php endif; ?>

  <section class="card" aria-labelledby="contacts-heading">
    <div class="card-head">
      <div>
        <h2 id="contacts-heading">Customer contacts</h2>
        <p class="subtle">Search your saved contacts or download an individual vCard.</p>
      </div>
      <span class="results-meta"><?=number_format($listing['total'])?> matching contact<?= $listing['total'] === 1 ? '' : 's' ?></span>
    </div>

    <?php if ($contacts !== []): ?>
    <form method="get" action="<?=h($base . '/')?>" class="filters" role="search">
      <div class="field">
        <label for="contact-search" class="field-label">Search</label>
        <input id="contact-search" type="search" name="q" maxlength="120" value="<?=h($search)?>" placeholder="Name, phone, email or service day">
      </div>
      <div class="field">
        <label for="contact-filter" class="field-label">Filter</label>
        <select id="contact-filter" name="only">
          <?php foreach ($filterOptions as $option => $label): ?>
          <option value="<?=h($option)?>" <?= $only === $option ? 'selected' : '' ?>><?=h($label)?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn">Search</button>
      <?php if ($hasSearch): ?><a class="clear-filter" href="<?=h($base . '/')?>">Clear</a><?php endif; ?>
    </form>
    <?php endif; ?>

    <?php if ($listing['total'] === 0): ?>
      <div class="empty-state">
        <?php if ($contacts === []): ?>
        <strong>Your contact list is empty</strong>
        <p>Use Refresh contacts to load your customer directory.</p>
        <?php else: ?>
        <strong>No matching contacts</strong>
        <p>Try another search or clear the filter.</p>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="table-scroll">
        <table>
          <thead><tr><th>Name</th><th>Phone</th><th>Email</th><th>Service day</th>
            <?php if ($hasAddresses): ?><th>Address</th><?php endif; ?>
            <th>Contact</th></tr></thead>
          <tbody>
          <?php foreach ($listing['contacts'] as $resource => $contact): ?>
          <tr>
            <td><span class="cell-name"><?=h((string)$contact['name'])?></span></td>
            <td><?= ($contact['phone'] ?? '') !== '' ? h((string)$contact['phone']) : '<span class="muted">—</span>' ?></td>
            <td><?= ($contact['email'] ?? '') !== '' ? h((string)$contact['email']) : '<span class="muted">—</span>' ?></td>
            <td><?= ($contact['service_days'] ?? []) !== [] ? h(implode(', ', $contact['service_days'])) : '<span class="muted">—</span>' ?></td>
            <?php if ($hasAddresses): ?><td><?= ($contact['addresses'] ?? []) !== [] ? h(implode(' · ', $contact['addresses'])) : '<span class="muted">—</span>' ?></td><?php endif; ?>
            <td class="actions">
              <a href="<?=h($base . '/?view=' . rawurlencode((string)$resource))?>">View</a>
              <a href="<?=h($base . '/?download=' . rawurlencode((string)$resource))?>">VCF</a>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($listing['pages'] > 1): ?>
        <nav class="pagination" aria-label="Contact pages">
          <span><?php if ($listing['page'] > 1): ?><a href="<?=h(pageUrl($base, $search, $only, $listing['page'] - 1))?>">← Previous</a><?php endif; ?></span>
          <span>Page <?= $listing['page'] ?> of <?= $listing['pages'] ?></span>
          <span><?php if ($listing['page'] < $listing['pages']): ?><a href="<?=h(pageUrl($base, $search, $only, $listing['page'] + 1))?>">Next →</a><?php endif; ?></span>
        </nav>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <footer class="footline">
    <span>Independent community project · Read-only contact directory</span>
    <a href="https://github.com/PoolProFlorida/pool-founder-contact-sync" rel="noopener noreferrer">Pool Pro Florida · Community Over Competition ↗</a>
  </footer>
</main>
</body>
</html>
