<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/ticketing_admin_actions.php';
function actionAssert(bool $yes,string $msg):void { if (!$yes) throw new RuntimeException($msg); }
$note=['order_id'=>'91','action'=>'note','idempotency_key'=>str_repeat('a',32),'note'=>'Verificación interna completada'];
foreach ([array_replace($note,['order_id'=>'0']),array_replace($note,['action'=>'pay']),
          array_replace($note,['idempotency_key'=>'x']),array_replace($note,['note'=>'user@example.test']),
          array_replace($note,['note'=>'https://example.test']),array_replace($note,['note'=>str_repeat('x',281)])] as $bad) {
    try { brvtalTicketAdminActionInput($bad); throw new RuntimeException('accepted invalid'); }
    catch (InvalidArgumentException) {}
}
actionAssert(!brvtalTicketAdminCanAction('door','note'),'door note');
actionAssert(!brvtalTicketAdminCanAction('admin','resend'),'admin resend');
actionAssert(brvtalTicketAdminCanAction('superadmin','resend'),'superadmin resend');
actionAssert(brvtalTicketAdminCanAction('admin','cancel_pending'),'admin finance');
actionAssert(str_contains((string)file_get_contents(__DIR__.'/../../api/ticketing/admin-actions.php'),
    'brvtal_admin_require_csrf()'),'CSRF missing');
