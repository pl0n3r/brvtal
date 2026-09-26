<?php
declare(strict_types=1);

require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/totp_auth.php';
require_once __DIR__ . '/password_rate_limit.php';

/**
 * Password change/reset primitives for DISCADMIN.
 *
 * Tokens are returned to the delivery boundary exactly once and are persisted
 * only as SHA-256 hashes. All credential mutations rotate credential_epoch so
 * every pre-existing authenticated PHP session fails its next revalidation.
 */
const BRVTAL_PASSWORD_RESET_TTL_SECONDS = 3600;

function brvtal_admin_password_error(string $password, string $currentHash = ''): ?string
{
    if (mb_strlen($password) < 12) {
        return 'PASSWORD_TOO_SHORT';
    }
    if (mb_strlen($password) > 4096) {
        return 'PASSWORD_TOO_LONG';
    }
    if ($currentHash !== '' && password_verify($password, $currentHash)) {
        return 'PASSWORD_REUSED';
    }

    $normalized = strtolower(trim($password));
    $common = [
        'password1234', 'password123!', 'admin123456', 'qwerty123456',
        '123456789012', 'letmein123456', 'brvtal123456',
    ];
    if (in_array($normalized, $common, true)) {
        return 'PASSWORD_COMMON';
    }
    return null;
}

function brvtal_password_reset_token_hash(string $token): string
{
    return hash('sha256', $token);
}

