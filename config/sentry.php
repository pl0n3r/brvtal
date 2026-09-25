<?php
declare(strict_types=1);

require_once __DIR__ . '/deployment.php';

const BRVTAL_SENTRY_TIMEOUT_SECONDS = 1.2;

/** @return array{dsn:string,endpoint:string,public_key:string,project_id:string}|null */
function brvtalSentryParseDsn(string $dsn): ?array
{
    $dsn = trim($dsn);
    if ($dsn === '' || strlen($dsn) > 2048) {
        return null;
    }

    $parts = parse_url($dsn);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
        return null;
    }
    if (isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
        return null;
    }
    if (isset($parts['port']) && (int)$parts['port'] !== 443) {
        return null;
    }

    $host = strtolower(trim((string)($parts['host'] ?? '')));
    $publicKey = trim((string)($parts['user'] ?? ''));
    $projectId = trim((string)($parts['path'] ?? ''), '/');

    if (
        $host === ''
        || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
        || filter_var($host, FILTER_VALIDATE_IP) !== false
        || $host === 'localhost'
    ) {
        return null;
    }
    if (preg_match('/^[A-Za-z0-9]{8,128}$/', $publicKey) !== 1) {
        return null;
    }
    if (preg_match('/^[1-9][0-9]{0,19}$/', $projectId) !== 1) {
        return null;
    }

    return [
        'dsn' => $dsn,
        'endpoint' => 'https://' . $host . '/api/' . $projectId . '/envelope/',
        'public_key' => $publicKey,
        'project_id' => $projectId,
    ];
}

/** @return array{dsn:string,endpoint:string,public_key:string,project_id:string}|null */
function brvtalSentryRuntimeConfig(array $config = []): ?array
{
    if (strtoupper(BRVTAL_APP_ENV) !== 'PRODUCTION') {
        return null;
    }

    $dsn = trim((string)(getenv('BRVTAL_SENTRY_DSN') ?: ''));
    if ($dsn === '') {
        $observability = $config['observability'] ?? [];
        $dsn = is_array($observability) ? trim((string)($observability['sentry_dsn'] ?? '')) : '';
    }

    return brvtalSentryParseDsn($dsn);
}

function brvtalSentryRelativeFile(string $file): string
{
    $root = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
    $file = str_replace('\\', '/', trim($file));
    if ($file === '') {
        return '[unknown]';
    }

    $prefix = $root . '/';
    if (str_starts_with($file, $prefix)) {
        $relative = substr($file, strlen($prefix));
        if ($relative !== '' && !str_contains($relative, '../')) {
            return $relative;
        }
    }

    return '[external]';
}

function brvtalSentrySymbol(string $value): string
{
    $value = trim($value);
    if ($value === '{closure}') {
        return $value;
    }
    if ($value === '' || strlen($value) > 160) {
        return '[symbol]';
    }
    if (str_contains($value, '@anonymous')) {
        return 'anonymous_class';
    }

    return preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/', $value) === 1
        ? $value
        : '[symbol]';
}

/** @return array{filename:string,lineno:int,in_app:bool,function?:string,module?:string} */
function brvtalSentryFrame(string $file, int $line, string $function = '', string $class = ''): array
{
    $filename = brvtalSentryRelativeFile($file);
    $frame = [
        'filename' => $filename,
        'lineno' => max(1, $line),
        'in_app' => $filename !== '[external]',
    ];
    if ($function !== '') {
        $frame['function'] = brvtalSentrySymbol($function);
    }
    if ($class !== '') {
        $frame['module'] = brvtalSentrySymbol($class);
    }
    return $frame;
}

/** @return list<array{filename:string,lineno:int,in_app:bool,function?:string,module?:string}> */
function brvtalSentryExceptionFrames(Throwable $exception): array
{
    $frames = [];
    $trace = array_slice($exception->getTrace(), 0, 30);
    foreach (array_reverse($trace) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $frames[] = brvtalSentryFrame(
            (string)($item['file'] ?? ''),
            (int)($item['line'] ?? 1),
            (string)($item['function'] ?? ''),
            (string)($item['class'] ?? '')
        );
    }
    $frames[] = brvtalSentryFrame($exception->getFile(), $exception->getLine());
    return $frames;
}

/** @return array<string,mixed> */
function brvtalSentryBaseEvent(string $eventId, string $level): array
{
    $tags = [
        'brvtal_version' => BRVTAL_APP_VERSION,
        'php_version' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
        'sapi' => PHP_SAPI,
    ];
    if (brvtalDeploymentIsExact()) {
        $tags['release_sha'] = brvtal_deployment_sha();
    }

    return [
        'event_id' => $eventId,
        'timestamp' => gmdate('c'),
        'platform' => 'php',
        'level' => $level,
        'environment' => strtolower(BRVTAL_APP_ENV),
        'release' => 'brvtal@' . BRVTAL_APP_VERSION,
        'tags' => $tags,
    ];
}

