<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/ticketing_wompi_webhook.php';
function t08(bool $ok,string $label): void { if (!$ok) throw new RuntimeException('T08: '.$label); }
function t08Reject(callable $fn,string $error): void {
    try { $fn(); } catch(DomainException $e) {
        t08($e->getMessage()===$error,'wrong error: '.$error); return;
    }
    throw new RuntimeException('T08: expected rejection '.$error);
}
$env = [
    'BRVTAL_TICKET_WOMPI_MODE'=>'mock','BRVTAL_TICKET_WOMPI_TESTING'=>'1',
    'BRVTAL_TICKET_WOMPI_PUBLIC_KEY'=>'pub_test_fixture_abc',
    'BRVTAL_TICKET_WOMPI_PRIVATE_KEY'=>'prv_test_fixture_abc',
    'BRVTAL_TICKET_WOMPI_INTEGRITY_SECRET'=>'test_integrity_fixture_abc',
    'BRVTAL_TICKET_WOMPI_EVENTS_SECRET'=>'test_events_fixture_abc',
    'BRVTAL_TICKET_WOMPI_ENABLED_METHODS'=>'CARD',
    'BRVTAL_TICKET_WOMPI_RETURN_URL'=>'https://example.invalid/payment-return',
];
t08Reject(fn()=>brvtal_wompi_hook_config(array_replace($env,
    ['BRVTAL_TICKET_WOMPI_EVENTS_SECRET'=>''])),'WEBHOOK_DISABLED');
$settings = brvtal_wompi_hook_config($env);
$reference = 'BW7-42-CARD-'.str_repeat('a',24);
$tx = ['id'=>'test-transaction-1','status'=>'APPROVED','amount_in_cents'=>5000000,
    'reference'=>$reference,'currency'=>'COP'];
$now=time();
$sign = static function(array $tx,int $timestamp,?array $fields=null) use($settings): array {
    $fields ??= ['transaction.id','transaction.status','transaction.amount_in_cents'];
    $message='';
    foreach($fields as $path) $message.=(string)($tx[substr($path,12)] ?? '');
    $checksum=hash('sha256',$message.$timestamp.$settings['eventsSecret']);
    $event=['event'=>'transaction.updated','environment'=>'test','timestamp'=>$timestamp,
        'data'=>['transaction'=>$tx], 'signature'=>['properties'=>$fields,'checksum'=>$checksum]];
    return [json_encode($event,JSON_THROW_ON_ERROR),$checksum];
};
[$body,$hash] = $sign($tx,$now);
$signed = brvtal_wompi_hook_verify($body,$hash,$settings,$now);
t08(array_intersect_key($signed,$tx)===$tx && isset($signed['_event_checksum']), 'signed Wompi event verified');
t08Reject(fn()=>brvtal_wompi_hook_verify($body,str_repeat('0',64),$settings,$now),'WEBHOOK_SIGNATURE');
t08Reject(fn()=>brvtal_wompi_hook_verify(str_replace('5000000','5000001',$body),$hash,$settings,$now),
    'WEBHOOK_SIGNATURE');
t08Reject(fn()=>brvtal_wompi_hook_verify($sign($tx,$now-172801)[0],'',$settings,$now),
    'WEBHOOK_REPLAY');
t08Reject(fn()=>brvtal_wompi_hook_verify($sign($tx,$now+301)[0],'',$settings,$now),
    'WEBHOOK_REPLAY');
$badSigned=['transaction.id','transaction.status','transaction.reference'];
t08Reject(fn()=>brvtal_wompi_hook_verify($sign($tx,$now,$badSigned)[0],'',$settings,$now),
    'WEBHOOK_UNSIGNED_FIELDS');
$provider = $tx;
$transport=static fn()=>['status'=>200,'body'=>json_encode(['data'=>$provider])];
t08(brvtal_wompi_hook_lookup($settings,$tx['id'],$transport)===$provider,
    'signed identity corroborated via sandbox lookup');
try { brvtal_wompi_hook_lookup($settings,$tx['id'],fn()=>['status'=>503,'body'=>'']);
    throw new RuntimeException('expected upstream failure');
} catch (RuntimeException $e) { t08($e->getMessage()==='WEBHOOK_PROVIDER_UNAVAILABLE','provider fail closed'); }
if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "BRVTAL T08 signature, replay and provider verification contract passed; MariaDB case runs in CI.\n";
    exit(0);
}
$prefix=(string)(getenv('BRVTAL_TEST_DB_NAME')?:'');
t08((bool)preg_match('/^brvtal_test[A-Za-z0-9_]*$/D',$prefix),'disposable DB only');
$host=(string)(getenv('BRVTAL_TEST_DB_HOST')?:'127.0.0.1');
$port=(int)(getenv('BRVTAL_TEST_DB_PORT')?:3306);
$user=(string)(getenv('BRVTAL_TEST_DB_USER')?:'root');
$pass=(string)(getenv('BRVTAL_TEST_DB_PASS')?:'');
$server=new PDO("mysql:host={$host};port={$port};charset=utf8mb4",$user,$pass,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$dbname=$prefix.'_hook_'.bin2hex(random_bytes(4));
$server->exec('CREATE DATABASE '.$dbname);
register_shutdown_function(static function() use($server,$dbname):void {
    try {$server->exec('DROP DATABASE IF EXISTS '.$dbname);} catch(Throwable) {}
});
$db=new PDO("mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4",$user,$pass,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
     PDO::ATTR_EMULATE_PREPARES=>false]);
