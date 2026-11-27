<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/ticketing_dashboard.php';
function dashboardAssert(bool $ok,string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
dashboardAssert(brvtalDashboardCan('admin') && !brvtalDashboardCan('door') && !brvtalDashboardCan(''),'role boundary');
foreach ([['event_id'=>['1']],['channel'=>'cash'],['from'=>'2026-02-30'],['to'=>'2026-01-01','from'=>'2026-01-02'],['email'=>'x']] as $bad) {
    try { brvtalDashboardFilters($bad); throw new RuntimeException('accepted invalid filter'); }
    catch (InvalidArgumentException) {}
}
if (getenv('BRVTAL_INTEGRATION_TESTS')!=='1') { echo "dashboard DB skipped\n"; exit(0); }
$prefix=(string)getenv('BRVTAL_TEST_DB_NAME');
dashboardAssert((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/D',$prefix),'disposable database only');
$name=$prefix.'_dash_'.bin2hex(random_bytes(4));
$host=getenv('BRVTAL_TEST_DB_HOST')?:'127.0.0.1';
$port=getenv('BRVTAL_TEST_DB_PORT')?:'3306';
$user=getenv('BRVTAL_TEST_DB_USER')?:'root';
$pass=getenv('BRVTAL_TEST_DB_PASS')?:'';
$opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$server=new PDO("mysql:host=$host;port=$port;charset=utf8mb4",$user,$pass,$opts);
$server->exec('CREATE DATABASE '.$name);
try {
    $db=new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",$user,$pass,$opts);
    foreach ([
        'CREATE TABLE events (id INT PRIMARY KEY)',
        'CREATE TABLE ticket_orders (id INT PRIMARY KEY,event_id INT,total_cop INT,currency CHAR(3),status VARCHAR(20),created_at_utc DATETIME)',
        'CREATE TABLE ticket_order_items (id INT PRIMARY KEY,order_id INT,event_id INT,ticket_type_id INT,quantity INT,unit_price_cop INT,subtotal_cop INT,ticket_name_snapshot VARCHAR(50))',
        'CREATE TABLE ticket_payment_attempts (id INT PRIMARY KEY,order_id INT,provider VARCHAR(20),provider_reference VARCHAR(50),provider_transaction_id VARCHAR(50),status VARCHAR(20),gross_amount_cop INT,fee_amount_cop INT,fee_vat_cop INT,withheld_amount_cop INT,net_amount_cop INT)',
        'CREATE TABLE ticket_payment_webhook_events (attempt_id INT,provider_transaction_id VARCHAR(50))',
        'CREATE TABLE ticket_pos_sales (id INT PRIMARY KEY,order_id INT,event_id INT,ticket_type_id INT,quantity INT,amount_cop INT,method VARCHAR(20),status VARCHAR(20),shift_id INT,operator_admin_id INT,completed_at_utc DATETIME,unit_price_cop INT)',
        'CREATE TABLE ticket_pos_shifts (id INT PRIMARY KEY,event_id INT,operator_admin_id INT)',
        'CREATE TABLE ticket_courtesy_invites (order_id INT)',
        'CREATE TABLE ticket_refunds (id INT PRIMARY KEY,order_id INT,event_id INT,status VARCHAR(20))',
        'CREATE TABLE ticket_refund_adjustments (refund_id INT,order_id INT,event_id INT,amount_cop INT,recorded_at_utc DATETIME)',
        'CREATE TABLE ticket_inventory_events (event_id INT PRIMARY KEY,capacity INT)',
        'CREATE TABLE ticket_entries (id INT PRIMARY KEY,order_item_id INT,event_id INT)',
        'CREATE TABLE ticket_checkins (event_id INT,entry_id INT,outcome VARCHAR(20))',
        'CREATE TABLE ticket_issue_outbox (order_id INT,state VARCHAR(20))',
        'CREATE TABLE ticket_mail_outbox (id INT PRIMARY KEY,message_key VARCHAR(100),state VARCHAR(20))',
        'CREATE TABLE ticket_mail_history (outbox_id INT,delivery_state VARCHAR(20))',
        'INSERT INTO events VALUES (1),(2)',
        "INSERT INTO ticket_orders VALUES (41,1,10000,'COP','paid','2026-11-19 05:00:00'),(42,1,20000,'COP','paid','2026-11-19 06:00:00'),(43,1,0,'COP','draft','2026-11-19 06:00:00'),(44,1,1000,'COP','pending','2026-11-19 06:00:00'),(45,1,3000,'COP','paid','2026-11-19 06:00:00'),(46,1,9000,'COP','pending','2026-11-19 04:59:59')",
        "INSERT INTO ticket_order_items VALUES (1,41,1,1,1,10000,10000,'secret-fixture'),(2,42,1,1,1,20000,20000,'POS'),(3,43,1,1,1,0,0,'courtesy'),(4,44,1,1,1,1000,1000,'pending'),(5,45,1,1,1,3000,3000,'unissued')",
        "INSERT INTO ticket_payment_attempts VALUES (1,41,'wompi','private-ref','TX-1','approved',10000,0,0,0,10000),(2,45,'wompi','private-ref2','TX-2','approved',3000,0,0,0,3000)",
        "INSERT INTO ticket_payment_webhook_events VALUES (1,'TX-1'),(1,'TX-1'),(2,'TX-2')",
        "INSERT INTO ticket_pos_sales VALUES (1,42,1,1,1,20000,'cash','paid',1,9,'2026-11-19 06:00:00',20000)",
        'INSERT INTO ticket_pos_shifts VALUES (1,1,9)',
        'INSERT INTO ticket_courtesy_invites VALUES (43)',
        "INSERT INTO ticket_refunds VALUES (1,41,1,'refunded')",
        "INSERT INTO ticket_refund_adjustments VALUES (1,41,1,4000,'2026-11-19 07:00:00')",
        'INSERT INTO ticket_inventory_events VALUES (1,100),(2,50)',
        'INSERT INTO ticket_entries VALUES (1,1,1),(2,2,1),(3,3,1)',
        "INSERT INTO ticket_checkins VALUES (1,1,'accepted'),(1,1,'accepted'),(1,2,'rejected')",
        "INSERT INTO ticket_issue_outbox VALUES (45,'failed')",
        "INSERT INTO ticket_mail_outbox VALUES (1,'order:41:payment_approved','accepted'),(2,'order:45:payment_approved','uncertain'),(3,'order:42:payment_approved','failed')",
        "INSERT INTO ticket_mail_history VALUES (1,'delivered')",
    ] as $sql) $db->exec($sql);
    $filter=['from'=>'2026-11-19','to'=>'2026-11-19'];
    $result=brvtalDashboardReport($db,$filter,'admin');
    dashboardAssert(count($result['rows'])===2,'events without orders retained');
    [$a,$b]=$result['rows']; $p=$a['period'];
    dashboardAssert($p['paid_orders']===3 && $p['gross_cop']===33000 && $p['refund_cop']===4000
        && $p['net_after_refunds_cop']===29000 && $p['pending_orders']===1 && $p['issued_units']===3,'cohort and evidence');
    dashboardAssert($b['period']['paid_orders']===0 && $b['period']['net_after_refunds_cop']===0,'empty verified cohort');
    dashboardAssert($a['current']===['capacity'=>100,'accepted_accesses'=>1],'distinct current accesses');
    dashboardAssert($a['alerts']['approved_without_full_issuance']===1 && $a['alerts']['issue_failed']===1
        && $a['alerts']['mail_uncertain']===1 && $a['alerts']['mail_failed']===1
        && $a['alerts']['mail_accepted']===1 && $a['alerts']['mail_delivered']===1,'independent alerts');
    $filtered=brvtalDashboardReport($db,$filter+['event_id'=>'1','channel'=>'pos'],'admin')['rows'];
    dashboardAssert(count($filtered)===1 && $filtered[0]['period']['gross_cop']===20000
        && $filtered[0]['current']===$a['current'],'channel/event scope independent of current');
    $json=json_encode($result,JSON_THROW_ON_ERROR);
    foreach (['private-ref','TX-1','secret-fixture','order_id','buyer_id','recipient'] as $private) {
        dashboardAssert(!str_contains($json,$private),'private evidence not exposed');
    }
    try { brvtalDashboardReport($db,[],'door'); throw new RuntimeException('door allowed'); }
    catch (DomainException $e) { dashboardAssert($e->getMessage()==='FORBIDDEN','door rejected'); }
    $db->exec('DROP TABLE ticket_mail_history');
    $db->exec('DROP TABLE ticket_entries');
    $unknown=brvtalDashboardReport($db,$filter,'admin')['rows'][0];
    dashboardAssert($unknown['period']['issued_units']===null && $unknown['alerts']['mail_delivered']===null
        && $unknown['alerts']['mail_uncertain']===1,'missing sources unknown independently');
    $db->exec('DROP TABLE ticket_payment_webhook_events');
    $unknown=brvtalDashboardReport($db,$filter,'admin')['rows'][0];
    dashboardAssert($unknown['period']['gross_cop']===null,'missing proof not zero');
    echo "dashboard MariaDB OK\n";
} finally {
    $db=null;
    $server->exec('DROP DATABASE '.$name);
}
