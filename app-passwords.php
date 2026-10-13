<?php
declare(strict_types=1);
require __DIR__ . '/src/Config.php';
require __DIR__ . '/src/Http.php';
require __DIR__ . '/src/DashboardAuth.php';
require __DIR__ . '/src/AppPasswords.php';

use PoolProFlorida\AppPasswords;
use PoolProFlorida\Config;
use PoolProFlorida\DashboardAuth;
use PoolProFlorida\Http;

if (!DashboardAuth::enabled()) {
    http_response_code(404);
    exit('Enable the separate dashboard login first.');
}
$user = DashboardAuth::guard();
header('X-Robots-Tag: noindex, nofollow, noarchive');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('Cache-Control: private, no-store');

$base = Http::base();
$error = '';
$created = null;
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
    $token = is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : '';
    if (!DashboardAuth::validCsrf($user, $token)) {
        http_response_code(403);
        exit('Invalid form token.');
    }
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    try {
        if ($action === 'create') {
            $created = AppPasswords::create(is_string($_POST['label'] ?? null) ? $_POST['label'] : '');
        } elseif ($action === 'revoke') {
            $id = is_string($_POST['id'] ?? null) ? $_POST['id'] : '';
            if (($_POST['confirm'] ?? '') !== 'yes') {
                $error = 'Confirm the client password before revoking access.';
            } elseif (!AppPasswords::revoke($id)) {
                $error = 'Password not found or already revoked.';
            } else {
                header('Location: ' . $base . '/app-passwords.php?revoked=1', true, 303);
                exit;
            }
        } else {
            http_response_code(400);
            $error = 'Invalid action.';
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('CardDAV app-password administration failed: ' . get_class($e));
        $error = 'Unable to save credentials. Check private storage permissions.';
    }
} elseif ($method !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Method not allowed.');
}
try {
    $apps = AppPasswords::all();
} catch (Throwable $e) {
    $apps = [];
    $error = 'Unable to read saved app passwords. Check private storage and permissions.';
}
$legacyActive = Config::get('CARDDAV_LEGACY_ENABLED', '1') !== '0';
$davUsername = Config::get('CARDDAV_USERNAME');
$revokeCandidate = null;
$revokeId = is_string($_GET['revoke'] ?? null) ? $_GET['revoke'] : '';
if (preg_match('/^[a-f0-9]{24}$/D', $revokeId)) {
    foreach ($apps as $app) {
        if (hash_equals($app['id'], $revokeId)) {
            $revokeCandidate = $app;
            break;
        }
    }
}
function hApp(string $value): string { return Http::escape($value); }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>App passwords | Pool Founder Contact Sync</title>
<link rel="stylesheet" href="<?=hApp($base . '/assets/admin.css')?>">
</head>
<body>
<header class="topbar">
  <div class="topbar-inner">
    <a class="identity" href="<?=hApp($base . '/')?>">
      <span class="brand-mark" aria-hidden="true">PF</span>
      <span class="identity-text"><strong>Pool Founder Contact Sync</strong><small>Private administration</small></span>
    </a>
    <nav class="nav-links" aria-label="Main navigation">
      <a class="nav-link" href="<?=hApp($base . '/')?>">Dashboard</a>
      <a class="nav-link active" aria-current="page" href="<?=hApp($base . '/app-passwords.php')?>">Manage CardDAV app passwords</a>
      <form method="post" action="<?=hApp($base . '/logout.php')?>" class="nav-form">
        <input type="hidden" name="csrf" value="<?=hApp(DashboardAuth::csrf($user))?>">
        <button type="submit" class="nav-button">Sign out</button>
      </form>
    </nav>
  </div>
