<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/public_contact.php';

function contact_rate_store_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "CONTACT RATE STORE CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$now = 1_800_000_000;
$key = hash('sha256', '127.0.0.1');
$directory = sys_get_temp_dir() . '/brvtal-contact-rate-store-' . bin2hex(random_bytes(6));
contact_rate_store_assert(mkdir($directory, 0700, true), 'temporary directory can be created');
$path = $directory . '/contact-rate-limit.json';

try {
    $first = brvtal_contact_consume_rate_limit($key, $path, $now, 5, 900);
    contact_rate_store_assert($first['allowed'] === true, 'healthy atomic persistence allows the request');
    contact_rate_store_assert(is_file($path), 'healthy persistence creates the state file');
    $state = json_decode((string)file_get_contents($path), true);
    contact_rate_store_assert(is_array($state), 'persisted Contact state remains valid JSON');
    contact_rate_store_assert(($state[$key] ?? []) === [$now], 'persisted bucket contains the accepted request');
    contact_rate_store_assert(is_file($path . '.lock'), 'Contact uses a stable sibling lock file');

    file_put_contents($path, '{broken-json', LOCK_EX);
    $corruptBefore = (string)file_get_contents($path);
    $corrupt = brvtal_contact_consume_rate_limit($key, $path, $now + 1, 5, 900);
    contact_rate_store_assert($corrupt['allowed'] === false, 'corrupt state fails closed');
    contact_rate_store_assert($corrupt['error'] === 'RATE_LIMIT_UNAVAILABLE', 'corrupt state reports unavailable limiter');
    contact_rate_store_assert((string)file_get_contents($path) === $corruptBefore, 'corrupt state is not silently replaced with an empty limiter');

    $validState = json_encode([$key => [$now]], JSON_UNESCAPED_SLASHES);
    contact_rate_store_assert(is_string($validState), 'valid fixture state encodes');
    file_put_contents($path, $validState, LOCK_EX);
    @chmod($path . '.lock', 0600);
    @chmod($directory, 0500);

    // On ordinary POSIX CI users, removing directory write permission allows the
    // existing lock to open but prevents creation of the atomic temporary file.
    if (!is_writable($directory)) {
        $beforeFailedReplace = (string)file_get_contents($path);
        $failed = brvtal_contact_consume_rate_limit($key, $path, $now + 2, 5, 900);
        contact_rate_store_assert($failed['allowed'] === false, 'replacement I/O failure fails closed');
        contact_rate_store_assert($failed['error'] === 'RATE_LIMIT_UNAVAILABLE', 'replacement I/O failure reports unavailable limiter');
        contact_rate_store_assert((string)file_get_contents($path) === $beforeFailedReplace, 'failed replacement preserves the last valid state');
    }
} finally {
    @chmod($directory, 0700);
    @unlink($path);
    @unlink($path . '.lock');
    foreach (glob($path . '.tmp.*') ?: [] as $temporary) @unlink($temporary);
    @rmdir($directory);
}

$contactSource = (string)file_get_contents(__DIR__ . '/../config/public_contact.php');
$storeSource = (string)file_get_contents(__DIR__ . '/../config/rate_limit_store.php');
contact_rate_store_assert(
    str_contains($contactSource, "require_once __DIR__ . '/rate_limit_store.php';"),
    'Contact reuses the shared rate-limit store boundary'
);
contact_rate_store_assert(
    str_contains($contactSource, 'brvtal_rate_limit_store_open_lock($path)'),
    'Contact serializes read-modify-write through the stable sibling lock'
);
contact_rate_store_assert(
    str_contains($contactSource, 'brvtal_rate_limit_store_atomic_replace($path, $encoded)'),
    'Contact persists state through atomic replacement'
);
foreach (['$offset !== $length', 'fflush($handle)', "function_exists('fsync')", '@rename($temporary, $file)'] as $needle) {
    contact_rate_store_assert(str_contains($storeSource, $needle), "shared atomic replacement enforces {$needle}");
}

echo "BRVTAL Contact rate-limit persistence contract passed.\n";
