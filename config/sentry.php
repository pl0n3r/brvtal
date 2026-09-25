<?php
declare(strict_types=1);

require_once __DIR__ . '/deployment.php';

const BRVTAL_SENTRY_TIMEOUT_SECONDS = 1.2;

/** @return list<string> */
function brvtalSentryResolveHost(string $host): array
{
    $records = @dns_get_record($host, DNS_A | DNS_AAAA);
    if ($records === false) {
        return [];
    }

    $resolvedIpes = [];
    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }
        if (($record['type'] ?? '') === 'A' && is_string($record['ip'] ?? null)) {
            $resolvedIpes[] = $record['ip'];
        }
        if (($record['type'] ?? '') === 'AAAA' && is_string($record['ipv6'] ?? null)) {
            $resolvedIpes[] = $record['ipv6'];
        }
    }

    $resolvedIpes = array_values(array_unique($resolvedIpes));
    sort($resolvedIpes, SORT_STRING);
    return $resolvedIpes;
}

/** @return list<string> */
function brvtalSentryPublicAddresses(string $host, ?callable $resolver = null): array
{
    try {
        $resolve = $resolver ?? 'brvtalSentryResolveHost';
        $resolved = $resolve($host);
    } catch (Throwable) {
        return [];
    }

    if (!is_array($resolved) || $resolved === []) {
        return [];
    }

    $resolvedIpes = [];
    foreach ($resolved as $resolvedIp) {
        if (!is_string($resolvedIp)) {
            return [];
        }
        $resolvedIp = trim($resolvedIp);
        $public = filter_var(
            $resolvedIp,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
        if ($public === false) {
            return [];
        }
        $resolvedIpes[] = $resolvedIp;
    }

    $resolvedIpes = array_values(array_unique($resolvedIpes));
    sort($resolvedIpes, SORT_STRING);
    return $resolvedIpes;
}

/**
 * @return array{
 *   dsn:string,
 *   endpoint:string,
 *   endpoint_path:string,
 *   host:string,
 *   public_key:string,
 *   project_id:string,
 *   addresses:list<string>
 * }|null
 */
function brvtalSentryParseDsn(string $dsn, ?callable $resolver = null): ?array
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

    $resolvedIpes = brvtalSentryPublicAddresses($host, $resolver);
    if ($resolvedIpes === []) {
        return null;
    }

    $endpointPath = '/api/' . $projectId . '/envelope/';
    return [
        'dsn' => $dsn,
        'endpoint' => 'https://' . $host . $endpointPath,
        'endpoint_path' => $endpointPath,
        'host' => $host,
        'public_key' => $publicKey,
        'project_id' => $projectId,
        'addresses' => $resolvedIpes,
    ];
}

/**
 * @return array{
 *   dsn:string,
 *   endpoint:string,
 *   endpoint_path:string,
 *   host:string,
 *   public_key:string,
 *   project_id:string,
 *   addresses:list<string>
 * }|null
 */
function brvtalSentryRuntimeConfig(array $config = [], ?callable $resolver = null): ?array
{
    if (strtoupper(BRVTAL_APP_ENV) !== 'PRODUCTION') {
        return null;
    }

    $dsn = trim((string)(getenv('BRVTAL_SENTRY_DSN') ?: ''));
    if ($dsn === '') {
        $observability = $config['observability'] ?? [];
        $dsn = is_array($observability) ? trim((string)($observability['sentry_dsn'] ?? '')) : '';
    }

    return brvtalSentryParseDsn($dsn, $resolver);
}

function brvtalSentrySenderOverride(): ?callable
{
    if (!defined('BRVTAL_SENTRY_TESTING') || BRVTAL_SENTRY_TESTING !== true) {
        return null;
    }
    $sender = $GLOBALS['brvtalSentryTestSender'] ?? null;
    return is_callable($sender) ? $sender : null;
}

