<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/ticketing_mail_templates.php';
require_once __DIR__ . '/../../config/buyer_auth_mail_templates.php';

function mail_delivery_assert(bool $ok, string $reason): void {
    if (!$ok) throw new RuntimeException('MAIL_DELIVERY_FAILED: '.$reason);
}
/** Safe shape check even when a mocked provider callback has not run. */
function mail_delivery_has_header(mixed $payload, string $header): bool {
    return is_array($payload) && is_array($payload['headers'] ?? null)
        && array_key_exists($header, $payload['headers']);
}
$source=(string)file_get_contents(__DIR__.'/../../config/ticketing_resend.php');
mail_delivery_assert(str_contains($source,"$"."payload['html']"), 'Resend HTML payload');
$schema=(string)file_get_contents(__DIR__.'/../../database/migration_ticket_mail_html_01.sql');
foreach (['body_html','template_key','template_version','IF NOT EXISTS'] as $field) {
    mail_delivery_assert(str_contains($schema,$field), 'additive migration '.$field);
}
if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "BRVTAL mail-delivery source contract OK (MariaDB suite skipped).\n";
    return;
}
$base=(string)getenv('BRVTAL_TEST_DB_NAME');
mail_delivery_assert((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/D',$base), 'database namespace');
$host=(string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1');
$port=(int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306);
$user=(string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root');
$pass=(string)(getenv('BRVTAL_TEST_DB_PASS') ?: '');
$name=$base.'_mail_delivery_'.bin2hex(random_bytes(4));
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false];
$server=new PDO("mysql:host=".$host.";port=".$port.";charset=utf8mb4",$user,$pass,$options);
$server->exec("CREATE DATABASE ".$name." CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
register_shutdown_function(static function()use($server,$name):void{
    try{$server->exec("DROP DATABASE IF EXISTS ".$name);}catch(Throwable){}
});
$db=new PDO("mysql:host=".$host.";port=".$port.";dbname=".$name.";charset=utf8mb4",$user,$pass,$options);
$db->exec((string)file_get_contents(__DIR__.'/../../database/migration_ticketing_mail_outbox_01.sql'));
$old=$GLOBALS['config']??null;
$oldTesting=getenv('BRVTAL_TICKET_MAIL_TESTING');
$oldMode=getenv('BRVTAL_TICKET_MAIL_MODE');
$GLOBALS['config']=['security'=>['encryption_key'=>str_repeat('fixture-only-encryption-key-32-',2)],
    'ticketing'=>['mail'=>['testing'=>'1','mode'=>'mock','from'=>'Fixture BRVTAL <test@brvtal.invalid>',
        'domain'=>'brvtal.invalid','privacy_approved'=>'1']]];
putenv('BRVTAL_TICKET_MAIL_TESTING=1');
putenv('BRVTAL_TICKET_MAIL_MODE=mock');
// Safe text-only fallback before the additive HTML columns exist.
$legacy=brvtal_mail_delivery_render($db,'order_pending',
    ['event'=>'Legacy event','link'=>'https://brvtal.invalid/buyer/orders']);
$db->beginTransaction();
$legacyId=brvtal_ticket_mail_enqueue($db,'test:legacy','order:0',
    'legacy@example.test',$legacy['subject'],$legacy['text'],$legacy['html'],'order_pending',null);
$db->commit();
mail_delivery_assert($legacyId>0,'safe text-only enqueue before migration');
$db->exec((string)file_get_contents(__DIR__.'/../../database/migration_ticket_mail_html_01.sql'));
$db->exec((string)file_get_contents(__DIR__.'/../../database/migration_ticket_mail_html_01.sql'));
mail_delivery_assert((int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ticket_mail_outbox'
    AND COLUMN_NAME IN ('body_html','template_key','template_version')")->fetchColumn()===3,
    'idempotent additive columns');
try {
    $event=['event'=>'Noche de prueba','link'=>'https://brvtal.invalid/buyer/orders'];
    foreach(['order_pending','order_rejected','payment_approved','reminder','refund'] as $kind) {
        $snapshot=brvtal_mail_delivery_render($db,$kind,$event);
        mail_delivery_assert(str_contains($snapshot['html'],'<!doctype html>')
            && str_contains($snapshot['text'],'Noche de prueba'),'render '.$kind);
        $db->beginTransaction();
        $id=brvtal_ticket_mail_enqueue($db,'test:'.$kind,'order:1','buyer@example.test',
            $snapshot['subject'],$snapshot['text'],$snapshot['html'],$kind,$snapshot['template_version']);
        $db->commit();
        mail_delivery_assert($id>0,'enqueued '.$kind);
    }
    // Published snapshot must survive later editor changes, and reject corruption.
    require_once __DIR__.'/../../config/mail_template_store.php';
    $schemaCopy=(string)file_get_contents(__DIR__.'/../../database/migration_mail_templates_01.sql');
    foreach(preg_split('/;\s*(?=CREATE|$)/i',$schemaCopy)?:[] as $stmt) {
        if(trim($stmt)!=='')$db->exec($stmt);
    }
    $doc=brvtal_mail_template_catalog()['order_pending']['defaults'];
    $doc['message']='Publicado para {{event_name}}';
    $doc['theme']['accent']='#aabbcc';
    $draft=brvtal_mail_template_save_draft($db,'order_pending','ticketing',$doc,1,0);
    $pub=brvtal_mail_template_publish($db,'order_pending','ticketing',$draft['version_id'],1,1);
    $snapshot=brvtal_mail_delivery_render($db,'order_pending',$event);
    mail_delivery_assert($snapshot['template_source']==='published'
        && $snapshot['template_version']===$pub['version_id']
        && str_contains($snapshot['html'],'#aabbcc')
        && str_contains($snapshot['text'],'Publicado para Noche de prueba'),
        'published theme rendered with allowlisted variable');
    $db->beginTransaction();
    $snapshotId=brvtal_ticket_mail_enqueue($db,'test:published','order:2','buyer@example.test',
        $snapshot['subject'],$snapshot['text'],$snapshot['html'],
        'order_pending',$snapshot['template_version']);
    $db->commit();
    $unchanged=$db->prepare('SELECT body_text,body_html,template_version FROM ticket_mail_outbox WHERE id=?');
    $unchanged->execute([$snapshotId]);$before=$unchanged->fetch(PDO::FETCH_ASSOC);
    $doc['message']='Se actualizó la plantilla posteriormente';
    $rev=brvtal_mail_template_save_draft($db,'order_pending','ticketing',$doc,2,2);
    brvtal_mail_template_publish($db,'order_pending','ticketing',$rev['version_id'],2,3);
    $unchanged->execute([$snapshotId]);$after=$unchanged->fetch(PDO::FETCH_ASSOC);
    mail_delivery_assert($after===$before,'queued snapshot immutable across publishes');
    $db->prepare('UPDATE mail_template_versions SET document_json=? WHERE id=?')
        ->execute(['{"invalid":"document"}',$rev['version_id']]);
    try {
        brvtal_mail_delivery_render($db,'order_pending',$event);
        mail_delivery_assert(false,'corrupt published document must fail closed');
    } catch(RuntimeException $error) {
        mail_delivery_assert($error->getMessage()==='MAIL_TEMPLATE_DOCUMENT_CORRUPTED','opaque corrupt failure');
    }
    // The actual outbox worker sends the original HTML/text snapshot, not the
    // currently published revision after the editor changed it.
    $db->exec("UPDATE ticket_mail_outbox SET state='accepted' WHERE message_key<>'test:published'");
    $transportSnapshot=null;
    $dispatched=brvtal_ticket_mail_deliver_one($db,
        static function(array $payload,array $headers)use(&$transportSnapshot):array{
            $transportSnapshot=$payload;
            return ['status'=>200,'body'=>'{"id":"synthetic-published-1889"}'];
        });
    mail_delivery_assert($dispatched==='accepted'
        && $transportSnapshot['text']===$before['body_text']
        && $transportSnapshot['html']===$before['body_html'],
        'worker sends immutable snapshot, not current published revision');
    try {
        brvtal_mail_delivery_render($db,'verify_account',
            ['link'=>'https://untrusted.invalid/mi-cuenta/restablecer?accion=verify&token='.str_repeat('Z',43)]);
        mail_delivery_assert(false,'foreign bearer links must be rejected');
    } catch (InvalidArgumentException $error) {
        mail_delivery_assert($error->getMessage()==='MAIL_LINK_INVALID','host rejected safely');
    }
    $token=str_repeat('A',43);
    foreach(['verify_account','reset_password'] as $kind) {
        $mail=brvtalBuyerMailContent($kind,$token);
        $db->beginTransaction();
        $id=brvtal_ticket_mail_enqueue($db,'buyer:1:'.$kind.':12','buyer:1','buyer@example.test',
            $mail['subject'],$mail['sealed']);
        $db->commit();
        $q=$db->prepare('SELECT body_text,body_html,template_key FROM ticket_mail_outbox WHERE id=?');
        $q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        mail_delivery_assert(str_starts_with((string)$row['body_html'],BRVTAL_BUYER_MAIL_SEAL_PREFIX)
            && !str_contains((string)$row['body_html'],$token)
            && !str_contains((string)$row['body_text'],$token),'sealed token '.$kind);
        $captured=null;
        $request=static function($payload,$headers)use(&$captured):array {
            $captured=$payload;
            return ['status'=>200,'body'=>'{"id":"synthetic-email-1889"}'];
        };
        $job=array_merge($row,['message_key'=>'buyer:1:'.$kind.':12',
            'recipient_email'=>'buyer@example.test','subject'=>$mail['subject']]);
        $res=brvtal_ticket_resend_send($job,$request);
        mail_delivery_assert($res['state']==='accepted'
            && str_contains((string)$captured['html'],$token)
            && str_contains((string)$captured['text'],$token),'same in-memory sealed snapshot '.$kind);
    }
    // #1993: names, Reply-To, defaults, negative injection checks and
    // marketing-only unsubscribe must be enforced by the real Resend adapter.
    foreach ([
        ['BRVTAL_TICKET_MAIL_FROM_NAME', "BRVTAL\r\nBcc: bad@example.test"],
        ['BRVTAL_TICKET_MAIL_FROM_NAME', 'Bad <Sender>'],
        ['BRVTAL_TICKET_MAIL_FROM_NAME', "BRVTAL\nOther"],
        ['BRVTAL_TICKET_MAIL_REPLY_TO', "soporte@example.test\r\nBcc: bad@example.test"],
        ['BRVTAL_TICKET_MAIL_REPLY_TO', "soporte@example.test\n"],
        ['BRVTAL_TICKET_MAIL_REPLY_TO', 'Not an address'],
        ['BRVTAL_TICKET_MAIL_REPLY_TO', 'Alias <soporte@example.test>'],
    ] as [$name, $value]) {
        $normalized = brvtalIntegrationSecretNormalize($name, $value);
        try {
            brvtalIntegrationSecretValidate($name, $normalized);
            mail_delivery_assert(false, 'reject malformed sender identity '.$name);
        } catch (InvalidArgumentException $error) {
            mail_delivery_assert($error->getMessage()==='SECRET_FORMAT_INVALID', 'safe sender rejection');
        }
    }
    brvtalIntegrationSecretValidate('BRVTAL_TICKET_MAIL_FROM_NAME', 'BRVTAL Música');
    brvtalIntegrationSecretValidate('BRVTAL_TICKET_MAIL_REPLY_TO', 'soporte@brvtal.com.co');
    $GLOBALS['config']['ticketing']['mail'] = [
        'mode'=>'test', 'api_key'=>'re_synthetic_mail_delivery_123456',
        'domain'=>'brvtal.invalid','from'=>'no-reply@brvtal.invalid',
        'from_name'=>'BRVTAL','reply_to'=>'soporte@brvtal.invalid',
        'test_recipients'=>'buyer@example.test',
    ];
    $payload=null;
    $headersRequest=static function(array $message, array $headers) use (&$payload): array {
        $payload=$message;
        return ['status'=>200,'body'=>'{"id":"synthetic-mail-deliverability-1993"}'];
    };
    $deliveryJob=['recipient_email'=>'buyer@example.test','message_key'=>'test:headers:1',
        'subject'=>'Confirmación de boleta','body_text'=>'Entrada sin cobro',
        'template_key'=>'payment_approved'];
    $sent=brvtal_ticket_resend_send($deliveryJob,$headersRequest);
    mail_delivery_assert($sent['state']==='accepted'
        && $payload['from']==='BRVTAL <no-reply@brvtal.invalid>'
        && $payload['reply_to']==='soporte@brvtal.invalid'
        && !mail_delivery_has_header($payload, 'List-Unsubscribe'), 'transactional sender with no unsubscribe');
    foreach (['verify_account','reset_password','order_pending','refund'] as $kind) {
        $payload=null;
        $sent=brvtal_ticket_resend_send(array_merge($deliveryJob,
            ['message_key'=>'test:headers:'. $kind,'template_key'=>$kind]),$headersRequest);
        mail_delivery_assert($sent['state']==='accepted'
            && $payload['from']==='BRVTAL <no-reply@brvtal.invalid>'
            && $payload['reply_to']==='soporte@brvtal.invalid'
            && !mail_delivery_has_header($payload, 'List-Unsubscribe'), 'no unsubscribe for '.$kind);
    }
    $GLOBALS['config']['ticketing']['mail']['from_name']='';
    $GLOBALS['config']['ticketing']['mail']['reply_to']='';
    $payload=null;
    $sent=brvtal_ticket_resend_send(array_merge($deliveryJob,['message_key'=>'test:headers:fallback']),
        $headersRequest);
    mail_delivery_assert($sent['state']==='accepted'
        && $payload['from']==='BRVTAL <no-reply@brvtal.invalid>'
        && $payload['reply_to']==='no-reply@brvtal.invalid', 'sender defaults are safe');
    $GLOBALS['config']['ticketing']['mail']['from_name']='BRVTAL';
    $GLOBALS['config']['ticketing']['mail']['reply_to']='soporte@brvtal.invalid';
    $payload=null;
    $marketing=array_merge($deliveryJob,['message_key'=>'test:headers:reminder',
        'template_key'=>'reminder',
        'unsubscribe_url'=>'https://www.brvtal.com.co/correo/baja?token=fixture']);
    $sent=brvtal_ticket_resend_send($marketing,$headersRequest);
    mail_delivery_assert($sent['state']==='accepted'
        && $payload['headers']['List-Unsubscribe']
            ==='<https://www.brvtal.com.co/correo/baja?token=fixture>', 'marketing only unsubscribe');
    $marketing['message_key']='test:headers:bad-unsubscribe';
    $marketing['unsubscribe_url']="https://www.brvtal.com.co/correo/baja\r\nBcc:bad@example.test";
    mail_delivery_assert(brvtal_ticket_resend_send($marketing,$headersRequest)['code']
        ==='invalid_configuration', 'unsubscribe header injection rejected');
    $GLOBALS['config']['ticketing']['mail']['from_name']="BRVTAL\nBcc:evil";
    mail_delivery_assert(brvtal_ticket_resend_send($deliveryJob,$headersRequest)['code']
        ==='invalid_configuration', 'invalid private sender config fails closed');

    // #1984: real Wompi approval and real cancellation/expiry transitions,
    // exercising an isolated MariaDB with no provider calls or real recipients.
    require_once __DIR__ . '/../../config/ticketing_wompi_webhook.php';
    require_once __DIR__ . '/../../config/ticketing_admin_actions.php';
    require_once __DIR__ . '/../../config/ticketing_inventory.php';
    $GLOBALS['config']['ticketing']['mail'] = [
        'mode'=>'mock','testing'=>'1','privacy_approved'=>'1',
        'admin_recipient'=>'admin@example.test',
        'domain'=>'brvtal.invalid','from'=>'test@brvtal.invalid',
    ];
    $history=(string)file_get_contents(
        __DIR__.'/../../database/migration_ticketing_mail_history_01.sql');
    foreach (explode(';',(string)preg_replace('/^\h*--[^\r\n]*$/m','',$history)) as $sql) {
        if (trim($sql)!=='') $db->exec($sql);
    }
    foreach ([
        'CREATE TABLE events (id INT PRIMARY KEY,title VARCHAR(160) NOT NULL) ENGINE=InnoDB',
        'CREATE TABLE ticket_buyers (id BIGINT PRIMARY KEY,email VARCHAR(190) NOT NULL) ENGINE=InnoDB',
        'CREATE TABLE ticket_orders (id BIGINT PRIMARY KEY,event_id INT NOT NULL,
            buyer_id BIGINT NOT NULL,status VARCHAR(16) NOT NULL,currency CHAR(3) NOT NULL,
            total_cop BIGINT NOT NULL,expires_at_utc DATETIME(6) NULL,
            updated_at_utc DATETIME(6) NULL) ENGINE=InnoDB',
        'CREATE TABLE ticket_payment_attempts (id BIGINT PRIMARY KEY,order_id BIGINT NOT NULL,
            provider VARCHAR(24),provider_reference VARCHAR(190),provider_transaction_id VARCHAR(190),
            gross_amount_cop BIGINT,status VARCHAR(16),updated_at_utc DATETIME(6),
            UNIQUE KEY uq_ref (provider,provider_reference)) ENGINE=InnoDB',
        'CREATE TABLE ticket_payment_webhook_events (id BIGINT AUTO_INCREMENT PRIMARY KEY,
            attempt_id BIGINT,provider_transaction_id VARCHAR(190),event_checksum CHAR(64),
            signed_status VARCHAR(16),event_timestamp BIGINT,
            UNIQUE KEY uq_hook (attempt_id,event_checksum)) ENGINE=InnoDB',
        'CREATE TABLE ticket_issue_outbox (order_id BIGINT PRIMARY KEY,payment_attempt_id BIGINT,
            state VARCHAR(16) NOT NULL DEFAULT "pending") ENGINE=InnoDB',
        'CREATE TABLE ticket_inventory_holds (id BIGINT AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT,status VARCHAR(16),expires_at_utc DATETIME(6)) ENGINE=InnoDB',
        'CREATE TABLE ticket_order_action_requests (id BIGINT AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT,actor_admin_id BIGINT,action VARCHAR(32),request_key_hash CHAR(64),
            request_hash CHAR(64),status VARCHAR(24),mail_outbox_id BIGINT,note_id BIGINT,
            UNIQUE KEY uq_action (request_key_hash)) ENGINE=InnoDB',
        'CREATE TABLE ticket_order_notes (id BIGINT AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT,actor_admin_id BIGINT,note_text VARCHAR(280)) ENGINE=InnoDB',
    ] as $ddl) $db->exec($ddl);
    $db->exec("INSERT INTO events VALUES (6,'Noche de prueba')");
    $db->exec("INSERT INTO ticket_buyers VALUES (3,'buyer@example.test')");
    $db->exec("INSERT INTO ticket_orders VALUES
      (701,6,3,'pending','COP',50000,NULL,NULL),
      (702,6,3,'pending','COP',50000,NULL,NULL),
      (703,6,3,'pending','COP',50000,'2000-01-01 00:00:00',NULL),
      (704,6,3,'pending','COP',50000,NULL,NULL),
      (705,6,3,'pending','COP',50000,NULL,NULL),
      (706,6,3,'pending','COP',50000,NULL,NULL)");
    $reference='BW7-701-CARD-'.str_repeat('b',24);
    $db->prepare("INSERT INTO ticket_payment_attempts
      VALUES (1,701,'wompi',?,NULL,50000,'created',NULL)")->execute([$reference]);
    $verified=['id'=>'synthetic-tx-1984','reference'=>$reference,
        'status'=>'APPROVED','currency'=>'COP','amount_in_cents'=>5000000,
        '_event_checksum'=>str_repeat('a',64),'_event_timestamp'=>time()];
    $apply=static function(array $event)use($db):string {
        $db->beginTransaction();
        try {
            $value=brvtal_wompi_hook_apply($db,$event,$event);
            $db->commit();
            return $value;
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    };
    mail_delivery_assert($apply($verified)==='approved','verified payment succeeds');
    mail_delivery_assert($apply($verified)==='duplicate','same payment retry');
    $countMail=static function(string $kind)use($db):int {
        $q=$db->prepare('SELECT COUNT(*) FROM ticket_mail_outbox WHERE template_key=?');
        $q->execute([$kind]);
        return (int)$q->fetchColumn();
    };
    mail_delivery_assert($countMail('admin_new_order')===1,
        'approved payment queues precisely one administrator email');
    $q=$db->prepare('SELECT recipient_email,body_text FROM ticket_mail_outbox
        WHERE message_key=?');
    $q->execute(['order:701:admin_new_order']);
    $adminMail=$q->fetch(PDO::FETCH_ASSOC);
    mail_delivery_assert($adminMail['recipient_email']==='admin@example.test'
        && str_contains($adminMail['body_text'],'Pedido: #701')
        && !str_contains($adminMail['body_text'],'buyer@example.test'),
        'configured recipient and no buyer PII in administrator mail');

    $cancel=brvtalTicketAdminActionInput(['order_id'=>'702','action'=>'cancel_pending',
        'idempotency_key'=>str_repeat('b',32)]);
    $first=brvtalTicketAdminRecordOrderAction($db,7,'admin',$cancel);
    $again=brvtalTicketAdminRecordOrderAction($db,7,'admin',$cancel);
    mail_delivery_assert($first['status']==='cancelled' && $first['email_queued']
        && $again['replayed'] && $countMail('order_cancelled')===1,
        'cancellation queues once, repeat is idempotent');

    $db->exec("INSERT INTO ticket_inventory_holds
        (order_id,status,expires_at_utc)
        VALUES (703,'active','2000-01-01 00:00:00')");
    brvtal_ticket_inventory_reconcile($db);
    mail_delivery_assert($countMail('order_expired')===1,
        'expired order queues one buyer email');
    // The disabled mode changes nothing about order truth, but must
    // suppress ALL three notification kinds even on authenticated events.
    $GLOBALS['config']['ticketing']['mail']['mode']='disabled';
    $referenceOff='BW7-704-CARD-'.str_repeat('c',24);
    $db->prepare("INSERT INTO ticket_payment_attempts
      VALUES (2,704,'wompi',?,NULL,50000,'created',NULL)")->execute([$referenceOff]);
    $verifiedOff=$verified;
    $verifiedOff['id']='synthetic-tx-1984-off';
    $verifiedOff['reference']=$referenceOff;
    $verifiedOff['_event_checksum']=str_repeat('c',64);
    mail_delivery_assert($apply($verifiedOff)==='approved','disabled does not block payment');
    $offCancel=brvtalTicketAdminActionInput(['order_id'=>'705',
        'action'=>'cancel_pending','idempotency_key'=>str_repeat('c',32)]);
    mail_delivery_assert(brvtalTicketAdminRecordOrderAction(
        $db,7,'admin',$offCancel)['status']==='cancelled',
        'disabled does not block cancellation');
    mail_delivery_assert($countMail('admin_new_order')===1
        && $countMail('order_cancelled')===1
        && $countMail('order_expired')===1,
        'mail disabled enqueues no additional notifications');
    $db->exec("INSERT INTO ticket_inventory_holds
        (order_id,status,expires_at_utc)
        VALUES (706,'active','2000-01-01 00:00:00')");
    $db->exec("UPDATE ticket_orders SET expires_at_utc='2000-01-01 00:00:00' WHERE id=706");
    brvtal_ticket_inventory_reconcile($db);
    mail_delivery_assert($db->query('SELECT status FROM ticket_orders WHERE id=706')
        ->fetchColumn()==='expired' && $countMail('order_expired')===1,
        'disabled expiry changes order status but queues no buyer email');
    brvtal_ticket_inventory_reconcile($db);
    mail_delivery_assert($countMail('order_expired')===1,
        'reconciliation replay does not duplicate expiry');

} finally {
    if($old===null)unset($GLOBALS['config']);else $GLOBALS['config']=$old;
    putenv($oldTesting===false ? 'BRVTAL_TICKET_MAIL_TESTING' : 'BRVTAL_TICKET_MAIL_TESTING='.$oldTesting);
    putenv($oldMode===false ? 'BRVTAL_TICKET_MAIL_MODE' : 'BRVTAL_TICKET_MAIL_MODE='.$oldMode);
}
echo "BRVTAL mail-template delivery synthetic MariaDB integration OK.\n";
