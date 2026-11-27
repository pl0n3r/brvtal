<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/form_entries.php';

function contactCaptureExpect(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException('Contact capture: ' . $message);
}
function contactCaptureReject(callable $call, string $class, ?string $message = null): void {
    try { $call(); } catch (Throwable $e) {
        contactCaptureExpect($e instanceof $class && ($message === null || $e->getMessage() === $message), 'unexpected rejection');
        return;
    }
    throw new RuntimeException('Contact capture: expected rejection');
}
/** Run the actual PHP endpoint and anti-abuse helpers against this disposable DB. */
function contactCaptureHttpFixture(PDO $pdo, array $config, array $payload, string $host, int $port, string $db, string $user, string $pass): void
{
    $root = sys_get_temp_dir() . '/brvtal-contact-fixture-' . bin2hex(random_bytes(8));
    contactCaptureExpect(mkdir($root,0700),'isolated HTTP root');
    $server = null;
    try {
        foreach (['api','config','storage'] as $dir) mkdir($root.'/'.$dir,0700);
        $files = ['api/contact.php','config/bootstrap.php','config/public_health.php','config/db_connect_recovery.php',
            'config/public_contact.php','config/public_abuse_guard.php','config/password_rate_limit.php',
            'config/rate_limit_store.php','config/form_entries.php'];
        foreach ($files as $file) contactCaptureExpect(copy(dirname(__DIR__,2).'/'.$file,$root.'/'.$file),'copy HTTP fixture');
        $config['security'] = ['csrf_key'=>str_repeat('synthetic-contact-fixture-',3)];
        $config['db'] = ['host'=>$host,'port'=>$port,'name'=>$db,'user'=>$user,'pass'=>$pass,'charset'=>'utf8mb4'];
        file_put_contents($root.'/config/config.php', '<?php return '.var_export($config,true).';',LOCK_EX);
        chmod($root.'/config/config.php',0600);
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        contactCaptureExpect(is_resource($socket),'allocate loopback port');
        $address = stream_socket_get_name($socket,false);
        fclose($socket);
        $server = proc_open([PHP_BINARY,'-d','sendmail_path=/bin/false','-S',$address,'-t',$root],
            [0=>['pipe','r'],1=>['file',$root.'/http.log','a'],2=>['file',$root.'/http.log','a']],$pipes);
        contactCaptureExpect(is_resource($server),'start isolated HTTP server');
        fclose($pipes[0]);
        $url = 'http://'.$address.'/api/contact.php';
        $ready = false;
        for ($i=0;$i<50;++$i) {
            $probe = @stream_socket_client('tcp://'.$address,$errno,$errstr,0.2);
            if (is_resource($probe)) { fclose($probe); $ready=true; break; }
            usleep(100000);
        }
        contactCaptureExpect($ready,'loopback server ready');
        $challenge = json_decode((string)file_get_contents($url),true,flags:JSON_THROW_ON_ERROR)['data'];
        contactCaptureExpect(preg_match('/^(\d+) \+ (\d+) = \?$/',$challenge['question'],$matches)===1,'challenge prompt');
        $input = $payload + ['website'=>'','captcha_token'=>$challenge['token'],'captcha_answer'=>(string)((int)$matches[1]+(int)$matches[2])];
        sleep(2);
        $post = static function(array $body, string $key, int $status, array $expected) use ($url): void {
            $context = stream_context_create(['http'=>['method'=>'POST','ignore_errors'=>true,'timeout'=>5,
                'header'=>"Content-Type: application/json\r\nIdempotency-Key: ".$key."\r\n",
                'content'=>json_encode($body,JSON_THROW_ON_ERROR)]]);
            $raw = file_get_contents($url,false,$context);
            $headers = http_get_last_response_headers();
            contactCaptureExpect(is_array($headers) && preg_match('/^HTTP\/\S+ '.$status.' /',$headers[0] ?? '')===1,'HTTP status '.$status);
            contactCaptureExpect(json_decode((string)$raw,true,flags:JSON_THROW_ON_ERROR)===$expected,'sanitized HTTP response '.$status);
        };
        $key = str_repeat('d',32);
        $post($input,'invalid',422,['ok'=>false,'error'=>'CONTACT_INTENT_INVALID']);
        $success = ['ok'=>true,'message'=>'MESSAGE_RECEIVED'];
        $post($input,$key,200,$success);
        $post($input,$key,200,$success);
        $post(array_replace($input,['message'=>'Different synthetic message']),$key,409,['ok'=>false,'error'=>'CONTACT_INTENT_CONFLICT']);
        $pdo->exec("CREATE TRIGGER fixture_http_failure BEFORE INSERT ON form_notification_intents FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic failure'");
        try { $post($input,str_repeat('e',32),503,['ok'=>false,'error'=>'CONTACT_UNAVAILABLE']); }
        finally { $pdo->exec('DROP TRIGGER fixture_http_failure'); }
        contactCaptureExpect((int)$pdo->query('SELECT COUNT(*) FROM form_entries')->fetchColumn()===4,'HTTP replay and rollback');
        contactCaptureExpect((int)$pdo->query('SELECT COUNT(*) FROM form_notification_intents')->fetchColumn()===4,'HTTP single durable intent');
    } finally {
        if (is_resource($server)) { proc_terminate($server); proc_close($server); }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) { if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
        rmdir($root);
    }
}
if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') throw new RuntimeException('Disposable MariaDB fixture required');
$prefix = (string)getenv('BRVTAL_TEST_DB_NAME');
contactCaptureExpect(preg_match('/\Abrvtal_test[a-zA-Z0-9_]*\z/', $prefix) === 1, 'test database namespace');
$host = (string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1');
$port = (int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306);
$user = (string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root');
$pass = (string)(getenv('BRVTAL_TEST_DB_PASS') ?: '');
$dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
$options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false];
$config = ['contact'=>['capture'=>['enabled'=>true,'privacy_approved'=>true,'notice_version'=>'fixture-v1',
    'retention_policy_key'=>'fixture-only','retention_days'=>1]]];
$payload = ['name'=>'Fixture buyer','email'=>'fixture@example.invalid','subject'=>'Synthetic test','message'=>'Synthetic message only'];
if (($argv[1] ?? '') === '--child') {
    $db = $argv[2] ?? '';
    contactCaptureExpect(preg_match('/\Abrvtal_test[a-zA-Z0-9_]*_capture_[a-f0-9]{8}\z/', $db) === 1, 'child database');
    $pdo = new PDO($dsn . ';dbname=' . $db,$user,$pass,$options);
    echo "ready\n";
    flush();
    echo brvtal_contact_capture($pdo,$payload,str_repeat('b',32),$config) ? 'fresh' : 'replayed';
    exit;
}
$server = new PDO($dsn,$user,$pass,$options);
$db = $prefix . '_capture_' . bin2hex(random_bytes(4));
$server->exec("CREATE DATABASE ".$db." CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
try {
    $pdo = new PDO($dsn . ';dbname=' . $db,$user,$pass,$options);
    $sql = file_get_contents(__DIR__ . '/../../database/migration_forms_entries_01.sql');
    // Split at DDL boundaries, not semicolons in the migration's explanatory comments.
    contactCaptureExpect(is_string($sql) && $sql !== '', 'migration source present');
    $parts = preg_split('/;\s*(?=CREATE TABLE|$)/i', $sql);
    contactCaptureExpect(is_array($parts) && count(array_filter(array_map(trim(...), $parts))) === 3, 'three schema statements');
    foreach (array_filter(array_map(trim(...), $parts)) as $statement) $pdo->exec($statement);
    $count = static fn(string $table): int => (int)$pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    $key = str_repeat('a',32);
    contactCaptureExpect(brvtal_contact_capture_policy([]) === null,'default off');
    contactCaptureReject(fn()=>brvtal_contact_capture($pdo,$payload,$key,[]),RuntimeException::class,'CONTACT_CAPTURE_UNAVAILABLE');
    contactCaptureReject(fn()=>brvtal_contact_capture($pdo,$payload,[],$config),InvalidArgumentException::class,'CONTACT_INTENT_INVALID');
    contactCaptureReject(fn()=>brvtal_contact_capture($pdo,array_replace($payload,['message'=>[]]),$key,$config),InvalidArgumentException::class,'CONTACT_INTENT_INVALID');
    contactCaptureExpect($count('form_entries')===0,'invalid inputs do not persist');
    contactCaptureExpect(brvtal_contact_capture($pdo,$payload+['captcha_token'=>'synthetic-unused'],$key,$config),'first capture');
    contactCaptureExpect(!brvtal_contact_capture($pdo,$payload,$key,$config),'idempotent replay');
    contactCaptureReject(fn()=>brvtal_contact_capture($pdo,array_replace($payload,['message'=>'Different synthetic message']),$key,$config),DomainException::class,'CONTACT_INTENT_CONFLICT');
    $stored = $pdo->query('SELECT payload_json FROM form_entries')->fetchColumn();
    contactCaptureExpect(json_decode((string)$stored,true) === $payload,'only allowlisted fields');
    contactCaptureExpect($count('form_entries')===1 && $count('form_notification_intents')===1,'single entry and intent');
    contactCaptureExpect($pdo->query('SELECT state FROM form_notification_intents')->fetchColumn()==='pending','never sent');
    $pdo->beginTransaction();
    $pdo->query("SELECT form_key FROM form_definition_versions WHERE form_key='contact' FOR UPDATE")->fetchColumn();
    $workers = [];
    try {
        for ($i=0; $i<2; ++$i) {
            $worker = proc_open([PHP_BINARY,__FILE__,'--child',$db],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            contactCaptureExpect(is_resource($worker),'start competing process');
            fclose($pipes[0]);
            $workers[] = [$worker,$pipes[1],$pipes[2]];
            stream_set_timeout($pipes[1],10);
            contactCaptureExpect(fgets($pipes[1])==="ready\n",'worker entered capture');
        }
        $pdo->commit();
        $outcomes = [];
        foreach ($workers as [$worker,$out,$err]) {
            $outcomes[] = stream_get_contents($out);
            $stderr = stream_get_contents($err);
            fclose($out); fclose($err);
            contactCaptureExpect(proc_close($worker)===0 && $stderr==='','worker completed');
        }
        sort($outcomes);
        contactCaptureExpect($outcomes===['fresh','replayed'],'exactly one winner under contention');
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach ($workers as [$worker,$out,$err]) {
            if (is_resource($out)) fclose($out);
            if (is_resource($err)) fclose($err);
            if (is_resource($worker)) { proc_terminate($worker); proc_close($worker); }
        }
    }
    contactCaptureExpect($count('form_entries')===2 && $count('form_notification_intents')===2,'one pair per intent');
    $pdo->exec("CREATE TRIGGER fixture_intent_failure BEFORE INSERT ON form_notification_intents FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic intent failure'");
    contactCaptureReject(fn()=>brvtal_contact_capture($pdo,$payload,str_repeat('c',32),$config),PDOException::class);
    contactCaptureExpect($count('form_entries')===2 && $count('form_notification_intents')===2,'entry rolled back when intent failed');
    $pdo->exec('DROP TRIGGER fixture_intent_failure');
    contactCaptureExpect(brvtal_contact_capture($pdo,$payload,str_repeat('c',32),$config),'retry after rollback');
    $pdo->exec("UPDATE form_entries SET state='redacted',payload_json=NULL,redacted_at_utc=UTC_TIMESTAMP(6) WHERE idempotency_digest=UNHEX('" . bin2hex(brvtal_contact_capture_key($key)) . "')");
    contactCaptureReject(fn()=>brvtal_contact_capture($pdo,$payload,$key,$config),DomainException::class,'CONTACT_INTENT_CONFLICT');
    contactCaptureExpect($count('form_entries')===3,'redacted replay cannot resurrect entry');
    contactCaptureHttpFixture($pdo, $config, $payload, $host, $port, $db, $user, $pass);
} finally {
    $server->exec("DROP DATABASE ".$db);
}
echo "BRVTAL contact capture MariaDB integration passed.\n";
