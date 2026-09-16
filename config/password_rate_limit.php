<?php
declare(strict_types=1);

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

function brvtal_password_rate_limit_state(
    string $email,
    ?string $directory = null,
    ?int $now = null,
    ?string $ip = null
): array {
    $now ??= time();
    $file = brvtal_password_rate_limit_path($email, $directory, $ip);
    if (!is_file($file)) return brvtal_password_rate_limit_result($file, ['attempts' => [], 'blocked_until' => 0], $now);

    $handle = @fopen($file, 'r');
    if (!$handle) return brvtal_password_rate_limit_result($file, ['attempts' => [], 'blocked_until' => 0], $now);
    try {
        if (!flock($handle, LOCK_SH)) return brvtal_password_rate_limit_result($file, ['attempts' => [], 'blocked_until' => 0], $now);
        $raw = stream_get_contents($handle) ?: '';
        $decoded = json_decode($raw, true);
        $data = brvtal_password_rate_limit_normalize(is_array($decoded) ? $decoded : [], $now);
        return brvtal_password_rate_limit_result($file, $data, $now);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
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
    $directoryPath = dirname($file);
    if (!is_dir($directoryPath) && !@mkdir($directoryPath, 0750, true) && !is_dir($directoryPath)) {
        return brvtal_password_rate_limit_result($file, ['attempts' => [], 'blocked_until' => $now + BRVTAL_PASSWORD_RATE_LIMIT_BLOCK_SECONDS], $now);
    }

    $handle = @fopen($file, 'c+');
    if (!$handle) return brvtal_password_rate_limit_result($file, ['attempts' => [], 'blocked_until' => $now + BRVTAL_PASSWORD_RATE_LIMIT_BLOCK_SECONDS], $now);
    try {
        if (!flock($handle, LOCK_EX)) return brvtal_password_rate_limit_result($file, ['attempts' => [], 'blocked_until' => $now + BRVTAL_PASSWORD_RATE_LIMIT_BLOCK_SECONDS], $now);
        rewind($handle);
        $raw = stream_get_contents($handle) ?: '';
        $decoded = json_decode($raw, true);
        $data = brvtal_password_rate_limit_normalize(is_array($decoded) ? $decoded : [], $now);
        if ((int)$data['blocked_until'] <= $now) {
            $data['attempts'][] = $now;
            if (count($data['attempts']) > BRVTAL_PASSWORD_RATE_LIMIT_MAX_FAILURES) {
                $data['blocked_until'] = $now + BRVTAL_PASSWORD_RATE_LIMIT_BLOCK_SECONDS;
            }
            $encoded = json_encode($data, JSON_UNESCAPED_SLASHES);
            if (!is_string($encoded)) return brvtal_password_rate_limit_result($file, ['attempts' => [], 'blocked_until' => $now + BRVTAL_PASSWORD_RATE_LIMIT_BLOCK_SECONDS], $now);
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, $encoded);
            fflush($handle);
        }
        return brvtal_password_rate_limit_result($file, $data, $now);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function brvtal_password_rate_limit_reset(
    string $email,
    ?string $directory = null,
    ?string $ip = null
): void {
    $file = brvtal_password_rate_limit_path($email, $directory, $ip);
    if (!is_file($file)) return;
    $handle = @fopen($file, 'r+');
    if (!$handle) return;
    try {
        if (flock($handle, LOCK_EX)) {
            rewind($handle);
            ftruncate($handle, 0);
            fflush($handle);
            @unlink($file);
        }
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