/** @return array{token:string,expires_at:string} */
function brvtal_password_reset_issue(PDO $pdo, int $adminId, ?int $now = null): array
{
    if ($adminId < 1) {
        throw new InvalidArgumentException('INVALID_ADMIN_ID');
    }

    $now ??= time();
    $token = bin2hex(random_bytes(32));
    $hash = brvtal_password_reset_token_hash($token);
    $expiresAt = date('Y-m-d H:i:s', $now + BRVTAL_PASSWORD_RESET_TTL_SECONDS);

    $pdo->beginTransaction();
    try {
        $admin = $pdo->prepare('SELECT id FROM admins WHERE id=? AND is_active=1 FOR UPDATE');
        $admin->execute([$adminId]);
        if ($admin->fetchColumn() === false) {
            throw new RuntimeException('ADMIN_NOT_ACTIVE');
        }

        $revoke = $pdo->prepare(
            'UPDATE admin_password_reset_tokens SET revoked_at=NOW() ' .
            'WHERE admin_id=? AND consumed_at IS NULL AND revoked_at IS NULL'
        );
        $revoke->execute([$adminId]);

        $insert = $pdo->prepare(
            'INSERT INTO admin_password_reset_tokens (admin_id,token_hash,expires_at) VALUES (?,?,?)'
        );
        $insert->execute([$adminId, $hash, $expiresAt]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return ['token' => $token, 'expires_at' => $expiresAt];
}

function brvtal_password_reset_revoke_token(PDO $pdo, string $token): void
{
    $hash = brvtal_password_reset_token_hash($token);
    $statement = $pdo->prepare(
        'UPDATE admin_password_reset_tokens SET revoked_at=NOW() ' .
        'WHERE token_hash=? AND consumed_at IS NULL AND revoked_at IS NULL'
    );
    $statement->execute([$hash]);
}

/** @return array{admin_id:int,credential_epoch:int} */
function brvtal_admin_change_password(
    PDO $pdo,
    int $adminId,
    string $currentPassword,
    string $newPassword
): array {
    if ($adminId < 1 || $currentPassword === '') {
        throw new RuntimeException('INVALID_CREDENTIALS');
    }

    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare(
            'SELECT password_hash,credential_epoch FROM admins WHERE id=? AND is_active=1 FOR UPDATE'
        );
        $statement->execute([$adminId]);
        $admin = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($admin) || !password_verify($currentPassword, (string)$admin['password_hash'])) {
            throw new RuntimeException('INVALID_CREDENTIALS');
        }

        $error = brvtal_admin_password_error($newPassword, (string)$admin['password_hash']);
        if ($error !== null) {
            throw new RuntimeException($error);
        }

        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('PASSWORD_HASH_FAILED');
        }

        $update = $pdo->prepare(
            'UPDATE admins SET password_hash=?,credential_epoch=credential_epoch+1 WHERE id=? AND is_active=1'
        );
        $update->execute([$hash, $adminId]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('PASSWORD_CHANGE_CONFLICT');
        }

        $revoke = $pdo->prepare(
            'UPDATE admin_password_reset_tokens SET revoked_at=NOW() ' .
            'WHERE admin_id=? AND consumed_at IS NULL AND revoked_at IS NULL'
        );
        $revoke->execute([$adminId]);

        $epoch = (int)$admin['credential_epoch'] + 1;
        if (function_exists('brvtal_activity_record')) {
            brvtal_activity_record(
                $pdo,
                'password_change',
                'admin_security',
                $adminId,
                null,
                null,
                ['channel' => 'authenticated'],
                'Admin security'
            );
        }
        $pdo->commit();
        return ['admin_id' => $adminId, 'credential_epoch' => $epoch];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** @return array{admin_id:int,credential_epoch:int} */
function brvtal_password_reset_consume(
    PDO $pdo,
    string $token,
    string $newPassword,
    string $secondFactorCode = '',
    ?int $now = null
): array {
    if ($token === '' || strlen($token) > 512) {
        throw new RuntimeException('RESET_TOKEN_INVALID');
    }

    $now ??= time();
    $tokenHash = brvtal_password_reset_token_hash($token);
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare(
            'SELECT r.id,r.admin_id,r.expires_at,r.consumed_at,r.revoked_at,a.password_hash,a.credential_epoch,a.is_active,a.totp_enabled,a.totp_secret_enc ' .
            'FROM admin_password_reset_tokens r JOIN admins a ON a.id=r.admin_id ' .
            'WHERE r.token_hash=? LIMIT 1 FOR UPDATE'
        );
        $statement->execute([$tokenHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)
            || (int)$row['is_active'] !== 1
            || $row['consumed_at'] !== null
            || $row['revoked_at'] !== null
            || strtotime((string)$row['expires_at']) < $now) {
            throw new RuntimeException('RESET_TOKEN_INVALID');
        }

        if ((int)($row['totp_enabled'] ?? 0) === 1) {
            $code = trim($secondFactorCode);
            $secret = brvtal_totp_decrypt_secret((string)($row['totp_secret_enc'] ?? ''));
            $secondFactorOk = $code !== ''
                && (($secret !== null && brvtal_totp_verify($secret, $code, null, 1))
                    || brvtal_totp_recovery_verify($pdo, (int)$row['admin_id'], $code));
            if (!$secondFactorOk) {
                throw new RuntimeException('SECOND_FACTOR_REQUIRED');
            }
        }

        $error = brvtal_admin_password_error($newPassword, (string)$row['password_hash']);
        if ($error !== null) {
            throw new RuntimeException($error);
        }

        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        if (!is_string($passwordHash) || $passwordHash === '') {
            throw new RuntimeException('PASSWORD_HASH_FAILED');
        }

        $consume = $pdo->prepare(
            'UPDATE admin_password_reset_tokens SET consumed_at=NOW() ' .
            'WHERE id=? AND consumed_at IS NULL AND revoked_at IS NULL'
        );
        $consume->execute([(int)$row['id']]);
        if ($consume->rowCount() !== 1) {
            throw new RuntimeException('RESET_TOKEN_INVALID');
        }

        $admin = $pdo->prepare(
            'UPDATE admins SET password_hash=?,credential_epoch=credential_epoch+1 WHERE id=? AND is_active=1'
        );
        $admin->execute([$passwordHash, (int)$row['admin_id']]);
        if ($admin->rowCount() !== 1) {
            throw new RuntimeException('PASSWORD_CHANGE_CONFLICT');
        }

        $revoke = $pdo->prepare(
            'UPDATE admin_password_reset_tokens SET revoked_at=NOW() ' .
            'WHERE admin_id=? AND id<>? AND consumed_at IS NULL AND revoked_at IS NULL'
        );
        $revoke->execute([(int)$row['admin_id'], (int)$row['id']]);

        $epoch = (int)$row['credential_epoch'] + 1;
        if (function_exists('brvtal_activity_record')) {
            brvtal_activity_record(
                $pdo,
                'password_reset',
                'admin_security',
                (int)$row['admin_id'],
                null,
                null,
                ['channel' => 'recovery'],
                'Admin security'
            );
        }
        $pdo->commit();
        return ['admin_id' => (int)$row['admin_id'], 'credential_epoch' => $epoch];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}


function brvtal_admin_password_send_mail(string $email, string $subject, string $body): bool
{
    $transport = $GLOBALS['brvtal_password_mail_transport'] ?? null;
    return is_callable($transport) && (bool)$transport($email, $subject, $body);
}

function brvtal_admin_password_forgot(PDO $pdo, string $email, string $baseUrl): void
{
    $started = hrtime(true);
    $normalized = strtolower(trim($email));
    $limit = brvtal_password_rate_limit_failure($normalized);
    if ($limit['limited']) {
        throw new DomainException('RATE_LIMITED');
    }

    $admin = null;
    if (filter_var($normalized, FILTER_VALIDATE_EMAIL) && strlen($normalized) <= 190) {
        $st = $pdo->prepare('SELECT id,email FROM admins WHERE email=? AND is_active=1 LIMIT 1');
        $st->execute([$normalized]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $admin = is_array($row) ? $row : null;
    }

    if ($admin !== null) {
        $issued = brvtal_password_reset_issue($pdo, (int)$admin['id']);
        $link = rtrim($baseUrl, '/') . '/discadmin/reset-password.php#token='
            . rawurlencode($issued['token']);
        if (!brvtal_admin_password_send_mail(
            (string)$admin['email'],
            'Recupera tu acceso a BRVTAL',
            "Solicitaste recuperar tu acceso a BRVTAL.\n\n"
                . $link
                . "\n\nEste enlace vence en 60 minutos y solo puede usarse una vez."
        )) {
            brvtal_password_reset_revoke_token($pdo, $issued['token']);
            brvtal_log(
                'PASSWORD_RECOVERY_DELIVERY_FAILED',
                'Password recovery mail was not delivered',
                ['admin_id' => (int)$admin['id']]
            );
        }
    } else {
        hash('sha256', random_bytes(32));
    }

    $elapsedNs = hrtime(true) - $started;
    $floorNs = 150_000_000;
    if ($elapsedNs < $floorNs) {
        usleep((int)(($floorNs - $elapsedNs) / 1000));
    }
}
