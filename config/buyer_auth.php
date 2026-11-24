<?php
declare(strict_types=1);

/** Buyer-only security boundary. Never reuses BRVTAL_ADMIN or staff roles. */
require_once __DIR__ . '/password_rate_limit.php';

const BRVTAL_BUYER_SESSION_NAME = 'BRVTAL_BUYER';
const BRVTAL_BUYER_IDLE_SECONDS = 1800;
const BRVTAL_BUYER_ABSOLUTE_SECONDS = 43200;

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
    // Every authenticated request must validate its persisted hashed session
    // and epoch. Reset/revocation immediately invalidates every old PHP cookie.
    $buyerId = (int)($_SESSION['buyer_id'] ?? 0);
    if ($buyerId > 0) {
        $valid = false;
        try {
            if (function_exists('db')) {
                $check = db()->prepare("SELECT 1 FROM ticket_buyer_sessions s
                    JOIN ticket_buyers b ON b.id=s.buyer_id
                    WHERE s.buyer_id=? AND s.session_hash=?
                      AND s.revoked_at_utc IS NULL
                      AND s.expires_at_utc>UTC_TIMESTAMP(6)
                      AND s.issued_epoch=b.security_epoch
                      AND b.status='active' AND b.email_verified_at IS NOT NULL LIMIT 1");
                $check->execute([$buyerId, hash('sha256', session_id())]);
                $valid = $check->fetchColumn() !== false;
            }
        } catch (Throwable) {
            $valid = false;
        }
        if (!$valid) {
            $_SESSION = [];
            if (!session_regenerate_id(true)) {
                throw new RuntimeException('BUYER_SESSION_ROTATION_FAILED');
            }
            $_SESSION['buyer_issued_at'] = $now;
            $_SESSION['buyer_last_at'] = $now;
            $_SESSION['buyer_csrf'] = brvtalBuyerNewToken();
        }
    }
}

function brvtalBuyerCsrfValid(mixed $token): bool
{
    return is_string($token)
        && is_string($_SESSION['buyer_csrf'] ?? null)
        && hash_equals($_SESSION['buyer_csrf'], $token);
}

