<?php
declare(strict_types=1);

// #1910: exercise the real authenticated endpoint against disposable MariaDB.
if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "Settings public values integration skipped (synthetic MariaDB required).\n";
    exit(0);
}
function publicValuesAssert(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException('PUBLIC_VALUES: ' . $message);
}
$base = (string)(getenv('BRVTAL_TEST_DB_NAME') ?: '');
publicValuesAssert((bool)preg_match('/^brvtal_test[A-Za-z0-9_]*$/D', $base), 'refuse non-test database');
$host = (string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1');
$port = (int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306);
$user = (string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root');
$pass = (string)(getenv('BRVTAL_TEST_DB_PASS') ?: '');
$name = $base . '_public_values_' . bin2hex(random_bytes(4));
$opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false];
$server = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, $opts);
$server->exec("CREATE DATABASE " . chr(96) . $name . chr(96) . " CHARACTER SET utf8mb4");
register_shutdown_function(static function () use ($server, $name): void {
    try { $server->exec("DROP DATABASE IF EXISTS " . chr(96) . $name . chr(96)); } catch (Throwable) {}
});
$pdo = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $pass, $opts);
$pdo->exec("CREATE TABLE admins (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    name VARCHAR(120) NOT NULL,
    staff_role VARCHAR(30) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    credential_epoch BIGINT UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB");
$pdo->exec("INSERT INTO admins(id,email,password_hash,name,staff_role)
    VALUES (1,'owner@synthetic.invalid','synthetic','Owner','owner'),
           (2,'staff@synthetic.invalid','synthetic','Staff','admin')");
$pdo->exec((string)file_get_contents(__DIR__ . '/../../database/migration_integration_secrets_01.sql'));
require_once __DIR__ . '/../../config/integration_secrets_store.php';
$masterHex = bin2hex(random_bytes(32));
$masterKey = hex2bin($masterHex);
publicValuesAssert(is_string($masterKey), 'synthetic key generated');
$public = [
    'BRVTAL_TICKET_MAIL_FROM' => 'entradas@synthetic.invalid',
    'BRVTAL_TICKET_MAIL_DOMAIN' => 'mail.synthetic.invalid',
    'BRVTAL_SMTP_HOST' => 'smtp.synthetic.invalid',
    'BRVTAL_SMTP_USER' => 'synthetic-smtp-user',
    'BRVTAL_MAIL_FROM' => 'smtp@synthetic.invalid',
    'BRVTAL_GA4_PROPERTY_ID' => '123456789',
    'BRVTAL_GA4_CLIENT_EMAIL' => 'ga4@synthetic.invalid',
];
$private = [
    'BRVTAL_TICKET_MAIL_API_KEY' => 're_NEVER_EXPOSE_123456789',
    'BRVTAL_TICKET_MAIL_WEBHOOK_SECRET' => 'whsec_NEVER_EXPOSE',
    'BRVTAL_TICKET_MAIL_TEST_RECIPIENTS' => 'private@synthetic.invalid',
    'BRVTAL_SMTP_PASSWORD' => 'SMTP_NEVER_EXPOSE',
    'BRVTAL_TICKET_WOMPI_PUBLIC_KEY' => 'pub_test_NEVER_EXPOSE',
    'BRVTAL_TICKET_WOMPI_PRIVATE_KEY' => 'prv_test_NEVER_EXPOSE',
    'BRVTAL_GA4_PRIVATE_KEY' => '-----BEGIN PRIVATE KEY-----NEVER_EXPOSE',
    'BRVTAL_SENTRY_DSN' => 'https://NEVER_EXPOSE@sentry.invalid/1',
];
$insert = $pdo->prepare(
    'INSERT INTO integration_secrets(secret_key,value_encrypted,updated_by_admin_id) VALUES(?,?,1)'
);
foreach (array_merge($public, $private) as $key => $value) {
    $insert->execute([$key, brvtalIntegrationSecretEncrypt($key, $value, $masterKey)]);
}
$config = [
    'app' => ['timezone' => 'America/Bogota'],
    'db' => ['host'=>$host,'port'=>$port,'name'=>$name,'user'=>$user,'pass'=>$pass,'charset'=>'utf8mb4'],
    'security' => [
        'session_name' => 'BRVTAL_PUBLIC_VALUES_TEST',
        'integration_secret_master_key' => $masterHex,
        'integration_secret_write' => false,
    ],
];
$configFile = tempnam(sys_get_temp_dir(), 'brvtal_public_cfg_');
$runner = tempnam(sys_get_temp_dir(), 'brvtal_public_run_');
publicValuesAssert(is_string($configFile) && is_string($runner), 'disposable harness available');
register_shutdown_function(static function () use ($configFile, $runner): void {
    @unlink($configFile);
    @unlink($runner);
});
file_put_contents($configFile, '<?php return ' . var_export($config, true) . ';');
$root = dirname(__DIR__, 2);
$harness = <<<'HARNESS'
<?php
declare(strict_types=1);
define('BRVTAL_SENTRY_TESTING', true);
$GLOBALS['brvtalBootstrapConfigPath'] = $argv[1];
require_once $argv[2] . '/config/admin_auth.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
if ($argv[3] === 'owner' || $argv[3] === 'staff') {
    brvtal_admin_session_start();
    $_SESSION['admin_id'] = $argv[3] === 'owner' ? 1 : 2;
    $_SESSION['credential_epoch'] = 1;
    $_SESSION['issued_at'] = time();
    $_SESSION['last_activity'] = time();
}
require $argv[2] . '/api/admin-integration-secrets.php';
HARNESS;
file_put_contents($runner, $harness);
$invoke = static function (string $actor) use ($runner, $configFile, $root): array {
    $process = proc_open([PHP_BINARY, $runner, $configFile, $root, $actor],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    publicValuesAssert(is_resource($process), 'API process started');
    $json = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    publicValuesAssert($code === 0 && is_string($json), 'API terminated cleanly: ' . (string)$error);
    $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
    publicValuesAssert(is_array($decoded), 'valid JSON response');
    return [$decoded, $json];
};
[$guest] = $invoke('guest');
publicValuesAssert(($guest['error'] ?? null) === 'AUTH_REQUIRED', 'guest denied');
[$staff] = $invoke('staff');
publicValuesAssert(($staff['error'] ?? null) === 'FORBIDDEN', 'staff without Settings permission denied');
[$owner, $json] = $invoke('owner');
publicValuesAssert(($owner['ok'] ?? false) === true, 'owner authenticated');
publicValuesAssert(($owner['write_enabled'] ?? true) === false, 'GET does not enable writing');
$rows = array_column($owner['items'] ?? [], null, 'key');
foreach ($public as $key => $expected) {
    publicValuesAssert(($rows[$key]['stored'] ?? false) === true, $key . ' stored');
    publicValuesAssert(($rows[$key]['value'] ?? null) === $expected, $key . ' decrypted only for owner');
}
foreach ($private as $key => $secret) {
    publicValuesAssert(($rows[$key]['stored'] ?? false) === true, $key . ' status retained');
    publicValuesAssert(!array_key_exists('value', $rows[$key]), $key . ' must be opaque');
    publicValuesAssert(!str_contains($json, $secret), $key . ' cannot leak');
}
publicValuesAssert(($owner['master_key_configured'] ?? false) === true, 'master key status preserved');
echo "Settings public values integration OK (authenticated allowlist, no secrets).\n";
