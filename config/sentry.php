<?php
declare(strict_types=1);

/*
 * Minimal Sentry reporter for BRVTAL (no Composer dependency).
 *
 * Sends EXCEPTION and FATAL events to the BRVTAL project on pl0n3r.sentry.io.
 * The DSN only allows SENDING events, so its default is versioned;
 * BRVTAL_SENTRY_DSN overrides it and an empty value disables it.
 * Only real production requests report (host *.brvtal.com.co); CLI, CI and
 * local servers never send. Privacy: no IP, cookies, headers, query strings,
 * request bodies or log context; only class, message, file:line and path.
 * Failures are swallowed with a short timeout: Sentry must never break a page.
 */

const BRVTAL_SENTRY_DEFAULT_DSN = 'https://fe21fcce4b9d7de2a2034837dbbab67b@o4512139951865856.ingest.us.sentry.io/4512140016222208';

function brvtal_sentry_enabled(): bool
{
    if (PHP_SAPI === 'cli' || PHP_SAPI === 'cli-server') {
        return false;
    }
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    return $host === 'brvtal.com.co' || str_ends_with($host, '.brvtal.com.co');
}

/** @return array{url:string,key:string}|null */
function brvtal_sentry_endpoint(): ?array
{
    $override = getenv('BRVTAL_SENTRY_DSN');
    $dsn = $override === false ? BRVTAL_SENTRY_DEFAULT_DSN : trim($override);
    if ($dsn === '') {
        return null;
    }
    $parts = parse_url($dsn);
    if (!is_array($parts) || empty($parts['user']) || empty($parts['host']) || empty($parts['path'])) {
        return null;
    }
    $project = trim($parts['path'], '/');
    return [
        'url' => sprintf('https://%s/api/%s/envelope/', $parts['host'], rawurlencode($project)),
        'key' => $parts['user'],
    ];
}

function brvtal_sentry_capture(string $level, string $message, string $class = 'Error', string $file = '', int $line = 0): void
{
    static $sent = 0;
    if ($sent >= 5 || !function_exists('curl_init') || !brvtal_sentry_enabled()) {
        return;
    }
    $endpoint = brvtal_sentry_endpoint();
    if ($endpoint === null) {
        return;
    }
    $sent++;

    try {
        $eventId = bin2hex(random_bytes(16));
        $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $root = dirname(__DIR__);
        $event = [
            'event_id' => $eventId,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'platform' => 'php',
            'level' => $level === 'FATAL' ? 'fatal' : 'error',
            'environment' => 'production',
            'release' => 'brvtal@' . (defined('BRVTAL_APP_VERSION') ? BRVTAL_APP_VERSION : '0.0.0-dev'),
            'transaction' => $path !== '' ? $path : null,
            'exception' => ['values' => [[
                'type' => $class,
                'value' => mb_substr($message, 0, 1000),
                'stacktrace' => ['frames' => [[
                    'filename' => str_starts_with($file, $root) ? substr($file, strlen($root)) : basename($file),
                    'lineno' => $line,
                    'in_app' => true,
                ]]],
            ]]],
        ];
        $body = json_encode(['event_id' => $eventId, 'sent_at' => $event['timestamp']]) . "\n"
            . json_encode(['type' => 'event']) . "\n"
            . json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";

        $ch = curl_init($endpoint['url']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-sentry-envelope',
                'X-Sentry-Auth: Sentry sentry_version=7, sentry_client=brvtal/1.0, sentry_key=' . $endpoint['key'],
            ],
        ]);
        curl_exec($ch);
    } catch (Throwable) {
        // Observability must never become the failure.
    }
}
