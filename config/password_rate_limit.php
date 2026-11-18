<?php
declare(strict_types=1);

require_once __DIR__ . '/rate_limit_store.php';

const BRVTAL_PASSWORD_RATE_LIMIT_WINDOW = 900;
const BRVTAL_PASSWORD_RATE_LIMIT_MAX_FAILURES = 5;
const BRVTAL_PASSWORD_RATE_LIMIT_BLOCK_SECONDS = 900;

function brvtal_password_rate_limit_path(string $email, ?string $directory = null, ?string $ip = null): string
{
    $directory ??= __DIR__ . '/../storage/rate_limits';
    $ip ??= (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $key = hash('sha256', $ip . '|' . strtolower(trim($email)));
    return rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $key . '.json';
}

function brvtal_password_rate_limit_normalize(array $data, int $now): array
{
    $attempts = array_values(array_filter(
        (array)($data['attempts'] ?? []),
        static fn($timestamp): bool => is_int($timestamp) && $timestamp > $now - BRVTAL_PASSWORD_RATE_LIMIT_WINDOW
    ));
    $blockedUntil = (int)($data['blocked_until'] ?? 0);
    if ($blockedUntil <= $now) $blockedUntil = 0;
    return ['attempts' => $attempts, 'blocked_until' => $blockedUntil];
}

function brvtal_password_rate_limit_result(string $file, array $data, int $now): array
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

function brvtal_password_rate_limit_blocked_fallback(string $file, int $now): array
{
    return brvtal_password_rate_limit_result(
        $file,
        ['attempts' => [], 'blocked_until' => $now + BRVTAL_PASSWORD_RATE_LIMIT_BLOCK_SECONDS],
        $now
    );
}

function brvtal_password_rate_limit_state(
    string $email,
    ?string $directory = null,
    ?int $now = null,
    ?string $ip = null
): array {
    $now ??= time();
    $file = brvtal_password_rate_limit_path($email, $directory, $ip);
    $lock = brvtal_rate_limit_store_open_lock($file);
    if (!$lock) return brvtal_password_rate_limit_blocked_fallback($file, $now);
    try {
        if (!flock($lock, LOCK_SH)) return brvtal_password_rate_limit_blocked_fallback($file, $now);
        $stored = brvtal_rate_limit_store_read($file);
        if ($stored === null) return brvtal_password_rate_limit_blocked_fallback($file, $now);
        return brvtal_password_rate_limit_result($file, brvtal_password_rate_limit_normalize($stored, $now), $now);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function brvtal_password_rate_limit_check(
    string $email,
    ?string $directory = null,
    ?int $now = null,
    ?string $ip = null
): array {
    return brvtal_password_rate_limit_state($email, $directory, $now, $ip);
}

function brvtal_password_rate_limit_failure(
    string $email,
    ?string $directory = null,
    ?int $now = null,
    ?string $ip = null
): array {
    $now ??= time();
    $file = brvtal_password_rate_limit_path($email, $directory, $ip);
    $lock = brvtal_rate_limit_store_open_lock($file);
    if (!$lock) return brvtal_password_rate_limit_blocked_fallback($file, $now);
    try {
        if (!flock($lock, LOCK_EX)) return brvtal_password_rate_limit_blocked_fallback($file, $now);
        $stored = brvtal_rate_limit_store_read($file);
        if ($stored === null) return brvtal_password_rate_limit_blocked_fallback($file, $now);
        $data = brvtal_password_rate_limit_normalize($stored, $now);
        if ((int)$data['blocked_until'] > $now) return brvtal_password_rate_limit_result($file, $data, $now);

        $data['attempts'][] = $now;
        if (count($data['attempts']) > BRVTAL_PASSWORD_RATE_LIMIT_MAX_FAILURES) {
            $data['blocked_until'] = $now + BRVTAL_PASSWORD_RATE_LIMIT_BLOCK_SECONDS;
        }
        if (!brvtal_rate_limit_store_write($file, $data)) {
            return brvtal_password_rate_limit_blocked_fallback($file, $now);
        }
        return brvtal_password_rate_limit_result($file, $data, $now);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function brvtal_password_rate_limit_reset(
    string $email,
    ?string $directory = null,
    ?string $ip = null
): bool {
    $file = brvtal_password_rate_limit_path($email, $directory, $ip);
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
