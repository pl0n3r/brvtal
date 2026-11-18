<?php
declare(strict_types=1);

/** Buyer-only security boundary. Never reuses BRVTAL_ADMIN or staff roles. */
require_once __DIR__ . '/password_rate_limit.php';

const BRVTAL_BUYER_SESSION_NAME = 'BRVTAL_BUYER';
const BRVTAL_BUYER_IDLE_SECONDS = 1800;
const BRVTAL_BUYER_ABSOLUTE_SECONDS = 43200;
// Unusable bcrypt hash: unknown accounts perform one comparable password_verify().
const BRVTAL_BUYER_DUMMY_PASSWORD_HASH = '$2y$12$pouUspTam7ujNcDBM.KC2.7bTXF0c0sMqn.DFmgLnkaqIMSV7TG.a';

function brvtalBuyerEmail(mixed $input): ?string
{
    if (!is_string($input)) return null;
    $email = strtolower(trim($input));
    if (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) return null;
    return $email;
}

function brvtalBuyerPasswordHash(mixed $input): ?string
{
    if (!is_string($input) || strlen($input) < 12 || strlen($input) > 72) return null;
    $hash = password_hash($input, PASSWORD_DEFAULT);
    return is_string($hash) ? $hash : null;
}

function brvtalBuyerNewToken(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function brvtalBuyerTokenDigest(string $token): string
{
    return hash('sha256', $token);
}

function brvtalBuyerSessionStart(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (session_name() !== BRVTAL_BUYER_SESSION_NAME) {
            throw new RuntimeException('BUYER_SESSION_COLLISION');
        }
        return;
    }
    session_name(BRVTAL_BUYER_SESSION_NAME);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Strict',
    ]);
    if (!session_start()) throw new RuntimeException('BUYER_SESSION_UNAVAILABLE');
    $now = time();
    $issued = (int)($_SESSION['buyer_issued_at'] ?? $now);
    $last = (int)($_SESSION['buyer_last_at'] ?? $now);
    if (($now - $issued) > BRVTAL_BUYER_ABSOLUTE_SECONDS
        || ($now - $last) > BRVTAL_BUYER_IDLE_SECONDS) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['buyer_issued_at'] ??= $now;
    $_SESSION['buyer_last_at'] = $now;
    $_SESSION['buyer_csrf'] ??= brvtalBuyerNewToken();
}

function brvtalBuyerCsrfValid(mixed $token): bool
{
    return is_string($token)
        && is_string($_SESSION['buyer_csrf'] ?? null)
        && hash_equals($_SESSION['buyer_csrf'], $token);
}

function brvtalBuyerLoginSession(int $id): void
{
    if ($id <= 0 || session_name() !== BRVTAL_BUYER_SESSION_NAME
        || session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException('BUYER_SESSION_REQUIRED');
    }
    session_regenerate_id(true);
    $_SESSION = [
        'buyer_id' => $id, 'buyer_issued_at' => time(),
        'buyer_last_at' => time(), 'buyer_csrf' => brvtalBuyerNewToken(),
    ];
}

function brvtalBuyerLogout(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        $settings = session_get_cookie_params();
        setcookie(BRVTAL_BUYER_SESSION_NAME, '', [
            'expires' => time() - 3600, 'path' => '/',
            'httponly' => true, 'secure' => (bool)$settings['secure'],
            'samesite' => 'Strict',
        ]);
        session_destroy();
    }
}

function brvtalBuyerThrottle(string $email, bool $failed = false): array
{
    // Separate lock namespace and hashed keys from staff authentication.
    $directory = __DIR__ . '/../storage/buyer_rate_limits';
    $key = 'buyer|' . $email;
    return $failed
        ? brvtal_password_rate_limit_failure($key, $directory)
        : brvtal_password_rate_limit_check($key, $directory);
}

function brvtalBuyerSchemaReady(PDO $pdo): bool
{
    $sql = "SELECT COUNT(DISTINCT COLUMN_NAME) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ticket_buyers'
            AND COLUMN_NAME IN ('id', 'email', 'password_hash', 'email_verified_at')";
    return (int)$pdo->query($sql)->fetchColumn() === 4;
}
