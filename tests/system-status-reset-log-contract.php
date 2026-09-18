<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_log.php';

$fail = static function (string $message): never {
    fwrite(STDERR, "System Status reset-log contract failed: {$message}\n");
    exit(1);
};
$assert = static function (bool $condition, string $message) use ($fail): void {
    if (!$condition) {
        $fail($message);
    }
};

$tmp = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR
    . 'brvtal-reset-log-' . bin2hex(random_bytes(8)) . '.log';

register_shutdown_function(static function () use ($tmp): void {
    if (is_file($tmp)) {
        unlink($tmp);
    }
});

$assert(file_put_contents($tmp, "before reset\n", LOCK_EX) !== false, 'temporary log fixture could not be created');

$method = brvtal_admin_log_clear_result('GET', 'csrf-token', 'csrf-token', $tmp);
$assert($method['status'] === 405, 'non-POST reset must return 405');
$assert(($method['payload']['error'] ?? '') === 'METHOD_NOT_ALLOWED', 'non-POST reset must expose METHOD_NOT_ALLOWED');
$assert(file_get_contents($tmp) === "before reset\n", 'non-POST reset must not mutate the log');

$csrf = brvtal_admin_log_clear_result('POST', 'csrf-token', 'wrong-token', $tmp);
$assert($csrf['status'] === 419, 'invalid CSRF reset must return 419');
$assert(($csrf['payload']['error'] ?? '') === 'CSRF', 'invalid CSRF reset must expose CSRF');
$assert(file_get_contents($tmp) === "before reset\n", 'invalid CSRF reset must not mutate the log');

$success = brvtal_admin_log_clear_result('POST', 'csrf-token', 'csrf-token', $tmp);
$assert($success['status'] === 200, 'valid reset must return 200');
$assert(($success['payload']['ok'] ?? false) === true, 'valid reset must report success');
$assert(($success['payload']['bytes'] ?? -1) === 0, 'valid reset must report zero bytes');
$assert(($success['payload']['lines'] ?? -1) === 0, 'valid reset must report zero lines');
$assert(file_get_contents($tmp) === '', 'valid reset must empty the temporary log');

$assert(file_put_contents($tmp, "preserve on failure\n", LOCK_EX) !== false, 'temporary failure fixture could not be restored');
$failure = brvtal_admin_log_clear_result(
    'POST',
    'csrf-token',
    'csrf-token',
    $tmp,
    static fn(string $path): bool => false
);
$assert($failure['status'] === 500, 'writer failure must return 500');
$assert(($failure['payload']['error'] ?? '') === 'LOG_CLEAR_FAILED', 'writer failure must expose LOG_CLEAR_FAILED');
$assert(file_get_contents($tmp) === "preserve on failure\n", 'writer failure must preserve the existing log');

unlink($tmp);

echo "System Status reset-log contract OK\n";
