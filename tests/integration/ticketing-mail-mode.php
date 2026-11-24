<?php
declare(strict_types=1);

if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "#1962 mail switch MariaDB contract skipped (BRVTAL_INTEGRATION_TESTS=1 required).\n";
    exit(0);
}
$baseDb = (string)(getenv('BRVTAL_TEST_DB_NAME') ?: '');
if (!preg_match('/^brvtal_test[A-Za-z0-9_]*$/D', $baseDb)) {
    throw new RuntimeException('REFUSING_NON_SYNTHETIC_DB');
}
function secretAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('MAIL_MODE: ' . $message);
}

$host = (string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1');
$port = (int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306);
$user = (string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root');
$pass = (string)(getenv('BRVTAL_TEST_DB_PASS') ?: '');
$name = $baseDb . '_mailmode_' . bin2hex(random_bytes(4));
$server = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
]);
$server->exec("CREATE DATABASE " . chr(96) . $name . chr(96) . " CHARACTER SET utf8mb4");
register_shutdown_function(static function() use ($server, $name): void {
    $server->exec("DROP DATABASE IF EXISTS " . chr(96) . $name . chr(96));
});
$pdo = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false,
]);
$pdo->exec("CREATE TABLE admins(
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    name VARCHAR(120) NOT NULL DEFAULT 'Synthetic Admin',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    credential_epoch BIGINT UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB");
$pdo->exec((string)file_get_contents(__DIR__ . '/../../database/migration_admin_activity_01.sql'));
$ddl = (string)file_get_contents(__DIR__ . '/../../database/migration_integration_secrets_01.sql');
secretAssert($ddl !== '', 'migration SQL exists');
$pdo->exec($ddl);
$pdo->exec($ddl);
$pdo->exec("INSERT INTO admins(email,password_hash,name) VALUES('synthetic@brvtal.test','synthetic-hash','Synthetic Admin')");
$actorId = (int)$pdo->lastInsertId();

$master = bin2hex(random_bytes(32));
$cfg = [
    'app'=>['timezone'=>'America/Bogota'],
    'db'=>['host'=>$host,'port'=>$port,'name'=>$name,'user'=>$user,'pass'=>$pass,'charset'=>'utf8mb4'],
    'security'=>[
        'session_name'=>'BRVTAL_MAIL_MODE_TEST',
        'integration_secret_master_key'=>$master,
        'integration_secret_write'=>true,
    ],
];
$tmp = tempnam(sys_get_temp_dir(), 'brvtal_mailmode_test_');
if (!is_string($tmp)) throw new RuntimeException('TEST_CONFIG_UNAVAILABLE');
file_put_contents($tmp, '<?php return ' . var_export($cfg, true) . ';');
register_shutdown_function(static fn() => @unlink($tmp));
define('BRVTAL_SENTRY_TESTING', true);
$GLOBALS['brvtalBootstrapConfigPath'] = $tmp;
require_once __DIR__ . '/../../config/integration_secrets_store.php';
require_once __DIR__ . '/../../config/secrets.php';
require_once __DIR__ . '/../../config/admin_activity.php';

brvtal_admin_session_start();
$_SESSION['admin_id'] = $actorId;
require_once __DIR__ . '/../../config/ticketing_mail_mode.php';
require_once __DIR__ . '/../../config/ticketing_resend.php';
foreach (['BRVTAL_TICKET_MAIL_MODE','BRVTAL_TICKET_MAIL_PRIVACY_APPROVED','BRVTAL_TICKET_MAIL_DNS_VERIFIED',
          'BRVTAL_TICKET_MAIL_ALLOW_LIVE','BRVTAL_TICKET_MAIL_LOOKUP_SECRET','BRVTAL_TICKET_MAIL_TESTING',
          'BRVTAL_TICKET_MAIL_TEST_RECIPIENTS','BRVTAL_TICKET_MAIL_API_KEY','BRVTAL_TICKET_MAIL_DOMAIN',
          'BRVTAL_TICKET_MAIL_FROM'] as $env) {
    putenv($env); // only the encrypted store may answer below
}
$stored = static fn(string $key): string => (string)brvtalIntegrationSecretRead($key, $pdo);
$apply = static fn(string $mode, bool $privacy = true, bool $dns = false, mixed $confirm = null): ?string =>
    brvtal_ticket_mail_mode_apply($pdo, $mode, $privacy, $dns, $confirm, $actorId);

// Default: nothing goes out.
secretAssert(brvtal_ticket_mail_status()['mode'] === 'disabled', 'default mode disabled');
secretAssert(brvtal_ticket_mail_status()['ready'] === false, 'disabled is not ready');

// Rejections, each with its own code, write nothing.
secretAssert($apply('loud') === 'MAIL_MODE_INVALID', 'unknown mode');
secretAssert($apply('test', false) === 'MAIL_PRIVACY_REQUIRED', 'privacy required for test');
secretAssert($apply('test') === 'MAIL_CREDENTIALS_MISSING', 'credentials required');
foreach (['BRVTAL_TICKET_MAIL_API_KEY'=>'re_SYNTHETIC_NO_SEND_12345','BRVTAL_TICKET_MAIL_DOMAIN'=>'brvtal.invalid',
          'BRVTAL_TICKET_MAIL_FROM'=>'noreply@brvtal.invalid'] as $k => $v) {
    brvtalIntegrationSecretMutate($pdo, 'POST', $k, $v, $actorId);
}
secretAssert($apply('test') === 'MAIL_TEST_RECIPIENTS_MISSING', 'test recipients required');
secretAssert($stored('BRVTAL_TICKET_MAIL_MODE') === '' && $stored('BRVTAL_TICKET_MAIL_LOOKUP_SECRET') === '',
    'rejections store nothing');

// Pruebas: privacy stored, lookup secret generated once, live gate closed.
brvtalIntegrationSecretMutate($pdo, 'POST', 'BRVTAL_TICKET_MAIL_TEST_RECIPIENTS', 'qa@brvtal.invalid', $actorId);
secretAssert($apply('test') === null, 'test mode applied');
$lookup = $stored('BRVTAL_TICKET_MAIL_LOOKUP_SECRET');
secretAssert(preg_match('/^[a-f0-9]{64}$/D', $lookup) === 1, 'lookup secret generated');
secretAssert($stored('BRVTAL_TICKET_MAIL_MODE') === 'test' && $stored('BRVTAL_TICKET_MAIL_PRIVACY_APPROVED') === '1'
    && $stored('BRVTAL_TICKET_MAIL_ALLOW_LIVE') === '0', 'test mode values stored');
secretAssert(!str_contains((string)$pdo->query("SELECT GROUP_CONCAT(value_encrypted) FROM integration_secrets")->fetchColumn(), $lookup),
    'values stored encrypted');
$status = brvtal_ticket_mail_status();
secretAssert($status['mode'] === 'test' && $status['ready'] === true, 'test mode ready');
// The Resend adapter sees the stored mode: an address outside the list is suppressed, never sent.
$sent = false;
$result = brvtal_ticket_resend_send(['recipient_email'=>'someone@example.test','message_key'=>'k1','subject'=>'S','body_text'=>'B'],
    static function () use (&$sent) { $sent = true; return ['status'=>200,'body'=>'{"id":"x"}']; });
secretAssert($result['state'] === 'suppressed_test' && $sent === false, 'test mode only reaches the allowlist');

// Real: DNS and the explicit token are required.
secretAssert($apply('live', true, false, 'ENVIO_REAL') === 'MAIL_DNS_REQUIRED', 'dns required for live');
secretAssert($apply('live', true, true, 'si') === 'MAIL_LIVE_CONFIRMATION_REQUIRED', 'token required for live');
secretAssert($stored('BRVTAL_TICKET_MAIL_MODE') === 'test', 'failed live attempt keeps the previous mode');
secretAssert($apply('live', true, true, 'ENVIO_REAL') === null, 'live applied');
secretAssert($stored('BRVTAL_TICKET_MAIL_MODE') === 'live' && $stored('BRVTAL_TICKET_MAIL_ALLOW_LIVE') === '1'
    && $stored('BRVTAL_TICKET_MAIL_DNS_VERIFIED') === '1', 'live gates stored');
secretAssert($stored('BRVTAL_TICKET_MAIL_LOOKUP_SECRET') === $lookup, 'lookup secret generated only once');
secretAssert(brvtal_ticket_mail_status()['ready'] === true, 'live ready');

// Apagado closes the real-send gate.
secretAssert($apply('disabled', false) === null, 'disabled applied without confirmations');
secretAssert($stored('BRVTAL_TICKET_MAIL_MODE') === 'disabled' && $stored('BRVTAL_TICKET_MAIL_ALLOW_LIVE') === '0',
    'disabled closes the live gate');
$audit = (int)$pdo->query("SELECT COUNT(*) FROM admin_activity_log")->fetchColumn();
secretAssert($audit > 0, 'changes are audited');

// A value pinned in private config wins and is reported.
$GLOBALS['config']['ticketing']['mail']['mode'] = 'disabled';
secretAssert(brvtal_ticket_mail_status()['pinned'] === true, 'config pin reported');
echo "BRVTAL #1962 mail switch passed against disposable MariaDB.\n";
