<?php
declare(strict_types=1);

require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/totp_rate_limit.php';

function brvtal_totp_secret_key(): string {
    return brvtal_totp_encryption_key();
}

function brvtal_totp_decrypt_secret(?string $encoded): ?string {
    if (!$encoded) return null;
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 28) return null;
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    foreach (brvtal_totp_decryption_keys() as $key) {
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain !== false) return $plain;
    }
    return null;
}

function brvtal_totp_pending_clear(): void {
    unset($_SESSION['totp_pending_admin_id'], $_SESSION['totp_pending_at'], $_SESSION['totp_pending_email']);
}

function brvtal_totp_pending_set(int $adminId, string $email): void {
    $_SESSION['totp_pending_admin_id'] = $adminId;
    $_SESSION['totp_pending_at'] = time();
    $_SESSION['totp_pending_email'] = $email;
}

function brvtal_totp_pending_admin_id(): ?int {
    $id = (int)($_SESSION['totp_pending_admin_id'] ?? 0);
    $at = (int)($_SESSION['totp_pending_at'] ?? 0);
    if ($id < 1 || $at < 1 || $at < time() - 600) {
        brvtal_totp_pending_clear();
        return null;
    }
    return $id;
}

function brvtal_totp_rate_limit_scope(int $adminId, string $scope): void {
    $state = brvtal_totp_rate_limit_failure($adminId, $scope);
    if (!$state['limited']) return;
    $retryAfter = max(1, (int)$state['retry_after']);
    json_response(['ok'=>false,'error'=>'RATE_LIMITED','retry_after'=>$retryAfter],429,['Retry-After'=>(string)$retryAfter]);
}

function brvtal_totp_rate_limit(int $adminId): void {
    brvtal_totp_rate_limit_scope($adminId, 'login');
}

function brvtal_totp_recovery_verify(PDO $pdo, int $adminId, string $code): bool {
    $normalized = strtoupper(preg_replace('/[^A-Z0-9]/', '', trim($code)) ?? '');
    if ($normalized === '' || strlen($normalized) !== 10) return false;
    $st = $pdo->prepare('SELECT id,code_hash FROM admin_recovery_codes WHERE admin_id=? AND used_at IS NULL');
    $st->execute([$adminId]);
    while ($row = $st->fetch()) {
        if (password_verify($normalized, (string)$row['code_hash'])) {
            $consume = $pdo->prepare('UPDATE admin_recovery_codes SET used_at=NOW() WHERE id=? AND used_at IS NULL');
            $consume->execute([(int)$row['id']]);
            return $consume->rowCount() === 1;
        }
    }
    return false;
}

function brvtal_totp_complete_login(PDO $pdo, array $admin): never {
    brvtal_totp_rate_limit_reset((int)$admin['id']);
    brvtal_totp_pending_clear();
    brvtal_admin_login_session((int)$admin['id']);
    $pdo->prepare('UPDATE admins SET last_login_at=NOW() WHERE id=?')->execute([(int)$admin['id']]);
    brvtal_log('AUTH_OK','Admin login successful with TOTP',['admin_id'=>(int)$admin['id']]);
    json_response(['ok'=>true,'admin'=>['id'=>(int)$admin['id'],'name'=>$admin['name'],'email'=>$admin['email']],'csrf'=>brvtal_admin_csrf_token(),'totp_verified'=>true]);
}
