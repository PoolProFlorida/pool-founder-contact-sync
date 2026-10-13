<?php
declare(strict_types=1);
require __DIR__ . '/src/Config.php';
require __DIR__ . '/src/Http.php';
require __DIR__ . '/src/DashboardAuth.php';

use PoolProFlorida\Config;
use PoolProFlorida\DashboardAuth;
use PoolProFlorida\Http;

if (!DashboardAuth::enabled()) {
    http_response_code(404);
    exit('Not found.');
}
$user = DashboardAuth::guard();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}
$token = is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : '';
if (!DashboardAuth::validCsrf($user, $token)) {
    http_response_code(403);
    exit('Invalid form token.');
}
DashboardAuth::logOut();
header('Cache-Control: private, no-store');
header('Location: ' . Http::base() . '/login.php', true, 303);
exit;
