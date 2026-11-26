<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/ticketing_wompi_webhook.php';
// Two independent DB connections race on the same Event mutex, not an in-memory simulation.
if (($argv[1] ?? '') === '--admit-race') {
    $dbname=(string)($argv[2] ?? '');
    if (!preg_match('/^brvtal_test[A-Za-z0-9_]*$/D',$dbname)) exit(2);
    $payload=json_decode((string)base64_decode((string)($argv[3] ?? ''),true),true);
    if (!is_array($payload) || count($payload)!==2) exit(3);
    $barrier=(float)($argv[4] ?? 0);
    $host=(string)(getenv('BRVTAL_TEST_DB_HOST')?:'127.0.0.1');
    $port=(int)(getenv('BRVTAL_TEST_DB_PORT')?:3306);
    $user=(string)(getenv('BRVTAL_TEST_DB_USER')?:'root');
    $pass=(string)(getenv('BRVTAL_TEST_DB_PASS')?:'');
    $db=new PDO("mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4",$user,$pass,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
    while(microtime(true)<$barrier) usleep(10000);
    $db->beginTransaction();
    try {
        $result=brvtal_wompi_hook_apply($db,$payload[0],$payload[1]);
        $db->commit();echo $result;exit(0);
    } catch(Throwable $error) {
        if($db->inTransaction()) $db->rollBack();
        fwrite(STDERR,$error::class.':'.$error->getMessage());exit(4);
    }
}
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
$db->exec("CREATE TABLE events (id INT UNSIGNED PRIMARY KEY,status VARCHAR(32) NOT NULL) ENGINE=InnoDB");
$db->exec("CREATE TABLE event_ticket_types (id INT UNSIGNED PRIMARY KEY,event_id INT UNSIGNED NOT NULL,
 UNIQUE KEY uq_t03_ticket_type_event(id,event_id)) ENGINE=InnoDB");
$db->exec("CREATE TABLE ticket_orders(id BIGINT UNSIGNED PRIMARY KEY,event_id INT UNSIGNED NOT NULL,
 status VARCHAR(16) NOT NULL,currency CHAR(3) NOT NULL,total_cop BIGINT NOT NULL,
 expires_at_utc DATETIME(6) NULL,updated_at_utc DATETIME(6) NULL,
 UNIQUE KEY uq_ticket_orders_id_event(id,event_id)) ENGINE=InnoDB");
$db->exec("CREATE TABLE ticket_order_items(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,event_id INT UNSIGNED NOT NULL,ticket_type_id INT UNSIGNED NOT NULL,
 quantity INT UNSIGNED NOT NULL,currency CHAR(3) NOT NULL,
 UNIQUE KEY uq_ticket_order_item_type(order_id,ticket_type_id)) ENGINE=InnoDB");
$db->exec("CREATE TABLE ticket_refund_event_decisions(event_id INT UNSIGNED PRIMARY KEY,cause VARCHAR(20) NOT NULL) ENGINE=InnoDB");
$holdsSql=(string)file_get_contents(__DIR__.'/../../database/migration_ticketing_reservations_01.sql');
foreach(explode(';',(string)preg_replace('/^\\h*--[^\\r\\n]*$/m','',$holdsSql)) as $ddl)
    if(trim($ddl)!=='') $db->exec($ddl);
$db->exec("INSERT INTO events VALUES (1,'published')");
$db->exec("INSERT INTO event_ticket_types VALUES (10,1),(11,1),(12,2)");
$db->exec("INSERT INTO events VALUES (2,'published')");
$db->exec("INSERT INTO ticket_inventory_events VALUES (1,100),(2,1)");
$db->exec("INSERT INTO ticket_inventory_types(ticket_type_id,event_id,capacity,courtesy_capacity)
 VALUES(10,1,100,0),(11,1,100,0),(12,2,1,0)");
$db->exec("CREATE TABLE ticket_payment_attempts(id BIGINT UNSIGNED PRIMARY KEY,order_id INT NOT NULL,
 provider VARCHAR(24) NOT NULL,provider_reference VARCHAR(190) NOT NULL,
 provider_transaction_id VARCHAR(190) NULL,gross_amount_cop BIGINT NOT NULL,
 status VARCHAR(16) NOT NULL,updated_at_utc DATETIME(6) NULL,
 UNIQUE KEY uq_tx (provider,provider_transaction_id),UNIQUE KEY uq_ref (provider,provider_reference)) ENGINE=InnoDB");
$migration=file_get_contents(__DIR__ . '/../../database/migration_ticketing_webhook_01.sql');
t08(is_string($migration) && $migration !== '', 'migration fixture available');
$runMigration=static function() use($db,$migration):void {
    $ddl=preg_replace('/^\h*--[^\r\n]*$/m', '', $migration);
    foreach(explode(';',(string)$ddl) as $statement) {
        if(trim($statement)!=='') $db->exec($statement);
    }
};
$runMigration();
$db->exec("INSERT INTO ticket_orders(id,event_id,status,currency,total_cop) VALUES
 (42,1,'pending','COP',50000)");
$db->exec("INSERT INTO ticket_order_items(order_id,event_id,ticket_type_id,quantity,currency)
 VALUES(42,1,10,1,'COP')");
$db->exec("INSERT INTO ticket_inventory_holds(order_id,event_id,ticket_type_id,quantity,kind,status,expires_at_utc)
 VALUES(42,1,10,1,'paid','active',UTC_TIMESTAMP(6)+INTERVAL 15 MINUTE)");
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
t08($db->query("SELECT status FROM ticket_inventory_holds WHERE order_id=42")->fetchColumn()==='consumed',
    'hold consumed atomically with paid and outbox');
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
$db->exec("UPDATE ticket_inventory_holds SET status='active',
 expires_at_utc=UTC_TIMESTAMP(6)+INTERVAL 15 MINUTE WHERE order_id=42");
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
// Authenticated approval after expiry, terminal state, incident or missing holds
// retains the original journal/approved attempt but never creates tickets.
$case = static function(int $id,string $orderState,string $holdState,bool $incident=false,
    bool $multi=false) use($db,$sign,$settings,$now):array {
    $ref='BW7-'.$id.'-CARD-'.str_repeat(dechex($id%16),24);
    $idTx='fixture-payment-'.$id;
    $db->prepare("INSERT INTO ticket_orders(id,event_id,status,currency,total_cop)
        VALUES(?,1,?,'COP',50000)")->execute([$id,$orderState]);
    $db->prepare("INSERT INTO ticket_order_items(order_id,event_id,ticket_type_id,quantity,currency)
        VALUES(?,1,10,1,'COP')")->execute([$id]);
    if($multi) $db->prepare("INSERT INTO ticket_order_items(order_id,event_id,ticket_type_id,quantity,currency)
        VALUES(?,1,11,1,'COP')")->execute([$id]);
    $db->prepare("INSERT INTO ticket_payment_attempts(id,order_id,provider,provider_reference,
        gross_amount_cop,status) VALUES(?,?,'wompi',?,50000,'created')")->execute([$id,$id,$ref]);
    if($holdState!=='missing') {
        $expiry=$holdState==='expired'?'UTC_TIMESTAMP(6)-INTERVAL 1 SECOND':
            'UTC_TIMESTAMP(6)+INTERVAL 15 MINUTE';
        $db->exec("INSERT INTO ticket_inventory_holds(order_id,event_id,ticket_type_id,quantity,kind,status,expires_at_utc)
            VALUES($id,1,10,1,'paid','active',$expiry)");
    }
    if($incident) $db->exec("INSERT INTO ticket_refund_event_decisions VALUES(1,'postponed')");
    $txn=['id'=>$idTx,'reference'=>$ref,'status'=>'APPROVED','currency'=>'COP',
        'amount_in_cents'=>5000000];
    [$raw,$sig]=$sign($txn,$now);
    $verified=brvtal_wompi_hook_verify($raw,$sig,$settings,$now);
    return [$verified,$txn];
};
foreach ([[43,'pending','missing',false,false],
          [44,'pending','expired',false,false],
          [45,'cancelled','valid',false,false],
          [46,'pending','valid',true,false],
          [47,'pending','valid',false,true]] as [$id,$state,$hold,$incident,$multi]) {
    [$s,$p]=$case($id,$state,$hold,$incident,$multi);
    t08($process($s,$p)==='approved_unfulfilled',"approved but unfulfilled $id");
    t08($process($s,$p)==='approved_unfulfilled',"duplicate unfulfilled $id");
    t08($db->query("SELECT status FROM ticket_payment_attempts WHERE id=$id")->fetchColumn()==='approved',
        "preserved monetary approval $id");
    t08($db->query("SELECT status FROM ticket_orders WHERE id=$id")->fetchColumn()===$state,
        "order never incorrectly paid $id");
    t08((int)$db->query("SELECT COUNT(*) FROM ticket_payment_webhook_events WHERE attempt_id=$id")->fetchColumn()===1,
        "journal exactly once $id");
    t08((int)$db->query("SELECT COUNT(*) FROM ticket_issue_outbox WHERE order_id=$id")->fetchColumn()===0,
        "no issue request $id");
    if($incident) $db->exec('DELETE FROM ticket_refund_event_decisions');
}
// A signed PENDING event with independently authenticated APPROVED status must
// keep its original signed provenance and still admit the valid reservation.
[$s,$p]=$case(48,'pending','valid');
$pendingTx=$p; $pendingTx['status']='PENDING';
[$raw,$sig]=$sign($pendingTx,$now);
$pendingSigned=brvtal_wompi_hook_verify($raw,$sig,$settings,$now);
t08($process($pendingSigned,$p)==='approved','provider-approved after signed pending');
t08($db->query("SELECT signed_status FROM ticket_payment_webhook_events WHERE attempt_id=48")
    ->fetchColumn()==='PENDING','original signature never upgraded');
t08((int)$db->query('SELECT COUNT(*) FROM ticket_issue_outbox WHERE order_id=48')->fetchColumn()===1,
    'provider authenticated approval enqueues once');
// Complete multi-type reservations remain admissible after a later sell-out;
// neither current public stock nor a newly edited price revokes a valid hold.
[$multiSigned,$multiProvider]=$case(51,'pending','valid',false,true);
$db->exec("INSERT INTO ticket_inventory_holds(order_id,event_id,ticket_type_id,quantity,kind,status,expires_at_utc)
 VALUES(51,1,11,1,'paid','active',UTC_TIMESTAMP(6)+INTERVAL 15 MINUTE)");
$db->exec("UPDATE events SET status='sold_out' WHERE id=1");
t08($process($multiSigned,$multiProvider)==='approved','valid multi-type hold admitted after sold-out');
t08((int)$db->query("SELECT COUNT(*) FROM ticket_inventory_holds
 WHERE order_id=51 AND status='consumed'")->fetchColumn()===2,'all ticket types consumed atomically');
$db->exec("UPDATE events SET status='published' WHERE id=1");
[$expirySigned,$expiryProvider]=$case(52,'pending','valid');
$db->exec("UPDATE ticket_orders SET expires_at_utc=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND
 WHERE id=52");
t08($process($expirySigned,$expiryProvider)==='approved_unfulfilled',
    'expired order cannot be paid despite a still-active hold');
// Optional administrative mail is NOT part of the paid/issued transaction.
// An enabled mock mail path with no mail schema must fail inside the savepoint,
// without rolling back the provider approval or the idempotent issue outbox.
$originalConfig=$GLOBALS['config']??null;
$GLOBALS['config']=['ticketing'=>['mail'=>[
    'mode'=>'mock','testing'=>'1','admin_recipient'=>'admin@example.test',
]]];
try {
    [$noticeSigned,$noticeProvider]=$case(53,'pending','valid');
    t08($process($noticeSigned,$noticeProvider)==='approved',
        'verified payment survives unavailable optional admin mail');
    t08($process($noticeSigned,$noticeProvider)==='duplicate',
        'replayed callback does not repeat admission on mail failure');
    t08($db->query("SELECT status FROM ticket_payment_attempts WHERE id=53")
        ->fetchColumn()==='approved','mail failure preserved authentic money');
    t08($db->query("SELECT status FROM ticket_orders WHERE id=53")
        ->fetchColumn()==='paid','mail failure preserved admitted order');
    t08((int)$db->query("SELECT COUNT(*) FROM ticket_issue_outbox WHERE order_id=53")
        ->fetchColumn()===1,'mail failure preserved one durable issue request');
} finally {
    if($originalConfig===null) unset($GLOBALS['config']);
    else $GLOBALS['config']=$originalConfig;
}
// Two processes race for an Event with exactly one legitimate held unit.
// The other confirmed charge is financially recorded without issuance.
$pack = static function(int $id,bool $hold) use($db,$sign,$settings,$now):array {
    $ref='BW7-'.$id.'-CARD-'.str_repeat(dechex($id%16),24);
    $db->prepare("INSERT INTO ticket_orders(id,event_id,status,currency,total_cop)
        VALUES(?,2,'pending','COP',50000)")->execute([$id]);
    $db->prepare("INSERT INTO ticket_order_items(order_id,event_id,ticket_type_id,quantity,currency)
        VALUES(?,2,12,1,'COP')")->execute([$id]);
    $db->prepare("INSERT INTO ticket_payment_attempts(id,order_id,provider,provider_reference,
        gross_amount_cop,status) VALUES(?,?,'wompi',?,50000,'created')")->execute([$id,$id,$ref]);
    if($hold) $db->prepare("INSERT INTO ticket_inventory_holds
        (order_id,event_id,ticket_type_id,quantity,kind,status,expires_at_utc)
        VALUES(?,2,12,1,'paid','active',UTC_TIMESTAMP(6)+INTERVAL 15 MINUTE)")->execute([$id]);
    $provider=['id'=>'race-'.$id,'reference'=>$ref,'status'=>'APPROVED',
        'amount_in_cents'=>5000000,'currency'=>'COP'];
    [$raw,$hash]=$sign($provider,$now);
    return [brvtal_wompi_hook_verify($raw,$hash,$settings,$now),$provider];
};
$racePayloads=[$pack(49,true),$pack(50,false)];
$barrier=microtime(true)+0.8;
$workers=[];
foreach($racePayloads as $payload) {
    $processRace=proc_open([PHP_BINARY,__FILE__,'--admit-race',$dbname,
        base64_encode(json_encode($payload,JSON_THROW_ON_ERROR)),(string)$barrier],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__);
    t08(is_resource($processRace),'race worker started');
    fclose($pipes[0]);$workers[]=[$processRace,$pipes];
}
$results=[];
foreach($workers as [$proc,$pipes]) {
    $out=trim((string)stream_get_contents($pipes[1]));
    $err=trim((string)stream_get_contents($pipes[2]));
    fclose($pipes[1]);fclose($pipes[2]);
    t08(proc_close($proc)===0 && $err==='','race worker completed: '.$err);
    $results[]=$out;
}
sort($results);
t08($results===['approved','approved_unfulfilled'],'last seat has only one admitted order');
t08((int)$db->query("SELECT COUNT(*) FROM ticket_orders
    WHERE event_id=2 AND status='paid'")->fetchColumn()===1,'exactly one paid order');
t08((int)$db->query("SELECT COUNT(*) FROM ticket_issue_outbox
    WHERE order_id IN (49,50)")->fetchColumn()===1,'exactly one issuance outbox');
t08((int)$db->query("SELECT COUNT(*) FROM ticket_payment_webhook_events
    WHERE attempt_id IN (49,50)")->fetchColumn()===2,'both authenticated charges journaled');
echo "BRVTAL T08 authentic money and two-connection atomic last-seat admission passed.\n";
