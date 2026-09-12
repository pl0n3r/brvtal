<?php
declare(strict_types=1);

/**
 * BRVTAL TOTP foundation (RFC 6238 / SHA-1 / 30s / 6 digits).
 * Foundation only: no login flow calls this file yet.
 */
require_once __DIR__ . '/bootstrap.php';

function brvtal_totp_base32_decode(string $value): string
{
    $value = strtoupper(preg_replace('/[^A-Z2-7]/', '', $value) ?? '');
    if ($value === '') return '';
    $bits = '';
    foreach (str_split($value) as $char) {
        $n = strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', $char);
        if ($n === false) return '';
        $bits .= str_pad(decbin($n), 5, '0', STR_PAD_LEFT);
    }
    $bytes = '';
    for ($i = 0, $len = strlen($bits) - 7; $i <= $len; $i += 8) {
        $bytes .= chr(bindec(substr($bits, $i, 8)));
    }
    return $bytes;
}

function brvtal_totp_base32_encode(string $value): string
{
    if ($value === '') return '';
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (unpack('C*', $value) ?: [] as $byte) {
        $bits .= str_pad(decbin((int)$byte), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    for ($i = 0, $len = strlen($bits); $i < $len; $i += 5) {
        $chunk = substr($bits, $i, 5);
        if (strlen($chunk) < 5) $chunk = str_pad($chunk, 5, '0');
        $out .= $alphabet[bindec($chunk)];
    }
    return $out;
}

function brvtal_totp_generate_secret(int $bytes = 20): string
{
    if ($bytes < 16 || $bytes > 64) throw new InvalidArgumentException('Invalid TOTP secret length');
    return brvtal_totp_base32_encode(random_bytes($bytes));
}

function brvtal_totp_encryption_key(): string
{
    global $config;
    $security = is_array($config['security'] ?? null) ? $config['security'] : [];
    $configured = trim((string)($security['encryption_key'] ?? ''));

    // Existing BRVTAL installations predate the dedicated encryption_key. Their
    // long random CSRF secret is a stable, server-only compatibility key until
    // configuration is rotated deliberately.
    if (strlen($configured) < 32) {
        $configured = trim((string)($security['csrf_key'] ?? ''));
    }
    if (strlen($configured) >= 32) return hash('sha256', $configured, true);
    $databaseKey = brvtal_totp_database_key(true);
    if ($databaseKey === null) throw new RuntimeException('TOTP encryption key is not configured.');
    return hash('sha256', $databaseKey, true);
}

function brvtal_totp_database_key(bool $create): ?string
{
    $pdo = db();
    $select = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key='security.totp_encryption_key' LIMIT 1");
    $select->execute();
    $stored = trim((string)$select->fetchColumn());
    if (strlen($stored) >= 32) return $stored;
    if (!$create) return null;

    $generated = base64_encode(random_bytes(32));
    $insert = $pdo->prepare("INSERT INTO settings(setting_key,setting_value,is_json) VALUES('security.totp_encryption_key',?,0) ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key)");
    $insert->execute([$generated]);
    $select->execute();
    $stored = trim((string)$select->fetchColumn());
    return strlen($stored) >= 32 ? $stored : null;
}

function brvtal_totp_decryption_keys(): array
{
    global $config;
    $security = is_array($config['security'] ?? null) ? $config['security'] : [];
    $keys = [];
    foreach (['encryption_key', 'csrf_key'] as $name) {
        $configured = trim((string)($security[$name] ?? ''));
        if (strlen($configured) >= 32) $keys[] = hash('sha256', $configured, true);
    }
    $databaseKey = brvtal_totp_database_key(false);
    if ($databaseKey !== null) $keys[] = hash('sha256', $databaseKey, true);
    return array_values(array_unique($keys));
}

function brvtal_totp_code(string $secret, ?int $timestamp = null, int $digits = 6, int $period = 30): string
{
    $key = brvtal_totp_base32_decode($secret);
    if ($key === '') throw new InvalidArgumentException('Invalid TOTP secret');
    $timestamp ??= time();
    $counter = intdiv($timestamp, $period);
    $counterBytes = pack('N2', ($counter >> 32) & 0xFFFFFFFF, $counter & 0xFFFFFFFF);
    $hash = hash_hmac('sha1', $counterBytes, $key, true);
    $offset = ord($hash[19]) & 0x0F;
    $binary = ((ord($hash[$offset]) & 0x7F) << 24)
        | (ord($hash[$offset + 1]) << 16)
        | (ord($hash[$offset + 2]) << 8)
        | ord($hash[$offset + 3]);
    return str_pad((string)($binary % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

function brvtal_totp_verify(string $secret, string $code, ?int $timestamp = null, int $window = 1): bool
{
    $code = preg_replace('/\D/', '', $code) ?? '';
    if (strlen($code) !== 6 || $window < 0 || $window > 2) return false;
    $timestamp ??= time();
    for ($offset = -$window; $offset <= $window; $offset++) {
        $expected = brvtal_totp_code($secret, $timestamp + ($offset * 30));
        if (hash_equals($expected, $code)) return true;
    }
    return false;
}

function brvtal_totp_otpauth_uri(string $secret, string $account, string $issuer = 'BRVTAL'): string
{
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
        . '?secret=' . rawurlencode($secret)
        . '&issuer=' . rawurlencode($issuer)
        . '&algorithm=SHA1&digits=6&period=30';
}
