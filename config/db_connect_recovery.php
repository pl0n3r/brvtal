<?php
declare(strict_types=1);

/**
 * Only the initial connection is eligible for recovery. A query, transaction
 * or mutation must never be executed from this helper or replayed after failure.
 */
function brvtal_db_transient_connect_failure(Throwable $error): bool
{
    if (!$error instanceof PDOException || !is_array($error->errorInfo)) {
        return false;
    }

    $sqlState = $error->errorInfo[0] ?? null;
    $rawDriverCode = $error->errorInfo[1] ?? null;
    if (!is_string($sqlState)
        || (!is_int($rawDriverCode) && !(is_string($rawDriverCode) && ctype_digit($rawDriverCode)))) {
        return false;
    }

    // Only recognized MySQL/MariaDB connection or saturation failures.
    // Invalid credentials, unknown failures and SQL/query errors are never retried.
    return in_array($sqlState, ['HY000', '08001', '08004', '08S01'], true)
        && in_array((int)$rawDriverCode, [1040, 1203, 2002, 2003, 2006, 2013], true);
}

/**
 * @template T
 * @param callable():T $connect Connection establishment ONLY; no SQL.
 * @param null|callable(int):void $wait Safe test injection of microsecond delay.
 * @return T
 */
function brvtal_db_connect_with_recovery(callable $connect, ?callable $wait = null): mixed
{
    try {
        return $connect();
    } catch (Throwable $error) {
        if (!brvtal_db_transient_connect_failure($error)) {
            throw $error;
        }
        // One bounded retry after 50ms, only when no PDO/SQL has been obtained.
        if (function_exists('brvtal_log')) {
            brvtal_log('DB_CONNECT_RETRY', 'Transient DB connection failure; one bounded recovery', [
                'phase' => 'connect',
                'code' => 'DB_CONNECT_RETRY',
            ]);
        }
        if ($wait === null) {
            usleep(50000);
        } else {
            $wait(50000);
        }
        return $connect();
    }
}
