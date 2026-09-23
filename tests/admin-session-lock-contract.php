<?php
declare(strict_types=1);

function session_lock_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("ADMIN SESSION LOCK CONTRACT FAILED: {$message}");
    }
}

session_lock_expect(function_exists('proc_open'), 'proc_open is required to validate real concurrent session locking');

$root = dirname(__DIR__);
$temp = sys_get_temp_dir() . '/brvtal-session-lock-' . bin2hex(random_bytes(6));
session_lock_expect(mkdir($temp, 0700, true), 'temporary session directory could not be created');

$configPath = $root . '/config/config.php';
$createdConfig = !is_file($configPath);
if ($createdConfig) {
    $configSource = <<<'PHP'
<?php
return [
    'app' => ['timezone' => 'America/Bogota'],
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'brvtal_session_lock_contract',
        'user' => 'unused',
        'pass' => 'unused',
        'charset' => 'utf8mb4',
    ],
    'security' => [
        'session_name' => 'BRVTAL_ADMIN_LOCK_TEST',
        'csrf_key' => 'contract-only-csrf-key',
    ],
];
PHP;
    session_lock_expect(
        file_put_contents($configPath, $configSource, LOCK_EX) !== false,
        'temporary config/config.php could not be created'
    );
}

$sessionId = 'brvtalsharedreadsession';
$seed = $temp . '/sess_' . $sessionId;
session_lock_expect(
    file_put_contents($seed, '', LOCK_EX) !== false,
    'strict-mode session seed could not be created'
);

$worker = $temp . '/worker.php';
$source = <<<'PHP'
<?php
declare(strict_types=1);
[$script,$root,$savePath,$sessionId,$marker,$sleepMicros] = $argv;
ini_set('session.save_path', $savePath);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTPS'] = 'on';
require $root . '/config/admin_auth.php';
session_id($sessionId);
brvtal_admin_session_start();
brvtal_admin_release_session();
file_put_contents($marker, (string)microtime(true), LOCK_EX);
usleep((int)$sleepMicros);
PHP;
session_lock_expect(
    file_put_contents($worker, $source, LOCK_EX) !== false,
    'worker script could not be created'
);

$descriptors = [
    0 => ['pipe','r'],
    1 => ['pipe','w'],
    2 => ['pipe','w'],
];
$spawn = static function (string $marker, int $sleepMicros) use ($worker, $root, $temp, $sessionId, $descriptors) {
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, $worker, $root, $temp, $sessionId, $marker, (string)$sleepMicros],
        $descriptors,
        $pipes
    );
    if (!is_resource($process)) {
        throw new RuntimeException('ADMIN SESSION LOCK CONTRACT FAILED: worker process could not start');
    }
    fclose($pipes[0]);
    return [$process,$pipes];
};

$drain = static function ($process, array $pipes): array {
    $stdout = '';
    $stderr = '';
    if (isset($pipes[1]) && is_resource($pipes[1])) {
        $stdout = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
    }
    if (isset($pipes[2]) && is_resource($pipes[2])) {
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[2]);
    }
    $status = proc_close($process);
    return [$status,$stdout,$stderr];
};

$markerA = $temp . '/a';
$markerB = $temp . '/b';
$processA = null;
$processB = null;
$pipesA = [];
$pipesB = [];

try {
    [$processA,$pipesA] = $spawn($markerA, 1500000);

    $deadline = microtime(true) + 1.0;
    while (!is_file($markerA) && microtime(true) < $deadline) {
        usleep(10000);
    }
    if (!is_file($markerA)) {
        [$statusA,$stdoutA,$stderrA] = $drain($processA,$pipesA);
        $processA = null;
        throw new RuntimeException(
            'ADMIN SESSION LOCK CONTRACT FAILED: first reader did not reach the released-session marker'
            . " (exit={$statusA}; stdout=" . trim($stdoutA) . '; stderr=' . trim($stderrA) . ')'
        );
    }

    $startedB = microtime(true);
    [$processB,$pipesB] = $spawn($markerB, 0);
    $deadlineB = $startedB + 0.8;
    while (!is_file($markerB) && microtime(true) < $deadlineB) {
        usleep(10000);
    }
    $elapsedB = microtime(true) - $startedB;

    if (is_resource($processB)) {
        [$statusB,$stdoutB,$stderrB] = $drain($processB,$pipesB);
        $processB = null;
        session_lock_expect(
            $statusB === 0,
            'second reader failed: ' . trim($stdoutB . ' ' . $stderrB)
        );
    }
    if (is_resource($processA)) {
        [$statusA,$stdoutA,$stderrA] = $drain($processA,$pipesA);
        $processA = null;
        session_lock_expect(
            $statusA === 0,
            'first reader failed: ' . trim($stdoutA . ' ' . $stderrA)
        );
    }

    session_lock_expect(
        is_file($markerB) && $elapsedB < 0.8,
        'a second reader using the same PHP session was serialized behind the first reader'
    );
} finally {
    if (is_resource($processB)) {
        proc_terminate($processB);
        $drain($processB,$pipesB);
    }
    if (is_resource($processA)) {
        proc_terminate($processA);
        $drain($processA,$pipesA);
    }
    foreach ([$markerA,$markerB,$worker,$seed] as $path) {
        if (is_file($path)) @unlink($path);
    }
    @rmdir($temp);
    if ($createdConfig && is_file($configPath)) {
        @unlink($configPath);
    }
}

echo "BRVTAL concurrent Admin session-read lock contract passed.\n";
