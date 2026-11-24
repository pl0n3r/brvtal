<?php
declare(strict_types=1);

/** Isolated CLI worker regression: synthetic MariaDB and simulated mail only. */
if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "BRVTAL worker-secret MariaDB regression skipped.\n";
    exit(0);
}
function worker_secret_assert(bool $ok, string $reason): void {
    if (!$ok) throw new RuntimeException('WORKER_SECRET_REGRESSION: ' . $reason);
}
function worker_secret_run(string $path, array $environment): array {
    $process = proc_open([PHP_BINARY, $path],
        [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes,
        dirname($path), $environment);
    worker_secret_assert(is_resource($process), 'start CLI child');
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process), (string)$out, (string)$err];
}
function worker_secret_config(string $path, array $data): void {
    worker_secret_assert(file_put_contents($path, '<?php return ' . var_export($data, true) . ';') !== false,
        'write synthetic configuration');
}

$base = (string)(getenv('BRVTAL_TEST_DB_NAME') ?: '');
worker_secret_assert((bool)preg_match('/^brvtal_test[A-Za-z0-9_]*$/D', $base),
    'refuse non-synthetic database');
$host = (string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1');
$port = (int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306);
$user = (string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root');
$pass = (string)(getenv('BRVTAL_TEST_DB_PASS') ?: '');
$dbName = $base . '_worker_' . bin2hex(random_bytes(4));
$opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
$server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, $opts);
$server->exec('CREATE DATABASE ' . chr(96) . $dbName . chr(96) . ' CHARACTER SET utf8mb4');
$repo = dirname(__DIR__, 2);
$sandbox = sys_get_temp_dir() . '/brvtal_mail_worker_' . bin2hex(random_bytes(6));
worker_secret_assert(mkdir($sandbox . '/ops', 0700, true)
    && mkdir($sandbox . '/config', 0700, true), 'isolated worker');
register_shutdown_function(static function () use ($server, $dbName, $sandbox): void {
    try { $server->exec('DROP DATABASE IF EXISTS ' . chr(96) . $dbName . chr(96)); }
    catch (Throwable) {}
    foreach (['ops/ticketing-mail-worker.php',
        'config/ticketing_mail_outbox.php', 'config/ticketing_issue_delivery.php',
        'config/ticketing_wompi_reconcile.php',
        'config/integration_secrets_store.php', 'config/secrets.php',
        'config/config.php'] as $file) @unlink($sandbox . '/' . $file);
    @rmdir($sandbox . '/ops'); @rmdir($sandbox . '/config'); @rmdir($sandbox);
});
$pdo = new PDO("mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4",
    $user, $pass, $opts);
$pdo->exec('CREATE TABLE admins (id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
$pdo->exec((string)file_get_contents($repo . '/database/migration_integration_secrets_01.sql'));
$pdo->exec((string)file_get_contents($repo . '/database/migration_ticketing_mail_outbox_01.sql'));
require_once $repo . '/config/integration_secrets_store.php';
$master = bin2hex(random_bytes(32));
$keyBytes = hex2bin($master);
worker_secret_assert(is_string($keyBytes), 'synthetic master');
$apiKey = 're_SYNTHETIC_WORKER_ONLY_NO_SEND_12345';
$recipient = 'synthetic-buyer@example.test';
$secrets = [
    'BRVTAL_TICKET_MAIL_API_KEY' => $apiKey,
    'BRVTAL_TICKET_MAIL_DOMAIN' => 'brvtal.invalid',
    'BRVTAL_TICKET_MAIL_FROM' => 'noreply@brvtal.invalid',
];
$insert = $pdo->prepare('INSERT INTO integration_secrets(secret_key,value_encrypted) VALUES(?,?)');
foreach ($secrets as $name => $value) {
    $insert->execute([$name, brvtalIntegrationSecretEncrypt($name, $value, $keyBytes)]);
}
worker_secret_assert(!str_contains((string)$pdo->query(
    "SELECT value_encrypted FROM integration_secrets WHERE secret_key='BRVTAL_TICKET_MAIL_API_KEY'"
)->fetchColumn(), $apiKey), 'key only stored as ciphertext');
$pdo->prepare('INSERT INTO ticket_mail_outbox
 (message_key,payload_hash,related_ref,recipient_email,subject,body_text) VALUES(?,?,?,?,?,?)')
    ->execute(['ticket:cli:1874', hash('sha256','fixture'), 'ticket:1874',
        $recipient, 'Entrada sintética', 'Contenido sintético']);

foreach (['ticketing-mail-worker.php'] as $file) {
    worker_secret_assert(copy($repo . '/ops/' . $file, $sandbox . '/ops/' . $file),
        'copy worker entrypoint');
}
foreach (['ticketing_mail_outbox.php','ticketing_issue_delivery.php','ticketing_wompi_reconcile.php',
    'integration_secrets_store.php','secrets.php'] as $file) {
    worker_secret_assert(symlink($repo . '/config/' . $file, $sandbox . '/config/' . $file),
        'link production module read-only');
}
$config = ['db'=>['host'=>$host,'port'=>$port,'name'=>$dbName,
    'user'=>$user,'pass'=>$pass,'charset'=>'utf8mb4'],
    'security'=>['integration_secret_master_key'=>$master],
    'app'=>['base_url'=>'https://brvtal.invalid'],
    'ticketing'=>['mail'=>['mode'=>'mock','testing'=>true]]];
worker_secret_config($sandbox . '/config/config.php', $config);
$environment = getenv();
worker_secret_assert(is_array($environment), 'child environment available');
foreach (['BRVTAL_TICKET_MAIL_API_KEY','BRVTAL_TICKET_MAIL_DOMAIN',
    'BRVTAL_TICKET_MAIL_FROM','BRVTAL_INTEGRATION_SECRET_MASTER_KEY',
    'BRVTAL_TICKET_MAIL_MODE','BRVTAL_TICKET_MAIL_TESTING'] as $name) {
    $environment[$name] = '';
}
[$exit, $out, $err] = worker_secret_run($sandbox . '/ops/ticketing-mail-worker.php', $environment);
// #1945: no ticketing tables here, so issuance reports unavailable and mail still flows.
worker_secret_assert($exit === 0 && trim($out) === '{"reconcile":"unavailable","issue":"unavailable","state":"accepted"}' && $err === '',
    'CLI processes synthetic queued mail with encrypted-only credentials');
worker_secret_assert((string)$pdo->query('SELECT state FROM ticket_mail_outbox')->fetchColumn()
    === 'accepted', 'outbox transitions to accepted');
foreach ([$apiKey,$recipient,'noreply@brvtal.invalid','Contenido sintético'] as $private) {
    worker_secret_assert(!str_contains($out . $err, $private), 'CLI output does not leak sensitive data');
}
unset($config['security']['integration_secret_master_key']);
worker_secret_config($sandbox . '/config/config.php', $config);
[$exit, $out, $err] = worker_secret_run($sandbox . '/ops/ticketing-mail-worker.php', $environment);
worker_secret_assert($exit !== 0 && $out === '' && trim($err) === 'ticket_mail_secret_unavailable',
    'missing master must fail closed without a trace');
worker_secret_assert(!str_contains($err, 'Stack trace')
    && !str_contains($err, $apiKey) && !str_contains($err, $recipient), 'generic CLI error only');
echo "BRVTAL encrypted-secret CLI mail worker regression passed.\n";
