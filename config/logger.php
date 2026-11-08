<?php
declare(strict_types=1);

require_once __DIR__ . '/sentry.php';

/*
 * BRVTAL LOGGER
 * Save as: /config/logger.php
 */

if (!defined('BRVTAL_LOGGER_LOADED')) {
    define('BRVTAL_LOGGER_LOADED', true);
}

$BRVTAL_ROOT = dirname(__DIR__);
$BRVTAL_LOG_DIR = $BRVTAL_ROOT . '/storage/logs';
$BRVTAL_LOG_FILE = (
    defined('BRVTAL_SENTRY_TESTING')
    && BRVTAL_SENTRY_TESTING === true
    && is_string($GLOBALS['brvtalSentryTestLogFile'] ?? null)
)
    ? $GLOBALS['brvtalSentryTestLogFile']
    : $BRVTAL_LOG_DIR . '/brvtal.log';

if (!is_dir($BRVTAL_LOG_DIR)) {
    @mkdir($BRVTAL_LOG_DIR, 0755, true);
}

function brvtal_log(string $level, string $message, array $context = []): void
{
    global $BRVTAL_LOG_FILE;

    $event = strtoupper(trim($level));
    // Routine bootstrap/connection events are opt-in, never per-request spam.
    if (in_array($event, ['BOOT', 'DB', 'DEBUG', 'TRACE'], true)
        && getenv('BRVTAL_LOG_VERBOSE') !== '1') {
        return;
    }
    $severity = match (true) {
        in_array($event, ['ERROR', 'FATAL', 'EXCEPTION', 'DB_ERROR'], true),
        str_contains($event, 'FAILED'), str_contains($event, 'ERROR') => 'ERROR',
        in_array($event, ['WARN', 'WARNING'], true) => 'WARNING',
        $event === 'NOTICE' => 'NOTICE',
        in_array($event, ['BOOT', 'DB', 'DEBUG', 'TRACE'], true) => 'DEBUG',
        default => 'INFO',
    };
    $event = substr(preg_replace('/[^A-Z0-9_]/', '', $event) ?: 'UNKNOWN', 0, 50);
    $safeContext = [];

    foreach ($context as $key => $value) {
        $keyLower = strtolower((string)$key);

        if (
            str_contains($keyLower, 'pass') ||
            str_contains($keyLower, 'secret') ||
            str_contains($keyLower, 'token') ||
            str_contains($keyLower, 'cookie') ||
            str_contains($keyLower, 'authorization')
        ) {
            $value = '[REDACTED]';
        }

        if (is_object($value)) {
            $value = '[OBJECT]';
        } elseif (is_resource($value)) {
            $value = '[RESOURCE]';
        } elseif (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $safeContext[$key] = $value;
    }

    // Explicit UTC even before bootstrap switches the local timezone.
    $time = gmdate(DATE_ATOM);
    if (!is_string($GLOBALS['brvtal_log_request_id'] ?? null)) {
        $GLOBALS['brvtal_log_request_id'] = bin2hex(random_bytes(8));
    }
    $requestId = $GLOBALS['brvtal_log_request_id'];
    // Never log client IP or query strings, which may contain credentials.
    $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $uri = is_string($path) && $path !== '' ? substr($path, 0, 200) : '-';
    $message = substr(str_replace(["\r", "\n"], ' ', $message), 0, 1500);

    $line = sprintf(
        "[%s] [%s] [EVENT:%s] [RID:%s] [URI:%s] %s",
        $time,
        $severity,
        $event,
        $requestId,
        $uri,
        $message
    );

    if ($safeContext) {
        $json = json_encode(
            $safeContext,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        $line .= ' | ' . ($json ?: '{}');
    }

    @file_put_contents(
        $BRVTAL_LOG_FILE,
        $line . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

set_error_handler(
    static function (
        int $severity,
        string $message,
        string $file,
        int $line
    ): bool {
        // @ masks the individual severity bit, not necessarily all error_reporting().
        if ((error_reporting() & $severity) === 0) {
            return false;
        }
        $logLevel = match (true) {
            in_array($severity, [E_WARNING, E_USER_WARNING, E_CORE_WARNING, E_COMPILE_WARNING], true) => 'WARNING',
            in_array($severity, [E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED], true) => 'NOTICE',
            default => 'ERROR',
        };
        brvtal_log($logLevel, $message, [
            'file' => $file,
            'line' => $line,
            'severity' => $severity
        ]);

        return false;
    }
);

set_exception_handler(
    static function (Throwable $exception): void {
        brvtal_log('EXCEPTION', $exception->getMessage(), [
            'class' => get_class($exception),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString()
        ]);
        brvtalSentryCaptureException(
            $exception,
            is_array($GLOBALS['config'] ?? null) ? $GLOBALS['config'] : [],
            brvtalSentrySenderOverride(),
            brvtalSentryResolverOverride()
        );

        http_response_code(500);

        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
        }

        echo "BRVTAL ERROR\n\n";
        echo "Se produjo un error interno.\n";
        echo "Revisa /discadmin/logs.php para ver el detalle.\n";
        exit;
    }
);

register_shutdown_function(
    static function (): void {
        $error = error_get_last();

        if (!$error) {
            return;
        }

        $fatalTypes = [
            E_ERROR,
            E_PARSE,
            E_CORE_ERROR,
            E_COMPILE_ERROR,
            E_USER_ERROR
        ];

        if (in_array($error['type'], $fatalTypes, true)) {
            brvtal_log('FATAL', $error['message'], [
                'file' => $error['file'],
                'line' => $error['line'],
                'type' => $error['type']
            ]);
            brvtalSentryCaptureFatal(
                $error,
                is_array($GLOBALS['config'] ?? null) ? $GLOBALS['config'] : [],
                brvtalSentrySenderOverride(),
                brvtalSentryResolverOverride()
            );
        }
    }
);

brvtal_log('BOOT', 'BRVTAL logger initialized.', [
    'php' => PHP_VERSION,
    'sapi' => PHP_SAPI
]);
