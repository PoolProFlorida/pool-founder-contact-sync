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
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
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
            if (!AppPasswords::revoke($id)) {
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
function hApp(string $value): string { return Http::escape($value); }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>CardDAV app passwords — Pool Founder Contact Sync</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f3f7fa;color:#173141;font:15px/1.5 system-ui,-apple-system,Segoe UI,sans-serif}
main{max-width:900px;margin:30px auto;padding:16px}
section{background:#fff;border:1px solid #dce7ed;border-radius:14px;padding:22px;margin:16px 0}
h1{font-size:28px;margin:8px 0}h2{font-size:19px;margin:0 0 10px}
input{font:inherit;padding:11px;border:1px solid #b7ccd7;border-radius:8px;max-width:100%}
button{font:inherit;background:#066d9b;color:white;border:0;border-radius:8px;padding:10px 16px;cursor:pointer}
button.danger{background:#8d342e}
p{margin:10px 0}a{color:#065f88}.muted{color:#546d78}
table{border-collapse:collapse;width:100%}td,th{text-align:left;border-bottom:1px solid #e3ebef;padding:11px 8px}
.code{padding:15px;background:#102c3a;color:#e9fcff;border-radius:8px;overflow-wrap:anywhere;font-size:17px}
.good{padding:13px;background:#effaef;border-left:4px solid #278c4b}
.error{padding:13px;background:#ffefeb;border-left:4px solid #b03323}
form.inline{display:inline}.row{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
</style>
</head>
<body><main>
<p><a href="<?=hApp($base . '/')?>">← Dashboard</a></p>
<h1>CardDAV app passwords</h1>
<p class="muted">Each client gets a separate read-only password. You can revoke a client without changing dashboard access or other clients.</p>
<?php if ($error !== ''): ?><p class="error"><?=hApp($error)?></p><?php endif; ?>
<?php if (isset($_GET['revoked'])): ?><p class="good">The app password was revoked.</p><?php endif; ?>
<?php if ($created !== null): ?>
<section>
<h2>Copy this new password now</h2>
<p class="good">App password created for <strong><?=hApp($created['label'])?></strong>. This password is shown only once. Store it securely.</p>
<p class="muted">Use the configured CardDAV username and the password below for this one client:</p>
<div class="code"><code><?=hApp($created['password'])?></code></div>
<p class="muted">The server stores only its password hash. Reloading this page will not show it again.</p>
</section>
<?php endif; ?>
<section>
<h2>Create app password</h2>
<form method="post" action="<?=hApp($base . '/app-passwords.php')?>" class="row">
<input type="hidden" name="csrf" value="<?=hApp(DashboardAuth::csrf($user))?>">
<input type="hidden" name="action" value="create">
<label for="label">Client name</label>
<input type="text" id="label" name="label" placeholder="Office laptop" minlength="2" maxlength="60" required>
<button type="submit">Generate password</button>
</form>
</section>
<section>
<h2>Active app passwords (<?=count($apps)?>)</h2>
<?php if (!$apps): ?><p class="muted">No active app passwords. The legacy CardDAV password remains available unless explicitly disabled.</p><?php else: ?>
<table><thead><tr><th>Client</th><th>Created (UTC)</th><th>Action</th></tr></thead><tbody>
<?php foreach ($apps as $app): ?><tr>
<td><?=hApp($app['label'])?></td><td><?=hApp($app['created_at'])?></td>
<td><form method="post" action="<?=hApp($base . '/app-passwords.php')?>" class="inline">
<input type="hidden" name="csrf" value="<?=hApp(DashboardAuth::csrf($user))?>">
<input type="hidden" name="action" value="revoke">
<input type="hidden" name="id" value="<?=hApp($app['id'])?>">
<button type="submit" class="danger">Revoke</button>
</form></td>
</tr><?php endforeach; ?>
</tbody></table>
<?php endif; ?>
</section>
<section>
<h2>Migrating existing clients</h2>
<p>Existing CardDAV login details continue to work by default. Create and verify a new app password before disabling the old shared password.</p>
<p>Once every client has moved to a new password, you may add <code>CARDDAV_LEGACY_ENABLED=0</code> to your local <code>.env</code>. This does not affect the dashboard login.</p>
</section>
</main></body></html>
