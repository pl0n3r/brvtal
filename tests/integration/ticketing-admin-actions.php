<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/ticketing_admin_actions.php';
function t18bAssert(bool $ok, string $why): void {
    if (!$ok) throw new RuntimeException('T18b: '.$why);
}
$valid = ['order_id'=>'91','action'=>'note','idempotency_key'=>str_repeat('a',32),
    'note'=>'Pendiente de revision interna'];
foreach ([
    array_merge($valid,['order_id'=>'0']),
    array_merge($valid,['action'=>'approve_payment']),
    array_merge($valid,['idempotency_key'=>'short']),
    array_merge($valid,['note'=>'correo@example.test']),
    array_merge($valid,['note'=>'https://example.test']),
    array_merge($valid,['note'=>str_repeat('a',281)]),
    array_merge($valid,['note'=>['malformed']]),
    array_merge($valid,['buyer_email'=>'a@example.test']),
] as $bad) {
    try { brvtalTicketAdminActionInput($bad); throw new RuntimeException('accepted invalid action'); }
    catch (InvalidArgumentException) { /* correct */ }
}
t18bAssert(brvtalTicketAdminActionInput($valid)['order_id']===91,'typed ID');
t18bAssert(!brvtalTicketAdminCanAction('door','note'),'door cannot note');
t18bAssert(!brvtalTicketAdminCanAction('admin','resend_preview'),'admin cannot reissue');
t18bAssert(brvtalTicketAdminCanAction('superadmin','resend_preview'),'superadmin can preview');
if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "T18b typed actions OK; disposable MariaDB fixture skipped.\n"; exit(0);
}
$base=(string)getenv('BRVTAL_TEST_DB_NAME');
t18bAssert((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/D',$base),'test database prefix');
$host=(string)(getenv('BRVTAL_TEST_DB_HOST')?:'127.0.0.1');
$port=(int)(getenv('BRVTAL_TEST_DB_PORT')?:3306);
$user=(string)(getenv('BRVTAL_TEST_DB_USER')?:'root');
$pass=(string)(getenv('BRVTAL_TEST_DB_PASS')?:'');
$name=$base.'_t18b_'.bin2hex(random_bytes(4));
$opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$root=new PDO('mysql:host='.$host.';port='.$port.';charset=utf8mb4',$user,$pass,$opts);
$root->exec('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4');
try {
    $pdo=new PDO('mysql:host='.$host.';port='.$port.';dbname='.$name.';charset=utf8mb4',$user,$pass,$opts);
    $pdo->exec('CREATE TABLE ticket_buyers (
        id BIGINT UNSIGNED PRIMARY KEY,consent_version VARCHAR(40),consent_recorded_at DATETIME NULL)');
    $pdo->exec('CREATE TABLE ticket_orders (
        id BIGINT UNSIGNED PRIMARY KEY,buyer_id BIGINT UNSIGNED,status VARCHAR(20))');
    $ddl=file_get_contents(__DIR__.'/../../database/migration_ticketing_admin_notes_01.sql');
    $pdo->exec((string)$ddl);
    $pdo->exec("INSERT INTO ticket_buyers VALUES (3,'consent-2026','2026-11-19'),(4,NULL,NULL)");
    $pdo->exec("INSERT INTO ticket_orders VALUES (91,3,'pending'),(92,4,'pending')");
    t18bAssert(brvtalTicketAdminSyntheticMode($pdo),'disposable only');
    $note=brvtalTicketAdminRecordAction($pdo,7,'admin',brvtalTicketAdminActionInput($valid));
    t18bAssert($note['status']==='recorded'&&!$note['email_sent']&&!$note['payment_changed'],
        'note recorded without provider or payment side effect');
    $same=brvtalTicketAdminRecordAction($pdo,7,'admin',brvtalTicketAdminActionInput($valid));
    t18bAssert($same['replayed']&&$same['receipt_id']===$note['receipt_id'],'idempotent replay');
    try {
        brvtalTicketAdminRecordAction($pdo,7,'admin',brvtalTicketAdminActionInput(
            array_merge($valid,['note'=>'Otro estado sintetico'])));
        throw new RuntimeException('reused key with changed payload');
    } catch (DomainException $e) {
        t18bAssert($e->getMessage()==='IDEMPOTENCY_CONFLICT','key collision rejected');
    }
    $blocked=brvtalTicketAdminRecordAction($pdo,7,'admin',brvtalTicketAdminActionInput([
        'order_id'=>'92','action'=>'contact_preview','idempotency_key'=>str_repeat('b',32)]));
    t18bAssert($blocked['status']==='blocked','missing consent never previews contact');
    $pending=brvtalTicketAdminRecordAction($pdo,7,'superadmin',brvtalTicketAdminActionInput([
        'order_id'=>'91','action'=>'resend_preview','idempotency_key'=>str_repeat('c',32)]));
    t18bAssert($pending['status']==='pending'&&!$pending['email_sent'],
        'resend remains pending, no email');
    try {
        brvtalTicketAdminRecordAction($pdo,7,'door',brvtalTicketAdminActionInput([
            'order_id'=>'91','action'=>'note','idempotency_key'=>str_repeat('d',32),'note'=>'Dato sintetico']));
        throw new RuntimeException('unauthorized role');
    } catch (DomainException $e) {
        t18bAssert($e->getMessage()==='FORBIDDEN','RBAC fail closed');
    }
    putenv('BRVTAL_INTEGRATION_TESTS=0');
    try {
        brvtalTicketAdminRecordAction($pdo,7,'superadmin',brvtalTicketAdminActionInput([
            'order_id'=>'91','action'=>'resend_preview','idempotency_key'=>str_repeat('e',32)]));
        throw new RuntimeException('real-mode write');
    } catch (DomainException $e) {
        t18bAssert($e->getMessage()==='SYNTHETIC_ONLY','non-test environment disabled');
    } finally { putenv('BRVTAL_INTEGRATION_TESTS=1'); }
    $audit=$pdo->query('SELECT order_id,actor_admin_id,action,status,note_text,retention_until_utc
         FROM ticket_admin_actions ORDER BY id')->fetchAll();
    t18bAssert(count($audit)===3,'exactly one row per key');
    t18bAssert((int)$audit[0]['actor_admin_id']===7 && $audit[0]['note_text']===$valid['note'],
        'actor and minimized note persisted');
    t18bAssert($audit[1]['status']==='blocked'&&$audit[1]['note_text']===null,
        'denial auditable without PII');
    t18bAssert($pdo->query("SELECT status FROM ticket_orders WHERE id=91")->fetchColumn()==='pending',
        'payment state untouched');
    $pdo->exec("UPDATE ticket_admin_actions SET retention_until_utc='2000-01-01' WHERE id=".$note['receipt_id']);
    brvtalTicketAdminRecordAction($pdo,7,'superadmin',brvtalTicketAdminActionInput([
        'order_id'=>'91','action'=>'recovery_preview','idempotency_key'=>str_repeat('f',32)]));
    t18bAssert((int)$pdo->query('SELECT COUNT(*) FROM ticket_admin_actions WHERE id='.$note['receipt_id'])->fetchColumn()===0,
        'expired synthetic note pruned');
} finally {
    $root->exec('DROP DATABASE IF EXISTS '.$name);
}
echo "T18b synthetic audit, retention, roles, consent and idempotency OK.\n";

// T29 shares this established MariaDB job; no extra test runner or live DB.
if (getenv('BRVTAL_INTEGRATION_TESTS') === '1') require __DIR__ . '/ticketing-recovery.php';
