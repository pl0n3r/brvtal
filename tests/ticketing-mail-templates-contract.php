<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/ticketing_mail_templates.php';

function t12_check(bool $ok, string $case): void {
    if (!$ok) throw new RuntimeException('T12: '.$case);
}
function t12_reject(callable $fn, string $code): void {
    try { $fn(); } catch (Throwable $error) {
        t12_check($error->getMessage()===$code, 'rejection '.$code);
        return;
    }
    throw new RuntimeException('T12: expected '.$code);
}
putenv('BRVTAL_TICKET_MAIL_TESTING=1');
$catalog = require __DIR__.'/../templates/ticketing/transactional.php';
t12_check(count($catalog)===7, 'seven Spanish templates');
$link = 'https://brvtal.invalid/buyer/login.php';
foreach ($catalog as $key=>$copy) {
    $rendered = brvtal_ticket_mail_render($key, ['event'=>'Festival <script>alert(1)</script>',
        'link'=>$link]);
    t12_check(str_contains($rendered['subject'],'BRVTAL'), $key.' subject');
    t12_check(str_contains($rendered['html'],'lang="es"')
        && str_contains($rendered['html'],'role="presentation"'), $key.' responsive accessibility');
    t12_check(!str_contains($rendered['html'],'<script>'), $key.' escaped HTML');
    t12_check(str_contains($rendered['text'],'Mensaje transaccional'), $key.' non-marketing');
}
t12_reject(fn()=>brvtal_ticket_mail_render('unlisted',['link'=>$link]), 'MAIL_TEMPLATE_UNKNOWN');
t12_reject(fn()=>brvtal_ticket_mail_render('payment_approved',
    ['event'=>'Evento','link'=>'https://evil.invalid/buyer/login.php']), 'MAIL_LINK_INVALID');
t12_reject(fn()=>brvtal_ticket_mail_render('payment_approved',
    ['event'=>'Evento','link'=>$link.'?ticket=secret']), 'MAIL_LINK_NOT_PROTECTED');
t12_reject(fn()=>brvtal_ticket_mail_render('payment_approved',
    ['event'=>"Evento\r\nInjected",'link'=>$link]), 'MAIL_EVENT_INVALID');
t12_reject(fn()=>brvtal_ticket_mail_render('reminder',
    ['event'=>'Evento','link'=>'http://brvtal.invalid/buyer/login.php']), 'MAIL_LINK_INVALID');
t12_check(!str_contains($catalog['payment_approved']['message'], 'QR adjunto'), 'no raw QR promised');
$mock = ['recipient_email'=>'person@example.test','message_key'=>'order:8:payment_approved',
    'subject'=>'Confirmación', 'body_text'=>'Mi Cuenta'];
putenv('BRVTAL_TICKET_MAIL_MODE=disabled');
t12_check(brvtal_ticket_resend_send($mock)['code']==='transport_disabled','provider disabled');
putenv('BRVTAL_TICKET_MAIL_MODE=mock');
t12_check(brvtal_ticket_resend_send($mock)['state']==='accepted','mock provider only');
putenv('BRVTAL_TICKET_MAIL_MODE=disabled');

if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "BRVTAL T12 templates, negative links and disabled transport passed (DB skipped).\n";
    exit(0);
}
$base=(string)getenv('BRVTAL_TEST_DB_NAME');
t12_check((bool)preg_match('/^brvtal_test[A-Za-z0-9_]*$/D',$base),'disposable DB namespace');
$host=(string)(getenv('BRVTAL_TEST_DB_HOST')?:'127.0.0.1');
$port=(int)(getenv('BRVTAL_TEST_DB_PORT')?:3306);
$user=(string)(getenv('BRVTAL_TEST_DB_USER')?:'root');
$pass=(string)(getenv('BRVTAL_TEST_DB_PASS')?:'');
$opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false];
$server=new PDO("mysql:host={$host};port={$port};charset=utf8mb4",$user,$pass,$opts);
$name=$base.'_t12_'.bin2hex(random_bytes(3));
$server->exec("CREATE DATABASE \`{$name}\`");
register_shutdown_function(static function () use ($server,$name): void {
    try {$server->exec("DROP DATABASE IF EXISTS \`{$name}\`");} catch (Throwable) {}
});
$db=new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",$user,$pass,$opts);
$db->exec((string)file_get_contents(__DIR__.'/../database/migration_ticketing_mail_outbox_01.sql'));
$db->exec((string)file_get_contents(__DIR__.'/../database/migration_ticketing_mail_history_01.sql'));
$db->exec("CREATE TABLE ticket_buyers (id INT PRIMARY KEY,email VARCHAR(190))");
$db->exec("CREATE TABLE ticket_orders (id INT PRIMARY KEY,event_id INT,buyer_id INT,status VARCHAR(16))");
$db->exec("CREATE TABLE ticket_order_items (id INT PRIMARY KEY,order_id INT,quantity INT)");
$db->exec("CREATE TABLE ticket_entries (id INT PRIMARY KEY,order_item_id INT,status VARCHAR(16))");
$db->exec("CREATE TABLE ticket_payment_attempts (id INT PRIMARY KEY,order_id INT,provider VARCHAR(24),
 status VARCHAR(16),provider_transaction_id VARCHAR(64))");