/** @return array<string,mixed> */
function brvtalSentryExceptionEvent(Throwable $exception): array
{
    $event = brvtalSentryBaseEvent(bin2hex(random_bytes(16)), 'error');
    $event['exception'] = [
        'values' => [[
            'type' => brvtalSentrySymbol(get_class($exception)),
            'value' => 'Unhandled exception',
            'stacktrace' => ['frames' => brvtalSentryExceptionFrames($exception)],
            'mechanism' => ['type' => 'generic', 'handled' => false],
        ]],
    ];
    return $event;
}

/** @param array{type?:mixed,file?:mixed,line?:mixed} $error
 *  @return array<string,mixed>
 */
function brvtalSentryFatalEvent(array $error): array
{
    $event = brvtalSentryBaseEvent(bin2hex(random_bytes(16)), 'fatal');
    $event['exception'] = [
        'values' => [[
            'type' => 'PHPFatalError',
            'value' => 'Fatal PHP error',
            'stacktrace' => ['frames' => [
                brvtalSentryFrame((string)($error['file'] ?? ''), (int)($error['line'] ?? 1)),
            ]],
            'mechanism' => ['type' => 'generic', 'handled' => false],
        ]],
    ];
    return $event;
}

/** @param array<string,mixed> $event
 *  @param array{dsn:string,endpoint:string,public_key:string,project_id:string} $runtime
 */
function brvtalSentryEnvelope(array $event, array $runtime): string
{
    $eventJson = json_encode(
        $event,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    $header = json_encode(
        ['event_id' => $event['event_id'] ?? '', 'sent_at' => gmdate('c')],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
    );
    $item = json_encode(
        ['type' => 'event', 'length' => strlen($eventJson)],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
    );
    return $header . "\n" . $item . "\n" . $eventJson . "\n";
}

/** @param list<string> $headers */
function brvtalSentryDefaultSender(
    string $endpoint,
    array $headers,
    string $body,
    float $timeoutSeconds
): bool {
    if (function_exists('curl_init')) {
        $handle = curl_init($endpoint);
        if ($handle === false) {
            return false;
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => 400,
            CURLOPT_TIMEOUT_MS => (int)round($timeoutSeconds * 1000),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_NOSIGNAL => true,
        ]);
        $response = @curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        unset($handle);
        return $response !== false && $status >= 200 && $status < 300;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => $timeoutSeconds,
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
        ],
    ]);
    $stream = @fopen($endpoint, 'rb', false, $context);
    if ($stream === false) {
        return false;
    }

    try {
        $response = stream_get_contents($stream);
        $metadata = stream_get_meta_data($stream);
        $responseHeaders = $metadata['wrapper_data'] ?? [];
        $status = 0;
        if (is_array($responseHeaders) && isset($responseHeaders[0])) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', (string)$responseHeaders[0], $match) === 1) {
                $status = (int)$match[1];
            }
        }
        return $response !== false && $status >= 200 && $status < 300;
    } finally {
        fclose($stream);
    }
}

/** @param array<string,mixed> $event */
function brvtalSentrySendEvent(array $event, array $config = [], ?callable $sender = null): bool
{
    try {
        $runtime = brvtalSentryRuntimeConfig($config);
        if ($runtime === null) {
            return false;
        }

        $body = brvtalSentryEnvelope($event, $runtime);
        $headers = [
            'Content-Type: application/x-sentry-envelope',
            'X-Sentry-Auth: Sentry sentry_version=7, sentry_client=brvtal-php/1.0, sentry_key='
                . $runtime['public_key'],
        ];
        $send = $sender ?? 'brvtalSentryDefaultSender';
        return (bool)$send(
            $runtime['endpoint'],
            $headers,
            $body,
            BRVTAL_SENTRY_TIMEOUT_SECONDS
        );
    } catch (Throwable) {
        return false;
    }
}

function brvtalSentryCaptureException(
    Throwable $exception,
    array $config = [],
    ?callable $sender = null
): bool {
    try {
        return brvtalSentrySendEvent(brvtalSentryExceptionEvent($exception), $config, $sender);
    } catch (Throwable) {
        return false;
    }
}

/** @param array{type?:mixed,file?:mixed,line?:mixed} $error */
function brvtalSentryCaptureFatal(
    array $error,
    array $config = [],
    ?callable $sender = null
): bool {
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array((int)($error['type'] ?? 0), $fatalTypes, true)) {
        return false;
    }
    try {
        return brvtalSentrySendEvent(brvtalSentryFatalEvent($error), $config, $sender);
    } catch (Throwable) {
        return false;
    }
}
