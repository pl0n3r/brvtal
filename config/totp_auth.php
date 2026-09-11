<?php
declare(strict_types=1);

require_once __DIR__ . '/totp.php';

function brvtal_totp_secret_key(): string {
    global $config;
    $configured = trim((string)($config['security']['encryption_key'] ?? ''));
    if ($configured === '' || strlen($configured) < 32) {
        throw new RuntimeException('TOTP encryption key is not configured.');
    }
    return hash('sha256', $configured, true);
}

function brvtal_totp_decrypt_secret(?string $encoded): ?string {
    if (!$encoded) return null;
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 28) return null;
    $key = brvtal_totp_secret_key();
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? null : $plain;
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

function brvtal_totp_rate_limit(int $adminId): void {
    $dir = __DIR__ . '/../storage/rate_limits';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $key = hash('sha256', 'totp|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|' . $adminId);
    $file = $dir . '/' . $key . '.json';
    $now = time(); $window = 900; $max = 5;
    $data = ['attempts'=>[], 'blocked_until'=>0];
    if (is_file($file)) {
        $decoded = json_decode((string)@file_get_contents($file), true);
        if (is_array($decoded)) $data = array_replace($data, $decoded);
    }
    $data['attempts'] = array_values(array_filter((array)$data['attempts'], static fn($t) => is_int($t) && $t > $now - $window));
    if ((int)$data['blocked_until'] > $now) {
        json_response(['ok'=>false,'error'=>'RATE_LIMITED','retry_after'=>(int)$data['blocked_until']-$now],429,['Retry-After'=>(string)((int)$data['blocked_until']-$now)]);
    }
    $data['attempts'][] = $now;
    if (count($data['attempts']) > $max) {
        $data['blocked_until'] = $now + 900;
        @file_put_contents($file, json_encode($data), LOCK_EX);
        json_response(['ok'=>false,'error'=>'RATE_LIMITED','retry_after'=>900],429,['Retry-After'=>'900']);
    }
    @file_put_contents($file, json_encode($data), LOCK_EX);
}

function brvtal_totp_recovery_verify(PDO $pdo, int $adminId, string $code): bool {
    $normalized = strtoupper(preg_replace('/[^A-Z0-9]/', '', trim($code)) ?? '');
    if ($normalized === '' || strlen($normalized) !== 10) return false;
    $st = $pdo->prepare('SELECT id,code_hash FROM admin_recovery_codes WHERE admin_id=? AND used_at IS NULL');
    $st->execute([$adminId]);
    while ($row = $st->fetch()) {
        if (password_verify($normalized, (string)$row['code_hash'])) {
            $pdo->prepare('UPDATE admin_recovery_codes SET used_at=NOW() WHERE id=? AND used_at IS NULL')->execute([(int)$row['id']]);
            return true;
        }
    }
    return false;
}

function brvtal_totp_complete_login(PDO $pdo, array $admin): never {
    brvtal_totp_pending_clear();
    brvtal_admin_login_session((int)$admin['id']);
    $pdo->prepare('UPDATE admins SET last_login_at=NOW() WHERE id=?')->execute([(int)$admin['id']]);
    brvtal_log('AUTH_OK','Admin login successful with TOTP',['admin_id'=>(int)$admin['id']]);
    json_response(['ok'=>true,'admin'=>['id'=>(int)$admin['id'],'name'=>$admin['name'],'email'=>$admin['email']],'csrf'=>brvtal_admin_csrf_token(),'totp_verified'=>true]);
}