$db->exec("CREATE TABLE ticket_payment_webhook_events (attempt_id INT,provider_transaction_id VARCHAR(64),signed_status VARCHAR(16))");
$db->exec("CREATE TABLE ticket_issue_outbox (order_id INT,payment_attempt_id INT,state VARCHAR(16))");
$db->exec("INSERT INTO ticket_buyers VALUES (3,'person@example.test')");
$db->exec("INSERT INTO ticket_orders VALUES (8,2,3,'pending')");
$db->exec("INSERT INTO ticket_order_items VALUES (7,8,2)");
$db->exec("INSERT INTO ticket_payment_attempts VALUES (5,8,'wompi','pending',NULL)");
$db->exec("INSERT INTO ticket_issue_outbox VALUES (8,5,'pending')");
$data=['event'=>'Rave sintética','link'=>$link];
t12_reject(fn()=>brvtal_ticket_mail_queue_order($db,8,'payment_approved','person@example.test',$data),
    'MAIL_TRANSACTION_REQUIRED');
$db->beginTransaction();
t12_reject(fn()=>brvtal_ticket_mail_queue_order($db,8,'payment_approved','person@example.test',$data),
    'MAIL_ORDER_UNAVAILABLE');
$db->rollBack();
$db->exec("UPDATE ticket_orders SET status='paid' WHERE id=8");
$db->beginTransaction();
t12_reject(fn()=>brvtal_ticket_mail_queue_order($db,8,'payment_approved','person@example.test',$data),
    'MAIL_PAYMENT_NOT_VERIFIED');
$db->rollBack();
$db->exec("UPDATE ticket_payment_attempts SET status='approved',provider_transaction_id='synthetic' WHERE id=5");
$db->exec("INSERT INTO ticket_payment_webhook_events VALUES (5,'synthetic','APPROVED')");
$db->exec("UPDATE ticket_issue_outbox SET state='done'");
$db->beginTransaction();
t12_reject(fn()=>brvtal_ticket_mail_queue_order($db,8,'payment_approved','person@example.test',$data),
    'MAIL_TICKETS_NOT_READY');
$db->rollBack();
$db->exec("INSERT INTO ticket_entries VALUES (1,7,'issued'),(2,7,'issued')");
putenv('BRVTAL_TICKET_MAIL_LOOKUP_SECRET=fixture-lookup-only');
$db->beginTransaction();
$id=brvtal_ticket_mail_queue_order($db,8,'payment_approved','person@example.test',$data);
$db->rollBack();
t12_check((int)$db->query('SELECT COUNT(*) FROM ticket_mail_outbox')->fetchColumn()===0,
    'rollback hides messages');
$db->beginTransaction();
$id=brvtal_ticket_mail_queue_order($db,8,'payment_approved','person@example.test',$data);
t12_check($id===brvtal_ticket_mail_queue_order($db,8,'payment_approved','person@example.test',$data),
    'replay uses one mail and one QR set');
$db->commit();
t12_check((int)$db->query('SELECT COUNT(*) FROM ticket_mail_outbox')->fetchColumn()===1, 'one batch');
t12_check((int)$db->query('SELECT COUNT(*) FROM ticket_mail_history')->fetchColumn()===1, 'T11 audit');
t12_check(brvtal_ticket_mail_deliver_one($db)==='failed','failed provider visible');
t12_check(brvtal_ticket_mail_redrive($db,$id),'admin redrive failed mail');
t12_check((int)$db->query('SELECT COUNT(*) FROM ticket_entries')->fetchColumn()===2,'mail never issues QR');
putenv('BRVTAL_TICKET_MAIL_LOOKUP_SECRET');
echo "BRVTAL T12 synthetic paid/retry/idempotence/rollback DB contract passed.\n";
