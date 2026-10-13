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

DashboardAuth::enabled();
DashboardAuth::start();
header('X-Robots-Tag: noindex, nofollow, noarchive');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
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
<title>Sign in | Pool Founder Contact Sync</title>
<link rel="stylesheet" href="<?=escapeLogin($base . '/assets/admin.css')?>">
</head>
<body class="login-screen">
<header class="login-top">
  <span class="brand-mark" aria-hidden="true">PF</span>
  <span class="eyebrow">Private administration</span>
  <h1>Welcome back</h1>
  <p class="lead">Pool Founder Contact Sync · Independent project by Pool Pro Florida</p>
</header>
<main class="login-wrap">
  <section class="login-card" aria-label="Dashboard sign in">
    <?php if ($error !== ''): ?><div class="alert error" role="alert"><?=escapeLogin($error)?></div><?php endif; ?>
    <form method="post" action="<?=escapeLogin($base . '/login.php')?>">
      <input type="hidden" name="csrf" value="<?=escapeLogin(DashboardAuth::csrf(Config::get('DASHBOARD_USERNAME')))?>">
      <div class="field">
        <label class="field-label" for="username">Username</label>
        <input id="username" name="username" autocomplete="username" required maxlength="120" autofocus>
      </div>
      <div class="field">
        <label class="field-label" for="password">Password</label>
        <input id="password" type="password" name="password" autocomplete="current-password" required>
      </div>
      <button type="submit" class="btn">Sign in</button>
    </form>
  </section>
  <p class="login-foot">Private connection · Encrypted HTTPS session</p>
</main>
</body>
</html>