function brvtalSentryResolverOverride(): ?callable
{
    if (!defined('BRVTAL_SENTRY_TESTING') || BRVTAL_SENTRY_TESTING !== true) {
        return null;
    }
    $resolver = $GLOBALS['brvtalSentryTestResolver'] ?? null;
    return is_callable($resolver) ? $resolver : null;
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

/**
 * @param array{
 *   endpoint:string,
 *   endpoint_path:string,
 *   host:string,
 *   addresses:list<string>
 * } $runtime
 */
function brvtalSentryPinnedAddress(array $runtime): string
{
    $resolvedIp = (string)($runtime['addresses'][0] ?? '');
    $public = filter_var(
        $resolvedIp,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    );
    if ($public === false) {
        throw new RuntimeException('Sentry destination IP is not public.');
    }
    return $resolvedIp;
}

/** @param array{host:string,addresses:list<string>} $runtime */
function brvtalSentryCurlResolveEntry(array $runtime): string
{
    $resolvedIp = brvtalSentryPinnedAddress($runtime);
    $formatted = str_contains($resolvedIp, ':') ? '[' . $resolvedIp . ']' : $resolvedIp;
    return $runtime['host'] . ':443:' . $formatted;
}

/** @param array{endpoint_path:string,addresses:list<string>} $runtime */
function brvtalSentryPinnedEndpoint(array $runtime): string
{
    $resolvedIp = brvtalSentryPinnedAddress($runtime);
    $authority = str_contains($resolvedIp, ':') ? '[' . $resolvedIp . ']' : $resolvedIp;
    return 'https://' . $authority . $runtime['endpoint_path'];
}

/**
 * @param array{
 *   endpoint:string,
 *   endpoint_path:string,
 *   host:string,
 *   addresses:list<string>
 * } $runtime
 * @param list<string> $headers
 */
function brvtalSentryDefaultSender(
    array $runtime,
    array $headers,
    string $body,
    float $timeoutSeconds
): bool {
    if (function_exists('curl_init')) {
        $handle = curl_init($runtime['endpoint']);
        if ($handle === false) {
            return false;
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk): int {
                return strlen($chunk);
            },
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => 400,
            CURLOPT_TIMEOUT_MS => (int)round($timeoutSeconds * 1000),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [brvtalSentryCurlResolveEntry($runtime)],
            CURLOPT_NOSIGNAL => true,
        ]);
        $ok = @curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        unset($handle);
        return $ok === true && $status >= 200 && $status < 300;
    }

    $streamHeaders = array_merge(['Host: ' . $runtime['host']], $headers);
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $streamHeaders),
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
            'peer_name' => $runtime['host'],
            'SNI_enabled' => true,
            'SNI_server_name' => $runtime['host'],
        ],
    ]);
    $stream = @fopen(brvtalSentryPinnedEndpoint($runtime), 'rb', false, $context);
    if ($stream === false) {
        return false;
    }

    try {
        $metadata = stream_get_meta_data($stream);
        $responseHeaders = $metadata['wrapper_data'] ?? [];
        $status = 0;
        if (is_array($responseHeaders) && isset($responseHeaders[0])) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', (string)$responseHeaders[0], $match) === 1) {
                $status = (int)$match[1];
            }
        }
        return $status >= 200 && $status < 300;
    } finally {
        fclose($stream);
    }
}

/** @param array<string,mixed> $event */
function brvtalSentrySendEvent(
    array $event,
    array $config = [],
    ?callable $sender = null,
    ?callable $resolver = null
): bool {
    try {
        $runtime = brvtalSentryRuntimeConfig($config, $resolver);
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
            $runtime,
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
    ?callable $sender = null,
    ?callable $resolver = null
): bool {
    try {
        return brvtalSentrySendEvent(
            brvtalSentryExceptionEvent($exception),
            $config,
            $sender,
            $resolver
        );
    } catch (Throwable) {
        return false;
    }
}

/** @param array{type?:mixed,file?:mixed,line?:mixed} $error */
function brvtalSentryCaptureFatal(
    array $error,
    array $config = [],
    ?callable $sender = null,
    ?callable $resolver = null
): bool {
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array((int)($error['type'] ?? 0), $fatalTypes, true)) {
        return false;
    }
    try {
        return brvtalSentrySendEvent(
            brvtalSentryFatalEvent($error),
            $config,
            $sender,
            $resolver
        );
    } catch (Throwable) {
        return false;
    }
}
