<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/sentry.php';

putenv('BRVTAL_SENTRY_DSN');

function sentry_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "SENTRY CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

/** @return array{exit:int,stdout:string,stderr:string} */
function sentry_run_fixture(string $body): array
{
    $path = tempnam(sys_get_temp_dir(), 'brvtal-sentry-fixture-');
    if ($path === false) {
        throw new RuntimeException('Unable to create fixture file.');
    }
    file_put_contents($path, "<?php\ndeclare(strict_types=1);\n" . $body);

    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, $path],
        [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes
    );
    if (!is_resource($process)) {
        @unlink($path);
        throw new RuntimeException('Unable to start PHP fixture.');
    }

    try {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        return [
            'exit' => $exit,
            'stdout' => $stdout === false ? '' : $stdout,
            'stderr' => $stderr === false ? '' : $stderr,
        ];
    } finally {
        @unlink($path);
    }
}

function sentry_temp_path(string $prefix): string
{
    $path = tempnam(sys_get_temp_dir(), $prefix);
    if ($path === false) {
        throw new RuntimeException('Unable to create temporary path.');
    }
    @unlink($path);
    return $path;
}

function sentry_fixture_code(
    string $root,
    string $capture,
    string $log,
    string $extra,
    ?string $status = null
): string {
    $template = <<<'PHP'
define('BRVTAL_SENTRY_TESTING', true);
$root = __ROOT__;
$captureFile = __CAPTURE__;
$logFile = __LOG__;
$statusFile = __STATUS__;
putenv('BRVTAL_SENTRY_DSN=https://public123@ingest.example.test/42');
$GLOBALS['brvtalSentryTestLogFile'] = $logFile;
$GLOBALS['brvtalSentryTestResolver'] = static fn(string $host): array => ['8.8.8.8'];
$GLOBALS['brvtalSentryTestSender'] = static function (
    array $runtime,
    array $headers,
    string $body,
    float $timeout
) use ($captureFile): bool {
    file_put_contents($captureFile, "capture\n", FILE_APPEND);
    return true;
};
__STATUS_HOOK__
__EXTRA__
PHP;
    $statusHook = $status === null
        ? ''
        : <<<'PHP'
register_shutdown_function(static function () use ($statusFile): void {
    file_put_contents($statusFile, (string)http_response_code());
});
PHP;

    return strtr($template, [
        '__ROOT__' => var_export($root, true),
        '__CAPTURE__' => var_export($capture, true),
        '__LOG__' => var_export($log, true),
        '__STATUS__' => var_export($status ?? '', true),
        '__STATUS_HOOK__' => $statusHook,
        '__EXTRA__' => $extra,
    ]);
}

$publicResolver = static fn(string $host): array => ['8.8.8.8', '2606:4700:4700::1111'];
$valid = brvtalSentryParseDsn('https://public123@ingest.example.test/42', $publicResolver);
sentry_expect(is_array($valid), 'valid HTTPS DSN must parse');
sentry_expect($valid['endpoint'] === 'https://ingest.example.test/api/42/envelope/', 'endpoint must derive from DSN');
sentry_expect($valid['public_key'] === 'public123', 'public key must derive from DSN');
sentry_expect(
    $valid['addresses'] === ['2606:4700:4700::1111', '8.8.8.8'],
    'all public A/AAAA results must be retained deterministically'
);
sentry_expect(
    brvtalSentryPinnedEndpoint($valid) === 'https://[2606:4700:4700::1111]/api/42/envelope/',
    'stream transport must pin a validated IP'
);
sentry_expect(
    brvtalSentryCurlResolveEntry($valid) === 'ingest.example.test:443:[2606:4700:4700::1111]',
    'curl transport must pin DNS while preserving hostname TLS'
);

foreach ([
    'http://public123@ingest.example.test/42',
    'https://public123:secret@ingest.example.test/42',
    'https://public123@127.0.0.1/42',
    'https://public123@localhost/42',
    'https://public123@ingest.example.test/not-a-project',
    'https://short@ingest.example.test/42',
    'https://public123@ingest.example.test/42?debug=1',
] as $invalid) {
    sentry_expect(
        brvtalSentryParseDsn($invalid, $publicResolver) === null,
        'invalid DSN must fail closed'
    );
}
sentry_expect(
    brvtalSentryParseDsn(
        'https://public123@ingest.example.test/42',
        static fn(string $host): array => []
    ) === null,
    'DNS lookup failure must disable reporting'
);
sentry_expect(
    brvtalSentryParseDsn(
        'https://public123@ingest.example.test/42',
        static fn(string $host): array => ['8.8.8.8', '127.0.0.1']
    ) === null,
    'any private or reserved DNS answer must reject the DSN'
);

