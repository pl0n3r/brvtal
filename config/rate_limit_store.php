<?php
declare(strict_types=1);

/**
 * File-backed rate-limit storage with a stable sibling lock file.
 *
 * State is written to a temporary file and atomically renamed only after the
 * full payload has been written and flushed. The .lock inode remains stable
 * across state-file replacement so concurrent requests serialize correctly.
 */

function brvtal_rate_limit_store_ensure_directory(string $file): bool
{
    $directory = dirname($file);
    return is_dir($directory) || (@mkdir($directory, 0750, true) || is_dir($directory));
}

function brvtal_rate_limit_store_lock_path(string $file): string
{
    return $file . '.lock';
}

/** @return resource|false */
function brvtal_rate_limit_store_open_lock(string $file)
{
    if (!brvtal_rate_limit_store_ensure_directory($file)) return false;
    return @fopen(brvtal_rate_limit_store_lock_path($file), 'c+');
}

/**
 * @return array{attempts:array<int,int>,blocked_until:int}|null
 */
function brvtal_rate_limit_store_read(string $file): ?array
{
    if (!is_file($file)) return ['attempts' => [], 'blocked_until' => 0];
    $raw = @file_get_contents($file);
    if ($raw === false) return null;
    if (trim($raw) === '') return ['attempts' => [], 'blocked_until' => 0];
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return null;
    return [
        'attempts' => array_values(array_filter(
            (array)($decoded['attempts'] ?? []),
            static fn($timestamp): bool => is_int($timestamp)
        )),
        'blocked_until' => (int)($decoded['blocked_until'] ?? 0),
    ];
}

function brvtal_rate_limit_store_write(string $file, array $state): bool
{
    $encoded = json_encode([
        'attempts' => array_values((array)($state['attempts'] ?? [])),
        'blocked_until' => (int)($state['blocked_until'] ?? 0),
    ], JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) return false;
    if (!brvtal_rate_limit_store_ensure_directory($file)) return false;

    try {
        $suffix = bin2hex(random_bytes(8));
    } catch (Throwable) {
        return false;
    }
    $temporary = $file . '.tmp.' . $suffix;
    $handle = @fopen($temporary, 'x+b');
    if (!$handle) return false;

    $ok = true;
    try {
        $length = strlen($encoded);
        $offset = 0;
        while ($offset < $length) {
            $written = fwrite($handle, substr($encoded, $offset));
            if (!is_int($written) || $written <= 0) {
                $ok = false;
                break;
            }
            $offset += $written;
        }
        if ($offset !== $length) $ok = false;
        if ($ok && !fflush($handle)) $ok = false;
        if ($ok && function_exists('fsync') && !fsync($handle)) $ok = false;
    } finally {
        fclose($handle);
    }

    if (!$ok) {
        @unlink($temporary);
        return false;
    }

    @chmod($temporary, 0640);
    if (!@rename($temporary, $file)) {
        @unlink($temporary);
        return false;
    }
    return true;
}
