<?php
declare(strict_types=1);

/**
 * Execute the authenticated debug-log clear policy against a caller-provided
 * file path. The endpoint owns authentication/response formatting; this helper
 * keeps the destructive mutation deterministic and safely testable.
 *
 * @return array{status:int,payload:array{ok:bool,error?:string,bytes?:int,lines?:int}}
 */
function brvtal_admin_log_clear_result(
    string $method,
    string $expectedCsrf,
    string $providedCsrf,
    string $logFile,
    ?callable $writer = null
): array {
    if ($method !== 'POST') {
        return [
            'status' => 405,
            'payload' => ['ok' => false, 'error' => 'METHOD_NOT_ALLOWED'],
        ];
    }

    if (
        $expectedCsrf === ''
        || $providedCsrf === ''
        || !hash_equals($expectedCsrf, $providedCsrf)
    ) {
        return [
            'status' => 419,
            'payload' => ['ok' => false, 'error' => 'CSRF'],
        ];
    }

    if (is_file($logFile)) {
        $write = $writer ?? static fn(string $path): int|false => file_put_contents($path, '', LOCK_EX);
        if ($write($logFile) === false) {
            return [
                'status' => 500,
                'payload' => ['ok' => false, 'error' => 'LOG_CLEAR_FAILED'],
            ];
        }
    }

    return [
        'status' => 200,
        'payload' => ['ok' => true, 'bytes' => 0, 'lines' => 0],
    ];
}
