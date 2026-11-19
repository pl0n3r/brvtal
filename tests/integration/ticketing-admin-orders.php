<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/ticketing_admin_orders.php';
function t18Assert(bool $ok,string $why):void {if(!$ok)throw new RuntimeException('T18: '.$why);}
foreach ([['kind'=>'other'],['status'=>'approved'],['event_id'=>'1 OR 1=1'],
          ['from'=>'2026-02-30'],['from'=>'2026-11-20','to'=>'2026-11-19'],['q'=>str_repeat('x',121)],
          ['email'=>['one@example.test']]] as $bad) {
    try { brvtalTicketAdminFilters($bad); throw new RuntimeException('accepted invalid'); }
    catch (InvalidArgumentException) { /* expected */ }
}
$f=brvtalTicketAdminFilters(['kind'=>'orders','event_id'=>'6','status'=>'paid','limit'=>'1']);
t18Assert($f['event_id']===6&&$f['limit']===1&&$f['status']==='paid','typed filters');
if (getenv('BRVTAL_INTEGRATION_TESTS')!=='1') {echo "T18 typed filters OK; database fixture skipped.\n";exit(0);}
$base=(string)getenv('BRVTAL_TEST_DB_NAME');
t18Assert((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/D',$base),'disposable DB namespace');
$host=(string)(getenv('BRVTAL_TEST_DB_HOST')?:'127.0.0.1');
$port=(int)(getenv('BRVTAL_TEST_DB_PORT')?:3306);
$user=(string)(getenv('BRVTAL_TEST_DB_USER')?:'root');
$pass=(string)(getenv('BRVTAL_TEST_DB_PASS')?:'');
$name=$base.'_t18_'.bin2hex(random_bytes(4));
$dsn='mysql:host='.$host.';port='.$port.';charset=utf8mb4';
$opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$root=new PDO($dsn,$user,$pass,$opts);
$root->exec('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4');
try {
    $pdo=new PDO('mysql:host='.$host.';port='.$port.';dbname='.$name.';charset=utf8mb4',$user,$pass,$opts);
    foreach ([
        'CREATE TABLE ticket_buyers (id BIGINT PRIMARY KEY,email VARCHAR(190),status VARCHAR(20),created_at DATETIME)',
        'CREATE TABLE ticket_orders (id BIGINT PRIMARY KEY,buyer_id BIGINT,event_id INT,status VARCHAR(20),currency CHAR(3),total_cop BIGINT,created_at_utc DATETIME)',
        'CREATE TABLE ticket_order_items (id BIGINT PRIMARY KEY,order_id BIGINT,ticket_name_snapshot VARCHAR(100),quantity INT,unit_price_cop BIGINT,subtotal_cop BIGINT)',
        'CREATE TABLE ticket_payment_attempts (id BIGINT PRIMARY KEY,order_id BIGINT,provider VARCHAR(20),status VARCHAR(20),gross_amount_cop BIGINT,created_at_utc DATETIME)',
        'CREATE TABLE ticket_entries (id BIGINT PRIMARY KEY,order_item_id BIGINT)',
        'CREATE TABLE ticket_mail_history (id BIGINT PRIMARY KEY,order_id BIGINT,delivery_state VARCHAR(20),created_at DATETIME)',
        "INSERT INTO ticket_buyers VALUES (3,'synthetic@example.test','active','2026-11-01'),(4,'other@example.test','guest','2026-11-01')",
        "INSERT INTO ticket_orders VALUES (91,3,6,'paid','COP',75000,'2026-11-19'),(92,3,7,'pending','COP',20000,'2026-11-18'),(93,4,6,'refunded','COP',10000,'2026-11-17')",
        "INSERT INTO ticket_order_items VALUES (1,91,'General',2,30000,60000),(2,91,'VIP',1,15000,15000)",
        "INSERT INTO ticket_payment_attempts VALUES (1,91,'wompi','pending',75000,'2026-11-19'),(2,91,'wompi','approved',75000,'2026-11-19')",
        'INSERT INTO ticket_entries VALUES (1,1),(2,1),(3,2)',
        "INSERT INTO ticket_mail_history VALUES (1,91,'delivered','2026-11-18'),(2,91,'failed','2026-11-19')",
    ] as $sql) $pdo->exec($sql);
    $page=brvtalTicketAdminPage($pdo,brvtalTicketAdminFilters(['kind'=>'orders','event_id'=>'6']));
    t18Assert(count($page['rows'])===2,'no row multiplication from item/payment/mail joins');
    $o=array_values(array_filter($page['rows'],static fn($x)=>(int)$x['id']===91))[0];
    t18Assert((int)$o['units']===3&&(int)$o['entries']===3&&(int)$o['approvals']===1,'independent counts');
    t18Assert($o['total_cop']==75000&&$o['mail_state']==='failed','total and latest mail state');
    $pages=brvtalTicketAdminPage($pdo,brvtalTicketAdminFilters(['kind'=>'orders','limit'=>'1']));
    t18Assert(count($pages['rows'])===1&&$pages['has_more']&&$pages['next_cursor']===93,'keyset first page');
    $next=brvtalTicketAdminPage($pdo,brvtalTicketAdminFilters(['kind'=>'orders','limit'=>'1','cursor'=>'93']));
    t18Assert((int)$next['rows'][0]['id']===92,'keyset second page');
    $buyers=brvtalTicketAdminPage($pdo,brvtalTicketAdminFilters(['kind'=>'buyers','q'=>'synthetic@example.test']));
    t18Assert(count($buyers['rows'])===1&&(int)$buyers['rows'][0]['order_count']===2,'buyers scoped aggregations');
    $detail=brvtalTicketAdminDetail($pdo,91);
    t18Assert(count($detail['items'])===2&&count($detail['payments'])===2&&count($detail['mail'])===2,'trace details');
    $pdo->exec('DROP TABLE ticket_mail_history');
    $withoutMail=brvtalTicketAdminPage($pdo,brvtalTicketAdminFilters(['kind'=>'orders','q'=>'91']));
    t18Assert($withoutMail['rows'][0]['mail_state']===null,'mail provider unavailable is not sale unavailable');
} finally { $root->exec('DROP DATABASE IF EXISTS '.$name); }
echo "T18 MariaDB synthetic joins, filters, pagination and degraded mail OK.\n";
