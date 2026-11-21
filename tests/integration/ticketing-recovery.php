<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/ticketing_recovery.php';
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
    foreach (array_filter(array_map('trim',explode(';',(string)$ddl))) as $sql) $db->exec($sql);
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
    $db->exec("UPDATE ticket_orders SET expires_at_utc=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 SECOND) WHERE id=91");
    t29(brvtalRecoveryMetrics($db)===[],'expired checkout hidden from recovery dashboard');
    $db->exec("UPDATE ticket_orders SET expires_at_utc=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 3 DAY) WHERE id=91");
    t29(count(brvtalRecoveryMetrics($db))===1,'future checkout remains eligible');
    t29(brvtalRecoveryOptOut($db,3,6),'optout');
    t29(brvtalRecoveryMetrics($db)===[],'optout removes dashboard count');
    putenv('BRVTAL_INTEGRATION_TESTS=0');
    try {brvtalRecoveryPlan($db,7,'superadmin',$input);throw new RuntimeException('real mode');}
    catch (DomainException $e) {t29($e->getMessage()==='SYNTHETIC_ONLY','real mode blocked');}
    putenv('BRVTAL_INTEGRATION_TESTS=1');
    t29((int)$db->query('SELECT COUNT(*) FROM ticket_recovery_previews')->fetchColumn()===1,
        'one preview, no duplicate');
} finally {
    $root->exec('DROP DATABASE IF EXISTS '.$name);
}
echo "T29 consent, payment, retry, dedupe, expiry and optout synthetic OK.\n";
