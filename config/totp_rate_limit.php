<?php
declare(strict_types=1);

require_once __DIR__ . '/rate_limit_store.php';

const BRVTAL_TOTP_RATE_LIMIT_WINDOW = 900;
const BRVTAL_TOTP_RATE_LIMIT_MAX_FAILURES = 5;
const BRVTAL_TOTP_RATE_LIMIT_BLOCK_SECONDS = 900;

function brvtal_totp_rate_limit_path(int $adminId, string $scope = 'login', ?string $directory = null, ?string $ip = null): string
{
    $directory ??= __DIR__ . '/../storage/rate_limits';
    $ip ??= (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $prefix = $scope === 'login' ? 'totp|' : 'totp|' . $scope . '|';
    $key = hash('sha256', $prefix . $ip . '|' . $adminId);
    return rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $key . '.json';
}

function brvtal_totp_rate_limit_normalize(array $data, int $now): array
{
    $attempts = array_values(array_filter(
        (array)($data['attempts'] ?? []),
        static fn($timestamp): bool => is_int($timestamp) && $timestamp > $now - BRVTAL_TOTP_RATE_LIMIT_WINDOW
    ));
    $blockedUntil = (int)($data['blocked_until'] ?? 0);
    if ($blockedUntil <= $now) $blockedUntil = 0;
    return ['attempts' => $attempts, 'blocked_until' => $blockedUntil];
}

function brvtal_totp_rate_limit_result(string $file, array $data, int $now): array
{
    $blockedUntil = (int)($data['blocked_until'] ?? 0);
    return [
        'file' => $file,
        'attempts' => array_values((array)($data['attempts'] ?? [])),
        'blocked_until' => $blockedUntil,
        'limited' => $blockedUntil > $now,
        'retry_after' => $blockedUntil > $now ? $blockedUntil - $now : 0,
    ];
}

function brvtal_totp_rate_limit_blocked_fallback(string $file, int $now): array
{
    return brvtal_totp_rate_limit_result(
        $file,
        ['attempts' => [], 'blocked_until' => $now + BRVTAL_TOTP_RATE_LIMIT_BLOCK_SECONDS],
        $now
    );
}

function brvtal_totp_rate_limit_state(
    int $adminId,
    string $scope = 'login',
    ?string $directory = null,
    ?int $now = null,
    ?string $ip = null
): array {
    $now ??= time();
    $file = brvtal_totp_rate_limit_path($adminId, $scope, $directory, $ip);
    $lock = brvtal_rate_limit_store_open_lock($file);
    if (!$lock) return brvtal_totp_rate_limit_blocked_fallback($file, $now);
    try {
        if (!flock($lock, LOCK_SH)) return brvtal_totp_rate_limit_blocked_fallback($file, $now);
        $stored = brvtal_rate_limit_store_read($file);
        if ($stored === null) return brvtal_totp_rate_limit_blocked_fallback($file, $now);
        return brvtal_totp_rate_limit_result($file, brvtal_totp_rate_limit_normalize($stored, $now), $now);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function brvtal_totp_rate_limit_failure(
    int $adminId,
    string $scope = 'login',
    ?string $directory = null,
    ?int $now = null,
    ?string $ip = null
): array {
    $now ??= time();
    $file = brvtal_totp_rate_limit_path($adminId, $scope, $directory, $ip);
    $lock = brvtal_rate_limit_store_open_lock($file);
    if (!$lock) return brvtal_totp_rate_limit_blocked_fallback($file, $now);
    try {
        if (!flock($lock, LOCK_EX)) return brvtal_totp_rate_limit_blocked_fallback($file, $now);
        $stored = brvtal_rate_limit_store_read($file);
        if ($stored === null) return brvtal_totp_rate_limit_blocked_fallback($file, $now);
        $data = brvtal_totp_rate_limit_normalize($stored, $now);
        if ((int)$data['blocked_until'] > $now) return brvtal_totp_rate_limit_result($file, $data, $now);

        $data['attempts'][] = $now;
        if (count($data['attempts']) > BRVTAL_TOTP_RATE_LIMIT_MAX_FAILURES) {
            $data['blocked_until'] = $now + BRVTAL_TOTP_RATE_LIMIT_BLOCK_SECONDS;
        }
        if (!brvtal_rate_limit_store_write($file, $data)) {
            return brvtal_totp_rate_limit_blocked_fallback($file, $now);
        }
        return brvtal_totp_rate_limit_result($file, $data, $now);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function brvtal_totp_rate_limit_reset(
    int $adminId,
    string $scope = 'login',
    ?string $directory = null,
    ?string $ip = null
): bool {
    $file = brvtal_totp_rate_limit_path($adminId, $scope, $directory, $ip);
    $lock = brvtal_rate_limit_store_open_lock($file);
    if (!$lock) return false;
    try {
        if (!flock($lock, LOCK_EX)) return false;
        return brvtal_rate_limit_store_write($file, ['attempts' => [], 'blocked_until' => 0]);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
