<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/ticketing_pos.php';
require_once __DIR__.'/../../config/ticketing_checkin.php';
require_once __DIR__.'/../../config/migrations.php';
require_once __DIR__.'/../../config/migration_reconcile.php';
function posAssert(bool $v,string $why): void {
    if (!$v) throw new RuntimeException('T24 POS: '.$why);
}
function posDenies(callable $run,string $code): void {
    try { $run(); throw new RuntimeException('POS accepted invalid input: '.$code); }
    catch (DomainException | InvalidArgumentException $e) {
        posAssert($e->getMessage()===$code, 'expected '.$code.', got '.$e->getMessage());
    }
}
$statusRead=['action'=>'shift_status','event_id'=>'6'];
posAssert(brvtalPosInput($statusRead)===['action'=>'shift_status','event_id'=>6],'typed status read');
posDenies(static fn()=>brvtalPosInput($statusRead+['operator_admin_id'=>'1']),'POS_INVALID_FIELDS');
posDenies(static fn()=>brvtalPosInput(array_replace($statusRead,['event_id'=>null])),'POS_INVALID_NUMBER');
$prepare=['action'=>'prepare','shift_id'=>'1','ticket_type_id'=>'10',
          'quantity'=>'1','method'=>'cash','idempotency_key'=>str_repeat('a',32)];
posAssert(brvtalPosInput($prepare)['quantity']===1,'typed prepare');
$typedEmail=brvtalPosInput(['action'=>'confirm','sale_id'=>'1',
    'confirmation_token'=>str_repeat('b',48),'confirm'=>true,
    'buyer_email'=>'PUERTA@example.test']);
posAssert($typedEmail['buyer_email']==='puerta@example.test',
    'optional buyer email canonicalized for transactional delivery');
posAssert(brvtalPosCan('superadmin')&&brvtalPosCan('admin'),'seller roles');
$manual=['action'=>'manual_order','event_id'=>'6','ticket_type_id'=>'10','quantity'=>'2',
    'buyer_name'=>'Comprador Ejemplo','buyer_email'=>'manual@example.test',
    'method'=>'nequi','payment_reference'=>'REF-2026-001',
    'idempotency_key'=>str_repeat('m',32),'confirm'=>true];
posAssert(brvtalPosInput($manual)['method']==='nequi','manual wallet accepted in validated input');
posDenies(static fn()=>brvtalPosInput(array_replace($manual,
    ['payment_reference'=>''])),'POS_INVALID_MANUAL_ORDER');
posDenies(static fn()=>brvtalPosInput(array_replace($manual,
    ['payment_reference'=>"REF\r\nInjected"])),'POS_INVALID_MANUAL_ORDER');
posDenies(static fn()=>brvtalPosInput(array_replace($manual,
    ['confirm'=>false])),'POS_INVALID_MANUAL_ORDER');
posDenies(static fn()=>brvtalPosInput(array_replace($manual,
    ['method'=>'wompi_qr'])),'POS_INVALID_MANUAL_ORDER');
posDenies(static fn()=>brvtalPosInput($manual+['total_cop'=>'1']),'POS_INVALID_FIELDS');
$received=['received_amount_cop'=>'42000','received_reason'=>'Pago offline previo',
    'received_reference'=>'REC-2026-001'];
posAssert(brvtalPosInput($manual+$received)['received_amount_cop']===42000,'typed received amount');
foreach (['received_reason','received_reference'] as $field) {
    $missing=$received; unset($missing[$field]);
    posDenies(static fn()=>brvtalPosInput($manual+$missing),'POS_INVALID_FIELDS');
    posDenies(static fn()=>brvtalPosInput($manual+array_replace($received,[$field=>''])),
        'POS_INVALID_RECEIVED_EVIDENCE');
}
foreach (['-1','0','1000000001'] as $bad) {
    posDenies(static fn()=>brvtalPosInput($manual+array_replace($received,['received_amount_cop'=>$bad])),
        'POS_INVALID_NUMBER');
}
posDenies(static fn()=>brvtalPosInput($manual+array_replace($received,['received_amount_cop'=>'42001'])),
    'POS_INVALID_RECEIVED_EVIDENCE');
posDenies(static fn()=>brvtalPosInput($manual+array_replace($received,['received_reason'=>"bad|reason"])),
    'POS_INVALID_RECEIVED_EVIDENCE');
posDenies(static fn()=>brvtalPosInput($prepare+$received),'POS_INVALID_FIELDS');
posAssert(!brvtalPosCan('door')&&!brvtalPosCan('viewer'),'door cannot sell');
foreach ([
  [$prepare+['payment_approved'=>true],'POS_INVALID_FIELDS'],
  [array_replace($prepare,['quantity'=>'11']),'POS_INVALID_NUMBER'],
  [array_replace($prepare,['quantity'=>'0']),'POS_INVALID_NUMBER'],
  [array_replace($prepare,['idempotency_key'=>'short']),'POS_INVALID_PREPARE'],
  [array_replace($prepare,['method'=>'wire']),'POS_INVALID_PREPARE'],
  [['action'=>'confirm','sale_id'=>'1','confirmation_token'=>str_repeat('b',48),
    'confirm'=>true,'buyer_email'=>"a@example.test\r\nBcc:x"],'POS_INVALID_EMAIL'],
  [['action'=>'confirm','sale_id'=>'1','confirmation_token'=>str_repeat('b',48),
    'confirm'=>true,'buyer_email'=>str_repeat('a',192).'@test.co'],'POS_INVALID_EMAIL'],
  [['action'=>'confirm','sale_id'=>'1','confirmation_token'=>str_repeat('b',48),'confirm'=>false],
    'POS_CONFIRMATION_REQUIRED'],
] as [$bad,$code]) posDenies(static fn()=>brvtalPosInput($bad),$code);
posAssert(!str_contains((string)file_get_contents(__DIR__.'/../../config/ticketing_pos.php'),'curl_exec('),
    'no provider invocation');
$api=(string)file_get_contents(__DIR__.'/../../api/ticketing/admin-pos.php');
posAssert(str_contains($api,'brvtal_admin_require_csrf()'),'HTTP CSRF enforced');
$gate=(string)file_get_contents(__DIR__.'/../../config/ticketing_checkin.php');
posAssert(str_contains($gate,"ps.status='paid'")&&str_contains($gate,"ps.method='cash'")
    && str_contains($gate,'brvtal_ticket_pos_verified'), 'door verifies POS evidence');
