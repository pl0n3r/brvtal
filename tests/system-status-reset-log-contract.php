<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$logs = file_get_contents($root . '/discadmin/logs.php');
$technical = file_get_contents($root . '/discadmin/technical.php');
$js = file_get_contents($root . '/discadmin/system-status-v2.js');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$assert(str_contains($logs, "REQUEST_METHOD'] ?? 'GET') !== 'POST'"), 'Log reset must remain POST-only.');
$assert(str_contains($logs, "hash_equals((string)\$_SESSION['csrf'], \$csrf)"), 'Log reset must retain session CSRF validation.');
$assert(str_contains($logs, "file_put_contents(\$logFile, '', LOCK_EX)"), 'Log reset must clear through the canonical log file boundary with locking.');
$assert(str_contains($logs, "'LOG_CLEAR_FAILED'"), 'Log reset must report filesystem failure instead of optimistic success.');
$assert(str_contains($logs, "'format'] ?? '') === 'json'"), 'Log reset must expose the JSON response mode used by System Status.');
$assert(str_contains($technical, "'bytes'=>\$size"), 'Technical log diagnostics must expose current byte size.');
$assert(str_contains($js, "LOG_RESET = '/discadmin/logs.php?action=clear&format=json'"), 'System Status must reuse the canonical log reset endpoint.');
$assert(str_contains($js, "new URLSearchParams({csrf:token})"), 'System Status reset must send the CSRF token.');
$assert(str_contains($js, "globalThis.confirm('Reset the current BRVTAL debug log?"), 'System Status reset must require explicit confirmation.');

echo "System Status reset-log contract OK\n";