function brvtalBuyerLoginSession(int $id, ?PDO $pdo = null): void
{
    if ($id <= 0 || session_name() !== BRVTAL_BUYER_SESSION_NAME
        || session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException('BUYER_SESSION_REQUIRED');
    }
    $pdo ??= db();
    $query = $pdo->prepare("SELECT security_epoch FROM ticket_buyers WHERE id=?
        AND status='active' AND email_verified_at IS NOT NULL LIMIT 1");
    $query->execute([$id]);
    $epoch = $query->fetchColumn();
    if ($epoch === false || (int)$epoch < 1) {
        throw new RuntimeException('BUYER_SESSION_NOT_ELIGIBLE');
    }
    if (!session_regenerate_id(true)) {
        throw new RuntimeException('BUYER_SESSION_ROTATION_FAILED');
    }
    $save = $pdo->prepare('INSERT INTO ticket_buyer_sessions
        (buyer_id,session_hash,issued_epoch,expires_at_utc)
        VALUES (?,?,?,DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 12 HOUR))');
    $save->execute([$id, hash('sha256', session_id()), (int)$epoch]);
    $_SESSION = [
        'buyer_id' => $id, 'buyer_issued_at' => time(),
        'buyer_last_at' => time(), 'buyer_csrf' => brvtalBuyerNewToken(),
    ];
}

function brvtalBuyerLogout(): void
{
    if (session_status() === PHP_SESSION_ACTIVE && (int)($_SESSION['buyer_id'] ?? 0) > 0) {
        try {
            if (function_exists('db')) {
                $revoke = db()->prepare('UPDATE ticket_buyer_sessions
                    SET revoked_at_utc=UTC_TIMESTAMP(6)
                    WHERE buyer_id=? AND session_hash=? AND revoked_at_utc IS NULL');
                $revoke->execute([(int)$_SESSION['buyer_id'], hash('sha256', session_id())]);
            }
        } catch (Throwable) {
            // Local logout still revokes the browser's cookie; never leak DB details.
        }
    }
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
            AND COLUMN_NAME IN ('id', 'email', 'full_name', 'password_hash', 'email_verified_at')";
    return (int)$pdo->query($sql)->fetchColumn() === 5;
}

/**
 * #1988: temporary proof of the ORIGINAL checkout browser, never of email
 * ownership. A public /compra/<token> URL alone cannot create this grant.
 * Checkout must explicitly call this AFTER persisting the matching order.
 * Kept outside buyer_id so an existing email can never log into its account.
 */
const BRVTAL_BUYER_CHECKOUT_GRANT_SECONDS = 7200;
function brvtalBuyerCheckoutRememberOrder(int $buyerId, int $orderId, bool $createdNewBuyer): void
{
    if ($buyerId < 1 || $orderId < 1 || session_status() !== PHP_SESSION_ACTIVE
        || session_name() !== BRVTAL_BUYER_SESSION_NAME
        || (int)($_SESSION['buyer_id'] ?? 0) > 0) {
        throw new RuntimeException('BUYER_CHECKOUT_SESSION_REQUIRED');
    }
    $now = time();
    $prior = $_SESSION['buyer_checkout_orders'] ?? [];
    $grants = [];
    if (is_array($prior)) {
        foreach ($prior as $id => $entry) {
            if (!ctype_digit((string)$id) || !is_array($entry)
                || !is_int($entry['issued_at'] ?? null)
                || $entry['issued_at'] > $now
                || $entry['issued_at'] < $now - BRVTAL_BUYER_CHECKOUT_GRANT_SECONDS
                || !is_int($entry['buyer_id'] ?? null)
                || $entry['buyer_id'] < 1
                || !is_bool($entry['created_new'] ?? null)) {
                continue;
            }
            $grants[(string)$id] = $entry;
        }
    }
    $key = (string)$orderId;
    // Idempotent retries in this SAME browser must not lose the original
    // "new buyer" provenance; another browser never inherits that marker.
    $previous = $grants[$key] ?? null;
    $createdNewBuyer = $createdNewBuyer || (
        is_array($previous) && $previous['buyer_id'] === $buyerId
        && $previous['created_new'] === true
    );
    unset($grants[$key]);
    $grants[$key] = [
        'buyer_id' => $buyerId, 'created_new' => $createdNewBuyer,
        'issued_at' => $now,
    ];
    $grants = array_slice($grants, -8, null, true);
    // Rotate the browser's anonymous session at the time the grant is issued;
    // a fixed pre-checkout PHP session ID must never inherit paid-order access.
    if (!session_regenerate_id(true)) {
        throw new RuntimeException('BUYER_SESSION_ROTATION_FAILED');
    }
    $_SESSION['buyer_checkout_orders'] = $grants;
}

/**
 * Only a PAID order matching a short-lived grant in this PHP session is
 * usable. Never infer session authority from a bearer URL, buyer email,
 * payment redirect, or Wompi callback query parameter.
 * @return array{order_id:int,buyer_id:int,created_new:bool}|null
 */
function brvtalBuyerCheckoutPaidGrant(PDO $pdo, string $orderToken): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE
        || session_name() !== BRVTAL_BUYER_SESSION_NAME
        || preg_match('/^[a-f0-9]{32}$/D', $orderToken) !== 1) {
        return null;
    }
    $grants = $_SESSION['buyer_checkout_orders'] ?? null;
    if (!is_array($grants) || count($grants) > 8) return null;
    $stmt = $pdo->prepare("SELECT o.id,o.buyer_id,b.status AS buyer_status,b.email_verified_at
        FROM ticket_orders o JOIN ticket_buyers b ON b.id=o.buyer_id
        WHERE o.public_id=UNHEX(?) AND o.status='paid' LIMIT 1");
    $stmt->execute([$orderToken]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) return null;
    $id = (int)$row['id'];
    $buyerId = (int)$row['buyer_id'];
    $grant = $grants[(string)$id] ?? null;
    $now = time();
    if ($id < 1 || $buyerId < 1 || !is_array($grant)
        || !is_int($grant['buyer_id'] ?? null)
        || $grant['buyer_id'] !== $buyerId
        || !is_bool($grant['created_new'] ?? null)
        || !is_int($grant['issued_at'] ?? null)
        || $grant['issued_at'] > $now
        || $grant['issued_at'] < $now - BRVTAL_BUYER_CHECKOUT_GRANT_SECONDS) {
        return null;
    }
    // A new-email claim does not verify control of the inbox. In particular,
    // a reused or verified account MUST fall back to purchase-only access.
    $isFreshGuest = $grant['created_new'] === true
        && $row['buyer_status'] === 'guest' && $row['email_verified_at'] === null;
    return ['order_id'=>$id, 'buyer_id'=>$buyerId, 'created_new'=>$isFreshGuest];
}