$secretMessage = 'person@example.test 203.0.113.7 token=secret-value';
$exception = new RuntimeException($secretMessage);
$event = brvtalSentryExceptionEvent($exception);
$encoded = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
sentry_expect(preg_match('/^[0-9a-f]{32}$/', (string)$event['event_id']) === 1, 'event id must be 32 hex chars');
sentry_expect(($event['environment'] ?? null) === 'production', 'environment must be production');
sentry_expect(($event['release'] ?? null) === 'brvtal@' . BRVTAL_APP_VERSION, 'release must use product version');
sentry_expect(($event['exception']['values'][0]['type'] ?? null) === 'RuntimeException', 'exception class must remain useful');
sentry_expect(($event['exception']['values'][0]['value'] ?? null) === 'Unhandled exception', 'remote value must be generic');
sentry_expect(!str_contains($encoded, $secretMessage), 'raw exception message must never leave the server');
sentry_expect(!str_contains($encoded, 'person@example.test'), 'remote event must not contain contact data');
sentry_expect(!str_contains($encoded, '203.0.113.7'), 'remote event must not contain network identity');
sentry_expect(!str_contains($encoded, 'secret-value'), 'remote event must not contain secret-like values');
sentry_expect(!array_key_exists('request', $event), 'remote event must omit request object');
sentry_expect(!array_key_exists('user', $event), 'remote event must omit user object');

$frames = $event['exception']['values'][0]['stacktrace']['frames'] ?? [];
sentry_expect(is_array($frames) && $frames !== [], 'exception must contain bounded stack frames');
foreach ($frames as $frame) {
    sentry_expect(!array_key_exists('vars', $frame), 'stack frame must omit local variables');
    sentry_expect(!array_key_exists('args', $frame), 'stack frame must omit arguments');
    $filename = (string)($frame['filename'] ?? '');
    sentry_expect(
        $filename === '[external]' || !str_starts_with($filename, '/'),
        'stack file must never expose absolute server path'
    );
}

