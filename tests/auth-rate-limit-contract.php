<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/password_rate_limit.php';
require_once __DIR__ . '/../config/totp_rate_limit.php';

function auth_rate_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "AUTH RATE LIMIT CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

/** @return array<int,array{process:resource,pipes:array<int,resource>}> */
function auth_rate_spawn_workers(
    string $worker,
    string $type,
    string $directory,
    int $count,
    int $now,
    string $ip
): array {
    $workers = [];
    $root = dirname(__DIR__);
    for ($i = 0; $i < $count; $i++) {
        $command = [PHP_BINARY, $worker, $root, $type, $directory, (string)$now, $ip];
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        auth_rate_expect(is_resource($process), "{$type} concurrent worker {$i} must start");
        $workers[] = ['process' => $process, 'pipes' => $pipes];
    }
    return $workers;
}

function auth_rate_wait_workers(array $workers, string $type): void
{
    foreach ($workers as $index => $worker) {
        $stdout = stream_get_contents($worker['pipes'][1]);
        $stderr = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        $exit = proc_close($worker['process']);
        auth_rate_expect(
            $exit === 0,
            "{$type} concurrent worker {$index} must exit cleanly; stdout={$stdout}; stderr={$stderr}"
        );
    }
}

function auth_rate_cleanup_directory(string $directory): void
{
    foreach (glob($directory . '/*') ?: [] as $path) {
        if (is_file($path) || is_link($path)) @unlink($path);
    }
    @rmdir($directory);
}

$passwordSource = (string)file_get_contents(__DIR__ . '/../config/password_rate_limit.php');
$totpSource = (string)file_get_contents(__DIR__ . '/../config/totp_auth.php');
$totpRateSource = (string)file_get_contents(__DIR__ . '/../config/totp_rate_limit.php');
$storeSource = (string)file_get_contents(__DIR__ . '/../config/rate_limit_store.php');

auth_rate_expect(str_contains($storeSource, "return \$file . '.lock';"), 'rate-limit storage must use a stable sibling lock inode');
auth_rate_expect(str_contains($storeSource, "fopen(\$temporary, 'x+b')"), 'rate-limit state must be staged in a unique temporary file');
auth_rate_expect(str_contains($storeSource, 'while ($offset < $length)'), 'rate-limit persistence must handle short writes');
auth_rate_expect(str_contains($storeSource, 'if ($offset !== $length) $ok = false;'), 'rate-limit persistence must require the complete payload');
auth_rate_expect(str_contains($storeSource, 'if ($ok && !fflush($handle)) $ok = false;'), 'rate-limit persistence must require fflush success');
auth_rate_expect(str_contains($storeSource, '@rename($temporary, $file)'), 'validated rate-limit state must replace the target atomically');
auth_rate_expect(str_contains($passwordSource, 'flock($lock, LOCK_EX)'), 'password failure updates must hold the shared stable lock across read-modify-write');
auth_rate_expect(str_contains($passwordSource, 'brvtal_rate_limit_store_write($file, $data)'), 'password failures must use atomic persistence');
auth_rate_expect(str_contains($totpRateSource, 'flock($lock, LOCK_EX)'), 'TOTP failure updates must hold the shared stable lock across read-modify-write');
auth_rate_expect(str_contains($totpRateSource, 'brvtal_rate_limit_store_write($file, $data)'), 'TOTP failures must use atomic persistence');
auth_rate_expect(str_contains($totpSource, "brvtal_totp_rate_limit_reset((int)\$admin['id'])"), 'successful TOTP login must reset the login-scope failure budget');

$resetOffset = strpos($totpSource, "brvtal_totp_rate_limit_reset((int)\$admin['id'])");
$sessionOffset = strpos($totpSource, 'brvtal_admin_login_session((int)$admin[\'id\'])');
auth_rate_expect($resetOffset !== false && $sessionOffset !== false && $resetOffset < $sessionOffset, 'TOTP failure budget must reset before the authenticated session is completed');

