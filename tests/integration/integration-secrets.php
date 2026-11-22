<?php
declare(strict_types=1);

if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "Integration secret MariaDB contract skipped (BRVTAL_INTEGRATION_TESTS=1 required).\n";
    exit(0);
}
$baseDb = (string)(getenv('BRVTAL_TEST_DB_NAME') ?: '');
if (!preg_match('/^brvtal_test[A-Za-z0-9_]*$/D', $baseDb)) {
    throw new RuntimeException('REFUSING_NON_SYNTHETIC_DB');
}
function secretAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('INTEGRATION_SECRETS: ' . $message);
}

$host = (string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1');
$port = (int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306);
$user = (string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root');
$pass = (string)(getenv('BRVTAL_TEST_DB_PASS') ?: '');
$name = $baseDb . '_secret_' . bin2hex(random_bytes(4));
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
        'session_name'=>'BRVTAL_INTEGRATION_SECRET_TEST',
        'integration_secret_master_key'=>$master,
        'integration_secret_write'=>true,
    ],
];
$tmp = tempnam(sys_get_temp_dir(), 'brvtal_secret_test_');
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
$_SESSION['credential_epoch'] = 1;
$secret = 're_SYNTHETIC_NO_MAIL_SENDING_12345';
$key = 'BRVTAL_TICKET_MAIL_API_KEY';
putenv('BRVTAL_TICKET_MAIL_API_KEY=re_ENV_ONLY_TEST_12345');
brvtalIntegrationSecretMutate($pdo,'POST',$key,$secret,$actorId);
$record = $pdo->query("SELECT value_encrypted,updated_by_admin_id FROM integration_secrets")->fetch();
secretAssert(is_array($record), 'encrypted row persisted');
secretAssert(!str_contains(json_encode($record),$secret), 'plaintext never stored');
secretAssert((int)$record['updated_by_admin_id'] === $actorId, 'actor captured');
secretAssert(brvtalIntegrationSecretRead($key,$pdo,$cfg) === $secret, 'decrypt round trip');
secretAssert(brvtal_secret_value($key,null,['ticketing'=>['mail'=>['api_key'=>'re_PRIVATE_TEST_12345']],
    'security'=>$cfg['security']],$pdo) === 're_PRIVATE_TEST_12345', 'config precedence');
secretAssert(brvtal_secret_value($key,null,$cfg,$pdo) === $secret, 'encrypted store precedence');
$withoutMaster = ['security'=>['integration_secret_master_key'=>'']];
$oldMasterEnv = getenv('BRVTAL_INTEGRATION_SECRET_MASTER_KEY');
putenv('BRVTAL_INTEGRATION_SECRET_MASTER_KEY');
secretAssert(brvtal_secret_value($key,null,$withoutMaster,$pdo) === 're_ENV_ONLY_TEST_12345',
    'missing master must fall back to environment without querying ciphertext');
if ($oldMasterEnv !== false) putenv('BRVTAL_INTEGRATION_SECRET_MASTER_KEY='.$oldMasterEnv);