$db->exec("CREATE TABLE ticket_orders(id BIGINT UNSIGNED PRIMARY KEY,status VARCHAR(16) NOT NULL,
 currency CHAR(3) NOT NULL,total_cop BIGINT NOT NULL,updated_at_utc DATETIME(6) NULL) ENGINE=InnoDB");
$db->exec("CREATE TABLE ticket_payment_attempts(id BIGINT UNSIGNED PRIMARY KEY,order_id INT NOT NULL,
 provider VARCHAR(24) NOT NULL,provider_reference VARCHAR(190) NOT NULL,
 provider_transaction_id VARCHAR(190) NULL,gross_amount_cop BIGINT NOT NULL,
 status VARCHAR(16) NOT NULL,updated_at_utc DATETIME(6) NULL,
 UNIQUE KEY uq_tx (provider,provider_transaction_id),UNIQUE KEY uq_ref (provider,provider_reference)) ENGINE=InnoDB");
$migration=file_get_contents(__DIR__ . '/../../database/migration_ticketing_webhook_01.sql');
t08(is_string($migration) && $migration !== '', 'migration fixture available');
$runMigration=static function() use($db,$migration):void {
    foreach(explode(';',$migration) as $statement) {
        if(trim($statement)!=='') $db->exec($statement);
    }
};
$runMigration();
$db->exec("INSERT INTO ticket_orders VALUES (42,'pending','COP',50000,NULL)");
$insert=$db->prepare("INSERT INTO ticket_payment_attempts VALUES
 (1,42,'wompi',?,NULL,50000,'created',NULL)");
$insert->execute([$reference]);
$process=static function(array $s,array $p) use($db):string {
    $db->beginTransaction();
    try { $out=brvtal_wompi_hook_apply($db,$s,$p); $db->commit(); return $out; }
    catch(Throwable $e) { $db->rollBack(); throw $e; }
};
t08($process($signed,$provider)==='approved','approved on Wompi verification');
t08($process($signed,$provider)==='duplicate','duplicate event no-op');
t08((int)$db->query('SELECT COUNT(*) FROM ticket_payment_webhook_events')->fetchColumn()===1,
    'identical signed event persisted once');
t08((int)$db->query('SELECT COUNT(*) FROM ticket_issue_outbox')->fetchColumn()===1,
    'exactly one durable issuance request');
t08($db->query("SELECT status FROM ticket_orders WHERE id=42")->fetchColumn()==='paid','order paid once');
$pending=$tx; $pending['status']='PENDING';
[$pendingBody,$pendingHash]=$sign($pending,$now);
$pendingSigned=brvtal_wompi_hook_verify($pendingBody,$pendingHash,$settings,$now);
t08($process($pendingSigned,$pending)==='duplicate','late pending no downgrade');
t08((int)$db->query('SELECT COUNT(*) FROM ticket_payment_webhook_events')->fetchColumn()===2,
    'late different event journaled without status downgrade');
t08((int)$db->query('SELECT COUNT(*) FROM ticket_issue_outbox')->fetchColumn()===1,
    'no duplicate issuance on late event');
t08($db->query("SELECT status FROM ticket_payment_attempts WHERE id=1")->fetchColumn()==='approved',
    'approval monotonic');
$bad=$provider; $bad['reference']='BW7-900-CARD-'.str_repeat('b',24);
t08Reject(fn()=>$process($signed,$bad),'WEBHOOK_PROVIDER_MISMATCH');
$bad=$provider; $bad['amount_in_cents']=500;
t08Reject(fn()=>$process($signed,$bad),'WEBHOOK_PROVIDER_MISMATCH');
$db->exec("UPDATE ticket_orders SET status='pending' WHERE id=42");
$db->exec("UPDATE ticket_payment_attempts SET status='created',provider_transaction_id=NULL WHERE id=1");
$db->exec("DROP TABLE ticket_payment_webhook_events");
try { $process($signed,$provider); throw new RuntimeException('expected DB failure'); }
catch(PDOException) { /* retry must not acknowledge as applied */ }
t08($db->query("SELECT status FROM ticket_payment_attempts WHERE id=1")->fetchColumn()==='created',
    'DB outage rolled back event');
$runMigration();
t08($process($signed,$provider)==='approved','retry after DB recovery');
t08((int)$db->query('SELECT COUNT(*) FROM ticket_issue_outbox')->fetchColumn()===1,
    'retry never duplicates issuance');
echo "BRVTAL T08 verified signed events, migration, outbox, reversals and DB recovery.\n";
