<?php
declare(strict_types=1);

function totp_enrollment_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "TOTP ENROLLMENT CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$core = (string)file_get_contents(__DIR__ . '/../config/totp.php');
$api = (string)file_get_contents(__DIR__ . '/../discadmin/totp-api.php');
$ui = (string)file_get_contents(__DIR__ . '/../discadmin/security.js');
$login = (string)file_get_contents(__DIR__ . '/../config/totp_auth.php');
$loginUi = (string)file_get_contents(__DIR__ . '/../discadmin/totp-login.js');
$coreApi = (string)file_get_contents(__DIR__ . '/../api/index.php');

totp_enrollment_expect(str_contains($core, "['encryption_key']"), 'dedicated encryption key must remain preferred');
totp_enrollment_expect(str_contains($core, "['csrf_key']"), 'existing installations must have a stable compatibility key');
totp_enrollment_expect(str_contains($core, "setting_key='security.totp_encryption_key'"), 'installations without a configured key must persist a private database key');
totp_enrollment_expect(str_contains($core, 'base64_encode(random_bytes(32))'), 'database fallback key must use cryptographically secure randomness');
totp_enrollment_expect(str_contains($core, 'ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key)'), 'concurrent enrollment must never replace an existing encryption key');
totp_enrollment_expect(str_contains($api, 'totp_assert_ready($pdo)'), 'enrollment must verify encryption and schema before exposing a secret');
totp_enrollment_expect(str_contains($api, "TABLE_NAME='admin_recovery_codes'"), 'enrollment readiness must validate the recovery-code table');
totp_enrollment_expect(str_contains($api, "unset(\$_SESSION['totp_enrollment_secret']"), 'failed enrollment secrets must be invalidated');
totp_enrollment_expect(str_contains($api, "'restart_required'=>true"), 'unexpected failures must tell the client to restart enrollment');
totp_enrollment_expect(str_contains($login, 'brvtal_totp_encryption_key()'), 'login and enrollment must derive the same encryption key');
totp_enrollment_expect(str_contains($login, 'brvtal_totp_decryption_keys()'), 'login must survive a deliberate transition to a dedicated encryption key');
totp_enrollment_expect(str_contains($ui, 'Start a new enrollment.'), 'UI must discard a failed enrollment instead of leaving an exposed stale secret');
totp_enrollment_expect(str_contains($loginUi, 'const data = await r.clone().json()'), 'login challenge must inspect a clone without consuming the real verification response');
totp_enrollment_expect(str_contains($loginUi, "const AUTH = '/api/index.php/auth'"), 'login challenge must use the canonical PHP auth route');
totp_enrollment_expect(str_contains($loginUi, 'window.location.reload()'), 'successful login challenge must restart from the authenticated server session');
totp_enrollment_expect(!str_contains($loginUi, "return new Response(JSON.stringify(verified), {status:200"), 'login challenge must not reconstruct a successful response in the browser');
totp_enrollment_expect(str_contains($coreApi, "setting_key<>'security.totp_encryption_key'"), 'admin Settings must never return the database encryption key');
totp_enrollment_expect(substr_count($coreApi, "PROTECTED_SETTING") >= 2, 'admin Settings must reject editing or deleting the encryption key');

totp_enrollment_expect(str_contains($login, "brvtal_totp_rate_limit_scope(\$adminId, 'login')"), 'login TOTP throttling must retain its dedicated scope');
totp_enrollment_expect(str_contains($api, "brvtal_totp_rate_limit_scope(\$id,'disable')"), '2FA disable must be rate limited independently from login challenges');
totp_enrollment_expect(str_contains($login, 'return $consume->rowCount() === 1;'), 'login recovery codes must only succeed for the request that atomically consumes the code');
totp_enrollment_expect(str_contains($api, "return ['valid'=>true,'recovery_id'=>(int)\$row['id']]"), 'disable recovery validation must defer consumption until the final transaction');
totp_enrollment_expect(str_contains($api, '$pdo->beginTransaction();if($verification[\'recovery_id\']!==null)'), 'disable recovery consumption must occur inside the disable transaction');
totp_enrollment_expect(str_contains($api, '$consume->rowCount()!==1'), 'disable must reject a recovery code that another request consumed first');

echo "BRVTAL TOTP enrollment contract tests passed.\n";