$wasInvalid = false;
try { brvtalIntegrationSecretValidate($key,'INVALID_PREFIX'); }
catch (InvalidArgumentException) { $wasInvalid = true; }
secretAssert($wasInvalid,'invalid Resend prefix rejected');
foreach (['pub_live_1234567890','prv_prod_1234567890'] as $invalid) {
    $wasInvalid = false;
    try {
        brvtalIntegrationSecretValidate(
            str_starts_with($invalid,'pub_') ? 'BRVTAL_TICKET_WOMPI_PUBLIC_KEY' : 'BRVTAL_TICKET_WOMPI_PRIVATE_KEY',
            $invalid
        );
    } catch (InvalidArgumentException) { $wasInvalid = true; }
    secretAssert($wasInvalid,'production Wompi key rejected');
}
$paddedWebhook = 'whsec_' . base64_encode(str_repeat('s', 32));
brvtalIntegrationSecretValidate('BRVTAL_TICKET_MAIL_WEBHOOK_SECRET', $paddedWebhook);
$plusSlashWebhook = 'whsec_' . base64_encode(str_repeat(chr(251) . chr(255), 16));
secretAssert(str_contains($plusSlashWebhook, '+') && str_contains($plusSlashWebhook, '/'), 'base64 fixture is standard');
brvtalIntegrationSecretValidate('BRVTAL_TICKET_MAIL_WEBHOOK_SECRET', $plusSlashWebhook);
foreach (['whsec_###', 'whsec_' . base64_encode('short'), 'whsec_' . str_repeat('A', 260)] as $bad) {
    $invalid = false;
    try { brvtalIntegrationSecretValidate('BRVTAL_TICKET_MAIL_WEBHOOK_SECRET', $bad); }
    catch (InvalidArgumentException) { $invalid = true; }
    secretAssert($invalid, 'malformed or short webhook key rejected');
}
$keypair = openssl_pkey_new(['private_key_bits'=>2048, 'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
secretAssert($keypair !== false, 'synthetic RSA generator');
$exported = '';
secretAssert(openssl_pkey_export($keypair, $exported), 'synthetic private key export');
brvtalIntegrationSecretValidate('BRVTAL_GA4_PRIVATE_KEY', $exported);
$escapedPem = str_replace("\n", '\\n', $exported);
brvtalIntegrationSecretValidate('BRVTAL_GA4_PRIVATE_KEY', $escapedPem);
foreach (['-----BEGIN PRIVATE KEY-----not-a-key-----END PRIVATE KEY-----', 'bad'] as $bad) {
    $invalid = false;
    try { brvtalIntegrationSecretValidate('BRVTAL_GA4_PRIVATE_KEY', $bad); }
    catch (InvalidArgumentException) { $invalid = true; }
    secretAssert($invalid, 'unparseable GA4 key rejected');
}
$forbidden = false;
try { brvtalIntegrationSecretValidate('BRVTAL_TICKET_MAIL_ALLOW_LIVE','1'); }
catch (InvalidArgumentException) { $forbidden = true; }
secretAssert($forbidden,'provider live-mode is not writable');

$GLOBALS['config']['security']['integration_secret_write'] = false;
$disabled = false;
try { brvtalIntegrationSecretMutate($pdo,'DELETE',$key,null,$actorId); }
catch (DomainException) { $disabled = true; }
secretAssert($disabled,'write disabled is fail-closed');
secretAssert(brvtalIntegrationSecretRead($key,$pdo,$cfg) === $secret,'disabled write leaves existing encrypted row');

$auditRows = $pdo->query('SELECT action,resource,meta_json,before_json,after_json FROM admin_activity_log')->fetchAll();
secretAssert(count($auditRows) === 1, 'one audited write');
secretAssert(!str_contains(json_encode($auditRows),$secret), 'audit excludes plaintext');
$encoded = json_encode(brvtalIntegrationSecretStatus($pdo));
secretAssert(is_string($encoded) && !str_contains($encoded,$secret), 'status never exposes plaintext');
secretAssert(str_contains($encoded,$key),'status identifies provider key');

$GLOBALS['config']['security']['integration_secret_write'] = true;
brvtalIntegrationSecretMutate($pdo,'DELETE',$key,null,$actorId);
secretAssert(brvtalIntegrationSecretRead($key,$pdo,$cfg) === null,'delete removes encrypted row');
$auditRows = $pdo->query('SELECT action,resource,meta_json FROM admin_activity_log ORDER BY id')->fetchAll();
secretAssert(count($auditRows) === 2 && $auditRows[1]['action'] === 'secret_delete', 'delete audited');
secretAssert(!str_contains(json_encode($auditRows),$secret), 'delete audit excludes plaintext');
echo "BRVTAL integration secrets MariaDB encryption, precedence, gate, format and audit passed.\n";
