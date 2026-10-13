<?php
declare(strict_types=1);
require __DIR__ . '/../src/Config.php';
require __DIR__ . '/../src/Http.php';
require __DIR__ . '/../src/DashboardAuth.php';
require __DIR__ . '/../src/AppPasswords.php';
require __DIR__ . '/../src/LoginLimiter.php';

use PoolProFlorida\AppPasswords;
use PoolProFlorida\DashboardAuth;
use PoolProFlorida\LoginLimiter;

$dir = sys_get_temp_dir() . '/pfcs-auth-test-' . bin2hex(random_bytes(8));
if (!mkdir($dir, 0700)) throw new RuntimeException('Cannot create test fixture directory');
define('PFCS_APP_PASSWORD_STORE', $dir . '/apps.json');
define('PFCS_LOGIN_LIMITER_STORE', $dir . '/attempts.json');

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
try {
    $one = AppPasswords::create('Laptop');
    check(strlen($one['password']) >= 40, 'App password is not sufficiently random');
    check(AppPasswords::verify($one['password']), 'Generated app password not accepted');
    check(!AppPasswords::verify('not-a-real-app-password'), 'Invalid app password accepted');
    check(count(AppPasswords::all()) === 1, 'Active app password list incorrect');
    $contents = (string)file_get_contents(AppPasswords::path());
    check(!str_contains($contents, $one['password']), 'Plaintext app password leaked into storage');
    check(!str_contains(json_encode(AppPasswords::all()), $one['password']), 'Plaintext app password leaked into list');
    $two = AppPasswords::create('Office laptop');
    check(AppPasswords::verify($two['password']), 'Second app password not accepted');
    check(AppPasswords::revoke($one['id']), 'Revocation failed');
    check(!AppPasswords::verify($one['password']), 'Revoked app password is still accepted');
    check(AppPasswords::verify($two['password']), 'Revocation unexpectedly disabled another client');
    check(!AppPasswords::revoke($one['id']), 'Double revocation should fail');
    check(count(AppPasswords::all()) === 1, 'Revoked client still shown as active');
    try {
        AppPasswords::create('x');
        throw new RuntimeException('Invalid app label accepted');
    } catch (InvalidArgumentException $expected) {
        // Expected.
    }

    putenv('DASHBOARD_USERNAME=testadmin');
    putenv('DASHBOARD_PASSWORD_HASH=' . password_hash('correct-passphrase-for-test', PASSWORD_DEFAULT));
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['SCRIPT_NAME'] = '/pool-founder-contact-sync/index.php';
    check(DashboardAuth::enabled(), 'Configured dashboard login did not activate');
    check(!DashboardAuth::signedIn(), 'Fresh session was already authenticated');
    check(!DashboardAuth::logIn('testadmin', 'wrong-password'), 'Incorrect dashboard password accepted');
    check(DashboardAuth::logIn('testadmin', 'correct-passphrase-for-test'), 'Correct dashboard password rejected');
    check(DashboardAuth::signedIn(), 'Authenticated session rejected');
    $token = DashboardAuth::csrf('testadmin');
    check(DashboardAuth::validCsrf('testadmin', $token), 'Session form token rejected');
    check(!DashboardAuth::validCsrf('testadmin', 'wrong'), 'Forged form token accepted');
    DashboardAuth::logOut();
    check(!DashboardAuth::signedIn(), 'Logged-out session accepted');

    $_SERVER['REMOTE_ADDR'] = '192.0.2.1';
    check(!LoginLimiter::blocked(), 'Brand-new IP is rate limited');
    for ($i = 0; $i < 5; $i++) LoginLimiter::failed();
    check(LoginLimiter::blocked(), 'Five failed logins did not trigger throttling');
    LoginLimiter::clear();
    check(!LoginLimiter::blocked(), 'Throttle was not cleared');
    $_SERVER['REMOTE_ADDR'] = '192.0.2.2';
    check(!LoginLimiter::blocked(), 'Independent origin incorrectly blocked');
    echo "Dashboard sessions, app-password revocation and login-throttle tests passed.\n";
} finally {
    foreach (glob($dir . '/*') ?: [] as $file) @unlink($file);
    @rmdir($dir);
}
