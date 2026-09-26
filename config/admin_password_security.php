<?php
declare(strict_types=1);

require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/totp_auth.php';

const BRVTAL_PASSWORD_RESET_TTL_SECONDS = 3600;
const BRVTAL_PASSWORD_MIN_LENGTH = 12;

/** @return string|null */
function brvtal_admin_password_policy_error(string $password, string $currentHash = ''): ?string
{
    if (strlen($password) < BRVTAL_PASSWORD_MIN_LENGTH) {
        return 'PASSWORD_TOO_SHORT';
    }
    if (strlen($password) > 4096) {
        return 'PASSWORD_TOO_LONG';
    }
    $common = [
        'password1234', 'password123!', '123456789012', 'qwertyuiop12',
        'administrator', 'admin12345678', 'brvtal123456',
    ];
    if (in_array(strtolower($password), $common, true)) {
        return 'PASSWORD_TOO_COMMON';
    }
    if ($currentHash !== '' && password_verify($password, $currentHash)) {
        return 'PASSWORD_REUSE';
    }
    return null;
}

function brvtal_admin_password_hash(string $password): string
{
    $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    $hash = password_hash($password, $algorithm);
    if (!is_string($hash) || $hash === '') {
        throw new RuntimeException('PASSWORD_HASH_FAILED');
    }
    return $hash;
}

