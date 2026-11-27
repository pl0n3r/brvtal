<?php
declare(strict_types=1);
// Isolated MariaDB fixture: never contact the provider or store a real recipient.
require_once __DIR__ . '/../../config/ticketing_recovery.php';
require_once __DIR__ . '/../../config/ticketing_mail_templates.php';
function t29(bool $ok,string $why):void {if(!$ok)throw new RuntimeException('T29 '.$why);}
foreach ([['order_id'=>-1,'campaign'=>'checkout','idempotency_key'=>str_repeat('a',32)],
          ['order_id'=>1,'campaign'=>'email@buyer','idempotency_key'=>str_repeat('a',32)],
          ['order_id'=>1,'campaign'=>'checkout','idempotency_key'=>'short']] as $bad) {
    try {brvtalRecoveryInput($bad);throw new RuntimeException('invalid accepted');}
    catch (InvalidArgumentException) {}
}
$template=brvtalRecoveryTemplate('Rave test','prueba');
t29($template['marketing'] && !$template['sent'] && !$template['restore_hold'],
    'marketing never mistaken for transactional or automatically restores hold');
if (getenv('BRVTAL_INTEGRATION_TESTS')!=='1') {echo "T29 types OK; MariaDB skipped.\n";exit(0);}
$base=(string)getenv('BRVTAL_TEST_DB_NAME');
t29((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/D',$base),'disposable namespace');
$name=$base.'_t29_'.bin2hex(random_bytes(4));
$dsn='mysql:host='.(getenv('BRVTAL_TEST_DB_HOST')?:'127.0.0.1')
    .';port='.(getenv('BRVTAL_TEST_DB_PORT')?:3306).';charset=utf8mb4';
$opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false];
$root=new PDO($dsn,(string)(getenv('BRVTAL_TEST_DB_USER')?:'root'),
    (string)(getenv('BRVTAL_TEST_DB_PASS')?:''),$opts);
$root->exec('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4');
try {
    $db=new PDO($dsn.';dbname='.$name,(string)(getenv('BRVTAL_TEST_DB_USER')?:'root'),
        (string)(getenv('BRVTAL_TEST_DB_PASS')?:''),$opts);
    $db->exec('CREATE TABLE events (id INT UNSIGNED PRIMARY KEY,title VARCHAR(160),slug VARCHAR(100))');
    $db->exec('CREATE TABLE ticket_buyers (id BIGINT UNSIGNED PRIMARY KEY,
        status VARCHAR(16),email_verified_at DATETIME(6))');
    $db->exec('CREATE TABLE ticket_orders (id BIGINT UNSIGNED PRIMARY KEY,buyer_id BIGINT UNSIGNED,
        event_id INT UNSIGNED,status VARCHAR(16),expires_at_utc DATETIME(6))');
    $db->exec('CREATE TABLE ticket_order_items (id BIGINT PRIMARY KEY,order_id BIGINT UNSIGNED,
        ticket_type_id INT UNSIGNED,quantity INT UNSIGNED)');
    $db->exec('CREATE TABLE ticket_payment_attempts (id BIGINT PRIMARY KEY,
        order_id BIGINT UNSIGNED,status VARCHAR(16))');
    $ddl=preg_replace('/^\s*--[^\n]*$/m','',
        (string)file_get_contents(__DIR__.'/../../database/migration_ticketing_recovery_01.sql'));
    foreach (array_filter(array_map(trim(...),explode(';',(string)$ddl))) as $sql) $db->exec($sql);
    $db->exec("INSERT INTO events VALUES (6,'Rave test','prueba')");
    $db->exec("INSERT INTO ticket_buyers VALUES
        (3,'active','2026-11-20'),(4,'active','2026-11-20')");
    $db->exec("INSERT INTO ticket_orders VALUES
        (91,3,6,'pending',DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 3 DAY)),
        (92,4,6,'pending',DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 3 DAY)),
        (93,3,6,'paid',DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 3 DAY))");
    $db->exec("INSERT INTO ticket_order_items VALUES (1,91,7,2),(2,92,7,1),(3,93,7,1)");
    $db->exec("INSERT INTO ticket_recovery_consents VALUES
        (3,6,'marketing-v1',UTC_TIMESTAMP(6),NULL,DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 30 DAY))");
    $db->exec("INSERT INTO ticket_payment_attempts VALUES (1,91,'pending'),(2,93,'approved')");
    $db->exec("INSERT INTO ticket_orders VALUES (94,3,6,'pending',DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 DAY))");
    $db->exec("INSERT INTO ticket_orders VALUES (95,3,6,'pending',DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 3 DAY))");
    $db->exec("INSERT INTO ticket_order_items VALUES (4,94,7,1),(5,95,7,1)");
    $db->exec("INSERT INTO ticket_payment_attempts VALUES (3,95,'approved')");
    $db->exec('ALTER TABLE ticket_buyers ADD email VARCHAR(190) NULL');
    $db->exec("UPDATE ticket_buyers SET email=CONCAT('fixture',id,'@brvtal.invalid')");
    $db->exec('ALTER TABLE ticket_orders ADD public_id BINARY(16) NULL,
        ADD created_at_utc DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6))');
    $db->exec("UPDATE ticket_orders SET public_id=UNHEX(LPAD(HEX(id),32,'0')),
        created_at_utc=DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 60 MINUTE)");
    foreach (['ticketing_mail_outbox_01','ticket_mail_html_01','ticketing_mail_history_01'] as $migration) {
        $db->exec((string)file_get_contents(__DIR__.'/../../database/migration_'.$migration.'.sql'));
    }
    // Real enqueue and history, never delivery. Synthetic configuration only.
    $GLOBALS['config'] = ['ticketing'=>['mail'=>['testing'=>'1','mode'=>'mock']]];
    putenv('BRVTAL_TICKET_MAIL_TESTING=1');
    $db->exec("ALTER TABLE ticket_payment_attempts ADD provider VARCHAR(24) NULL,
        ADD provider_transaction_id VARCHAR(190) NULL");
    $db->exec('CREATE TABLE ticket_payment_webhook_events (attempt_id BIGINT,
        provider_transaction_id VARCHAR(190),signed_status VARCHAR(16))');
    $db->exec('CREATE TABLE ticket_issue_outbox (order_id BIGINT PRIMARY KEY,
        payment_attempt_id BIGINT,state VARCHAR(16))');
    $db->exec('CREATE TABLE ticket_entries (id BIGINT PRIMARY KEY,
        order_item_id BIGINT,status VARCHAR(16))');
    // Real checkout holds expire in 10 minutes. A reminder must fit inside that hold.
    $db->exec("UPDATE ticket_orders SET
        created_at_utc=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 6 MINUTE),
        expires_at_utc=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 4 MINUTE) WHERE id=91");
    $db->exec("INSERT INTO ticket_orders
        (id,buyer_id,event_id,status,expires_at_utc,created_at_utc,public_id)
        VALUES (96,3,6,'pending',DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 8 MINUTE),
            DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 2 MINUTE),UNHEX(LPAD(HEX(96),32,'0')))");

    $input=['order_id'=>91,'campaign'=>'noviembre','idempotency_key'=>str_repeat('a',32)];
    $result=brvtalRecoveryPlan($db,7,'superadmin',$input);
    t29($result['state']==='planned' && $result['stage']==='payment_open'
        && !$result['sent'] && !$result['queued'] && !$result['restore_hold'],'planned only');
    t29(brvtalRecoveryPlan($db,7,'superadmin',$input)['replayed'],'idempotent');
    t29(brvtalRecoveryPlan($db,7,'superadmin',array_merge($input,
        ['idempotency_key'=>str_repeat('b',32)]))['reason']==='frequency_limit','debounce');
    t29(brvtalRecoveryPlan($db,7,'superadmin',array_merge($input,
        ['order_id'=>92,'idempotency_key'=>str_repeat('c',32)]))['reason']==='consent_unavailable',
        'terms alone never grant marketing');
    t29(brvtalRecoveryPlan($db,7,'superadmin',array_merge($input,
        ['order_id'=>93,'idempotency_key'=>str_repeat('d',32)]))['reason']==='order_terminal',
        'paid never targeted');
    t29(brvtalRecoveryPlan($db,7,'superadmin',array_merge($input,
        ['order_id'=>94,'idempotency_key'=>str_repeat('e',32)]))['reason']==='checkout_expired',
        'expired selection cannot rearm hold');
    t29(brvtalRecoveryPlan($db,7,'superadmin',array_merge($input,
        ['order_id'=>95,'idempotency_key'=>str_repeat('g',32)]))['reason']==='payment_confirmed',
        'server-approved payment blocks recovery even when order state is still pending');
    t29(count(brvtalRecoveryMetrics($db))===1,'aggregate eligible only');
    $ready=['mode'=>'live','ready'=>true];
    // No new marketing sends from a normal deploy: explicit opt-in gate required.
    putenv('BRVTAL_RECOVERY_ENABLED');
    t29(brvtalRecoverySend($db,7,'superadmin',$input,$ready)['reason']==='recovery_disabled',
        'new commercial campaign defaults OFF');
    putenv('BRVTAL_RECOVERY_ENABLED=1');
    $send=brvtalRecoverySend($db,7,'superadmin',$input,$ready);
    t29($send['state']==='queued' && $send['queued'],'valid opt-in queued');
    $again=brvtalRecoverySend($db,7,'superadmin',$input,$ready);
    t29($again['reason']==='already_reminded','one reminder per order');
    foreach ([92=>'consent_unavailable',93=>'order_not_pending',
              94=>'checkout_expired',95=>'payment_confirmed',
              96=>'outside_window'] as $id=>$reason) {
        $once=brvtalRecoverySend($db,7,'superadmin',
            array_merge($input,['order_id'=>$id]),$ready);
        t29($once['reason']===$reason,"suppression $id");
    }
    t29((int)$db->query("SELECT COUNT(*) FROM ticket_mail_outbox
        WHERE message_key='order:91:abandoned_checkout' AND template_key='reminder'
          AND body_text LIKE '%/compra/%'")->fetchColumn()===1, 'one durable abandonment snapshot');
    // An unrelated old shared key is deliberately not classified from current status.
    $db->exec("UPDATE ticket_orders SET created_at_utc=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 6 MINUTE)
        WHERE id=96");
    $db->beginTransaction();
    $legacyId=brvtal_ticket_mail_enqueue($db,'order:96:reminder','order:96',
        'fixture3@brvtal.invalid','Legacy fixture','Unknown historical purpose');
    $db->commit();
    t29(brvtalRecoverySend($db,7,'superadmin',array_merge($input,['order_id'=>96]),$ready)
        ['reason']==='legacy_reminder_undetermined','legacy suppresses automatic recovery');
    $metrics=brvtalRecoveryLiveMetrics($db)[0];
    t29((int)$metrics['reminders']===1 && (int)$metrics['legacy_undetermined']===1,
        'legacy is not counted as abandonment');
    t29(brvtalRecoverySend($db,7,'superadmin',$input,['mode'=>'disabled','ready'=>false])
        ['reason']==='mail_unavailable','mail mode fail closed');
    try { brvtalRecoveryReminder('Rave','not-a-token'); throw new RuntimeException('token accepted'); }
    catch (DomainException) {}

    $db->exec("UPDATE ticket_orders SET expires_at_utc=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 SECOND) WHERE id=91");
    t29(brvtalRecoveryMetrics($db)===[],'expired checkout hidden from recovery dashboard');
    $db->exec("UPDATE ticket_orders SET expires_at_utc=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 3 DAY) WHERE id=91");
    t29(count(brvtalRecoveryMetrics($db))===1,'future checkout remains eligible');
    t29(brvtalRecoveryOptOut($db,3,6),'optout');
    t29(brvtalRecoveryMetrics($db)===[],'optout removes dashboard count');
    t29(brvtalRecoverySend($db,7,'superadmin',$input,$ready)['reason']==='consent_unavailable',
        'optout blocks marketing');
    // Same order becomes paid, with independent signed payment and issuance proof.
    $db->exec("UPDATE ticket_orders SET status='paid' WHERE id IN (91,96)");
    $db->exec("UPDATE ticket_payment_attempts SET status='approved',provider='wompi',
        provider_transaction_id='fixture-91' WHERE id=1");
    $db->exec("INSERT INTO ticket_payment_attempts VALUES (4,96,'approved','wompi','fixture-96')");
    $db->exec("INSERT INTO ticket_payment_webhook_events VALUES
        (1,'fixture-91','APPROVED'),(4,'fixture-96','APPROVED')");
    $db->exec("INSERT INTO ticket_issue_outbox VALUES (91,1,'done'),(96,4,'done')");
    $db->exec("INSERT INTO ticket_order_items VALUES (6,96,7,1)");
    $db->exec("INSERT INTO ticket_entries VALUES (1,1,'issued'),(2,1,'issued'),(3,6,'issued')");
    $event=['event'=>'Rave test','link'=>'https://brvtal.invalid/buyer/orders'];
    $db->beginTransaction();
    $eventId=brvtal_ticket_mail_queue_order($db,91,'reminder','fixture3@brvtal.invalid',$event);
    t29($eventId!==$send['mail_id'] &&
        $eventId===brvtal_ticket_mail_queue_order($db,91,'reminder','fixture3@brvtal.invalid',$event),
        'paid event reminder distinct and idempotent');
    $db->commit();
    $db->beginTransaction();
    try {
        brvtal_ticket_mail_queue_order($db,96,'reminder','fixture3@brvtal.invalid',$event);
        throw new RuntimeException('legacy event replay accepted');
    } catch (DomainException $error) {
        t29($error->getMessage()==='MAIL_LEGACY_REMINDER_UNDETERMINED','legacy event suppressed');
    } finally { $db->rollBack(); }
    $metrics=brvtalRecoveryLiveMetrics($db)[0];
    t29((int)$metrics['reminders']===1 && (int)$metrics['queued']===1
        && (int)$metrics['legacy_undetermined']===1,'paid event excluded from recovery metrics');
    t29((int)$db->query('SELECT COUNT(*) FROM ticket_mail_outbox')->fetchColumn()===3
        && (int)$db->query("SELECT COUNT(*) FROM ticket_mail_outbox WHERE id=".$legacyId."
            AND body_text='Unknown historical purpose'")->fetchColumn()===1,'legacy row immutable');
    t29(brvtalRecoverySend($db,7,'superadmin',$input,$ready)['reason']==='order_not_pending',
        'paid order cannot queue marketing');

    // #2134: actual persisted queue -> worker -> injected transport, not an enqueue stub.
    $calls = 0;
    $transport = static function () use (&$calls): array {
        ++$calls;
        return ['status'=>200,'body'=>json_encode(['id'=>'fixture-dispatch-'.$calls])];
    };
    $readMail = $db->prepare('SELECT state,last_error_code,provider_message_id FROM ticket_mail_outbox WHERE id=?');
    $expectMail = static function (int $id, string $state, ?string $reason) use ($readMail): void {
        $readMail->execute([$id]);
        $row = $readMail->fetch(PDO::FETCH_ASSOC);
        t29($row['state']===$state && $row['last_error_code']===$reason
            && $row['provider_message_id']===null, 'local terminal state and sanitized reason');
    };
    t29(brvtal_ticket_mail_deliver_one($db,$transport)==='suppressed','paid recovery suppressed');
    $expectMail($send['mail_id'],'suppressed','order_not_pending');
    t29(brvtal_ticket_mail_deliver_one($db,$transport)==='suppressed','ambiguous legacy not replayed');
    $expectMail($legacyId,'suppressed','legacy_reminder_undetermined');
    t29($calls===0,'no provider calls for paid or legacy suppression');
    t29(brvtal_ticket_mail_deliver_one($db,$transport)==='accepted' && $calls===1,
        'scoped transactional reminder unaffected by marketing optout');
    t29((string)$db->query("SELECT delivery_state FROM ticket_mail_history WHERE outbox_id=$eventId")
        ->fetchColumn()==='accepted','transactional receipt linked');

    $freshOrder = static function (int $id) use ($db,$input,$ready): int {
        $db->exec("UPDATE ticket_recovery_consents SET revoked_at_utc=NULL WHERE buyer_id=3 AND event_id=6");
        $db->exec("INSERT INTO ticket_orders (id,buyer_id,event_id,status,expires_at_utc,created_at_utc,public_id)
            VALUES ($id,3,6,'pending',DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 4 MINUTE),
                DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 6 MINUTE),UNHEX(LPAD(HEX($id),32,'0')))");
        $result = brvtalRecoverySend($db,7,'superadmin',array_merge($input,['order_id'=>$id]),$ready);
        t29($result['state']==='queued','real recovery queued');
        return $result['mail_id'];
    };
    $cases = [
        101=>['consent_unavailable',"UPDATE ticket_recovery_consents SET revoked_at_utc=UTC_TIMESTAMP(6) WHERE buyer_id=3"],
        102=>['payment_confirmed',"INSERT INTO ticket_payment_attempts (id,order_id,status) VALUES (102,102,'approved')"],
        103=>['order_not_pending',"UPDATE ticket_orders SET status='cancelled' WHERE id=103"],
        104=>['checkout_expired',"UPDATE ticket_orders SET expires_at_utc=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 SECOND) WHERE id=104"],
        105=>['outside_window',"UPDATE ticket_orders SET created_at_utc=UTC_TIMESTAMP(6) WHERE id=105"],
    ];
    foreach ($cases as $orderId=>[$reason,$change]) {
        $id = $freshOrder($orderId);
        $db->exec($change);
        t29(brvtal_ticket_mail_deliver_one($db,$transport)==='suppressed','pre-dispatch '.$reason);
        $expectMail($id,'suppressed',$reason);
        t29(!brvtal_ticket_mail_redrive($db,$id) && $calls===1,'suppression terminal, no provider');
    }
    t29((string)$db->query("SELECT status FROM ticket_payment_attempts WHERE id=102")->fetchColumn()==='approved',
        'worker does not change Wompi attempt');

    // A retry after a potentially accepted request is not proof of suppression.
    $retryId = $freshOrder(106);
    $retryTransport = static function () use (&$calls): array {
        ++$calls;
        return ['status'=>503,'body'=>'{}'];
    };
    t29(brvtal_ticket_mail_deliver_one($db,$retryTransport)==='retry' && $calls===2,'first attempt reached transport');
    $db->exec("UPDATE ticket_mail_outbox SET next_attempt_at=UTC_TIMESTAMP() WHERE id=$retryId");
    brvtalRecoveryOptOut($db,3,6);
    t29(brvtal_ticket_mail_deliver_one($db,$transport)==='uncertain','retry after optout stays uncertain');
    $expectMail($retryId,'uncertain','receipt_unknown');
    t29($calls===2 && !brvtal_ticket_mail_redrive($db,$retryId),'no repeat request or manual redrive');

    // Two connections: consent revocation after authorization cannot revoke an
    // already started call. No transaction is held across the external boundary.
    $raceId = $freshOrder(107);
    brvtal_mail_history_record($db,$raceId,['order_id'=>107,'buyer_id'=>3,'event_id'=>6,
        'template_key'=>'reminder','actor_kind'=>'admin']);
    $second = new PDO($dsn.';dbname='.$name,(string)(getenv('BRVTAL_TEST_DB_USER')?:'root'),
        (string)(getenv('BRVTAL_TEST_DB_PASS')?:''),$opts);
    $second->exec('SET innodb_lock_wait_timeout=1');
    $raceTransport = static function () use ($db,$second,$transport): array {
        t29(!$db->inTransaction(),'provider invoked outside DB transaction');
        t29(brvtal_ticket_mail_deliver_one($second,$transport)==='idle','second worker cannot claim in-flight job');
        t29(brvtalRecoveryOptOut($second,3,6),'revocation during provider call is not locked out');
        brvtal_mail_history_event($second,'fixture-recovery-race','fixture-race','delivered',
            new DateTimeImmutable('now',new DateTimeZone('UTC')),hash('sha256','fixture-race'));
        return ['status'=>200,'body'=>'{"id":"fixture-race"}'];
    };
    t29(brvtal_ticket_mail_deliver_one($db,$raceTransport)==='accepted','in-flight receipt not mislabeled suppressed');
    t29((string)$db->query("SELECT delivery_state FROM ticket_mail_history WHERE outbox_id=$raceId")
        ->fetchColumn()==='delivered','early webhook reconciled after receipt');
    t29(brvtal_ticket_mail_deliver_one($second,$transport)==='idle' && $calls===2,'no second transport');

    putenv('BRVTAL_INTEGRATION_TESTS=0');
    try {brvtalRecoveryPlan($db,7,'superadmin',$input);throw new RuntimeException('real mode');}
    catch (DomainException $e) {t29($e->getMessage()==='SYNTHETIC_ONLY','real mode blocked');}
    putenv('BRVTAL_RECOVERY_ENABLED');
    putenv('BRVTAL_INTEGRATION_TESTS=1');
    t29((int)$db->query('SELECT COUNT(*) FROM ticket_recovery_previews')->fetchColumn()===1,
        'one preview, no duplicate');
} finally {
    $root->exec('DROP DATABASE IF EXISTS '.$name);
}
echo "T29 consent, payment, retry, dedupe, expiry and optout synthetic OK.\n";
