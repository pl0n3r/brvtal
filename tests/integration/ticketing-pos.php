<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/ticketing_pos.php';
require_once __DIR__.'/../../config/ticketing_checkin.php';
function posAssert(bool $v,string $why): void {
    if (!$v) throw new RuntimeException('T24 POS: '.$why);
}
function posDenies(callable $run,string $code): void {
    try { $run(); throw new RuntimeException('POS accepted invalid input: '.$code); }
    catch (DomainException | InvalidArgumentException $e) {
        posAssert($e->getMessage()===$code, 'expected '.$code.', got '.$e->getMessage());
    }
}
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
            if($actor===1)posAssert($status===200&&$body===['ok'=>true,'data'=>$expected],'real HTTP receipt');
            elseif($actor===2)posAssert($status===200&&$body===['ok'=>true,'data'=>['state'=>'not_found']],'HTTP actor isolation');
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
$open=['action'=>'open','event_id'=>'6','opening_cash_cop'=>'50000'];
posDenies(static fn()=>brvtalPosRun($db,1,'door',$open),'FORBIDDEN');
$shift=brvtalPosRun($db,1,'admin',$open);
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
posDenies(static fn()=>brvtalPosRun($db,1,'admin',$confirm),'POS_SALE_UNAVAILABLE');
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
    $created=brvtalPosRun($db,1,'superadmin',$manual);
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
        array_replace($manual,['idempotency_key'=>str_repeat('n',32),'quantity'=>'1'])),
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
try {posDenies(static fn()=>brvtalPosRun($db,1,'admin',$open),'POS_SYNTHETIC_ONLY');}
finally {putenv('BRVTAL_INTEGRATION_TESTS=1');}
echo "T24 disposable POS cash, inventory, QR/check-in, audit, cancellation, RBAC OK.\n";
