<?php
declare(strict_types=1);

require_once __DIR__ . '/buyer_auth.php';
require_once __DIR__ . '/version.php';
require_once __DIR__ . '/password_policy.php';
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
    if (!is_string($password)
        || ($error = brvtal_admin_password_error($password, $oldHash)) !== null) {
        throw new InvalidArgumentException($error ?? 'PASSWORD_INVALID');
    }
    // Bcrypt silently truncates after 72 bytes. Use Argon2id for longer valid
    // passwords instead of accepting two distinct phrases as one credential.
    if (strlen($password) > 72 && !defined('PASSWORD_ARGON2ID')) {
        throw new InvalidArgumentException('PASSWORD_INVALID');
    }
    $algorithm = strlen($password) > 72 ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    $hash = password_hash($password, $algorithm);
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
    mixed $newPassword = null): void
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
        if (!is_array($row)) throw new DomainException('BUYER_TOKEN_INVALID');
        // A purchase-email token can establish ownership of a guest mailbox.
        // It never upgrades a different, existing registered account.
        $newGuest = $purpose === 'reset_password'
            && $row['status'] === 'guest'
            && $row['email_verified_at'] === null
            && $row['password_hash'] === null;
        if ($row['status'] !== 'active' && !$newGuest) {
            throw new DomainException('BUYER_TOKEN_INVALID');
        }
        $buyerId = (int)$row['buyer_id'];
        if ($purpose === 'verify_email') {
            if ($row['email_verified_at'] !== null) {
                throw new DomainException('BUYER_TOKEN_INVALID');
            }
            // Only the mailbox holder chooses the first usable credential.
            // Pending registrations keep their existing token and consent; the
            // unverified signup hash never becomes a usable login credential.
            $hash = brvtalBuyerAuthPassword($newPassword, (string)$row['password_hash']);
            $save = $pdo->prepare("UPDATE ticket_buyers SET password_hash=?,
                email_verified_at=UTC_TIMESTAMP(6),security_epoch=security_epoch+1
                WHERE id=? AND status='active' AND email_verified_at IS NULL");
            $save->execute([$hash,$buyerId]);
            if ($save->rowCount() !== 1) throw new DomainException('BUYER_TOKEN_INVALID');
            $pdo->prepare('UPDATE ticket_buyer_sessions SET revoked_at_utc=UTC_TIMESTAMP(6)
                WHERE buyer_id=? AND revoked_at_utc IS NULL')->execute([$buyerId]);
        } elseif ($purpose === 'reset_password'
            && ($row['email_verified_at'] !== null || $newGuest)) {
            if ($newGuest) {
                // The order is the trusted provenance. An arbitrary guest
                // cannot create an account using an unrelated reset token.
                $paid = $pdo->prepare("SELECT 1 FROM ticket_orders
                    WHERE buyer_id=? AND status='paid' LIMIT 1");
                $paid->execute([$buyerId]);
                if ($paid->fetchColumn() === false) {
                    throw new DomainException('BUYER_TOKEN_INVALID');
                }
            }
            $hash = brvtalBuyerAuthPassword($newPassword, (string)$row['password_hash']);
            $sql = $newGuest
                ? "UPDATE ticket_buyers SET password_hash=?,status='active',
                    email_verified_at=UTC_TIMESTAMP(6),security_epoch=security_epoch+1
                    WHERE id=? AND status='guest' AND password_hash IS NULL
                      AND email_verified_at IS NULL"
                : "UPDATE ticket_buyers SET password_hash=?,security_epoch=security_epoch+1
                    WHERE id=? AND status='active' AND email_verified_at IS NOT NULL";
            $save = $pdo->prepare($sql);
            $save->execute([$hash,$buyerId]);
            if ($save->rowCount() !== 1) throw new DomainException('BUYER_TOKEN_INVALID');
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


/**
 * Mint the existing reset-password token inside the trusted paid-issuance
 * transaction. Return a link only for a genuine guest without any password.
 * The URL lives in memory until the already sealed purchase mail is queued.
 */
function brvtalBuyerAuthGuestPurchaseLink(PDO $pdo, int $orderId): ?string
{
    if (!$pdo->inTransaction()) throw new LogicException('BUYER_MAIL_TRANSACTION_REQUIRED');
    if ($orderId < 1) throw new InvalidArgumentException('BUYER_ORDER_INVALID');
    $q = $pdo->prepare("SELECT b.id,b.email,b.status,b.password_hash,b.email_verified_at
        FROM ticket_orders o
        JOIN ticket_buyers b ON b.id=o.buyer_id
        JOIN ticket_issue_outbox x ON x.order_id=o.id AND x.state='done'
        WHERE o.id=? AND o.status='paid' LIMIT 1 FOR UPDATE");
    $q->execute([$orderId]);
    $buyer = $q->fetch(PDO::FETCH_ASSOC);
    if (!is_array($buyer) || $buyer['status'] !== 'guest'
        || $buyer['password_hash'] !== null || $buyer['email_verified_at'] !== null
        || brvtalBuyerEmail($buyer['email'] ?? null) === null) return null;
    $token = brvtalBuyerAuthToken($pdo,(int)$buyer['id'],'reset_password');
    return brvtalBuyerMailGuestPasswordLink($token['raw']);
}

/** Resolve a public action inside the auth service, not as an inline password literal. */
function brvtalBuyerAuthRequestForAction(PDO $pdo, mixed $email, string $action): array
{
    $purposes = [
        'request-reset' => 'reset_password',
        'resend-verification' => 'verify_email',
    ];
    if (!array_key_exists($action, $purposes)) {
        throw new InvalidArgumentException('BUYER_ACTION_INVALID');
    }
    return brvtalBuyerAuthRequest($pdo, $email, $purposes[$action]);
}

function brvtalBuyerAuthRequest(PDO $pdo, mixed $emailInput, string $kind,
    ?string $rateDirectory = null): array
{
    $email = brvtalBuyerEmail($emailInput);
    if ($email === null) return ['ok' => true];
    if (!brvtalBuyerAuthThrottle($kind, $email, $rateDirectory)) return ['ok' => true];
    brvtalBuyerAuthTransaction($pdo, static function (PDO $pdo) use ($email, $kind): void {
        $q = $pdo->prepare('SELECT id,email_verified_at,status,password_hash
            FROM ticket_buyers WHERE email=? FOR UPDATE');
        $q->execute([$email]);
        $buyer = $q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($buyer) || !in_array($buyer['status'], ['active','guest'], true)) return;
        $guest = $buyer['status'] === 'guest' && $buyer['password_hash'] === null
            && $buyer['email_verified_at'] === null;
        if ($kind === 'reset_password' && $buyer['email_verified_at'] === null) {
            if (!$guest) return;
            // A guest may request a new link after expiration only if they
            // actually have a paid order. The public response stays neutral.
            $paid = $pdo->prepare("SELECT 1 FROM ticket_orders
                WHERE buyer_id=? AND status='paid' LIMIT 1");
            $paid->execute([(int)$buyer['id']]);
            if ($paid->fetchColumn() === false) return;
        }
        if ($kind === 'verify_email'
            && ($buyer['status'] !== 'active' || $buyer['email_verified_at'] !== null)) return;
        $id = (int)$buyer['id'];
        $token = brvtalBuyerAuthToken($pdo, $id, $kind);
        $mailKind = $kind === 'verify_email' ? 'verify_account' : 'reset_password';
        brvtalBuyerAuthQueue($pdo, $id, $token['id'], $mailKind, $email, $token['raw']);
    });
    return ['ok' => true]; // Never disclose account presence or mail delivery.
}

/**
 * #1877: privacy-preserving 72h expiration for still-unverified buyer signups.
 * Bounded, transactional anonymization preserves FK and paid-order history.
 * No production mutation without an explicit operator retention authorization.
 * Synthetic tests may run only on isolated brvtal_test* databases.
 */
function brvtalBuyerPurgeUnverified(PDO $pdo, int $limit = 25): int
{
    if ($limit < 1 || $limit > 100) {
        throw new InvalidArgumentException('BUYER_PURGE_LIMIT_INVALID');
    }
    $testing = getenv('BRVTAL_INTEGRATION_TESTS') === '1';
    if (!$testing && getenv('BRVTAL_BUYER_RETENTION_PURGE_APPROVED') !== '1') {
        return 0;
    }
    if ($testing) {
        $name = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!is_string($name) || preg_match('/^brvtal_test[A-Za-z0-9_]*$/D', $name) !== 1) {
            throw new RuntimeException('BUYER_PURGE_TEST_DATABASE_REQUIRED');
        }
    }
    return brvtalBuyerAuthTransaction($pdo, static function (PDO $pdo) use ($limit): int {
        $stmt = $pdo->query('SELECT b.id FROM ticket_buyers b
            WHERE b.status=\'active\' AND b.email_verified_at IS NULL
              AND b.created_at<DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 72 HOUR)
              AND NOT EXISTS (SELECT 1 FROM ticket_orders o WHERE o.buyer_id=b.id)
            ORDER BY b.id LIMIT ' . $limit . ' FOR UPDATE');
        $ids = array_map(intval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
        if (!$ids) return 0;
        $tokens = $pdo->prepare('UPDATE ticket_buyer_tokens
            SET revoked_at_utc=UTC_TIMESTAMP(6) WHERE buyer_id=?
            AND consumed_at_utc IS NULL AND revoked_at_utc IS NULL');
        $sessions = $pdo->prepare('UPDATE ticket_buyer_sessions
            SET revoked_at_utc=UTC_TIMESTAMP(6) WHERE buyer_id=?
            AND revoked_at_utc IS NULL');
        $outbox = $pdo->prepare("UPDATE ticket_mail_outbox
            SET recipient_email='purged@invalid.example',subject='Aviso caducado',
                body_text='Aviso descartado por caducidad de verificación',
                state='failed',last_error_code='consent_revoked',locked_until=NULL,
                updated_at=UTC_TIMESTAMP()
            WHERE related_ref=? AND state IN ('queued','retry')");
        $buyer = $pdo->prepare("UPDATE ticket_buyers
            SET email=NULL,full_name=NULL,password_hash=NULL,status='blocked',
                security_epoch=security_epoch+1
            WHERE id=? AND status='active' AND email_verified_at IS NULL");
        $count = 0;
        foreach ($ids as $id) {
            $tokens->execute([$id]);
            $sessions->execute([$id]);
            $outbox->execute(['buyer:' . $id]);
            $buyer->execute([$id]);
            if ($buyer->rowCount() !== 1) {
                throw new RuntimeException('BUYER_PURGE_RACE');
            }
            $count++;
        }
        return $count;
    });
}
