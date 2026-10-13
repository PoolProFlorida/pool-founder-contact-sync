<?php
declare(strict_types=1);
/**
 * Test-only router for PHP's built-in HTTP server.
 * Never use it on production hosting. Apache .htaccess protections are NOT
 * evaluated by this router and must be checked separately on a real server.
 */
if (PHP_SAPI !== 'cli-server') {
    http_response_code(403);
    exit;
}

$uri = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (!is_string($uri)) {
    http_response_code(400);
    exit;
}

$root = '/pool-founder-contact-sync';
$_SERVER['HTTPS'] = 'on'; // Test only: simulate HTTPS termination.
if ($uri === $root || $uri === $root . '/' || $uri === $root . '/index.php') {
    $_SERVER['SCRIPT_NAME'] = $root . '/index.php';
    require dirname(__DIR__) . '/index.php';
    return true;
}
foreach (['login.php', 'logout.php', 'app-passwords.php'] as $page) {
    if ($uri === $root . '/' . $page) {
        $_SERVER['SCRIPT_NAME'] = $root . '/' . $page;
        require dirname(__DIR__) . '/' . $page;
        return true;
    }
}
if ($uri === $root . '/assets/admin.css') {
    header('Content-Type: text/css; charset=utf-8');
    readfile(dirname(__DIR__) . '/assets/admin.css');
    return true;
}
if ($uri === $root . '/carddav' || str_starts_with($uri, $root . '/carddav/')) {
    $_SERVER['SCRIPT_NAME'] = $root . '/carddav.php';
    require dirname(__DIR__) . '/carddav.php';
    return true;
}
http_response_code(404);
echo "Not found.\n";
return true;
