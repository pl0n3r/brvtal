<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/password_rate_limit.php';

function auth_rate_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "AUTH RATE LIMIT CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$passwordSource = (string)file_get_contents(__DIR__ . '/../config/password_rate_limit.php');
$totpSource = (string)file_get_contents(__DIR__ . '/../config/totp_auth.php');

auth_rate_expect(str_contains($passwordSource, "fopen(\$file, 'c+')"), 'password failures must use a read/write handle');
auth_rate_expect(str_contains($passwordSource, 'flock($handle, LOCK_EX)'), 'password failure updates must hold an exclusive lock across read-modify-write');
auth_rate_expect(str_contains($passwordSource, 'ftruncate($handle, 0)'), 'password failure state must be replaced while holding the lock');
auth_rate_expect(str_contains($totpSource, "fopen(\$file, 'c+')"), 'TOTP failures must use a read/write handle');
auth_rate_expect(str_contains($totpSource, 'flock($handle, LOCK_EX)'), 'TOTP failure updates must hold an exclusive lock across read-modify-write');
auth_rate_expect(str_contains($totpSource, 'ftruncate($handle, 0)'), 'TOTP failure state must be replaced while holding the lock');
auth_rate_expect(str_contains($totpSource, "brvtal_totp_rate_limit_reset((int)\$admin['id'])"), 'successful TOTP login must reset the login-scope failure budget');

$resetOffset = strpos($totpSource, "brvtal_totp_rate_limit_reset((int)\$admin['id'])");
$sessionOffset = strpos($totpSource, 'brvtal_admin_login_session((int)$admin[\'id\'])');
auth_rate_expect($resetOffset !== false && $sessionOffset !== false && $resetOffset < $sessionOffset, 'TOTP failure budget must reset before the authenticated session is completed');

$tempDir = sys_get_temp_dir() . '/brvtal-password-rate-' . bin2hex(random_bytes(5));
$now = 1_800_100_000;
$email = 'admin@example.test';
$ip = '127.0.0.77';
try {
    for ($i = 0; $i < BRVTAL_PASSWORD_RATE_LIMIT_MAX_FAILURES; $i++) {
        $state = brvtal_password_rate_limit_failure($email, $tempDir, $now + $i, $ip);
        auth_rate_expect($state['limited'] === false, 'password failures below the threshold remain allowed');
        auth_rate_expect(count($state['attempts']) === $i + 1, 'every password failure is persisted exactly once');
    }

    $blocked = brvtal_password_rate_limit_failure($email, $tempDir, $now + BRVTAL_PASSWORD_RATE_LIMIT_MAX_FAILURES, $ip);
    auth_rate_expect($blocked['limited'] === true, 'the next password failure activates the block');
    auth_rate_expect((int)$blocked['retry_after'] > 0, 'blocked password state exposes a retry window');

    brvtal_password_rate_limit_reset($email, $tempDir, $ip);
    $reset = brvtal_password_rate_limit_check($email, $tempDir, $now + BRVTAL_PASSWORD_RATE_LIMIT_MAX_FAILURES + 1, $ip);
    auth_rate_expect($reset['limited'] === false && $reset['attempts'] === [], 'successful password authentication can clear the failure budget');
} finally {
    $file = brvtal_password_rate_limit_path($email, $tempDir, $ip);
    @unlink($file);
    @rmdir($tempDir);
}

echo "Authentication rate-limit contract passed.\n";
