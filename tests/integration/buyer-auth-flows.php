<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/buyer_auth_flows.php';

function buyerAuthAssert(bool $ok, string $reason): void
{
    if (!$ok) throw new RuntimeException('BUYER AUTH CONTRACT FAILED: ' . $reason);
}

if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "BRVTAL buyer-auth MariaDB integration skipped (synthetic-only).\n";
    return;
}
$prefix = (string)getenv('BRVTAL_TEST_DB_NAME');
buyerAuthAssert(preg_match('/^brvtal_test[a-zA-Z0-9_]*$/D', $prefix) === 1,
    'only disposable test DB names');
$host = (string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1');
$port = (int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306);
$user = (string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root');
$pass = (string)(getenv('BRVTAL_TEST_DB_PASS') ?: '');
$dbName = $prefix . '_buyer_auth_' . bin2hex(random_bytes(4));
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false];
$server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, $options);
$server->exec("CREATE DATABASE " . $dbName . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
register_shutdown_function(static function () use ($server, $dbName): void {
    try { $server->exec("DROP DATABASE IF EXISTS " . $dbName); } catch (Throwable) {}
});
$db = new PDO("mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4", $user, $pass, $options);
$schema = (string)file_get_contents(__DIR__ . '/../../database/migration_ticketing_orders_01.sql');
$prefixSql = explode('-- Add a composite parent key', $schema, 2)[0];
$prefixSql = preg_replace('/^\s*--[^\n]*$/m', '', $prefixSql);
foreach (array_filter(array_map(trim(...), explode(';', (string)$prefixSql))) as $statement) {
    $db->exec($statement);
}
$db->exec((string)file_get_contents(__DIR__ . '/../../database/migration_buyer_auth_tokens_01.sql'));
$db->exec((string)file_get_contents(__DIR__ . '/../../database/migration_buyer_auth_tokens_01.sql'));
$db->exec((string)file_get_contents(__DIR__ . '/../../database/migration_ticketing_mail_outbox_01.sql'));
$originalConfig = $GLOBALS['config'] ?? null;
$GLOBALS['config'] = ['security'=>['encryption_key'=>str_repeat('synthetic-test-key-', 3)]];
putenv('BRVTAL_TICKET_MAIL_TESTING=1');
putenv('BRVTAL_TICKET_MAIL_MODE=disabled');
$rateDirectory = sys_get_temp_dir() . '/brvtal-buyer-rate-' . bin2hex(random_bytes(6));
mkdir($rateDirectory, 0700);
register_shutdown_function(static function () use ($rateDirectory): void {
    foreach (glob($rateDirectory . '/*') ?: [] as $file) @unlink($file);
    @rmdir($rateDirectory);
});
$input = ['name'=>'Persona Sintética', 'email'=>'buyer@example.test',
    'password'=>'SynthETIC-9345-safe-Long', 'terms_accepted'=>true,'consent_version'=>'0.1.277'];
$a = brvtalBuyerAuthRegister($db, $input);
$b = brvtalBuyerAuthRegister($db, $input);
buyerAuthAssert($a === $b && $a === ['ok'=>true], 'duplicate is neutral');
buyerAuthAssert((int)$db->query('SELECT COUNT(*) FROM ticket_buyers')->fetchColumn() === 1,
    'one buyer after duplicate');
$row = $db->query('SELECT id,full_name,password_hash,email_verified_at,security_epoch FROM ticket_buyers')->fetch();
// Match the 12..4096-character policy without silent bcrypt 72-byte truncation.
if (defined('PASSWORD_ARGON2ID')) {
    $longPassword = str_repeat('SYNTHETIC-secure-4829-', 7);
    $longHash = brvtalBuyerAuthPassword($longPassword);
    buyerAuthAssert(password_verify($longPassword, $longHash)
        && !password_verify(substr($longPassword, 0, 72), $longHash),
        'long password preserves all bytes with Argon2id');
}
buyerAuthAssert($row['full_name'] === $input['name']
    && $row['password_hash'] !== $input['password']
    && password_verify($input['password'], $row['password_hash'])
    && $row['email_verified_at'] === null, 'unverified hashed buyer and profile');
buyerAuthAssert($db->query('SELECT consent_version FROM ticket_buyers')->fetchColumn()
    === BRVTAL_APP_VERSION, 'recorded consent version is canonical server value');
buyerAuthAssert($db->query('SELECT consent_recorded_at FROM ticket_buyers')->fetchColumn() !== null,
    'consent timestamp is server generated');
buyerAuthAssert((int)$db->query('SELECT COUNT(*) FROM ticket_mail_outbox')->fetchColumn() === 1,
    'one queued verification only');
$sealed = (string)$db->query('SELECT body_text FROM ticket_mail_outbox LIMIT 1')->fetchColumn();
buyerAuthAssert(str_starts_with($sealed, BRVTAL_BUYER_MAIL_SEAL_PREFIX)
    && !str_contains($sealed,'token='), 'no plaintext bearer in outbox');
$mail = brvtalBuyerMailUnseal($sealed);
preg_match('/token=([A-Za-z0-9_-]{43})/', (string)$mail, $matches);
$verifyToken = $matches[1] ?? '';
buyerAuthAssert($verifyToken !== '' && !str_contains($sealed, $verifyToken), 'secure 43-char token in memory');
// The worker unwraps only at the delivery boundary; ciphertext stays in DB.
putenv('BRVTAL_TICKET_MAIL_MODE=mock');
$captured = null;
$delivery = brvtal_ticket_resend_send([
    'recipient_email'=>'buyer@example.test','message_key'=>'buyer:1:verify_account:1',
    'subject'=>'Correo de verificación','body_text'=>$sealed,
], static function (array $payload, array $headers) use (&$captured): array {
    $captured = ['payload'=>$payload,'headers'=>$headers];
    return ['status'=>200,'body'=>'{"id":"buyer-auth-synthetic-1"}'];
});
buyerAuthAssert($delivery['state'] === 'accepted'
    && is_array($captured) && str_contains($captured['payload']['text'], $verifyToken)
    && $captured['headers'][0] === 'Authorization: Bearer ',
    'only synthetic provider receives decoded link in memory');
$corrupt = substr($sealed, 0, -4) . 'AAAA';
$invalid = brvtal_ticket_resend_send([
    'recipient_email'=>'buyer@example.test','message_key'=>'buyer:1:verify_account:1',
    'subject'=>'Correo','body_text'=>$corrupt,
], static fn(): array => ['status'=>200,'body'=>'{"id":"should-not-send"}']);
buyerAuthAssert($invalid['code'] === 'invalid_configuration',
    'tampered sealed message fails closed before provider');
putenv('BRVTAL_TICKET_MAIL_MODE=disabled');
buyerAuthAssert((string)$db->query('SELECT token_hash FROM ticket_buyer_tokens LIMIT 1')->fetchColumn()
    === hash('sha256', $verifyToken), 'only token digest persisted');
$db->prepare('UPDATE ticket_buyer_tokens SET expires_at_utc=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 SECOND)
    WHERE token_hash=?')->execute([hash('sha256',$verifyToken)]);
try {
    brvtalBuyerAuthConsume($db, $verifyToken, 'verify_email');
    buyerAuthAssert(false, 'expired verification rejected');
} catch (DomainException) {}
buyerAuthAssert(brvtalBuyerAuthRequest($db, 'buyer@example.test', 'verify_email', $rateDirectory) === ['ok'=>true],
    'reissue unverified is neutral');
$next = (string)$db->query('SELECT body_text FROM ticket_mail_outbox ORDER BY id DESC LIMIT 1')->fetchColumn();
preg_match('/token=([A-Za-z0-9_-]{43})/', (string)brvtalBuyerMailUnseal($next), $vm);
$valid = $vm[1] ?? '';
buyerAuthAssert($valid !== '' && $valid !== $verifyToken, 'reissued token differs');
brvtalBuyerAuthConsume($db, $valid, 'verify_email');
try {
    brvtalBuyerAuthConsume($db, $valid, 'verify_email');
    buyerAuthAssert(false, 'verification replay rejected');
} catch (DomainException) {}
buyerAuthAssert($db->query('SELECT email_verified_at FROM ticket_buyers')->fetchColumn() !== null,
    'verification persisted');
$neutralKnown = brvtalBuyerAuthRequest($db,'buyer@example.test','reset_password',$rateDirectory);
$neutralUnknown = brvtalBuyerAuthRequest($db,'unknown@example.test','reset_password',$rateDirectory);
buyerAuthAssert($neutralKnown === $neutralUnknown && $neutralKnown === ['ok'=>true],
    'unknown and known reset responses identical');
$resetSealed = (string)$db->query('SELECT body_text FROM ticket_mail_outbox ORDER BY id DESC LIMIT 1')->fetchColumn();
preg_match('/token=([A-Za-z0-9_-]{43})/', (string)brvtalBuyerMailUnseal($resetSealed), $rm);
$resetToken = $rm[1] ?? '';
buyerAuthAssert($resetToken !== '' && !str_contains($resetSealed, $resetToken),
    'reset is sealed at rest');
$db->prepare('INSERT INTO ticket_buyer_sessions
    (buyer_id,session_hash,issued_epoch,expires_at_utc)
    VALUES (?,?,?,DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 1 HOUR))')
    ->execute([(int)$row['id'],hash('sha256','synthetic-session-id'),(int)$row['security_epoch']]);
$newPassword = 'SynthETIC-new-3849-Strong';
brvtalBuyerAuthConsume($db,$resetToken,'reset_password',$newPassword);
$after = $db->query('SELECT password_hash,security_epoch FROM ticket_buyers')->fetch();
buyerAuthAssert(password_verify($newPassword, (string)$after['password_hash'])
    && !password_verify($input['password'], (string)$after['password_hash'])
    && (int)$after['security_epoch'] === (int)$row['security_epoch']+1,
    'reset rotates password and epoch');
buyerAuthAssert($db->query('SELECT revoked_at_utc FROM ticket_buyer_sessions')->fetchColumn() !== null,
    'all prior stored sessions revoked');
try {
    brvtalBuyerAuthConsume($db,$resetToken,'reset_password','SynthETIC-3494-Reused');
    buyerAuthAssert(false,'reset token replay rejected');
} catch (DomainException) {}
// Flood both a known and unknown mailbox; no response discloses existence.
for ($i=0;$i<8;$i++) {
    buyerAuthAssert(brvtalBuyerAuthRequest($db,'buyer@example.test','reset_password',$rateDirectory)
        === ['ok'=>true], 'rate limited response remains neutral');
}
buyerAuthAssert((int)$db->query("SELECT COUNT(*) FROM ticket_mail_outbox WHERE subject
    LIKE '%Restablece%'")->fetchColumn() <= 6, 'IP and email rate limit bounds mail volume');
buyerAuthAssert((int)$db->query("SELECT COUNT(*) FROM ticket_buyer_tokens WHERE token_hash
    LIKE '%SynthETIC%'")->fetchColumn() === 0, 'raw password never stored as token');
// #1877: 72h retention in a disposable DB, with production mutation opt-in.
foreach (['old-expiring@example.test' => 73, 'young-expiring@example.test' => 71] as $address => $age) {
    $profile = $input;
    $profile['email'] = $address;
    buyerAuthAssert(brvtalBuyerAuthRegister($db, $profile) === ['ok'=>true],
        'unverified synthetic retention fixture');
    $db->exec("UPDATE ticket_buyers SET created_at=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL "
        . $age . " HOUR) WHERE email=" . $db->quote($address));
}
putenv('BRVTAL_INTEGRATION_TESTS=0');
buyerAuthAssert(brvtalBuyerPurgeUnverified($db) === 0,
    'no destructive cleanup when production authorization is absent');
putenv('BRVTAL_INTEGRATION_TESTS=1');
buyerAuthAssert(brvtalBuyerPurgeUnverified($db) === 1,
    'expire only unverified buyers older than 72 hours');
$expired = $db->query("SELECT id,email,full_name,password_hash,status
    FROM ticket_buyers WHERE status='blocked' AND email IS NULL ORDER BY id DESC LIMIT 1")
    ->fetch(PDO::FETCH_ASSOC);
buyerAuthAssert(is_array($expired)
    && $expired['email'] === null && $expired['full_name'] === null
    && $expired['password_hash'] === null, 'expired identity anonymized');
$id = (int)$expired['id'];
$tokenCheck = $db->prepare('SELECT COUNT(*) FROM ticket_buyer_tokens WHERE buyer_id=?
    AND consumed_at_utc IS NULL AND revoked_at_utc IS NULL');
$tokenCheck->execute([$id]);
buyerAuthAssert((int)$tokenCheck->fetchColumn() === 0,
    'expired verification tokens revoked');
$mailCheck = $db->prepare("SELECT COUNT(*) FROM ticket_mail_outbox
    WHERE related_ref=? AND state='failed' AND last_error_code='consent_revoked'
    AND recipient_email='purged@invalid.example'");
$mailCheck->execute(['buyer:'.$id]);
buyerAuthAssert((int)$mailCheck->fetchColumn() >= 1, 'unsent recipient scrubbed');
buyerAuthAssert((int)$db->query("SELECT COUNT(*) FROM ticket_buyers
    WHERE email='young-expiring@example.test' AND status='active'
    AND email_verified_at IS NULL")->fetchColumn() === 1, 'under 72h preserved');
buyerAuthAssert(brvtalBuyerPurgeUnverified($db) === 0, 'second pass idempotent');

putenv('BRVTAL_TICKET_MAIL_TESTING');
$GLOBALS['config'] = $originalConfig;
echo "BRVTAL buyer-auth MariaDB integration passed.\n";
// #1877: all public form abuse bursts run from the existing buyer-auth CI entrypoint.
require __DIR__ . '/public-forms-abuse.php';
