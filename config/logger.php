<?php
declare(strict_types=1);

/*
 * BRVTAL LOGGER
 * Save as: /config/logger.php
 */

if (!defined('BRVTAL_LOGGER_LOADED')) {
    define('BRVTAL_LOGGER_LOADED', true);
}

$BRVTAL_ROOT = dirname(__DIR__);
$BRVTAL_LOG_DIR = $BRVTAL_ROOT . '/storage/logs';
$BRVTAL_LOG_FILE = $BRVTAL_LOG_DIR . '/brvtal.log';

if (!is_dir($BRVTAL_LOG_DIR)) {
    @mkdir($BRVTAL_LOG_DIR, 0755, true);
}

function brvtal_log(string $level, string $message, array $context = []): void
{
    global $BRVTAL_LOG_FILE;

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

    $time = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '-';
    $uri = $_SERVER['REQUEST_URI'] ?? '-';

    $line = sprintf(
        "[%s] [%s] [IP:%s] [URI:%s] %s",
        $time,
        strtoupper($level),
        $ip,
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
        brvtal_log('ERROR', $message, [
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
        }
    }
);

brvtal_log('BOOT', 'BRVTAL logger initialized.', [
    'php' => PHP_VERSION,
    'sapi' => PHP_SAPI
]);
