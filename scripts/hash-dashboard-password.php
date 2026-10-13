<?php
declare(strict_types=1);

/**
 * Local-only, interactive password-hash generator.
 * Passwords are never accepted as command-line arguments or printed.
 */
if (PHP_SAPI !== 'cli' || !function_exists('stream_isatty') || !stream_isatty(STDIN)) {
    fwrite(STDERR, "Run interactively in a private SSH terminal: php scripts/hash-dashboard-password.php\n");
    exit(2);
}
if (count($argv) !== 1) {
    fwrite(STDERR, "Do not supply a password on the command line.\n");
    exit(2);
}

function hiddenPassword(string $prompt): string
{
    fwrite(STDERR, $prompt);
    $status = 1;
    @system('stty -echo 2>/dev/null', $status);
    if ($status !== 0) {
        fwrite(STDERR, "\nUnable to hide input; use a terminal with stty support.\n");
        exit(2);
    }
    try {
        $line = fgets(STDIN);
    } finally {
        @system('stty echo 2>/dev/null');
        fwrite(STDERR, "\n");
    }
    return is_string($line) ? rtrim($line, "\r\n") : '';
}

$first = hiddenPassword('New dashboard password (16+ characters): ');
$second = hiddenPassword('Confirm dashboard password: ');
if (strlen($first) < 16 || $first !== $second) {
    fwrite(STDERR, "Passwords must match and contain at least 16 characters.\n");
    exit(1);
}
echo password_hash($first, PASSWORD_DEFAULT), PHP_EOL;
fwrite(STDERR, "Copy only the generated hash into DASHBOARD_PASSWORD_HASH in your private .env file.\n");