function brvtal_admin_password_token(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function brvtal_admin_password_token_hash(string $token): string
{
    return hash('sha256', $token);
}

/**
 * Test seam for mail delivery. Production SMTP is intentionally fail-closed
 * until the dedicated transport is configured in the next slice.
 */
function brvtal_admin_password_send_mail(
    string $email,
    string $subject,
    string $body
): bool {
    $transport = $GLOBALS['brvtal_password_mail_transport'] ?? null;
    if (is_callable($transport)) {
        return (bool)$transport($email, $subject, $body);
    }
    return false;
}

/** @return array{token:string,token_hash:string,expires_at:string} */
function brvtal_admin_password_reset_issue(PDO $pdo, int $adminId): array
{
    if ($adminId < 1) {
        throw new InvalidArgumentException('INVALID_ADMIN');
    }

    $token = brvtal_admin_password_token();
    $tokenHash = brvtal_admin_password_token_hash($token);
    $expiresAt = gmdate('Y-m-d H:i:s', time() + BRVTAL_PASSWORD_RESET_TTL_SECONDS);

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM admin_password_reset_tokens WHERE admin_id=?')
            ->execute([$adminId]);
        $pdo->prepare(
            'INSERT INTO admin_password_reset_tokens(admin_id,token_hash,expires_at) VALUES(?,?,?)'
        )->execute([$adminId, $tokenHash, $expiresAt]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return ['token' => $token, 'token_hash' => $tokenHash, 'expires_at' => $expiresAt];
}

function brvtal_admin_password_reset_revoke(
    PDO $pdo,
    int $adminId,
    ?string $tokenHash = null
): void {
    if ($tokenHash === null) {
        $pdo->prepare('DELETE FROM admin_password_reset_tokens WHERE admin_id=?')
            ->execute([$adminId]);
        return;
    }
    $pdo->prepare(
        'DELETE FROM admin_password_reset_tokens WHERE admin_id=? AND token_hash=?'
    )->execute([$adminId, $tokenHash]);
}

function brvtal_admin_password_audit(
    PDO $pdo,
    string $action,
    int $adminId,
    string $channel
): void {
    if (!function_exists('brvtal_activity_record')) {
        return;
    }
    brvtal_activity_record(
        $pdo,
        $action,
        'admin_security',
        $adminId,
        null,
        null,
        ['channel' => $channel],
        'Admin security'
    );
}

function brvtal_admin_password_change_authenticated(
    PDO $pdo,
    int $adminId,
    string $currentPassword,
    string $newPassword
): void {
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            'SELECT password_hash FROM admins WHERE id=? AND is_active=1 LIMIT 1 FOR UPDATE'
        );
        $st->execute([$adminId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !password_verify($currentPassword, (string)$row['password_hash'])) {
            throw new DomainException('CURRENT_PASSWORD_INVALID');
        }
        $policy = brvtal_admin_password_policy_error($newPassword, (string)$row['password_hash']);
        if ($policy !== null) {
            throw new DomainException($policy);
        }

        $newHash = brvtal_admin_password_hash($newPassword);
        $pdo->prepare(
            'UPDATE admins SET password_hash=?, credential_epoch=credential_epoch+1, password_changed_at=NOW() WHERE id=?'
        )->execute([$newHash, $adminId]);
        brvtal_admin_password_reset_revoke($pdo, $adminId);
        brvtal_admin_password_audit($pdo, 'password_change', $adminId, 'authenticated');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function brvtal_admin_password_forgot(PDO $pdo, string $email, string $baseUrl): void
{
    $started = hrtime(true);
    $normalized = strtolower(trim($email));
    $admin = null;
    if (filter_var($normalized, FILTER_VALIDATE_EMAIL) && strlen($normalized) <= 190) {
        $st = $pdo->prepare(
            'SELECT id,email FROM admins WHERE email=? AND is_active=1 LIMIT 1'
        );
        $st->execute([$normalized]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $admin = is_array($row) ? $row : null;
    }

    if ($admin !== null) {
        $issued = brvtal_admin_password_reset_issue($pdo, (int)$admin['id']);
        $link = rtrim($baseUrl, '/') . '/discadmin/reset-password.php#token='
            . rawurlencode($issued['token']);
        $delivered = brvtal_admin_password_send_mail(
            (string)$admin['email'],
            'Recupera tu acceso a BRVTAL',
            "Solicitaste recuperar tu acceso a BRVTAL.\n\n"
            . $link
            . "\n\nEste enlace vence en 60 minutos y solo puede usarse una vez."
        );
        if (!$delivered) {
            brvtal_admin_password_reset_revoke(
                $pdo,
                (int)$admin['id'],
                $issued['token_hash']
            );
            brvtal_log('PASSWORD_RECOVERY_DELIVERY_FAILED', 'Password recovery mail was not delivered', [
                'admin_id' => (int)$admin['id'],
            ]);
        }
    } else {
        hash('sha256', random_bytes(32));
    }

    $elapsedNs = hrtime(true) - $started;
    $minimumNs = 150_000_000;
    if ($elapsedNs < $minimumNs) {
        usleep((int)(($minimumNs - $elapsedNs) / 1000));
    }
}

function brvtal_admin_password_verify_second_factor(
    PDO $pdo,
    array $admin,
    string $code
): bool {
    if ((int)($admin['totp_enabled'] ?? 0) !== 1) {
        return true;
    }
    $code = trim($code);
    if ($code === '') {
        return false;
    }
    $secret = brvtal_totp_decrypt_secret((string)($admin['totp_secret_enc'] ?? ''));
    if ($secret !== null && brvtal_totp_verify($secret, $code, null, 1)) {
        return true;
    }
    return brvtal_totp_recovery_verify($pdo, (int)$admin['id'], $code);
}

function brvtal_admin_password_reset_consume(
    PDO $pdo,
    string $token,
    string $newPassword,
    string $secondFactorCode = ''
): int {
    $tokenHash = brvtal_admin_password_token_hash($token);
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            'SELECT t.admin_id,t.expires_at,a.password_hash,a.is_active,a.totp_enabled,a.totp_secret_enc '
            . 'FROM admin_password_reset_tokens t JOIN admins a ON a.id=t.admin_id '
            . 'WHERE t.token_hash=? AND t.used_at IS NULL LIMIT 1 FOR UPDATE'
        );
        $st->execute([$tokenHash]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (
            !is_array($row)
            || (int)$row['is_active'] !== 1
            || strtotime((string)$row['expires_at']) < time()
        ) {
            throw new DomainException('RESET_TOKEN_INVALID');
        }

        $policy = brvtal_admin_password_policy_error($newPassword, (string)$row['password_hash']);
        if ($policy !== null) {
            throw new DomainException($policy);
        }
        $admin = [
            'id' => (int)$row['admin_id'],
            'totp_enabled' => (int)$row['totp_enabled'],
            'totp_secret_enc' => (string)($row['totp_secret_enc'] ?? ''),
        ];
        if (!brvtal_admin_password_verify_second_factor($pdo, $admin, $secondFactorCode)) {
            throw new DomainException('SECOND_FACTOR_REQUIRED');
        }

        $newHash = brvtal_admin_password_hash($newPassword);
        $adminId = (int)$row['admin_id'];
        $pdo->prepare(
            'UPDATE admins SET password_hash=?, credential_epoch=credential_epoch+1, password_changed_at=NOW() WHERE id=?'
        )->execute([$newHash, $adminId]);
        $pdo->prepare(
            'UPDATE admin_password_reset_tokens SET used_at=NOW() WHERE admin_id=? AND token_hash=? AND used_at IS NULL'
        )->execute([$adminId, $tokenHash]);
        $pdo->prepare(
            'DELETE FROM admin_password_reset_tokens WHERE admin_id=? AND token_hash<>?'
        )->execute([$adminId, $tokenHash]);
        brvtal_admin_password_audit($pdo, 'password_reset', $adminId, 'recovery');
        $pdo->commit();
        return $adminId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
