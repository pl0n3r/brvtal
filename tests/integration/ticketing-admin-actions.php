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
      'CREATE TABLE events (id INT UNSIGNED PRIMARY KEY,title VARCHAR(160) NOT NULL,event_date DATETIME NOT NULL,status VARCHAR(30) NOT NULL DEFAULT "published",published_at DATETIME NULL) ENGINE=InnoDB',
      'CREATE TABLE event_ticket_types (id INT UNSIGNED PRIMARY KEY,event_id INT UNSIGNED NOT NULL) ENGINE=InnoDB',
      'CREATE TABLE admins (id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB',
      'CREATE TABLE ticket_refund_event_decisions (event_id INT UNSIGNED PRIMARY KEY,cause VARCHAR(16) NOT NULL) ENGINE=InnoDB',
      'CREATE TABLE ticket_inventory_holds (order_id BIGINT UNSIGNED,status VARCHAR(20)) ENGINE=InnoDB',
      'CREATE TABLE ticket_mail_outbox (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,message_key VARCHAR(128) UNIQUE,payload_hash CHAR(64),related_ref VARCHAR(128),recipient_email VARCHAR(254),subject VARCHAR(255),body_text TEXT,body_html TEXT,template_key VARCHAR(64),template_version INT,provider_message_id VARCHAR(128),state VARCHAR(16) DEFAULT "queued",next_attempt_at DATETIME DEFAULT CURRENT_TIMESTAMP,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB',
      'CREATE TABLE ticket_mail_history (id BIGINT AUTO_INCREMENT PRIMARY KEY,outbox_id BIGINT UNSIGNED UNIQUE,message_key VARCHAR(128) UNIQUE,template_key VARCHAR(64),recipient_hint VARCHAR(128),recipient_digest CHAR(64),event_id BIGINT UNSIGNED,buyer_id BIGINT UNSIGNED,order_id BIGINT UNSIGNED,ticket_id BIGINT UNSIGNED,actor_kind VARCHAR(16),resend_email_id VARCHAR(128),delivery_state VARCHAR(16),retention_until DATETIME,state_at DATETIME,created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB'
    ] as $ddl) $db->exec($ddl);
    foreach(['ticketing_orders','ticketing_webhook','ticketing_entries','ticket_entry_secret','ticketing_order_notes'] as $migration) {
        foreach(preg_split('/;\s*\n/',(string)file_get_contents(__DIR__.'/../../database/migration_'.$migration.'_01.sql')) as $part) {
            if(trim($part)!=='') $db->exec($part);
        }
    }
    $eventClock=new DateTimeImmutable('now',new DateTimeZone('America/Bogota'));
    $eventStart=$eventClock->modify('-1 hour')->format('Y-m-d H:i:s');
    $eventInsert=$db->prepare("INSERT INTO events(id,title,event_date) VALUES (?, ?, ?)");
    $eventInsert->execute([6,'Synthetic',$eventStart]);
    $eventInsert->execute([7,'Other',$eventStart]);
    $db->exec('INSERT INTO admins(id) VALUES (7)');
    $db->exec("INSERT INTO event_ticket_types VALUES (11,6),(12,7)");
    $db->exec("INSERT INTO ticket_buyers(id,public_id,email) VALUES (3,UNHEX(LPAD('3',32,'0')),'buyer@example.test')");
    foreach ([91=>'pending',92=>'paid',93=>'pending',94=>'paid'] as $orderId=>$status) {
        $db->prepare("INSERT INTO ticket_orders(id,public_id,event_id,buyer_id,status,checkout_key_hash,total_cop)
            VALUES (?,UNHEX(LPAD(?,32,'0')),6,3,?,SHA2(?,256),10000)")
            ->execute([$orderId,(string)$orderId,$status,'checkout-'.$orderId]);
    }
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
    $db->exec("INSERT INTO ticket_payment_attempts(order_id,provider,provider_reference,idempotency_key_hash,status,gross_amount_cop) VALUES (93,'wompi','pending-93',SHA2('attempt-93',256),'pending',10000)");
    try{brvtalTicketAdminRecordAction($db,7,'admin',brvtalTicketAdminActionInput(['order_id'=>'93','action'=>'cancel_pending','idempotency_key'=>str_repeat('d',32)]));
      throw new RuntimeException('payment raced');}catch(DomainException $e){actionAssert($e->getMessage()==='PAYMENT_RECONCILIATION_REQUIRED','active payment retained');}
    $db->exec("INSERT INTO ticket_payment_attempts(order_id,provider,provider_reference,provider_transaction_id,idempotency_key_hash,status,gross_amount_cop)
        VALUES (92,'wompi','approved-92','synthetic-tx-92',SHA2('attempt-92',256),'approved',10000)");
    $attempt=(int)$db->lastInsertId();
    $db->exec("INSERT INTO ticket_payment_webhook_events(attempt_id,provider_transaction_id,event_checksum,signed_status,event_timestamp)
        VALUES ({$attempt},'synthetic-tx-92',SHA2('synthetic-event',256),'APPROVED',1)");
    $db->exec("INSERT INTO ticket_issue_outbox(order_id,payment_attempt_id,state) VALUES (92,{$attempt},'done')");
    $db->exec("INSERT INTO ticket_order_items(order_id,event_id,ticket_type_id,ticket_name_snapshot,quantity,unit_price_cop,subtotal_cop)
        VALUES (92,6,11,'Synthetic',1,10000,10000)");
    $id=(int)$db->lastInsertId();
    $db->exec("INSERT INTO ticket_entries(public_id,order_item_id,event_id,ticket_type_id,unit_number,status,issued_at_utc)
        VALUES (UNHEX(LPAD('1',32,'0')),{$id},6,11,1,'issued',UTC_TIMESTAMP(6))");
    $GLOBALS['config']['security']['encryption_key']=str_repeat('t',48);
    putenv('BRVTAL_TICKET_MAIL_TESTING=1');putenv('BRVTAL_TICKET_MAIL_MODE=mock');
    $sealed=brvtalBuyerMailSeal('QR anterior BRVTAL:T1:obsolete y activación token=obsolete');
    $sealedHtml=brvtalBuyerMailSeal('<!doctype html><html><body>BRVTAL:T1:obsolete token=obsolete</body></html>');
    $insert=$db->prepare('INSERT INTO ticket_mail_outbox(message_key,payload_hash,related_ref,recipient_email,subject,body_text,body_html,template_key,template_version) VALUES (?,?,?,?,?,?,?,?,?)');
    $insert->execute(['order:92:payment_approved',hash('sha256',$sealed),'order:92','previous@example.test','Boletas',$sealed,$sealedHtml,'payment_approved',1]);
    $resend=['order_id'=>'92','action'=>'resend','idempotency_key'=>str_repeat('e',32)];
    // The historical sealed envelope is not authority to send to a guest.
    try {
        brvtalTicketAdminRecordAction($db,7,'superadmin',brvtalTicketAdminActionInput($resend));
        throw new RuntimeException('guest recovery accepted');
    } catch (DomainException $error) {
        actionAssert($error->getMessage()==='MAIL_PAYLOAD_NOT_RECOVERABLE','guest fails closed');
    }
    actionAssert((int)$db->query('SELECT COUNT(*) FROM ticket_order_action_requests')->fetchColumn()===2,'guest leaves no receipt');
    $db->exec("UPDATE ticket_buyers SET status='active',email_verified_at=UTC_TIMESTAMP(6),
        password_hash='synthetic-password-hash' WHERE id=3");

    // #2119: real projection fixture, reused by #2101.
    require_once __DIR__.'/../../config/ticketing_issue.php';
    $db->exec("UPDATE ticket_order_items SET quantity=9 WHERE id={$id}");
    $entryInsert=$db->prepare("INSERT INTO ticket_entries(public_id,order_item_id,event_id,ticket_type_id,unit_number,status,issued_at_utc)
        VALUES (?, ?,6,11,?,?,UTC_TIMESTAMP(6))");
    $credentialInsert=$db->prepare("INSERT INTO ticket_entry_credentials(entry_id,version,token_hash,token_encrypted,created_at_utc)
        VALUES (?,?,?,?,UTC_TIMESTAMP(6))");
    $first=(int)$db->query("SELECT id FROM ticket_entries WHERE order_item_id={$id} AND unit_number=1")->fetchColumn();
    $states=['issued','issued','used','revoked','refunded','issued','issued','issued','issued'];
    foreach ($states as $index=>$status) {
        $unit=$index+1;
        $publicId=$unit===1?hex2bin(str_pad('1',32,'0',STR_PAD_LEFT)):random_bytes(16);
        if ($unit===1) {
            $entryId=$first;
        } else {
            $entryInsert->execute([$publicId,$id,$unit,$status]);
            $entryId=(int)$db->lastInsertId();
        }
        if ($unit===8) continue;
        $secret=random_bytes(32);
        $cipher=brvtal_ticket_issue_seal_token($secret,$publicId);
        if ($unit===6) $cipher=null;
        if ($unit===7) $cipher=brvtal_ticket_issue_seal_token($secret,random_bytes(16));
        $hash=hash('sha256',$unit===9?random_bytes(32):$secret,true);
        if ($unit===2) {
            $oldCredential=$db->prepare("INSERT INTO ticket_entry_credentials
                (entry_id,version,token_hash,status,created_at_utc,revoked_at_utc)
                VALUES (?,1,?,'revoked',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))");
            $oldCredential->execute([$entryId,hash('sha256',random_bytes(32),true)]);
        }
        $credentialInsert->execute([$entryId,$unit===2?2:1,$hash,$cipher]);
    }
    $snapshot=static function() use ($db): string {
        $tables=['ticket_orders','ticket_order_items','ticket_entries','ticket_entry_credentials',
            'ticket_inventory_holds','ticket_mail_outbox','ticket_order_action_requests',
            'ticket_payment_attempts','ticket_issue_outbox','ticket_mail_history'];
        $digest=hash_init('sha256');
        foreach ($tables as $table) {
            hash_update($digest,serialize($db->query('SELECT * FROM '.$table)->fetchAll()));
        }
        return hash_final($digest);
    };
    $before=$snapshot();
    $project=static function(int $order=92,int $event=6,string $email='buyer@example.test',
        string $role='superadmin') use ($db,$snapshot): array {
        $db->beginTransaction();
        $initial=$snapshot();
        try {
            return brvtalTicketAdminRecoveryProjection($db,7,$role,$order,$event,$email);
        } finally {
            try {
                actionAssert($db->inTransaction() && $initial===$snapshot(),'projection must not write or commit');
            } finally { if ($db->inTransaction()) $db->rollBack(); }
        }
    };
    $projection=$project();
    actionAssert($projection['buyer_id']===3,'current buyer');
    actionAssert(array_column($projection['entries'],'state')===
        ['recoverable','recoverable','used','revoked','refunded','historical','invalid_ciphertext','inconsistent','invalid_ciphertext'],'projection states');
    actionAssert(array_column($projection['entries'],'version')===[1,2,null,null,null,1,1,null,1],'credential versions');
    foreach ($projection['entries'] as $entry) {
        actionAssert(array_keys($entry)===['entry_id','state','version'],'metadata only');
    }
    $reject=static function(callable $fn,string $error):void {
        try { $fn(); throw new RuntimeException('unexpected projection'); }
        catch (DomainException $e) { actionAssert($e->getMessage()===$error,'projection rejection'); }
    };
    $reject(fn()=>$project(92,6,'other@example.test'),'RECOVERY_ORDER_UNAVAILABLE');
    $reject(fn()=>$project(92,7),'RECOVERY_ORDER_UNAVAILABLE');
    $reject(fn()=>$project(93),'RECOVERY_ORDER_UNAVAILABLE');
    $reject(fn()=>$project(94),'RECOVERY_PAYMENT_UNVERIFIED');
    $reject(fn()=>$project(92,6,'buyer@example.test','door'),'FORBIDDEN');
    try {
        brvtalTicketAdminRecoveryProjection($db,7,'superadmin',92,6,'buyer@example.test');
        throw new RuntimeException('missing transaction accepted');
    } catch (LogicException $e) { actionAssert($e->getMessage()==='RECOVERY_TRANSACTION_REQUIRED','transaction required'); }
    actionAssert($before===$snapshot(),'projection never mutates orders, entries, credentials, stock or mail');
    $db->exec("UPDATE ticket_payment_webhook_events SET signed_status='PENDING' WHERE attempt_id={$attempt}");
    $reject(fn()=>$project(),'RECOVERY_PAYMENT_UNVERIFIED');
    $db->exec("UPDATE ticket_payment_webhook_events SET signed_status='APPROVED' WHERE attempt_id={$attempt}");
    $db->exec("UPDATE ticket_entries SET event_id=7 WHERE id={$first}");
    $reject(fn()=>$project(),'RECOVERY_INCONSISTENT');
    $db->exec("UPDATE ticket_entries SET event_id=6 WHERE id={$first}");
    $db->exec("UPDATE ticket_buyers SET status='blocked' WHERE id=3");
    $reject(fn()=>$project(),'RECOVERY_ORDER_UNAVAILABLE');
    echo "2119 MariaDB recovery projection: all synthetic cases OK.\n";
    $db->exec("UPDATE ticket_buyers SET status='active' WHERE id=3");
    $invoke=static fn(array $request)=>brvtalTicketAdminRecordAction(
        $db,7,'superadmin',brvtalTicketAdminActionInput($request));
    $before=$snapshot();
    putenv('BRVTAL_TICKET_MAIL_MODE=disabled');
    try {
        $reject(fn()=>$invoke($resend),'MAIL_NOT_ENABLED');
        actionAssert($snapshot()===$before,'disabled enqueue leaves no action or mail');
    } finally { putenv('BRVTAL_TICKET_MAIL_MODE=mock'); }
    $db->exec("UPDATE ticket_entries SET status='revoked' WHERE order_item_id={$id} AND unit_number IN (1,2)");
    $before=$snapshot();
    $reject(fn()=>$invoke($resend),'MAIL_PAYLOAD_NOT_RECOVERABLE');
    actionAssert($snapshot()===$before,'no current recoverable credential leaves no side effects');
    $db->exec("UPDATE ticket_entries SET status='issued' WHERE order_item_id={$id} AND unit_number IN (1,2)");
    $db->exec("UPDATE events SET event_date='2000-01-01 00:00:00' WHERE id=6");
    $before=$snapshot();
    $reject(fn()=>$invoke($resend),'MAIL_PAYLOAD_NOT_RECOVERABLE');
    actionAssert($before===$snapshot(),'expired account access leaves no side effects');
    $db->prepare('UPDATE events SET event_date=? WHERE id=6')->execute([$eventStart]);
    $db->exec("CREATE TRIGGER fixture_enqueue_failure BEFORE INSERT ON ticket_mail_outbox
        FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='SYNTHETIC_ENQUEUE_FAILURE'");
    try {
        $before=$snapshot();
        try {
            $invoke($resend);
            throw new RuntimeException('failed enqueue committed');
        } catch (PDOException $error) {
            actionAssert($error->getCode()==='45000','controlled enqueue failure');
        }
        actionAssert($before===$snapshot(),'failed enqueue rolls back action and outbox');
    } finally { $db->exec('DROP TRIGGER fixture_enqueue_failure'); }
    $durable=static function() use ($db): string {
        $tables=['ticket_orders','ticket_order_items','ticket_entries','ticket_entry_credentials',
            'ticket_inventory_holds','ticket_payment_attempts','ticket_issue_outbox'];
        $values=[];
        foreach ($tables as $table) $values[]=$db->query('SELECT * FROM '.$table)->fetchAll();
        return hash('sha256',serialize($values));
    };
    $before=$durable();
    $result=$invoke($resend);
    actionAssert($result['email_queued'] && $result['status']==='queued','account resend queued');
    $after=$snapshot();
    $replay=$invoke($resend);
    actionAssert($replay['replayed'] && $replay['receipt_id']===$result['receipt_id']
        && $snapshot()===$after,'resend replay preserves original receipt');
    actionAssert($durable()===$before,'resend does not rotate, emit, pay or mutate inventory');
    $copies=$db->query("SELECT * FROM ticket_mail_outbox WHERE related_ref='order:92' ORDER BY id")->fetchAll();
    actionAssert(count($copies)===2 && $copies[0]['body_text']===$sealed
        && $copies[0]['body_html']===$sealedHtml,'historical mail immutable');
    actionAssert($copies[1]['recipient_email']==='buyer@example.test'
        && $copies[1]['template_key']==='payment_approved'
        && str_starts_with($copies[1]['body_text'],BRVTAL_BUYER_MAIL_SEAL_PREFIX)
        && str_starts_with($copies[1]['body_html'],BRVTAL_BUYER_MAIL_SEAL_PREFIX),'current recipient and sealed versioned render');
    foreach (['body_text','body_html'] as $field) {
        $plain=brvtalBuyerMailUnseal($copies[1][$field]);
        actionAssert(is_string($plain) && str_contains($plain,'https://www.brvtal.com.co/buyer/')
            && !str_contains($plain,'BRVTAL:T1:') && !str_contains($plain,'token=')
            && !str_contains($plain,'obsolete'),'account-only link, no old bearer');
    }
    // Use the real reissue/account/check-in services after enqueue, not a
    // second handcrafted QR or a provider call. The queued link remains safe.
    require_once __DIR__.'/../../config/buyer_account.php';
    require_once __DIR__.'/../../config/public_visibility.php';
    define('BRVTAL_T19_LIBRARY_ONLY',true);
    require_once __DIR__.'/../../api/ticketing/admin-entries.php';
    $db->exec("UPDATE ticket_buyers SET consent_version='fixture',consent_recorded_at=UTC_TIMESTAMP(6) WHERE id=3");
    $db->exec("INSERT INTO ticket_buyers(id,public_id,email,status,email_verified_at)
        VALUES (4,UNHEX(LPAD('4',32,'0')),'other@example.test','active',UTC_TIMESTAMP(6))");
    $ticketId=(string)$db->query("SELECT LOWER(HEX(public_id)) FROM ticket_entries WHERE id={$first}")->fetchColumn();
    $priorQr=brvtalBuyerAccountStoredQr($db,3,$ticketId)['content'];
    $reject(fn()=>brvtalBuyerAccountStoredQr($db,4,$ticketId),'TICKET_UNAVAILABLE');
    $rotation=brvtalTicketAdminEntryMutation($db,$first,7,'superadmin','reissue','buyer_request',str_repeat('r',32));
    $currentQr=brvtalBuyerAccountStoredQr($db,3,$ticketId)['content'];
    actionAssert($rotation['qr']===$currentQr && $priorQr!==$currentQr,'current account QR follows actual rotation');
    $priorToken=explode(':',$priorQr)[3];
    $reject(fn()=>brvtalBuyerAccountQr($db,3,$ticketId,$priorToken),'TICKET_UNAVAILABLE');
    $scan=static fn(string $qr)=>brvtalTicketCheckinConsume($db,6,7,'fixture-2101',$qr);
    actionAssert($scan($priorQr)['reason']==='credential_revoked','old QR fails at admission');
    actionAssert($scan($currentQr)['ok'],'current QR admits once');
    actionAssert($scan($currentQr)['reason']==='already_used','duplicate scan denied');
    $reject(fn()=>brvtalBuyerAccountStoredQr($db,3,$ticketId),'TICKET_UNAVAILABLE');
    // Revoke the remaining recoverable unit; the version-independent account
    // URL still carries no bearer and cannot grant access to either QR.
    $db->exec("UPDATE ticket_entries SET status='revoked' WHERE order_item_id={$id} AND unit_number=2");
    $sent=brvtal_ticket_resend_send($copies[1],static function(array $payload,array $headers): array {
        actionAssert($payload['to']===['buyer@example.test'],'mock transport recipient');
        foreach (['text','html'] as $field) {
            actionAssert(str_contains($payload[$field],'/buyer/')
                && !str_contains($payload[$field],'BRVTAL:T1:')
                && !str_contains($payload[$field],'token='),'mock transport contains no bearer');
        }
        return ['status'=>200,'body'=>'{"id":"synthetic-recovery"}'];
    });
    actionAssert($sent['state']==='accepted','synthetic transport only');
    echo "2101 MariaDB: current account resend, immutable history and mock transport OK.\n";
} finally { $server->exec("DROP DATABASE IF EXISTS {$dbName}"); }
echo "1979 MariaDB: note, cancellation, safe mail resend, idempotence OK.\n";
