<?php
declare(strict_types=1);
require __DIR__ . '/src/Config.php';
require __DIR__ . '/src/Http.php';
require __DIR__ . '/src/DashboardAuth.php';
require __DIR__ . '/src/LoginLimiter.php';

use PoolProFlorida\Config;
use PoolProFlorida\DashboardAuth;
use PoolProFlorida\Http;
use PoolProFlorida\LoginLimiter;

if (!DashboardAuth::enabled()) {
    header('Location: ' . Http::base() . '/', true, 303);
    exit;
}
DashboardAuth::start();
header('X-Robots-Tag: noindex, nofollow, noarchive');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('Cache-Control: private, no-store');

if (DashboardAuth::signedIn()) {
    header('Location: ' . Http::base() . '/', true, 303);
    exit;
}

$error = '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
    $token = is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : '';
    if (!DashboardAuth::validCsrf(Config::get('DASHBOARD_USERNAME'), $token)) {
        http_response_code(403);
        exit("Invalid login form token.\n");
    }
    $name = is_string($_POST['username'] ?? null) ? $_POST['username'] : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    try {
        if (LoginLimiter::blocked()) {
            $error = 'Too many login attempts. Try again in 15 minutes.';
            http_response_code(429);
        } elseif (DashboardAuth::logIn($name, $password)) {
            LoginLimiter::clear();
            header('Location: ' . Http::base() . '/', true, 303);
            exit;
        } else {
            LoginLimiter::failed();
            $error = 'Invalid username or password.';
        }
    } catch (Throwable $e) {
        error_log('Dashboard login protection failed: ' . get_class($e));
        http_response_code(503);
        $error = 'Login is temporarily unavailable. Check private storage permissions.';
    }
} elseif ($method !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Method not allowed.');
}

$base = Http::base();
function escapeLogin(string $value): string { return Http::escape($value); }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Dashboard sign in — Pool Founder Contact Sync</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f2f7fa;color:#163442;font:16px/1.5 system-ui,-apple-system,Segoe UI,sans-serif}
main{max-width:430px;margin:8vh auto;padding:16px}
section{background:white;border:1px solid #dce6ec;border-radius:16px;padding:30px;box-shadow:0 10px 32px #153c4d15}
h1{font-size:26px;margin:0 0 6px}p{color:#526b78}label{display:block;font-weight:700;margin-top:16px}
input{display:block;width:100%;margin-top:5px;padding:12px;border:1px solid #b8cbd5;border-radius:8px;font:inherit}
button{background:#076a93;color:white;border:0;border-radius:9px;padding:12px 18px;width:100%;margin-top:22px;font:inherit;font-weight:700;cursor:pointer}
.error{padding:12px;background:#fff1ef;border-left:4px solid #a32f25;color:#84271f}
</style>
</head>
<body><main><section>
<h1>Pool Founder Contact Sync</h1>
<p>Sign in to the private dashboard.</p>
<?php if ($error !== ''): ?><p class="error"><?=escapeLogin($error)?></p><?php endif; ?>
<form method="post" action="<?=escapeLogin($base . '/login.php')?>">
<input type="hidden" name="csrf" value="<?=escapeLogin(DashboardAuth::csrf(Config::get('DASHBOARD_USERNAME')))?>">
<label for="username">Username</label>
<input id="username" name="username" autocomplete="username" required maxlength="120" autofocus>
<label for="password">Password</label>
<input id="password" type="password" name="password" autocomplete="current-password" required>
<button type="submit">Sign in</button>
</form>
</section></main></body></html>