</header>
<main class="shell">
  <div class="page-heading">
    <div>
      <span class="eyebrow">Access management</span>
      <h1>CardDAV app passwords</h1>
      <p class="lead">Give each address-book client its own password. Revoke access whenever you need to, without disrupting other clients.</p>
    </div>
    <span class="status-tag <?= $legacyActive ? 'warning' : 'good' ?>">
      <?= $legacyActive ? 'Shared password enabled' : 'App passwords only' ?>
    </span>
  </div>

  <?php if ($error !== ''): ?><div class="alert error" role="alert"><?=hApp($error)?></div><?php endif; ?>
  <?php if (isset($_GET['revoked'])): ?><div class="alert success" role="status">The app password was revoked successfully.</div><?php endif; ?>

  <?php if ($revokeCandidate !== null): ?>
  <section class="card" aria-labelledby="revoke-confirm-heading">
    <span class="eyebrow">Confirm access change</span>
    <h2 id="revoke-confirm-heading">Revoke <?=hApp($revokeCandidate['label'])?>?</h2>
    <div class="alert warning">This client will lose access immediately. Existing contacts and other app passwords won't be affected.</div>
    <form method="post" action="<?=hApp($base . '/app-passwords.php')?>" class="action-row">
      <input type="hidden" name="csrf" value="<?=hApp(DashboardAuth::csrf($user))?>">
      <input type="hidden" name="action" value="revoke">
      <input type="hidden" name="id" value="<?=hApp($revokeCandidate['id'])?>">
      <input type="hidden" name="confirm" value="yes">
      <button type="submit" class="btn danger">Yes, revoke password</button>
      <a class="btn secondary" href="<?=hApp($base . '/app-passwords.php')?>">Cancel</a>
    </form>
  </section>
  <?php endif; ?>

  <?php if ($created !== null): ?>
  <section class="card" aria-labelledby="new-password-heading">
    <div class="card-head">
      <div>
        <span class="eyebrow">One-time password</span>
        <h2 id="new-password-heading">Save this password now</h2>
        <p class="subtle">Created for <strong><?=hApp($created['label'])?></strong>. It won't be displayed again after you leave or refresh this page.</p>
      </div>
    </div>
    <div class="code-secret"><code><?=hApp($created['password'])?></code></div>
    <p class="card-caption">Use username <strong><?=hApp($davUsername)?></strong> with this password in the CardDAV client. Store it in a password manager.</p>
  </section>
  <?php endif; ?>

  <section class="stacked-cards" aria-label="Credential settings">
    <div class="card">
      <div class="card-head">
        <div>
          <h2>Create app password</h2>
          <p class="subtle">Label it so you can recognize and revoke it later.</p>
        </div>
      </div>
      <form method="post" action="<?=hApp($base . '/app-passwords.php')?>">
        <input type="hidden" name="csrf" value="<?=hApp(DashboardAuth::csrf($user))?>">
        <input type="hidden" name="action" value="create">
        <div class="field">
          <label class="field-label" for="label">Client name</label>
          <input type="text" id="label" name="label" placeholder="Office laptop" minlength="2" maxlength="60" required>
        </div>
        <p class="card-caption">For example: Office laptop, Reception desktop, or Home computer.</p>
        <div class="action-row">
          <button type="submit" class="btn">Generate password</button>
        </div>
      </form>
    </div>
    <div class="card">
      <div class="card-head">
        <div>
          <h2>Connection details</h2>
          <p class="subtle">Your address-book URL and username stay the same across app passwords.</p>
        </div>
      </div>
      <div class="endpoint">
        <span class="endpoint-label">Server</span>
        <code><?=hApp($base . '/carddav/')?></code>
      </div>
      <div class="endpoint">
        <span class="endpoint-label">Username</span>
        <code><?=hApp($davUsername)?></code>
      </div>
      <p class="card-caption">All client access is read-only over HTTPS. Client passwords do not grant dashboard access.</p>
    </div>
  </section>

  <section class="card" aria-labelledby="active-clients-heading">
    <div class="card-head">
      <div>
        <h2 id="active-clients-heading">Active app passwords</h2>
        <p class="subtle"><?=count($apps)?> client<?=count($apps) === 1 ? '' : 's' ?> currently authorized.</p>
      </div>
    </div>
    <?php if (!$apps): ?>
    <div class="empty-state">
      <strong>No app passwords yet</strong>
      <p>Generate one above when you're ready to connect a client.</p>
    </div>
    <?php else: ?>
    <div class="table-scroll">
      <table>
        <thead><tr><th>Client</th><th>Created (UTC)</th><th>Access</th></tr></thead>
        <tbody>
        <?php foreach ($apps as $app): ?>
        <tr>
          <td><span class="cell-name"><?=hApp($app['label'])?></span></td>
          <td><?=hApp($app['created_at'])?></td>
          <td>
            <a class="btn compact danger" href="<?=hApp($base . '/app-passwords.php?revoke=' . rawurlencode($app['id']))?>" aria-label="Review revocation for <?=hApp($app['label'])?>">Revoke</a>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="card-caption">Revocation takes effect immediately. You can issue a new password at any time.</p>
    <?php endif; ?>
  </section>

  <section class="card" aria-labelledby="migration-heading">
    <div class="card-head">
      <div>
        <h2 id="migration-heading">Shared-password migration</h2>
        <p class="subtle">Your original CardDAV password can stay active while you move clients over.</p>
      </div>
      <span class="status-tag <?= $legacyActive ? 'warning' : 'good' ?>"><?= $legacyActive ? 'Enabled' : 'Disabled' ?></span>
    </div>
    <?php if ($legacyActive): ?>
    <div class="alert info">Existing clients will keep working. Once every client has its own tested app password, you can disable the shared password in your private server configuration.</div>
    <p class="small-print">To disable the old credential after migration, set <code>CARDDAV_LEGACY_ENABLED=0</code> in your private <code>.env</code>. Do not change the CardDAV username or disable the shared password before testing replacements.</p>
    <?php else: ?>
    <div class="alert success">The shared CardDAV password is disabled. Only active app passwords can authenticate.</div>
    <?php endif; ?>
  </section>

  <footer class="footline">
    <span>Private access settings · Credentials are stored as password hashes</span>
    <a href="https://github.com/PoolProFlorida/pool-founder-contact-sync" rel="noopener noreferrer">Pool Pro Florida · Community Over Competition ↗</a>
  </footer>
</main>
</body>
</html>
