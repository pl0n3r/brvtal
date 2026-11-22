<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/ticketing_mail_events.php';
require_once __DIR__ . '/../../api/ticketing/webhook-resend.php';
function mail_t11_assert(bool $ok,string $case): void {
    if (!$ok) throw new RuntimeException('T11 FAILED: '.$case);
}
putenv('BRVTAL_TICKET_MAIL_TESTING=1');putenv('BRVTAL_TICKET_MAIL_WEBHOOK_ENABLED=1');
$secret='whsec_'.base64_encode(str_repeat('s',32));$now=time();
$sign=static function(string $id,string $raw,int $stamp): array {
    $key=str_repeat('s',32);
    return ['svix-id'=>$id,'svix-timestamp'=>(string)$stamp,
        'svix-signature'=>'v1,'.base64_encode(hash_hmac('sha256',$id.'.'.$stamp.'.'.$raw,$key,true))];
};
$raw=json_encode(['type'=>'email.delivered','created_at'=>gmdate('c',$now-10),
    'data'=>['email_id'=>'synthetic-email-1','to'=>['buyer@example.test']]],JSON_THROW_ON_ERROR);
$headers=$sign('msg_synthetic_1',$raw,$now);
mail_t11_assert(brvtal_mail_webhook_verify($raw,$headers,$secret,$now),'HMAC');
mail_t11_assert(!brvtal_mail_webhook_verify($raw.' ',$headers,$secret,$now),'tampered');
mail_t11_assert(!brvtal_mail_webhook_verify($raw,$headers,'',$now),'missing secret');
mail_t11_assert(!brvtal_mail_webhook_verify($raw,$sign('msg_old',$raw,$now-600),$secret,$now),'replay');
if (getenv('BRVTAL_INTEGRATION_TESTS')!=='1') {
    echo "BRVTAL ticket-mail-history signature fixtures OK; MariaDB skipped.\n";exit(0);
}
$base=(string)getenv('BRVTAL_TEST_DB_NAME');
mail_t11_assert((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/D',$base),'DB namespace');
$host=(string)(getenv('BRVTAL_TEST_DB_HOST')?:'127.0.0.1');
$port=(int)(getenv('BRVTAL_TEST_DB_PORT')?:3306);$user=(string)(getenv('BRVTAL_TEST_DB_USER')?:'root');
$pass=(string)(getenv('BRVTAL_TEST_DB_PASS')?:'');$dbName=$base.'_t11_'.bin2hex(random_bytes(4));
$opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false];
$server=new PDO('mysql:host='.$host.';port='.$port.';charset=utf8mb4',$user,$pass,$opts);
$server->exec('CREATE DATABASE '.$dbName.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
register_shutdown_function(static function () use($server,$dbName): void {
    try {$server->exec('DROP DATABASE IF EXISTS '.$dbName);} catch (Throwable) {}
});
$pdo=new PDO('mysql:host='.$host.';port='.$port.';dbname='.$dbName.';charset=utf8mb4',$user,$pass,$opts);
$pdo->exec((string)file_get_contents(__DIR__.'/../../database/migration_ticketing_mail_outbox_01.sql'));
$schema=(string)file_get_contents(__DIR__.'/../../database/migration_ticketing_mail_history_01.sql');
foreach (preg_split('/;\s*(?=CREATE|$)/i',$schema) ?: [] as $statement) {
    if (trim($statement)!=='') $pdo->exec($statement);
}
$pdo->exec("INSERT INTO ticket_mail_outbox (message_key,payload_hash,related_ref,recipient_email,subject,body_text,provider_message_id,state)
 VALUES ('test:mail:1',REPEAT('a',64),'entry:1','buyer@example.test','Test','Synthetic','synthetic-email-1','accepted')");
$id=brvtal_mail_history_record($pdo,1,['event_id'=>5,'buyer_id'=>6,'order_id'=>7,'ticket_id'=>8]);
mail_t11_assert($id===brvtal_mail_history_record($pdo,1),'idempotent');
foreach(['event_id'=>5,'buyer_id'=>6,'order_id'=>7,'ticket_id'=>8] as $key=>$val) {
    mail_t11_assert(count(brvtal_mail_history_list($pdo,[$key=>$val],1))===1,$key);
}
try {brvtal_mail_history_list($pdo,['event_id'=>5],0);mail_t11_assert(false,'public leak');}
catch(RuntimeException $e){mail_t11_assert($e->getMessage()==='MAIL_ADMIN_REQUIRED','admin gate');}
mail_t11_assert((string)$pdo->query('SELECT delivery_state FROM ticket_mail_history')->fetchColumn()==='accepted','accepted != delivered');
mail_t11_assert(brvtal_mail_webhook_process($pdo,$raw,$headers,$secret,$now)===200,'delivered');
mail_t11_assert(brvtal_mail_webhook_process($pdo,$raw,$headers,$secret,$now)===200,'duplicate');
mail_t11_assert((int)$pdo->query('SELECT COUNT(*) FROM ticket_mail_provider_events')->fetchColumn()===1,'dedup');
$old=json_encode(['type'=>'email.sent','created_at'=>gmdate('c',$now-60),
    'data'=>['email_id'=>'synthetic-email-1']],JSON_THROW_ON_ERROR);
mail_t11_assert(brvtal_mail_webhook_process($pdo,$old,$sign('msg_old_sent',$old,$now),$secret,$now)===200,'out of order');
mail_t11_assert((string)$pdo->query('SELECT delivery_state FROM ticket_mail_history')->fetchColumn()==='delivered','no downgrade');

$later=json_encode(['type'=>'email.sent','created_at'=>gmdate('c',$now+10),
    'data'=>['email_id'=>'synthetic-email-1']],JSON_THROW_ON_ERROR);
mail_t11_assert(brvtal_mail_webhook_process($pdo,$later,$sign('msg_later_sent',$later,$now),$secret,$now)===200,
    'later sent event');
mail_t11_assert((string)$pdo->query('SELECT delivery_state FROM ticket_mail_history')->fetchColumn()==='delivered',
    'later sent must not downgrade delivered');

$orphan=json_encode(['type'=>'email.bounced','created_at'=>gmdate('c',$now),
    'data'=>['email_id'=>'synthetic-email-2']],JSON_THROW_ON_ERROR);
mail_t11_assert(brvtal_mail_webhook_process($pdo,$orphan,$sign('msg_orphan',$orphan,$now),$secret,$now)===200,'orphan');
$pdo->exec("INSERT INTO ticket_mail_outbox (message_key,payload_hash,related_ref,recipient_email,subject,body_text,provider_message_id,state)
 VALUES ('test:mail:2',REPEAT('b',64),'entry:2','other@example.test','Test','Synthetic','synthetic-email-2','accepted')");
$id2=brvtal_mail_history_record($pdo,2);
$q=$pdo->prepare('SELECT delivery_state FROM ticket_mail_history WHERE id=?');$q->execute([$id2]);
mail_t11_assert((string)$q->fetchColumn()==='bounced','orphan reconciled');
$pdo->prepare('UPDATE ticket_mail_history SET retention_until=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY) WHERE id=?')->execute([$id]);
mail_t11_assert(brvtal_mail_history_redact_expired($pdo)===1,'retention');
mail_t11_assert(count(brvtal_mail_history_list($pdo,['event_id'=>5],1))===1,'non-PII history preserved');
// A queued record must bind the provider ID when Resend accepts it later.
$pdo->exec("INSERT INTO ticket_mail_outbox (message_key,payload_hash,related_ref,recipient_email,subject,body_text,state)
 VALUES ('test:mail:3',REPEAT('c',64),'entry:3','third@example.test','Test','Synthetic','queued')");
$id3=brvtal_mail_history_record($pdo,3,['order_id'=>11]);
$pdo->exec("UPDATE ticket_mail_outbox SET provider_message_id='synthetic-email-3',state='accepted'
 WHERE message_key='test:mail:3'");
mail_t11_assert($id3===brvtal_mail_history_record($pdo,3,['order_id'=>11]),'backfill stable');
$bind=$pdo->prepare('SELECT resend_email_id,delivery_state FROM ticket_mail_history WHERE id=?');
$bind->execute([$id3]);$bound=$bind->fetch(PDO::FETCH_ASSOC);
mail_t11_assert($bound['resend_email_id']==='synthetic-email-3'
    && $bound['delivery_state']==='accepted','queued upgraded to accepted');
$delivered=json_encode(['type'=>'email.delivered','created_at'=>gmdate('c',$now),
    'data'=>['email_id'=>'synthetic-email-3']],JSON_THROW_ON_ERROR);
mail_t11_assert(brvtal_mail_webhook_process($pdo,$delivered,$sign('msg_upgraded',$delivered,$now),$secret,$now)===200,
    'upgraded delivered');
$bind->execute([$id3]);$bound=$bind->fetch(PDO::FETCH_ASSOC);
mail_t11_assert($bound['delivery_state']==='delivered','upgrade receipt persisted');
putenv('BRVTAL_TICKET_MAIL_WEBHOOK_ENABLED=0');
mail_t11_assert(brvtal_mail_webhook_process($pdo,$raw,$headers,$secret,$now)===503,'disabled fail closed');
putenv('BRVTAL_TICKET_MAIL_TESTING=0');
putenv('BRVTAL_TICKET_MAIL_PRIVACY_APPROVED=0');
try {brvtal_mail_history_record($pdo,1);mail_t11_assert(false,'privacy denied');}
catch (RuntimeException $e) {mail_t11_assert($e->getMessage()==='MAIL_PRIVACY_NOT_APPROVED','privacy gate');}

// #1874: exercise the encrypted-secret CLI worker in the existing MariaDB T11 gate.
// Keep local fixture state isolated from the webhook and subsequent buyer-auth tests.
(static function (): void {
    require __DIR__ . '/ticketing-mail-worker-secrets.php';
})();
echo "BRVTAL ticket-mail-history integration OK.\n";

// #1889 separate synthetic MariaDB delivery snapshots.
require __DIR__ . '/mail-templates-delivery.php';
