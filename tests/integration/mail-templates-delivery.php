<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/ticketing_mail_templates.php';
require_once __DIR__ . '/../../config/buyer_auth_mail_templates.php';

function mail_delivery_assert(bool $ok, string $reason): void {
    if (!$ok) throw new RuntimeException('MAIL_DELIVERY_FAILED: '.$reason);
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
$db->exec((string)file_get_contents(__DIR__.'/../../database/migration_ticket_mail_html_01.sql'));
$db->exec((string)file_get_contents(__DIR__.'/../../database/migration_ticket_mail_html_01.sql'));
mail_delivery_assert((int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ticket_mail_outbox'
    AND COLUMN_NAME IN ('body_html','template_key','template_version')")->fetchColumn()===3,
    'idempotent additive columns');
$old=$GLOBALS['config']??null;
$GLOBALS['config']=['security'=>['encryption_key'=>str_repeat('fixture-only-encryption-key-32-',2)],
    'ticketing'=>['mail'=>['testing'=>'1','mode'=>'mock','from'=>'Fixture BRVTAL <test@brvtal.invalid>',
        'domain'=>'brvtal.invalid','privacy_approved'=>'1']]];
putenv('BRVTAL_TICKET_MAIL_TESTING=1');
putenv('BRVTAL_TICKET_MAIL_MODE=mock');
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
} finally {
    if($old===null)unset($GLOBALS['config']);else $GLOBALS['config']=$old;
    putenv('BRVTAL_TICKET_MAIL_TESTING');
    putenv('BRVTAL_TICKET_MAIL_MODE');
}
echo "BRVTAL mail-template delivery synthetic MariaDB integration OK.\n";