$fatal = brvtalSentryFatalEvent([
    'type' => E_ERROR,
    'message' => $secretMessage,
    'file' => __FILE__,
    'line' => __LINE__,
]);
$fatalEncoded = json_encode($fatal, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
sentry_expect(($fatal['level'] ?? null) === 'fatal', 'fatal event must use fatal level');
sentry_expect(!str_contains($fatalEncoded, $secretMessage), 'fatal remote event must omit raw message');

$captured = [];
$sender = static function (
    array $runtime,
    array $headers,
    string $body,
    float $timeout
) use (&$captured): bool {
    $captured = compact('runtime', 'headers', 'body', 'timeout');
    return true;
};
$config = ['observability' => ['sentry_dsn' => 'https://public123@ingest.example.test/42']];
sentry_expect(
    brvtalSentryCaptureException($exception, $config, $sender, $publicResolver),
    'fake transport must report success'
);
sentry_expect(
    ($captured['runtime']['endpoint'] ?? null) === 'https://ingest.example.test/api/42/envelope/',
    'transport runtime must retain canonical endpoint'
);
sentry_expect(($captured['timeout'] ?? 99) <= 1.2, 'transport timeout must remain short');
$headers = implode("\n", $captured['headers'] ?? []);
sentry_expect(str_contains($headers, 'sentry_key=public123'), 'auth header must contain only public DSN key');
sentry_expect(!str_contains((string)($captured['body'] ?? ''), $secretMessage), 'envelope must not contain raw exception text');

$called = false;
$never = static function () use (&$called): bool {
    $called = true;
    return true;
};
sentry_expect(!brvtalSentryCaptureException($exception, [], $never, $publicResolver), 'missing DSN must disable remote transport');
sentry_expect($called === false, 'disabled transport must not invoke sender');
sentry_expect(
    !brvtalSentryCaptureFatal(
        ['type' => E_WARNING, 'file' => __FILE__, 'line' => __LINE__],
        $config,
        $sender,
        $publicResolver
    ),
    'non-fatal PHP errors must not be sent'
);

$root = dirname(__DIR__);

// Uncaught exception: exactly one remote capture, preserved local log/output and HTTP 500.
$capture = sentry_temp_path('brvtal-cap-');
$log = sentry_temp_path('brvtal-log-');
$status = sentry_temp_path('brvtal-status-');
try {
    $fixture = sentry_fixture_code(
        $root,
        $capture,
        $log,
        <<<'PHP'
$GLOBALS['config'] = [
    'observability' => ['sentry_dsn' => 'https://public123@ingest.example.test/42'],
];
require $root . '/config/logger.php';
throw new RuntimeException('fixture exception');
PHP,
        $status
    );
    $result = sentry_run_fixture($fixture);
    $captureLines = is_file($capture) ? file($capture, FILE_IGNORE_NEW_LINES) : [];
    $logBody = is_file($log) ? (string)file_get_contents($log) : '';
    $statusBody = is_file($status) ? trim((string)file_get_contents($status)) : '';
    sentry_expect(count($captureLines ?: []) === 1, 'exception handler must capture remotely exactly once');
    sentry_expect(str_contains($logBody, '[EXCEPTION]'), 'exception handler must preserve local exception logging');
    sentry_expect(str_contains($result['stdout'], 'BRVTAL ERROR'), 'exception handler must preserve user-safe 500 output');
    sentry_expect($statusBody === '500', 'exception handler must set HTTP 500');
} finally {
    @unlink($capture);
    @unlink($log);
    @unlink($status);
}

// Fatal shutdown: exactly one remote fatal capture and preserved FATAL local log.
$capture = sentry_temp_path('brvtal-cap-');
$log = sentry_temp_path('brvtal-log-');
try {
    $fixture = sentry_fixture_code(
        $root,
        $capture,
        $log,
        <<<'PHP'
$GLOBALS['config'] = [
    'observability' => ['sentry_dsn' => 'https://public123@ingest.example.test/42'],
];
require $root . '/config/logger.php';
trigger_error('fixture fatal', E_USER_ERROR);
PHP
    );
    sentry_run_fixture($fixture);
    $captureLines = is_file($capture) ? file($capture, FILE_IGNORE_NEW_LINES) : [];
    $logBody = is_file($log) ? (string)file_get_contents($log) : '';
    sentry_expect(count($captureLines ?: []) === 1, 'fatal shutdown must capture remotely exactly once');
    sentry_expect(str_contains($logBody, '[FATAL]'), 'fatal shutdown must preserve local fatal logging');
} finally {
    @unlink($capture);
    @unlink($log);
}

// Bootstrap missing-config path: guarded exit, local log and exactly one remote capture.
$capture = sentry_temp_path('brvtal-cap-');
$log = sentry_temp_path('brvtal-log-');
$missing = sentry_temp_path('brvtal-missing-');
try {
    $fixture = sentry_fixture_code(
        $root,
        $capture,
        $log,
        <<<'PHP'
$GLOBALS['brvtalBootstrapConfigPath'] = __MISSING__;
require $root . '/config/bootstrap.php';
PHP
    );
    $fixture = str_replace('__MISSING__', var_export($missing, true), $fixture);
    $result = sentry_run_fixture($fixture);
    $captureLines = is_file($capture) ? file($capture, FILE_IGNORE_NEW_LINES) : [];
    $logBody = is_file($log) ? (string)file_get_contents($log) : '';
    sentry_expect(count($captureLines ?: []) === 1, 'bootstrap failure must capture remotely exactly once');
    sentry_expect(str_contains($logBody, '[FATAL]'), 'bootstrap failure must preserve local fatal logging');
    sentry_expect(
        str_contains($result['stdout'], 'BRVTAL: falta config/config.php.'),
        'bootstrap missing-config path must preserve guarded exit output'
    );
} finally {
    @unlink($capture);
    @unlink($log);
    @unlink($missing);
}

$source = (string)file_get_contents(__DIR__ . '/../config/sentry.php');
sentry_expect(!str_contains($source, 'curl_close('), 'PHP 8.5-deprecated curl_close must not be used');
sentry_expect(!str_contains($source, '$http_response_header'), 'PHP 8.5-deprecated response header variable must not be used');
sentry_expect(!str_contains($source, 'stream_get_contents($stream)'), 'stream sender must not buffer response bodies');
sentry_expect(str_contains($source, 'CURLOPT_WRITEFUNCTION'), 'curl sender must discard response bodies');
sentry_expect(str_contains($source, 'CURLOPT_RESOLVE'), 'curl sender must pin validated DNS');
foreach ([
    '\$_SERVER', '\$_COOKIE', '\$_POST', '\$_GET', '\$_SESSION',
    'REMOTE_ADDR', 'REQUEST_URI', 'HTTP_USER_AGENT', 'HTTP_REFERER',
] as $forbidden) {
    sentry_expect(!str_contains($source, $forbidden), 'Sentry source must not inspect request identity: ' . $forbidden);
}

$example = (string)file_get_contents(__DIR__ . '/../config/config.example.php');
sentry_expect(str_contains($example, 'BRVTAL_SENTRY_DSN'), 'runtime DSN override must be documented');
sentry_expect(!preg_match('#https?://[^\s]+@[^\s]+/\d+#', $example), 'example must not commit a live DSN');

echo "BRVTAL Sentry contract tests passed.\n";