if(getenv('BRVTAL_INTEGRATION_TESTS')!=='1'){
    echo "1979 input, RBAC, CSRF checks OK; fixture requires isolated MariaDB.\n";exit;
}
$prefix=(string)getenv('BRVTAL_TEST_DB_NAME');
actionAssert((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/D',$prefix),'unsafe DB prefix');
$host=(string)(getenv('BRVTAL_TEST_DB_HOST')?:'127.0.0.1');$port=(int)(getenv('BRVTAL_TEST_DB_PORT')?:3306);
$user=(string)(getenv('BRVTAL_TEST_DB_USER')?:'root');$pass=(string)(getenv('BRVTAL_TEST_DB_PASS')?:'');
$dbName=$prefix.'_1979_'.bin2hex(random_bytes(3));
$opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$server=new PDO("mysql:host={$host};port={$port};charset=utf8mb4",$user,$pass,$opts);
$server->exec("CREATE DATABASE {$dbName}");
try {
    $db=new PDO("mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4",$user,$pass,$opts);
    foreach([
      'CREATE TABLE ticket_orders (id BIGINT UNSIGNED PRIMARY KEY,event_id INT UNSIGNED,buyer_id BIGINT UNSIGNED,status VARCHAR(20),updated_at_utc DATETIME(6)) ENGINE=InnoDB',
      'CREATE TABLE ticket_payment_attempts (id BIGINT PRIMARY KEY AUTO_INCREMENT,order_id BIGINT UNSIGNED,status VARCHAR(20)) ENGINE=InnoDB',
      'CREATE TABLE ticket_issue_outbox (order_id BIGINT UNSIGNED PRIMARY KEY,state VARCHAR(20)) ENGINE=InnoDB',
      'CREATE TABLE ticket_inventory_holds (order_id BIGINT UNSIGNED,status VARCHAR(20)) ENGINE=InnoDB',
      'CREATE TABLE ticket_order_items (id BIGINT PRIMARY KEY AUTO_INCREMENT,order_id BIGINT UNSIGNED,quantity INT) ENGINE=InnoDB',
      'CREATE TABLE ticket_entries (id BIGINT PRIMARY KEY AUTO_INCREMENT,order_item_id BIGINT,status VARCHAR(16)) ENGINE=InnoDB',
      'CREATE TABLE ticket_mail_outbox (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,message_key VARCHAR(128) UNIQUE,payload_hash CHAR(64),related_ref VARCHAR(128),recipient_email VARCHAR(254),subject VARCHAR(255),body_text TEXT,body_html TEXT,template_key VARCHAR(64),template_version INT,provider_message_id VARCHAR(128),state VARCHAR(16) DEFAULT "queued",next_attempt_at DATETIME DEFAULT CURRENT_TIMESTAMP,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB',
      'CREATE TABLE ticket_mail_history (id BIGINT AUTO_INCREMENT PRIMARY KEY,outbox_id BIGINT UNSIGNED UNIQUE,message_key VARCHAR(128) UNIQUE,template_key VARCHAR(64),recipient_hint VARCHAR(128),recipient_digest CHAR(64),event_id BIGINT UNSIGNED,buyer_id BIGINT UNSIGNED,order_id BIGINT UNSIGNED,ticket_id BIGINT UNSIGNED,actor_kind VARCHAR(16),resend_email_id VARCHAR(128),delivery_state VARCHAR(16),retention_until DATETIME,state_at DATETIME,created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB'
    ] as $ddl) $db->exec($ddl);
    foreach(preg_split('/;\s*\n/',(string)file_get_contents(__DIR__.'/../../database/migration_ticketing_order_notes_01.sql')) as $part) {
        if(trim($part)!=='') $db->exec($part);
    }
    $db->exec("INSERT INTO ticket_orders VALUES (91,6,3,'pending',UTC_TIMESTAMP(6)),(92,6,3,'paid',UTC_TIMESTAMP(6)),(93,6,3,'pending',UTC_TIMESTAMP(6)),(94,6,3,'paid',UTC_TIMESTAMP(6))");
    $db->exec("INSERT INTO ticket_inventory_holds VALUES (91,'active'),(93,'active')");
    $r=brvtalTicketAdminRecordAction($db,7,'admin',brvtalTicketAdminActionInput($note));
    actionAssert($r['status']==='recorded' && !$r['email_queued'],'real note');
    actionAssert(brvtalTicketAdminRecordAction($db,7,'admin',brvtalTicketAdminActionInput($note))['replayed'],'note replay');
    try { brvtalTicketAdminRecordAction($db,7,'admin',brvtalTicketAdminActionInput(array_replace($note,['note'=>'Otra nota'])));
      throw new RuntimeException('collision'); } catch (DomainException $e){actionAssert($e->getMessage()==='IDEMPOTENCY_CONFLICT','collision');}
    actionAssert((int)$db->query('SELECT COUNT(*) FROM ticket_order_notes')->fetchColumn()===1,'note history');
    $cancel=['order_id'=>'91','action'=>'cancel_pending','idempotency_key'=>str_repeat('b',32)];
    actionAssert(brvtalTicketAdminRecordAction($db,7,'admin',brvtalTicketAdminActionInput($cancel))['status']==='cancelled','cancel');
    actionAssert($db->query('SELECT status FROM ticket_inventory_holds WHERE order_id=91')->fetchColumn()==='released','stock released');
    actionAssert(brvtalTicketAdminRecordAction($db,7,'admin',brvtalTicketAdminActionInput($cancel))['replayed'],'cancel idempotent');
    foreach([['id'=>'92','act'=>'cancel_pending','error'=>'ORDER_NOT_PENDING'],['id'=>'94','act'=>'resend','error'=>'TICKETS_NOT_ISSUED']] as $case){
      try{brvtalTicketAdminRecordAction($db,7,'superadmin',brvtalTicketAdminActionInput(['order_id'=>$case['id'],'action'=>$case['act'],'idempotency_key'=>str_repeat('c',32)]));
        throw new RuntimeException('unsafe');}catch(DomainException $e){actionAssert($e->getMessage()===$case['error'],'fails closed');}
    }
    $db->exec("INSERT INTO ticket_payment_attempts(order_id,status) VALUES (93,'pending')");
    try{brvtalTicketAdminRecordAction($db,7,'admin',brvtalTicketAdminActionInput(['order_id'=>'93','action'=>'cancel_pending','idempotency_key'=>str_repeat('d',32)]));
      throw new RuntimeException('payment raced');}catch(DomainException $e){actionAssert($e->getMessage()==='PAYMENT_RECONCILIATION_REQUIRED','active payment retained');}
    $db->exec("INSERT INTO ticket_issue_outbox VALUES (92,'done')");
    $db->exec("INSERT INTO ticket_order_items(order_id,quantity) VALUES (92,1)");
    $id=(int)$db->lastInsertId();
    $db->exec("INSERT INTO ticket_entries(order_item_id,status) VALUES ({$id},'issued')");
    $GLOBALS['config']['security']['encryption_key']=str_repeat('t',48);
    putenv('BRVTAL_TICKET_MAIL_TESTING=1');putenv('BRVTAL_TICKET_MAIL_MODE=mock');
    $sealed=brvtalBuyerMailSeal('QR ya emitido, únicamente cifrado');
    $sealedHtml=brvtalBuyerMailSeal('<!doctype html><html><body>Boleta ya emitida</body></html>');
    $insert=$db->prepare('INSERT INTO ticket_mail_outbox(message_key,payload_hash,related_ref,recipient_email,subject,body_text,body_html,template_key,template_version) VALUES (?,?,?,?,?,?,?,?,?)');
    $insert->execute(['order:92:payment_approved',hash('sha256',$sealed),'order:92','buyer@example.test','Boletas',$sealed,$sealedHtml,'payment_approved',1]);
    $resend=['order_id'=>'92','action'=>'resend','idempotency_key'=>str_repeat('e',32)];
    $result=brvtalTicketAdminRecordAction($db,7,'superadmin',brvtalTicketAdminActionInput($resend));
    actionAssert($result['email_queued'] && $result['status']==='queued','resend queued');
    actionAssert(brvtalTicketAdminRecordAction($db,7,'superadmin',brvtalTicketAdminActionInput($resend))['replayed'],'resend idempotent');
    $copies=$db->query("SELECT body_text FROM ticket_mail_outbox WHERE related_ref='order:92' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    actionAssert(count($copies)===2 && $copies[0]===$copies[1] && str_starts_with($copies[1],BRVTAL_BUYER_MAIL_SEAL_PREFIX),'same sealed QR envelope');
    actionAssert((int)$db->query('SELECT COUNT(*) FROM ticket_order_action_requests')->fetchColumn()===3,'three committed actions');
} finally { $server->exec("DROP DATABASE IF EXISTS {$dbName}"); }
echo "1979 MariaDB: note, cancellation, safe mail resend, idempotence OK.\n";