$tempDir = sys_get_temp_dir() . '/brvtal-auth-rate-' . bin2hex(random_bytes(6));
auth_rate_expect(@mkdir($tempDir, 0700, true), 'isolated authentication rate-limit test directory must be created');
$worker = $tempDir . '/worker.php';
$workerSource = <<<'PHP'
<?php
declare(strict_types=1);

$root = $argv[1] ?? '';
$type = $argv[2] ?? '';
$directory = $argv[3] ?? '';
$now = (int)($argv[4] ?? 0);
$ip = (string)($argv[5] ?? '127.0.0.1');

if ($type === 'password') {
    require_once $root . '/config/password_rate_limit.php';
    $state = brvtal_password_rate_limit_failure('admin@example.test', $directory, $now, $ip);
    exit(isset($state['limited']) ? 0 : 2);
}
if ($type === 'totp') {
    require_once $root . '/config/totp_rate_limit.php';
    $state = brvtal_totp_rate_limit_failure(42, 'login', $directory, $now, $ip);
    exit(isset($state['limited']) ? 0 : 3);
}
exit(4);
PHP;
auth_rate_expect(file_put_contents($worker, $workerSource) === strlen($workerSource), 'concurrency worker fixture must be written completely');

$now = 1_800_100_000;
$passwordIp = '127.0.0.77';
$totpIp = '127.0.0.78';
try {
    $passwordWorkers = auth_rate_spawn_workers(
        $worker,
        'password',
        $tempDir,
        BRVTAL_PASSWORD_RATE_LIMIT_MAX_FAILURES + 1,
        $now,
        $passwordIp
    );
    auth_rate_wait_workers($passwordWorkers, 'password');
    $passwordState = brvtal_password_rate_limit_check('admin@example.test', $tempDir, $now + 1, $passwordIp);
    auth_rate_expect(count($passwordState['attempts']) === BRVTAL_PASSWORD_RATE_LIMIT_MAX_FAILURES + 1, 'concurrent password failures must not lose attempts');
    auth_rate_expect($passwordState['limited'] === true, 'concurrent password failures must activate the blocking threshold');

    $totpWorkers = auth_rate_spawn_workers(
        $worker,
        'totp',
        $tempDir,
        BRVTAL_TOTP_RATE_LIMIT_MAX_FAILURES + 1,
        $now,
        $totpIp
    );
    auth_rate_wait_workers($totpWorkers, 'totp');
    $totpState = brvtal_totp_rate_limit_state(42, 'login', $tempDir, $now + 1, $totpIp);
    auth_rate_expect(count($totpState['attempts']) === BRVTAL_TOTP_RATE_LIMIT_MAX_FAILURES + 1, 'concurrent TOTP failures must not lose attempts');
    auth_rate_expect($totpState['limited'] === true, 'concurrent TOTP failures must activate the blocking threshold');

    auth_rate_expect(brvtal_totp_rate_limit_reset(42, 'login', $tempDir, $totpIp), 'TOTP reset must persist successfully in the isolated store');
    $totpReset = brvtal_totp_rate_limit_state(42, 'login', $tempDir, $now + 2, $totpIp);
    auth_rate_expect($totpReset['limited'] === false && $totpReset['attempts'] === [], 'successful TOTP verification can clear the login failure budget');

    auth_rate_expect(brvtal_password_rate_limit_reset('admin@example.test', $tempDir, $passwordIp), 'password reset must persist successfully in the isolated store');
    $passwordReset = brvtal_password_rate_limit_check('admin@example.test', $tempDir, $now + 2, $passwordIp);
    auth_rate_expect($passwordReset['limited'] === false && $passwordReset['attempts'] === [], 'successful password authentication can clear the failure budget');
} finally {
    auth_rate_cleanup_directory($tempDir);
}

echo "Authentication rate-limit contract passed.\n";
