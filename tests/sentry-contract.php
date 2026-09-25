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

$valid = brvtal_sentry_parse_dsn('https://public123@ingest.example.invalid/42');
sentry_expect(is_array($valid), 'valid HTTPS DSN must parse');
sentry_expect($valid['endpoint'] === 'https://ingest.example.invalid/api/42/envelope/', 'endpoint must derive from DSN');
sentry_expect($valid['public_key'] === 'public123', 'public key must derive from DSN');
foreach ([
    'http://public123@ingest.example.invalid/42',
    'https://public123:secret@ingest.example.invalid/42',
    'https://public123@127.0.0.1/42',
    'https://public123@localhost/42',
    'https://public123@ingest.example.invalid/not-a-project',
    'https://short@ingest.example.invalid/42',
    'https://public123@ingest.example.invalid/42?debug=1',
] as $invalid) {
    sentry_expect(brvtal_sentry_parse_dsn($invalid) === null, 'invalid DSN must fail closed');
}

$secretMessage = 'person@example.test 203.0.113.7 token=secret-value';
$exception = new RuntimeException($secretMessage);
$event = brvtal_sentry_exception_event($exception);
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
    sentry_expect($filename === '[external]' || !str_starts_with($filename, '/'), 'stack file must never expose absolute server path');
}

$fatal = brvtal_sentry_fatal_event([
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
    string $endpoint,
    array $headers,
    string $body,
    float $timeout
) use (&$captured): bool {
    $captured = compact('endpoint', 'headers', 'body', 'timeout');
    return true;
};
$config = ['observability' => ['sentry_dsn' => 'https://public123@ingest.example.invalid/42']];
sentry_expect(brvtal_sentry_capture_exception($exception, $config, $sender), 'fake transport must report success');
sentry_expect(($captured['endpoint'] ?? null) === 'https://ingest.example.invalid/api/42/envelope/', 'transport endpoint must be bounded to DSN');
sentry_expect(($captured['timeout'] ?? 99) <= 1.2, 'transport timeout must remain short');
$headers = implode("\n", $captured['headers'] ?? []);
sentry_expect(str_contains($headers, 'sentry_key=public123'), 'auth header must contain only public DSN key');
sentry_expect(!str_contains((string)($captured['body'] ?? ''), $secretMessage), 'envelope must not contain raw exception text');

$called = false;
$never = static function () use (&$called): bool {
    $called = true;
    return true;
};
sentry_expect(!brvtal_sentry_capture_exception($exception, [], $never), 'missing DSN must disable remote transport');
sentry_expect($called === false, 'disabled transport must not invoke sender');
sentry_expect(
    !brvtal_sentry_capture_fatal(['type' => E_WARNING, 'file' => __FILE__, 'line' => __LINE__], $config, $sender),
    'non-fatal PHP errors must not be sent'
);

$source = (string)file_get_contents(__DIR__ . '/../config/sentry.php');
sentry_expect(!str_contains($source, 'curl_close('), 'PHP 8.5-deprecated curl_close must not be used');
sentry_expect(!str_contains($source, '$http_response_header'), 'PHP 8.5-deprecated response header variable must not be used');
foreach ([
    '\$_SERVER', '\$_COOKIE', '\$_POST', '\$_GET', '\$_SESSION',
    'REMOTE_ADDR', 'REQUEST_URI', 'HTTP_USER_AGENT', 'HTTP_REFERER',
] as $forbidden) {
    sentry_expect(!str_contains($source, $forbidden), 'Sentry source must not inspect request identity: ' . $forbidden);
}
$bootstrap = (string)file_get_contents(__DIR__ . '/../config/bootstrap.php');
sentry_expect(
    str_contains($bootstrap, "function_exists('brvtal_sentry_capture_fatal')"),
    'bootstrap must remain safe when optional logger transport is unavailable'
);
sentry_expect(
    str_contains($bootstrap, "function_exists('brvtal_sentry_capture_exception')"),
    'configuration failure path must guard optional transport'
);

$logger = (string)file_get_contents(__DIR__ . '/../config/logger.php');
sentry_expect(str_contains($logger, 'brvtal_sentry_capture_exception('), 'uncaught exception handler must report remotely');
sentry_expect(str_contains($logger, 'brvtal_sentry_capture_fatal('), 'fatal shutdown handler must report remotely');
sentry_expect(substr_count($logger, 'brvtal_sentry_capture_exception(') === 1, 'exception reporting must not duplicate');
sentry_expect(substr_count($logger, 'brvtal_sentry_capture_fatal(') === 1, 'fatal reporting must not duplicate');

$example = (string)file_get_contents(__DIR__ . '/../config/config.example.php');
sentry_expect(str_contains($example, 'BRVTAL_SENTRY_DSN'), 'runtime DSN override must be documented');
sentry_expect(!preg_match('#https?://[^\s]+@[^\s]+/\d+#', $example), 'example must not commit a live DSN');

echo "BRVTAL Sentry contract tests passed.\n";
