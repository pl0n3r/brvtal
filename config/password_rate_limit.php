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

function brvtal_password_rate_limit_state(
    string $email,
    ?string $directory = null,
    ?int $now = null,
    ?string $ip = null
): array {
    $now ??= time();
    $file = brvtal_password_rate_limit_path($email, $directory, $ip);
    $data = ['attempts' => [], 'blocked_until' => 0];
    if (is_file($file)) {
        $decoded = json_decode((string)@file_get_contents($file), true);
        if (is_array($decoded)) $data = array_replace($data, $decoded);
    }

    $data['attempts'] = array_values(array_filter(
        (array)$data['attempts'],
        static fn($timestamp): bool => is_int($timestamp) && $timestamp > $now - BRVTAL_PASSWORD_RATE_LIMIT_WINDOW
    ));
    $blockedUntil = (int)($data['blocked_until'] ?? 0);
    if ($blockedUntil <= $now) $blockedUntil = 0;

    return [
        'file' => $file,
        'attempts' => $data['attempts'],
        'blocked_until' => $blockedUntil,
        'limited' => $blockedUntil > $now,
        'retry_after' => $blockedUntil > $now ? $blockedUntil - $now : 0,
    ];
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
    $state = brvtal_password_rate_limit_state($email, $directory, $now, $ip);
    if ($state['limited']) return $state;

    $attempts = $state['attempts'];
    $attempts[] = $now;
    $blockedUntil = 0;
    if (count($attempts) > BRVTAL_PASSWORD_RATE_LIMIT_MAX_FAILURES) {
        $blockedUntil = $now + BRVTAL_PASSWORD_RATE_LIMIT_BLOCK_SECONDS;
    }

    $directoryPath = dirname((string)$state['file']);
    if (!is_dir($directoryPath)) @mkdir($directoryPath, 0750, true);
    @file_put_contents(
        (string)$state['file'],
        json_encode(['attempts' => $attempts, 'blocked_until' => $blockedUntil]),
        LOCK_EX
    );

    return [
        'file' => $state['file'],
        'attempts' => $attempts,
        'blocked_until' => $blockedUntil,
        'limited' => $blockedUntil > $now,
        'retry_after' => $blockedUntil > $now ? $blockedUntil - $now : 0,
    ];
}

function brvtal_password_rate_limit_reset(
    string $email,
    ?string $directory = null,
    ?string $ip = null
): void {
    $file = brvtal_password_rate_limit_path($email, $directory, $ip);
    if (is_file($file)) @unlink($file);
}