$delivery=(string)file_get_contents(__DIR__.'/../../config/ticketing_issue_delivery.php');
posAssert(str_contains($delivery,'brvtal_ticket_issue_mail_deliverer')
    && str_contains($delivery,'brvtal_ticket_mail_queue_order'),
    'worker uses sealed QR delivery and mail outbox');
if (getenv('BRVTAL_INTEGRATION_TESTS')!=='1') {
    echo "T24 POS validation/RBAC/CSRF/cash check-in contract OK; disposable MariaDB not configured.\n";
    exit(0);
}

/** Actual localhost HTTP endpoint, with disposable DB and canonical session/CSRF. */
function posIntentHttp(array $settings,array $intent,array $expected): void {
    posAssert((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/D',$settings['name']),'HTTP disposable DB only');
    $dir=sys_get_temp_dir().'/brvtal-pos-http-'.bin2hex(random_bytes(8));
    if (!mkdir($dir,0700)) throw new RuntimeException('fixture directory failed');
    $server=null;
    try {
        $root=realpath(__DIR__.'/../..');
        posAssert(is_string($root),'fixture source root');
        file_put_contents($dir.'/config.php','<?php return '.var_export([
            'app'=>['timezone'=>'America/Bogota'],
            'db'=>$settings,'security'=>['session_name'=>'BRVTAL_POS_FIXTURE']
        ],true).';');
        chmod($dir.'/config.php',0600);
        $router='<?php define("BRVTAL_SENTRY_TESTING",true);'
            .'$GLOBALS["brvtalBootstrapConfigPath"]='.var_export($dir.'/config.php',true).';'
            .'ini_set("session.save_path",'.var_export($dir,true).');'
            .'$root='.var_export($root,true).';'
            . <<<'ROUTER'
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if ($path==='/fixture-session') {
    require $root.'/config/admin_auth.php';
    $actor=(int)($_GET['actor']??0);
    if (!in_array($actor,[1,2,3],true)) {http_response_code(404);exit;}
    brvtal_admin_login_session($actor);
    json_response(['csrf'=>brvtal_admin_csrf_token()]);
}
if ($path==='/api/ticketing/admin-pos.php') {
    require $root.$path;
    return true;
}
http_response_code(404);
ROUTER;
        file_put_contents($dir.'/router.php',$router);
        $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);
        posAssert(is_resource($socket),'fixture socket');
        $address=stream_socket_get_name($socket,false);fclose($socket);
        $server=proc_open([PHP_BINARY,'-S',$address,$dir.'/router.php'],
            [0=>['pipe','r'],1=>['file',$dir.'/server.log','a'],2=>['file',$dir.'/server.log','a']],$pipes,$root);
        posAssert(is_resource($server),'HTTP fixture starts');fclose($pipes[0]);
        $port=(int)substr(strrchr($address,':'),1);
        $ready=false;
        for($i=0;$i<40;$i++){
            $probe=@fsockopen('127.0.0.1',$port,$errno,$errstr,0.2);
            if(is_resource($probe)){fclose($probe);$ready=true;break;}
            usleep(50000);
        }
        posAssert($ready,'HTTP fixture ready');
        $request=static function(string $path,array $headers=[],?array $payload=null)use($address):array{
            $options=['method'=>$payload===null?'GET':'POST','ignore_errors'=>true,
                'timeout'=>5,'header'=>implode("\r\n",$headers)];
            if($payload!==null)$options['content']=json_encode($payload,JSON_THROW_ON_ERROR);
            $body=file_get_contents('http://'.$address.$path,false,stream_context_create(['http'=>$options]));
            $lines=http_get_last_response_headers();
            posAssert(is_string($body)&&is_array($lines)&&isset($lines[0]),'HTTP response present');
            return [(int)substr($lines[0],9,3),json_decode($body,true,8,JSON_THROW_ON_ERROR),$lines];
        };
        $path='/api/ticketing/admin-pos.php';
        [$status]=$request($path,['Content-Type: application/json'],$intent);
        posAssert($status===401,'HTTP unauthenticated rejected');
        foreach([1,2,3] as $actor){
            [$status,$session,$headers]=$request('/fixture-session?actor='.$actor);
            posAssert($status===200&&isset($session['csrf']),'real session bootstrap');
            $cookie='';
            foreach($headers as $header){
                if(str_starts_with($header,'Set-Cookie: BRVTAL_POS_FIXTURE=')){
                    $cookie=explode(';',substr($header,12),2)[0];
                }
            }
            posAssert($cookie!=='','session cookie');
            $base=['Content-Type: application/json','Cookie: '.$cookie];
            [$status]=$request($path,$base,$intent);
            posAssert($status===419,'HTTP CSRF required');
            [$status,$body]=$request($path,[...$base,'X-CSRF-Token: '.$session['csrf']],$intent);
            if($intent['action']==='manual_quote' && $actor!==3) {
                $quote=$body['data']??[];
                posAssert($status===200 && preg_match('/^[0-9]{10}[.][a-f0-9]{64}$/D',$quote['quote_token']??''),
                    'real HTTP quote and opaque proof');
                unset($quote['quote_token'],$quote['expires_at_utc']);
                posAssert($quote===$expected,'real HTTP quote fields');
            }
            elseif($actor===1)posAssert($status===200&&$body===['ok'=>true,'data'=>$expected],'real HTTP receipt');
            elseif($actor===2)posAssert($status===200&&$body===['ok'=>true,'data'=>['state'=>'not_found']],'HTTP actor isolation');
            elseif($intent['action']==='shift_status')
                posAssert($status===200&&$body===['ok'=>true,'data'=>['state'=>'not_found']],'HTTP other staff isolated');
            else posAssert($status===403&&$body['error']==='FORBIDDEN','HTTP role denied');
        }
    } finally {
        if(is_resource($server)){proc_terminate($server);proc_close($server);}
        foreach(glob($dir.'/*')?:[] as $file)unlink($file);
        rmdir($dir);
    }
}

$prefix=(string)getenv('BRVTAL_TEST_DB_NAME');
posAssert((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/D',$prefix),'only disposable DB');
$host=(string)(getenv('BRVTAL_TEST_DB_HOST')?:'127.0.0.1');
$port=(int)(getenv('BRVTAL_TEST_DB_PORT')?:3306);
$user=(string)(getenv('BRVTAL_TEST_DB_USER')?:'root');
$pass=(string)(getenv('BRVTAL_TEST_DB_PASS')?:'');
$opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
       PDO::ATTR_EMULATE_PREPARES=>false];
$server=new PDO("mysql:host=$host;port=$port;charset=utf8mb4",$user,$pass,$opts);
$name=$prefix.'_pos_'.bin2hex(random_bytes(3));
$server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4");
register_shutdown_function(static function() use($server,$name):void {
    try {$server->exec("DROP DATABASE IF EXISTS `$name`");} catch(Throwable) {}
});
$db=new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",$user,$pass,$opts);
$ddl=[
  "CREATE TABLE admins (id INT UNSIGNED PRIMARY KEY,is_active TINYINT NOT NULL DEFAULT 1,
      credential_epoch INT NOT NULL DEFAULT 1,staff_role VARCHAR(32) NOT NULL DEFAULT 'admin') ENGINE=InnoDB",
  "CREATE TABLE ticket_refund_event_decisions (event_id INT UNSIGNED PRIMARY KEY,
      cause VARCHAR(20) NOT NULL) ENGINE=InnoDB",
  "CREATE TABLE events (id INT UNSIGNED PRIMARY KEY,status VARCHAR(24),event_date DATETIME,
      published_at DATETIME NULL) ENGINE=InnoDB",
  "CREATE TABLE event_ticket_types (
      id INT UNSIGNED PRIMARY KEY,event_id INT UNSIGNED,name VARCHAR(120),price DECIMAL(12,2),
      status VARCHAR(24),currency CHAR(3),available_from DATETIME NULL,available_until DATETIME NULL,
      UNIQUE KEY uq_t03_ticket_type_event(id,event_id)) ENGINE=InnoDB",
  "CREATE TABLE ticket_buyers (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      public_id BINARY(16),email VARCHAR(190) NULL UNIQUE,full_name VARCHAR(120),
      status VARCHAR(24)) ENGINE=InnoDB",
  "CREATE TABLE ticket_orders (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      public_id BINARY(16),buyer_id BIGINT UNSIGNED,event_id INT UNSIGNED,
      checkout_key_hash CHAR(64) UNIQUE,status VARCHAR(24),currency CHAR(3),total_cop BIGINT UNSIGNED,
      updated_at_utc DATETIME(6) DEFAULT (UTC_TIMESTAMP(6)),
      UNIQUE KEY uq_ticket_orders_id_event(id,event_id)) ENGINE=InnoDB",
  "CREATE TABLE ticket_order_items (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      order_id BIGINT UNSIGNED,event_id INT UNSIGNED,ticket_type_id INT UNSIGNED,
      ticket_name_snapshot VARCHAR(120),quantity INT UNSIGNED,unit_price_cop BIGINT UNSIGNED,
      subtotal_cop BIGINT UNSIGNED,currency CHAR(3),
      UNIQUE KEY uq_ticket_order_item_type(order_id,ticket_type_id)) ENGINE=InnoDB",
  "CREATE TABLE ticket_entries (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_id BINARY(16),
      order_item_id BIGINT UNSIGNED,event_id INT UNSIGNED,ticket_type_id INT UNSIGNED,
      unit_number INT UNSIGNED,status VARCHAR(24) NOT NULL DEFAULT 'issued',
      issued_at_utc DATETIME(6),used_at_utc DATETIME(6) NULL,
      UNIQUE KEY uq_ticket_entries_order_unit(order_item_id,unit_number)) ENGINE=InnoDB",
  "CREATE TABLE ticket_entry_credentials(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      entry_id BIGINT UNSIGNED,version INT UNSIGNED,token_hash BINARY(32),
      created_at_utc DATETIME(6) NOT NULL,
      status VARCHAR(24) NOT NULL DEFAULT 'active') ENGINE=InnoDB",
  "CREATE TABLE ticket_payment_attempts (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      order_id BIGINT UNSIGNED,provider VARCHAR(24),status VARCHAR(24),
      provider_transaction_id VARCHAR(120)) ENGINE=InnoDB",
  "CREATE TABLE ticket_payment_webhook_events (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      attempt_id BIGINT UNSIGNED,provider_transaction_id VARCHAR(120),signed_status VARCHAR(24)) ENGINE=InnoDB",
  "CREATE TABLE ticket_issue_outbox (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      order_id BIGINT UNSIGNED UNIQUE,payment_attempt_id BIGINT UNSIGNED NULL,
      state VARCHAR(24) NOT NULL DEFAULT 'pending',attempts INT NOT NULL DEFAULT 0,
      next_attempt_at_utc DATETIME(6) NULL,
      updated_at_utc DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6))) ENGINE=InnoDB",
  "CREATE TABLE ticket_order_notes (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      order_id BIGINT UNSIGNED,actor_admin_id INT UNSIGNED,note_text VARCHAR(280),
      created_at_utc DATETIME(6) DEFAULT (UTC_TIMESTAMP(6))) ENGINE=InnoDB",
  "CREATE TABLE ticket_checkins (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      request_id BINARY(16),event_id INT UNSIGNED,entry_id BIGINT UNSIGNED NULL,
      credential_version INT UNSIGNED NULL,operator_admin_id INT UNSIGNED,
      device_id VARCHAR(96),outcome VARCHAR(24),reason_code VARCHAR(40) NULL,
      attempted_at_utc DATETIME(6)) ENGINE=InnoDB",
];
foreach($ddl as $sql) $db->exec($sql);
foreach(['migration_ticketing_reservations_01.sql','migration_ticketing_pos_01.sql'] as $path) {
    $sql=(string)file_get_contents(__DIR__.'/../../database/'.$path);
    $sql=preg_replace('/^\s*--[^\n]*$/m','',$sql);
    foreach(array_filter(array_map(trim(...),explode(';',$sql))) as $statement) $db->exec($statement);
}
$when=(new DateTimeImmutable('now',new DateTimeZone('America/Bogota')));
$date=$when->modify('+8 days')->format('Y-m-d H:i:s');
$doorClock=new DateTimeImmutable($date,new DateTimeZone('America/Bogota'));
$published=$when->modify('-1 day')->format('Y-m-d H:i:s');
$db->exec("INSERT INTO admins(id,is_active,staff_role) VALUES (1,1,'superadmin'),(2,1,'superadmin'),(3,1,'admin')");
$event=$db->prepare("INSERT INTO events VALUES (6,'published',?,?)");
$event->execute([$date,$published]);
$db->exec("INSERT INTO event_ticket_types(id,event_id,name,price,status,currency)
 VALUES(10,6,'General',25000.00,'active','COP')");
$db->exec("INSERT INTO ticket_inventory_events(event_id,capacity) VALUES(6,3)");
$db->exec("INSERT INTO ticket_inventory_types(ticket_type_id,event_id,capacity,courtesy_capacity)
 VALUES(10,6,3,0)");
// #2260: seed genuine old-schema synthetic sales before applying the additive DDL.
$db->exec("INSERT INTO ticket_pos_shifts(event_id,operator_admin_id,opening_cash_cop) VALUES(6,3,0)");
$legacyShift=(int)$db->lastInsertId();
$db->exec("INSERT INTO ticket_buyers(public_id,status) VALUES (UNHEX(REPEAT('ab',16)),'guest')");
$legacyBuyer=(int)$db->lastInsertId();
$db->prepare("INSERT INTO ticket_orders(public_id,buyer_id,event_id,checkout_key_hash,status,currency,total_cop)
    VALUES (UNHEX(REPEAT('cd',16)),?,6,?,'paid','COP',25000)")
    ->execute([$legacyBuyer,hash('sha256','legacy-fixture')]);
$legacyOrder=(int)$db->lastInsertId();
$seed=$db->prepare("INSERT INTO ticket_pos_sales
    (shift_id,event_id,operator_admin_id,ticket_type_id,order_id,quantity,unit_price_cop,amount_cop,method,
     status,request_key_hash,request_hash,confirmation_hash,expires_at_utc,completed_at_utc)
    VALUES (?,6,3,10,?,1,25000,25000,'cash',?,?,?, ?,DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 120 SECOND),?)");
foreach (['prepared','cancelled','paid'] as $state) {
    $seed->execute([$legacyShift,$state==='paid'?$legacyOrder:null,$state,hash('sha256','legacy-key-'.$state),
        hash('sha256','legacy-request-'.$state),hash('sha256','legacy-confirm-'.$state),
        $state==='prepared'?null:gmdate('Y-m-d H:i:s')]);
}
$quoteMigration='migration_ticketing_pos_03.sql';
$quoteSql=(string)file_get_contents(__DIR__.'/../../database/'.$quoteMigration);
$quoteProof=static fn():array => brvtalMigrationSchemaProof($db,$quoteMigration);
$quoteRows=static fn():array => $db->query('SELECT * FROM ticket_pos_sales ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$legacy=$quoteRows();
$absent=$quoteProof();
posAssert(count($legacy)===3 && brvtalMigrationProofIsFullyAbsent($absent),'old schema and sales exist');
brvtalMigrationAssertReconciliationSql($quoteMigration,$quoteSql,$absent);
$db->exec($quoteSql);
posAssert($quoteProof()['complete'],'quote proof complete after actual DDL');
$columns=['quote_source'=>null,'quote_stage_code'=>null,'quote_channel'=>null];
foreach($quoteRows() as $index=>$row) {
    posAssert(array_intersect_key($row,$columns)===$columns && array_diff_key($row,$columns)===$legacy[$index],
        'legacy sale unchanged and provenance unknown');
}
posAssert(count($quoteRows())===count($legacy),'quote DDL preserves row count');
// Only disposable fixture rows are removed to keep the original count assertions.
$db->prepare('DELETE FROM ticket_pos_sales WHERE shift_id=?')->execute([$legacyShift]);
$db->prepare('DELETE FROM ticket_pos_shifts WHERE id=?')->execute([$legacyShift]);
$db->prepare('DELETE FROM ticket_orders WHERE id=?')->execute([$legacyOrder]);
$db->prepare('DELETE FROM ticket_buyers WHERE id=?')->execute([$legacyBuyer]);
foreach (['migration_ticketing_door_price_01.sql','migration_ticketing_stages_01.sql'] as $path) {
    $db->exec((string)file_get_contents(__DIR__.'/../../database/'.$path));
}
$open=['action'=>'open','event_id'=>'6','opening_cash_cop'=>'50000'];
posDenies(static fn()=>brvtalPosRun($db,1,'door',$open),'FORBIDDEN');
$read=static fn(int $actor=1,?array $input=null)=>brvtalPosRun($db,$actor,'admin',$input??$statusRead);
posAssert($read()===['state'=>'not_found'],'no shift before opening');
posDenies(static fn()=>brvtalPosRun($db,1,'door',$statusRead),'FORBIDDEN');
$shift=brvtalPosRun($db,1,'admin',$open);
$openState=['state'=>'open','shift_id'=>$shift['shift_id'],'event_id'=>6,'preparations'=>[]];
posAssert($read()===$openState,'own durable open shift');
posAssert($read(2)===['state'=>'not_found'],'other operator cannot see shift');
posAssert($read(1,['action'=>'shift_status','event_id'=>'7'])===['state'=>'not_found'],'other event isolated');
posAssert($shift['status']==='open','open drawer');
posDenies(static fn()=>brvtalPosRun($db,1,'admin',$open),'POS_SHIFT_ALREADY_OPEN');
posDenies(static fn()=>brvtalPosRun($db,2,'admin',['action'=>'prepare']+$prepare),
    'POS_SHIFT_UNAVAILABLE');
posDenies(static fn()=>brvtalPosRun($db,1,'admin',
    array_replace($prepare,['shift_id'=>(string)$shift['shift_id'],'method'=>'wompi_qr'])),
    'POS_PROVIDER_PROOF_REQUIRED');
posDenies(static fn()=>brvtalPosRun($db,1,'admin',
    array_replace($prepare,['shift_id'=>(string)$shift['shift_id'],'method'=>'transfer'])),
    'POS_TRANSFER_RULES_REQUIRED');
$req=array_replace($prepare,['shift_id'=>(string)$shift['shift_id']]);
$prepared=brvtalPosRun($db,1,'admin',$req);
posAssert($prepared['status']==='prepared'&&$prepared['amount_cop']===25000,'server-owned price');
$snapshotRead=static function()use($db):array {
    $snapshot=[];
    foreach(['ticket_pos_shifts','ticket_pos_sales','ticket_orders','ticket_order_items',
        'ticket_inventory_holds','ticket_issue_outbox','ticket_entries','ticket_order_notes'] as $table) {
        $snapshot[$table]=$db->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
    }
    return $snapshot;
};
$beforeRead=$snapshotRead();
$pendingState=$openState;
$pendingState['preparations'][]=['sale_id'=>$prepared['sale_id'],'ticket_type_id'=>10,
    'quantity'=>1,'amount_cop'=>25000,'state'=>'pending'];
posAssert($read()===$pendingState,'own preparation without credentials or PII');
posIntentHttp(['host'=>$host,'port'=>$port,'name'=>$name,'user'=>$user,'pass'=>$pass],$statusRead,$pendingState);
posAssert($snapshotRead()===$beforeRead,'status read does not mutate durable tables');
$expiry=$db->prepare('UPDATE ticket_pos_sales SET expires_at_utc=? WHERE id=?');
$expiry->execute(['2000-01-01 00:00:00',$prepared['sale_id']]);
$expiredState=$pendingState;
$expiredState['preparations'][0]['state']='expired';
posAssert($read()===$expiredState,'expired preparation remains distinguishable');
$expiry->execute([$beforeRead['ticket_pos_sales'][0]['expires_at_utc'],$prepared['sale_id']]);
$repeat=brvtalPosRun($db,1,'admin',$req);
posAssert($repeat['replayed']&&$repeat['confirmation_token']===null,'replay cannot mint token');
posDenies(static fn()=>brvtalPosRun($db,1,'admin',array_replace($req,['quantity'=>'2'])),
    'POS_IDEMPOTENCY_CONFLICT');
$confirm=['action'=>'confirm','sale_id'=>(string)$prepared['sale_id'],
    'confirmation_token'=>$prepared['confirmation_token'],'confirm'=>true,
    'buyer_email'=>'puerta@example.test'];
posDenies(static fn()=>brvtalPosRun($db,2,'admin',$confirm),'POS_SHIFT_UNAVAILABLE');
posDenies(static fn()=>brvtalPosRun($db,1,'admin',array_replace($confirm,
    ['confirmation_token'=>str_repeat('e',48)])),'POS_SALE_UNAVAILABLE');
posAssert((int)$db->query('SELECT COUNT(*) FROM ticket_entries')->fetchColumn()===0,
    'no tickets before double confirmation');
$paid=brvtalPosRun($db,1,'admin',$confirm);
posAssert($paid['status']==='paid'&&count($paid['tickets'])===1,'one ticket');
posAssert((int)$db->query('SELECT COUNT(*) FROM ticket_entries')->fetchColumn()===1,
    'exactly one committed entry');
posAssert($db->query("SELECT email FROM ticket_buyers WHERE email='puerta@example.test'")->fetchColumn()
    ==='puerta@example.test','transactional buyer email persisted in disposable fixture');
posAssert((int)$db->query('SELECT COUNT(*) FROM ticket_payment_attempts')->fetchColumn()===0,
    'no forged Wompi approved attempt');
$againReceipt=brvtalPosRun($db,1,'admin',$confirm);
posAssert($againReceipt['replayed'] && $againReceipt['receipt']===$paid['receipt']
    && $againReceipt['amount_cop']===$paid['amount_cop'] && $againReceipt['tickets']===[], 'same receipt, no new QR');
$early=brvtalTicketCheckinConsume($db,6,2,'synthetic-reader',$paid['tickets'][0],
    $doorClock->modify('-1 second'));
posAssert(!$early['ok']&&$early['reason']==='event_closed',
    'POS ticket does not grant early admission');
$checkin=brvtalTicketCheckinConsume($db,6,2,'synthetic-reader',$paid['tickets'][0],$doorClock);
posAssert($checkin['ok']===true&&$checkin['reason']==='accepted','door accepts recorded cash sale');
$again=brvtalTicketCheckinConsume($db,6,2,'synthetic-reader',$paid['tickets'][0],$doorClock);
posAssert(!$again['ok']&&$again['reason']==='already_used','door never double enters');
$second=brvtalPosRun($db,1,'admin',array_replace($req,
    ['idempotency_key'=>str_repeat('b',32)]));
$cancel=brvtalPosRun($db,1,'admin',['action'=>'cancel','sale_id'=>(string)$second['sale_id'],
    'confirmation_token'=>$second['confirmation_token']]);
posAssert($cancel['status']==='cancelled','cancel preparation');
posDenies(static fn()=>brvtalPosRun($db,1,'admin',[
    'action'=>'confirm','sale_id'=>(string)$second['sale_id'],
    'confirmation_token'=>$second['confirmation_token'],'confirm'=>true]),'POS_SALE_UNAVAILABLE');
$close=brvtalPosRun($db,1,'admin',[
    'action'=>'close','shift_id'=>(string)$shift['shift_id'],'closing_cash_cop'=>'70000']);
posAssert($close['expected_cash_cop']===75000&&$close['cash_difference_cop']===-5000,
    'cash variance excludes Wompi and cancelled sale');
posDenies(static fn()=>brvtalPosRun($db,1,'admin',$req),'POS_SHIFT_UNAVAILABLE');
posAssert($read()===['state'=>'closed','shift_id'=>$shift['shift_id'],'event_id'=>6,'preparations'=>[]],
    'closed physical drawer has durable sale evidence');
$empty=brvtalPosRun($db,2,'admin',array_replace($open,['opening_cash_cop'=>'0']));
brvtalPosRun($db,2,'admin',['action'=>'close','shift_id'=>(string)$empty['shift_id'],'closing_cash_cop'=>'0']);
posAssert($read(2)===['state'=>'unverifiable'],'empty zero-cash closure is not proof of physical identity');
// Real-owner lane is allowed without synthetic-mode flag but only on the
// disposable database fixture; no Wompi charge or network request is made.
putenv('BRVTAL_INTEGRATION_TESTS=0');
try {
    posDenies(static fn()=>brvtalPosRun($db,1,'admin',$manual),'FORBIDDEN');
    posDenies(static fn()=>brvtalPosRun($db,1,'door',$manual),'FORBIDDEN');
    $intent=['action'=>'manual_intent','idempotency_key'=>$manual['idempotency_key']];
    posDenies(static fn()=>brvtalPosInput($intent+['buyer_email'=>'fake@example.test']),'POS_INVALID_FIELDS');
    posDenies(static fn()=>brvtalPosInput(array_replace($intent,['idempotency_key'=>'short'])),'POS_INVALID_MANUAL_INTENT');
    posDenies(static fn()=>brvtalPosRun($db,1,'admin',$intent),'FORBIDDEN');
    posAssert(brvtalPosRun($db,1,'superadmin',$intent)===['state'=>'not_found'],'absent intention');
    posDenies(static fn()=>brvtalPosRun($db,1,'superadmin',$manual),'POS_QUOTE_REQUIRED');
    $manualKey=random_bytes(32);
    $manualQuote=['action'=>'manual_quote','event_id'=>'6','ticket_type_id'=>'10','quantity'=>'2',
        'idempotency_key'=>$manual['idempotency_key']];
    $q=brvtalPosRun($db,1,'superadmin',$manualQuote,$manualKey);
    posAssert($q['amount_cop']===50000 && $q['source']==='base','manual quote reflects base when no stages');
    $manual['quote_token']=$q['quote_token'];
    $created=brvtalPosRun($db,1,'superadmin',$manual,$manualKey);
    putenv('BRVTAL_INTEGRATION_TESTS=1');
    try {posAssert($read()===['state'=>'unverifiable'],'manual audit parent is not a physical drawer');}
    finally {putenv('BRVTAL_INTEGRATION_TESTS=0');}
    posAssert($created['status']==='paid' && $created['issuance_queued'] && !$created['replayed'],
        'owner records paid wallet order and queues QR issuance');
    posAssert((int)$db->query("SELECT COUNT(*) FROM ticket_payment_attempts")->fetchColumn()===0,
        'offline wallet never forges Wompi approval');
    posAssert((int)$db->query("SELECT COUNT(*) FROM ticket_entries")->fetchColumn()===1,
        'owner commit does not issue QR in HTTP transaction');
    $audit=$db->prepare('SELECT note_text FROM ticket_order_notes WHERE order_id=?');
    $audit->execute([$created['order_id']]);
    posAssert(str_starts_with((string)$audit->fetchColumn(),'manual-payment|nequi|REF-2026-001|'),
        'owner reference and original method audited');
    // Real audit history can grow before issuance/retry. Never rely on the
    // latest ten notes to find the immutable owner payment proof.
    $newNote=$db->prepare('INSERT INTO ticket_order_notes(order_id,actor_admin_id,note_text)
        VALUES (?,?,?)');
    for($i=0;$i<14;$i++) {
        $newNote->execute([$created['order_id'],1,'operational-note-'.$i]);
    }
    posAssert(brvtal_ticket_pos_verified($db,(int)$created['order_id']),
        'verified offline payment survives more than ten newer audit notes');
    $replay=brvtalPosRun($db,1,'superadmin',$manual);
    posAssert($replay['replayed'] && $replay['order_id']===$created['order_id'],
        'owner same idempotency key returns one order');
    posDenies(static fn()=>brvtalPosRun($db,1,'superadmin',
        array_replace($manual,['quantity'=>'1'])),'POS_IDEMPOTENCY_CONFLICT');
    posDenies(static fn()=>brvtalPosRun($db,1,'superadmin',
        array_replace($manualQuote,['idempotency_key'=>str_repeat('n',32),'quantity'=>'1']),$manualKey),
        'INVENTORY_SOLD_OUT');
    $outbox=$db->prepare('SELECT id,payment_attempt_id,state FROM ticket_issue_outbox WHERE order_id=?');
    $outbox->execute([$created['order_id']]);
    $job=$outbox->fetch(PDO::FETCH_ASSOC);
    posAssert(is_array($job) && $job['state']==='pending' && $job['payment_attempt_id']===null,
        'offline order durable issue job without Wompi attempt');
    $snapshot=static function()use($db):array {
        $counts=[];
        foreach(['ticket_orders','ticket_order_items','ticket_pos_sales','ticket_pos_shifts',
            'ticket_order_notes','ticket_issue_outbox','ticket_entries','ticket_inventory_holds'] as $table) {
            $counts[$table]=(int)$db->query("SELECT COUNT(*) FROM ".$table)->fetchColumn();
        }
        return $counts;
    };
    $before=$snapshot();
    $receipt=brvtalPosRun($db,1,'superadmin',$intent);
    posAssert($receipt===['state'=>'found','order_id'=>$created['order_id'],
        'amount_cop'=>50000,'method'=>'nequi','status'=>'paid','issuance'=>'pending'],'safe pending receipt');
    posAssert(brvtalPosRun($db,1,'superadmin',$intent)===$receipt,'read replay');
    posIntentHttp(['host'=>$host,'port'=>$port,'name'=>$name,'user'=>$user,'pass'=>$pass],$intent,$receipt);
    posAssert(brvtalPosRun($db,2,'superadmin',$intent)===['state'=>'not_found'],'different actor isolated');
    $db->prepare("UPDATE ticket_issue_outbox SET state='failed' WHERE id=?")->execute([$job['id']]);
    posAssert(brvtalPosRun($db,1,'superadmin',$intent)===['state'=>'uncertain'],'unverified issuance fails closed');
    $db->prepare("UPDATE ticket_issue_outbox SET state='pending' WHERE id=?")->execute([$job['id']]);
    posAssert($snapshot()===$before,'receipt reads never create rows');
    $issued=brvtal_ticket_issue_consume($db,(int)$job['id']);
    $receipt['issuance']='issued';
    posAssert(brvtalPosRun($db,1,'superadmin',$intent)===$receipt,'issued receipt without QR or mail claim');
    posAssert($issued['state']==='issued' && count($issued['tickets'])===2,
        'trusted issuance worker creates exactly two QR credentials');
    $first=$issued['tickets'][0];
    $qr='BRVTAL:T1:'.$first['public_id'].':'.$first['token'];
    $scanned=brvtalTicketCheckinConsume($db,6,2,'offline-reader',$qr,$doorClock);
    posAssert($scanned['ok'] && $scanned['reason']==='accepted',
        'verified wallet order QR accepted at door');
    posAssert(brvtal_ticket_issue_consume($db,(int)$job['id'])['state']==='duplicate',
        'issuance idempotency does not duplicate QR');
} finally {putenv('BRVTAL_INTEGRATION_TESTS=1');}
putenv('BRVTAL_INTEGRATION_TESTS=0');
try {
    posDenies(static fn()=>brvtalPosRun($db,1,'admin',$open),'POS_SYNTHETIC_ONLY');
    posDenies(static fn()=>brvtalPosRun($db,1,'admin',$statusRead),'POS_SYNTHETIC_ONLY');
}
finally {putenv('BRVTAL_INTEGRATION_TESTS=1');}
echo "T24 disposable POS cash, inventory, QR/check-in, audit, cancellation, RBAC OK.\n";

// #2176: two real connections; change the policy between quote and confirmation.
$event=$db->prepare("INSERT INTO events VALUES (7,'published',?,?)");
$event->execute([$date,$published]);
$db->exec("INSERT INTO event_ticket_types(id,event_id,name,price,status,currency,door_price_cop)
    VALUES(11,7,'General etapa',19000.00,'active','COP',35000)");
$db->exec("INSERT INTO ticket_inventory_events(event_id,capacity) VALUES(7,20)");
$db->exec("INSERT INTO ticket_inventory_types(ticket_type_id,event_id,capacity,courtesy_capacity) VALUES(11,7,20,0)");
$stage=$db->prepare("INSERT INTO ticket_type_stages(ticket_type_id,event_id,code,price_cop,cumulative_capacity,
    opens_at_local,closes_at_local) VALUES(11,7,'preventa',27000,20,?,?)");
$stage->execute([$when->modify('-1 day')->format('Y-m-d H:i:s'),$when->modify('+1 day')->format('Y-m-d H:i:s')]);
$other=new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",$user,$pass,$opts);
$edit=static function(string $sql)use($other):void{
    $other->beginTransaction();
    try {$other->query('SELECT id FROM events WHERE id=7 FOR UPDATE')->fetchColumn();$other->exec($sql);$other->commit();}
    catch(Throwable $e){$other->rollBack();throw $e;}
};
$doorShift=brvtalPosRun($db,2,'admin',['action'=>'open','event_id'=>'7','opening_cash_cop'=>'0']);
$doorRequest=array_replace($prepare,['shift_id'=>(string)$doorShift['shift_id'],'ticket_type_id'=>'11',
    'idempotency_key'=>str_repeat('d',32)]);
$door=brvtalPosRun($db,2,'admin',$doorRequest);
posAssert($door['amount_cop']===35000 && $door['source']==='door' && $door['stage_code']==='preventa','explicit door price');
$doorConfirm=['action'=>'confirm','sale_id'=>(string)$door['sale_id'],'confirmation_token'=>$door['confirmation_token'],'confirm'=>true];
$before=$snapshotRead();
$edit('UPDATE event_ticket_types SET door_price_cop=36000 WHERE id=11');
posDenies(static fn()=>brvtalPosRun($db,2,'admin',$doorConfirm),'POS_QUOTE_CHANGED');
posAssert($snapshotRead()===$before,'door conflict makes no sale or entry');
brvtalPosRun($db,2,'admin',['action'=>'cancel','sale_id'=>$doorConfirm['sale_id'],'confirmation_token'=>$doorConfirm['confirmation_token']]);
$edit('UPDATE event_ticket_types SET door_price_cop=NULL WHERE id=11');
$door=brvtalPosRun($db,2,'admin',array_replace($doorRequest,['idempotency_key'=>str_repeat('e',32)]));
posAssert($door['amount_cop']===27000 && $door['source']==='stage','door falls back to active stage');
$doorConfirm=array_replace($doorConfirm,['sale_id'=>(string)$door['sale_id'],'confirmation_token'=>$door['confirmation_token']]);
$paidDoor=brvtalPosRun($db,2,'admin',$doorConfirm);
posAssert($paidDoor['amount_cop']===27000,'door shown equals receipt');
$key=random_bytes(32);
$order=array_replace($manual,['event_id'=>'7','ticket_type_id'=>'11','quantity'=>'2','idempotency_key'=>str_repeat('f',32)]);
unset($order['quote_token']);
$quoteInput=['action'=>'manual_quote','event_id'=>'7','ticket_type_id'=>'11','quantity'=>'2','idempotency_key'=>$order['idempotency_key']];
posDenies(static fn()=>brvtalPosRun($db,1,'admin',$quoteInput,$key),'FORBIDDEN');
$before=$snapshotRead();
$q=brvtalPosRun($db,1,'superadmin',$quoteInput,$key);
posAssert($q['amount_cop']===54000 && $q['source']==='stage' && $snapshotRead()===$before,'manual quote read only');
$order['quote_token']=$q['quote_token'];
posDenies(static fn()=>brvtalPosRun($db,2,'superadmin',$order,$key),'POS_QUOTE_CHANGED');
$edit("UPDATE ticket_type_stages SET code='general',price_cop=29000 WHERE ticket_type_id=11");
posDenies(static fn()=>brvtalPosRun($db,1,'superadmin',$order,$key),'POS_QUOTE_CHANGED');
posAssert($snapshotRead()===$before,'stage transition creates no paid order');
$q=brvtalPosRun($db,1,'superadmin',$quoteInput,$key);
$order['quote_token']=$q['quote_token'];
$edit('UPDATE ticket_inventory_events SET capacity=19 WHERE event_id=7');
posDenies(static fn()=>brvtalPosRun($db,1,'superadmin',$order,$key),'POS_QUOTE_CHANGED');
posAssert($snapshotRead()===$before,'capacity conflict rolls back');
$q=brvtalPosRun($db,1,'superadmin',$quoteInput,$key);
$order['quote_token']=$q['quote_token'];
$paidManual=brvtalPosRun($db,1,'superadmin',$order,$key);
posAssert($paidManual['amount_cop']===58000 && $paidManual['amount_cop']===$q['amount_cop'],'manual total equals quote');
$edit('UPDATE ticket_type_stages SET price_cop=31000 WHERE ticket_type_id=11');
$replayed=brvtalPosRun($db,1,'superadmin',$order);
posAssert($replayed['replayed'] && $replayed['order_id']===$paidManual['order_id'] &&
    $replayed['amount_cop']===58000,'manual replay before re-quote');
$q=$db->prepare("SELECT s.unit_price_cop,s.quote_source,s.quote_stage_code,s.quote_channel,
    s.amount_cop,o.total_cop,i.subtotal_cop FROM ticket_pos_sales s
    JOIN ticket_orders o ON o.id=s.order_id JOIN ticket_order_items i ON i.order_id=o.id
    WHERE s.id=?");
foreach ([[$paidDoor,27000,'preventa','door'],[$paidManual,29000,'general','manual']] as [$receipt,$unit,$stageCode,$channel]) {
    $q->execute([$receipt['sale_id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
    posAssert((int)$row['unit_price_cop']===$unit && $row['quote_source']==='stage'
        && $row['quote_stage_code']===$stageCode && $row['quote_channel']===$channel
        && (int)$row['amount_cop']===$receipt['amount_cop'] && (int)$row['total_cop']===$receipt['amount_cop']
        && (int)$row['subtotal_cop']===$receipt['amount_cop'],'immutable quote snapshot agrees across sale, order and line');
}
posIntentHttp(['host'=>$host,'port'=>$port,'name'=>$name,'user'=>$user,'pass'=>$pass],
    array_replace($quoteInput,['quantity'=>'1','idempotency_key'=>str_repeat('h',32)]),
    ['channel'=>'manual','unit_price_cop'=>31000,'amount_cop'=>31000,'source'=>'stage','stage_code'=>'general']);
// #2177: exact received amount, evidence, authorization and replay.
$offline=array_replace($order,['idempotency_key'=>str_repeat('r',32)])+$received;
$offlineQuote=array_replace($quoteInput,['idempotency_key'=>$offline['idempotency_key']]);
$offline['quote_token']=brvtalPosRun($db,1,'superadmin',$offlineQuote,$key)['quote_token'];
$before=$snapshotRead();
posDenies(static fn()=>brvtalPosRun($db,2,'admin',$offline,$key),'FORBIDDEN');
$missing=$offline;unset($missing['received_reason']);
posDenies(static fn()=>brvtalPosRun($db,1,'superadmin',$missing,$key),'POS_INVALID_FIELDS');
posAssert($snapshotRead()===$before,'received negatives do not create orders');
$paidOffline=brvtalPosRun($db,1,'superadmin',$offline,$key);
posAssert($paidOffline['amount_cop']===42000 && !$paidOffline['replayed'],'actual received amount persisted');
$q=$db->prepare("SELECT s.unit_price_cop,s.amount_cop,s.request_hash,o.total_cop,i.subtotal_cop
    FROM ticket_pos_sales s JOIN ticket_orders o ON o.id=s.order_id
    JOIN ticket_order_items i ON i.order_id=o.id WHERE s.id=?");
$q->execute([$paidOffline['sale_id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
posAssert((int)$row['unit_price_cop']===21000 && (int)$row['amount_cop']===42000
    && (int)$row['total_cop']===42000 && (int)$row['subtotal_cop']===42000,'received totals reconcile');
$evidence=$db->prepare("SELECT actor_admin_id,note_text FROM ticket_order_notes
    WHERE order_id=? AND note_text LIKE 'manual-received|%'");
$evidence->execute([$paidOffline['order_id']]);$note=$evidence->fetch(PDO::FETCH_ASSOC);
posAssert((int)$note['actor_admin_id']===1
    && $note['note_text']==='manual-received|'.$row['request_hash'].'|62000|42000|REC-2026-001|Pago offline previo',
    'owner, original quote, amount, reference and reason audited');
$after=$snapshotRead();
$replay=brvtalPosRun($db,1,'superadmin',$offline);
posAssert($replay['replayed'] && $replay['order_id']===$paidOffline['order_id']
    && $replay['amount_cop']===42000 && $snapshotRead()===$after,'received retry is idempotent');
foreach (['received_amount_cop'=>'44000','received_reason'=>'Otro motivo','received_reference'=>'REC-OTHER'] as $field=>$value) {
    posDenies(static fn()=>brvtalPosRun($db,1,'superadmin',array_replace($offline,[$field=>$value])),
        'POS_IDEMPOTENCY_CONFLICT');
}
posAssert($snapshotRead()===$after,'changed received evidence never duplicates sale');
$intent=['action'=>'manual_intent','idempotency_key'=>$offline['idempotency_key']];
$found=brvtalPosRun($db,1,'superadmin',$intent);
posAssert($found['state']==='found' && $found['amount_cop']===42000
    && brvtal_ticket_pos_verified($db,(int)$paidOffline['order_id']),'received receipt and issuance verified');
$beforeRepeat=$quoteRows();
$db->exec($quoteSql);
posAssert($quoteRows()===$beforeRepeat && $quoteProof()['complete'],'second DDL preserves all rows and proof');
echo "T24 POS channel quote, conflict, snapshot and replay integration passed.\n";

// Negative proof against an empty, isolated schema; never modify the historical fixture rows.
$negativeName=$name.'_quote';
$server->exec("CREATE DATABASE `$negativeName` CHARACTER SET utf8mb4");
try {
    $server->exec("USE `$negativeName`");
    $server->exec("CREATE TABLE ticket_pos_sales (
        quote_source ENUM('door','stage','base') NOT NULL,
        quote_stage_code VARCHAR(32) NULL,
        quote_channel ENUM('door','manual') NULL) ENGINE=InnoDB");
    $negative=brvtalMigrationSchemaProof($server,$quoteMigration);
    posAssert(!$negative['complete'] && !$negative['checks']['column-nullable:ticket_pos_sales.quote_source']
        && !$negative['checks']["column-type:ticket_pos_sales.quote_stage_code=enum('early_bird','preventa','general','puerta')"],
        'quote proof rejects wrong nullability and column type');
    posAssert(brvtalMigrationProofDisposition($quoteMigration,$negative)==='blocked','schema drift is fail-closed');
} finally {
    $server->exec("DROP DATABASE `$negativeName`");
}
echo "T24 POS quote snapshot DDL twice, historical NULLs, proof and negative drift OK.\n";
