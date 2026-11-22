<?php
declare(strict_types=1);

require_once __DIR__ . '/buyer_auth.php';
require_once __DIR__ . '/version.php';
require_once __DIR__ . '/admin_password_security.php';
require_once __DIR__ . '/ticketing_mail_outbox.php';
require_once __DIR__ . '/buyer_auth_mail_templates.php';

const BRVTAL_BUYER_VERIFY_TTL = 86400;
const BRVTAL_BUYER_RESET_TTL = 1800;

/** All account + token + sealed outbox writes are one atomic operation. */
function brvtalBuyerAuthTransaction(PDO $pdo, callable $operation): mixed
{
    $pdo->beginTransaction();
    try {
        $result = $operation($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function brvtalBuyerAuthPassword(mixed $password, string $oldHash = ''): string
{
    if (!is_string($password) || strlen($password) > 72
        || ($error = brvtal_admin_password_error($password, $oldHash)) !== null) {
        throw new InvalidArgumentException($error ?? 'PASSWORD_INVALID');
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if (!is_string($hash) || $hash === '') {
        throw new RuntimeException('BUYER_PASSWORD_HASH_FAILED');
    }
    return $hash;
}

function brvtalBuyerAuthToken(PDO $pdo, int $buyerId, string $purpose): array
{
    if (!in_array($purpose, ['verify_email', 'reset_password'], true) || $buyerId < 1) {
        throw new InvalidArgumentException('BUYER_TOKEN_PURPOSE_INVALID');
    }
    $revoke = $pdo->prepare('UPDATE ticket_buyer_tokens SET revoked_at_utc=UTC_TIMESTAMP(6)
        WHERE buyer_id=? AND purpose=? AND consumed_at_utc IS NULL AND revoked_at_utc IS NULL');
    $revoke->execute([$buyerId, $purpose]);
    $raw = brvtalBuyerNewToken();
    $ttl = $purpose === 'verify_email' ? BRVTAL_BUYER_VERIFY_TTL : BRVTAL_BUYER_RESET_TTL;
    $insert = $pdo->prepare('INSERT INTO ticket_buyer_tokens
        (buyer_id,purpose,token_hash,expires_at_utc)
        VALUES (?,?,?,DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ' . $ttl . ' SECOND))');
    $insert->execute([$buyerId, $purpose, brvtalBuyerTokenDigest($raw)]);
    return ['id' => (int)$pdo->lastInsertId(), 'raw' => $raw];
}

function brvtalBuyerAuthQueue(PDO $pdo, int $buyerId, int $tokenId, string $kind,
    string $email, string $token): void
{
    if (!$pdo->inTransaction()) throw new RuntimeException('BUYER_MAIL_TRANSACTION_REQUIRED');
    $mail = brvtalBuyerMailContent($kind, $token);
    // No raw bearer token is stored anywhere, including ticket_mail_outbox.body_text.
    brvtal_ticket_mail_enqueue($pdo, "buyer:{$buyerId}:{$kind}:{$tokenId}",
        "buyer:{$buyerId}", $email, $mail['subject'], $mail['sealed']);
}

function brvtalBuyerAuthThrottle(string $scope, string $email, ?string $directory = null): bool
{
    $directory ??= __DIR__ . '/../storage/buyer_rate_limits';
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $perIp = brvtal_password_rate_limit_failure('__buyer_' . $scope . '_ip__',
        $directory, null, $ip);
    $perEmail = brvtal_password_rate_limit_failure('__buyer_' . $scope . '_email__' . $email,
        $directory, null, '__account__');
    return !$perIp['limited'] && !$perEmail['limited'];
}

/** Identical public outcome for an existing and a new email. */
function brvtalBuyerAuthRegister(PDO $pdo, array $input): array
{
    $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
    $email = brvtalBuyerEmail($input['email'] ?? null);
    $consent = $input['terms_accepted'] ?? $input['accepted_terms'] ?? false;
    // Provenance comes from server release metadata, never a browser-controlled version.
    $version = BRVTAL_APP_VERSION;
    $privacy = $input['privacy_accepted'] ?? $consent;
    if ($email === null || mb_strlen($name) < 2 || mb_strlen($name) > 120
        || preg_match('/[\x00-\x1f\x7f]/u', $name)
        || $consent !== true || $privacy !== true
        || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,39}$/D', $version) !== 1) {
        throw new InvalidArgumentException('BUYER_PROFILE_INVALID');
    }
    $hash = brvtalBuyerAuthPassword($input['password'] ?? null);
    return brvtalBuyerAuthTransaction($pdo, static function (PDO $pdo) use
        ($name, $email, $hash, $version): array {
        $query = $pdo->prepare('SELECT id FROM ticket_buyers WHERE email=? FOR UPDATE');
        $query->execute([$email]);
        if ($query->fetchColumn() !== false) {
            return ['ok' => true]; // Do not reveal whether this address exists.
        }
        try {
            $insert = $pdo->prepare("INSERT INTO ticket_buyers
                (public_id,email,full_name,password_hash,consent_version,consent_recorded_at,status)
                VALUES (?,?,?,?,?,UTC_TIMESTAMP(6),'active')");
            $insert->execute([random_bytes(16), $email, $name, $hash, $version]);
        } catch (PDOException $error) {
            if ((string)$error->getCode() === '23000' && (int)($error->errorInfo[1] ?? 0) === 1062) {
                return ['ok' => true]; // Race: unique email wins; same neutral response.
            }
            throw $error;
        }
        $buyerId = (int)$pdo->lastInsertId();
        $token = brvtalBuyerAuthToken($pdo, $buyerId, 'verify_email');
        brvtalBuyerAuthQueue($pdo, $buyerId, $token['id'], 'verify_account', $email, $token['raw']);
        return ['ok' => true];
    });
}

function brvtalBuyerAuthConsume(PDO $pdo, string $token, string $purpose,
    ?string $newPassword = null): void
{
    if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) !== 1) {
        throw new DomainException('BUYER_TOKEN_INVALID');
    }
    brvtalBuyerAuthTransaction($pdo, static function (PDO $pdo) use ($token, $purpose, $newPassword): void {
        $q = $pdo->prepare("SELECT t.id,t.buyer_id,b.email_verified_at,b.password_hash,b.status
            FROM ticket_buyer_tokens t JOIN ticket_buyers b ON b.id=t.buyer_id
            WHERE t.token_hash=? AND t.purpose=? AND t.consumed_at_utc IS NULL
              AND t.revoked_at_utc IS NULL AND t.expires_at_utc>UTC_TIMESTAMP(6) FOR UPDATE");
        $q->execute([brvtalBuyerTokenDigest($token), $purpose]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $row['status'] !== 'active') {
            throw new DomainException('BUYER_TOKEN_INVALID');
        }
        $buyerId = (int)$row['buyer_id'];
        if ($purpose === 'verify_email') {
            $pdo->prepare('UPDATE ticket_buyers SET email_verified_at=UTC_TIMESTAMP(6)
                WHERE id=? AND email_verified_at IS NULL')->execute([$buyerId]);
        } elseif ($purpose === 'reset_password' && $row['email_verified_at'] !== null) {
            $hash = brvtalBuyerAuthPassword($newPassword, (string)$row['password_hash']);
            $pdo->prepare('UPDATE ticket_buyers SET password_hash=?,
                security_epoch=security_epoch+1 WHERE id=?')->execute([$hash, $buyerId]);
            $pdo->prepare('UPDATE ticket_buyer_sessions SET revoked_at_utc=UTC_TIMESTAMP(6)
                WHERE buyer_id=? AND revoked_at_utc IS NULL')->execute([$buyerId]);
        } else {
            throw new DomainException('BUYER_TOKEN_INVALID');
        }
        $pdo->prepare('UPDATE ticket_buyer_tokens SET consumed_at_utc=UTC_TIMESTAMP(6)
            WHERE id=? AND consumed_at_utc IS NULL AND revoked_at_utc IS NULL')->execute([(int)$row['id']]);
        $pdo->prepare('UPDATE ticket_buyer_tokens SET revoked_at_utc=UTC_TIMESTAMP(6)
            WHERE buyer_id=? AND consumed_at_utc IS NULL AND revoked_at_utc IS NULL')->execute([$buyerId]);
    });
}

function brvtalBuyerAuthRequest(PDO $pdo, mixed $emailInput, string $kind,
    ?string $rateDirectory = null): array
{
    $email = brvtalBuyerEmail($emailInput);
    if ($email === null) return ['ok' => true];
    if (!brvtalBuyerAuthThrottle($kind, $email, $rateDirectory)) return ['ok' => true];
    brvtalBuyerAuthTransaction($pdo, static function (PDO $pdo) use ($email, $kind): void {
        $q = $pdo->prepare('SELECT id,email_verified_at,status FROM ticket_buyers WHERE email=? FOR UPDATE');
        $q->execute([$email]);
        $buyer = $q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($buyer) || $buyer['status'] !== 'active') return;
        if ($kind === 'reset_password' && $buyer['email_verified_at'] === null) return;
        if ($kind === 'verify_email' && $buyer['email_verified_at'] !== null) return;
        $id = (int)$buyer['id'];
        $token = brvtalBuyerAuthToken($pdo, $id, $kind);
        $mailKind = $kind === 'verify_email' ? 'verify_account' : 'reset_password';
        brvtalBuyerAuthQueue($pdo, $id, $token['id'], $mailKind, $email, $token['raw']);
    });
    return ['ok' => true]; // Never disclose account presence or mail delivery.
}
