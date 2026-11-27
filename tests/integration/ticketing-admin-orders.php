<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/ticketing_admin_orders.php';
function t18Assert(bool $ok,string $why):void {if(!$ok)throw new RuntimeException('T18: '.$why);}
foreach ([['kind'=>'other'],['status'=>'approved'],['event_id'=>'1 OR 1=1'],
          ['from'=>'2026-02-30'],['from'=>'2026-11-20','to'=>'2026-11-19'],['q'=>str_repeat('x',121)],
          ['email'=>['one@example.test']],['format'=>'csv','order_id'=>'91'],
          ['format'=>'csv','export_scope'=>'all','cursor'=>'91'],['export_scope'=>'all']] as $bad) {
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
        'CREATE TABLE ticket_orders (id BIGINT PRIMARY KEY,buyer_id BIGINT,event_id INT,status VARCHAR(20),currency CHAR(3),total_cop BIGINT,created_at_utc DATETIME(6))',
        'CREATE TABLE ticket_order_items (id BIGINT PRIMARY KEY,order_id BIGINT,ticket_name_snapshot VARCHAR(100),quantity INT,unit_price_cop BIGINT,subtotal_cop BIGINT)',
        'CREATE TABLE ticket_payment_attempts (id BIGINT PRIMARY KEY,order_id BIGINT,provider VARCHAR(20),status VARCHAR(20),gross_amount_cop BIGINT,created_at_utc DATETIME,provider_reference VARCHAR(190) NULL,provider_transaction_id VARCHAR(190) NULL)',
        'CREATE TABLE ticket_entries (id BIGINT PRIMARY KEY,order_item_id BIGINT)',
        'CREATE TABLE ticket_mail_history (id BIGINT PRIMARY KEY,order_id BIGINT,delivery_state VARCHAR(20),created_at DATETIME)',
        "INSERT INTO ticket_buyers VALUES (3,'synthetic@example.test','active','2026-11-01'),(4,'other@example.test','guest','2026-11-01')",
        "INSERT INTO ticket_orders VALUES (91,3,6,'paid','COP',75000,'2026-11-19'),(92,3,7,'pending','COP',20000,'2026-11-18'),(93,4,6,'refunded','COP',10000,'2026-11-17')",
        "INSERT INTO ticket_order_items VALUES (1,91,'General',2,30000,60000),(2,91,'VIP',1,15000,15000)",
        "INSERT INTO ticket_payment_attempts VALUES (1,91,'wompi','pending',75000,'2026-11-19','BW7-91-CARD-aaaaaaaaaaaaaaaaaaaaaaaa',NULL),(2,91,'wompi','approved',75000,'2026-11-19','BW7-91-CARD-bbbbbbbbbbbbbbbbbbbbbbbb','tx-91')",
        'INSERT INTO ticket_entries VALUES (1,1),(2,1),(3,2)',
        "INSERT INTO ticket_mail_history VALUES (1,91,'delivered','2026-11-18'),(2,91,'failed','2026-11-19')",
    ] as $sql) $pdo->exec($sql);
    $page=brvtalTicketAdminPage($pdo,brvtalTicketAdminFilters(['kind'=>'orders','event_id'=>'6']));
    t18Assert(count($page['rows'])===2,'no row multiplication from item/payment/mail joins');
    $o=array_first(array_filter($page['rows'],static fn($x)=>(int)$x['id']===91));
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
    // #1950: staff can cross-check each payment with Wompi.
    t18Assert($detail['payments'][0]['provider_reference']==='BW7-91-CARD-bbbbbbbbbbbbbbbbbbbbbbbb'
        &&$detail['payments'][0]['provider_transaction_id']==='tx-91'
        &&$detail['payments'][1]['provider_transaction_id']===null,'payment reference and transaction id');
    $pdo->exec('DROP TABLE ticket_mail_history');
    $withoutMail=brvtalTicketAdminPage($pdo,brvtalTicketAdminFilters(['kind'=>'orders','q'=>'91']));
    t18Assert($withoutMail['rows'][0]['mail_state']===null,'mail provider unavailable is not sale unavailable');

    // #2041: synthetic POS/courtesy/Wompi proof; real data and payment writes are forbidden.
    foreach ([
        'ALTER TABLE ticket_order_items ADD COLUMN event_id INT NOT NULL DEFAULT 0, ADD COLUMN ticket_type_id INT NOT NULL DEFAULT 0',
        'UPDATE ticket_order_items SET event_id=6,ticket_type_id=IF(id=1,7,8)',
        'CREATE TABLE ticket_pos_shifts (id BIGINT PRIMARY KEY,event_id INT,operator_admin_id INT)',
        'CREATE TABLE ticket_pos_sales (id BIGINT PRIMARY KEY,shift_id BIGINT,event_id INT,operator_admin_id INT,ticket_type_id INT,order_id BIGINT,quantity INT,unit_price_cop BIGINT,amount_cop BIGINT,method VARCHAR(20),status VARCHAR(20),completed_at_utc DATETIME)',
        'CREATE TABLE ticket_courtesy_invites (id BIGINT PRIMARY KEY,order_id BIGINT,status VARCHAR(20))',
        'CREATE TABLE ticket_payment_webhook_events (id BIGINT PRIMARY KEY,attempt_id BIGINT,provider_transaction_id VARCHAR(190))',
        "INSERT INTO ticket_orders VALUES (94,3,6,'paid','COP',12000,'2026-11-19'),(95,3,6,'paid','COP',12000,'2026-11-19'),(96,3,6,'paid','COP',12000,'2026-11-19'),(97,3,6,'paid','COP',12000,'2026-11-19'),(98,4,6,'draft','COP',0,'2026-11-19'),(99,3,6,'paid','COP',12000,'2026-11-19'),(100,3,6,'paid','COP',12000,'2026-11-19'),(101,3,6,'paid','COP',12000,'2026-11-19')",
        "INSERT INTO ticket_order_items VALUES (3,94,'General',1,12000,12000,6,9),(4,96,'General',1,12000,12000,6,9),(5,98,'Cortesía',1,0,0,6,9),(6,100,'General',1,11000,11000,6,9),(7,101,'General',1,12000,12000,6,9)",
        "INSERT INTO ticket_pos_shifts VALUES (1,6,12)",
        "INSERT INTO ticket_pos_sales VALUES (1,1,6,12,9,94,1,12000,12000,'cash','paid','2026-11-19'),(2,1,6,12,9,96,1,12000,12000,'cash','paid','2026-11-19'),(3,1,6,12,9,100,1,11000,11000,'cash','paid','2026-11-19'),(4,1,6,12,9,101,1,12000,12000,'transfer','paid','2026-11-19')",
        "INSERT INTO ticket_courtesy_invites VALUES (1,98,'issued')",
        "INSERT INTO ticket_payment_attempts VALUES (3,95,'wompi','approved',12000,'2026-11-19','BW7-95-CARD-aaaaaaaaaaaaaaaaaaaaaaaa','tx-95-a'),(4,95,'wompi','approved',12000,'2026-11-19','BW7-95-CARD-bbbbbbbbbbbbbbbbbbbbbbbb','tx-95-b'),(5,96,'wompi','pending',12000,'2026-11-19','BW7-96-CARD-cccccccccccccccccccccccc',NULL),(6,97,'wompi','approved',11000,'2026-11-19','BW7-97-CARD-dddddddddddddddddddddddd','tx-97')",
        "INSERT INTO ticket_payment_webhook_events VALUES (1,2,'tx-91'),(2,3,'tx-95-a'),(3,4,'tx-95-b'),(4,6,'tx-97')",
    ] as $sql) $pdo->exec($sql);
    $get=$pdo->prepare('SELECT id,status,total_cop,currency FROM ticket_orders WHERE id=?');
    $cases=[
        91=>['wompi','verified',false],92=>['none','no_payment',false],
        94=>['pos','registered_offline',false],95=>['conflict','discrepancy',true],
        96=>['conflict','discrepancy',true],97=>['conflict','discrepancy',true],
        98=>['courtesy','courtesy',false],99=>['conflict','discrepancy',true],
        100=>['conflict','discrepancy',true],101=>['unknown','unavailable',false],
    ];
    $schema=brvtalTicketAdminPaymentSchema($pdo);
    foreach ($cases as $id=>[$channel,$state,$alert]) {
        $get->execute([$id]); $raw=$get->fetch(PDO::FETCH_ASSOC);
        $proof=brvtalTicketAdminPaymentEvidence($pdo,$raw,$schema);
        t18Assert($proof['channel']===$channel && $proof['state']===$state
            && $proof['anomaly']===$alert,'payment evidence order '.$id);
    }
    $posPage=brvtalTicketAdminPage($pdo,brvtalTicketAdminFilters(['kind'=>'orders','q'=>'94']));
    $posDetail=brvtalTicketAdminDetail($pdo,94);
    t18Assert($posPage['rows'][0]['payment_evidence']===$posDetail['payment_evidence'],
        'POS row and detail use the same trusted classification');
    t18Assert((int)$posPage['rows'][0]['approvals']===0
        && $posPage['rows'][0]['payment_evidence']['anomaly']===false,
        'valid POS has zero Wompi approvals without a false alarm');
    $pdo->exec('DROP TABLE ticket_pos_sales');
    $get->execute([94]); $missingPos=$get->fetch(PDO::FETCH_ASSOC);
    t18Assert(brvtalTicketAdminPaymentEvidence($pdo,$missingPos)['state']==='unavailable',
        'missing POS schema does not invent a failed payment');
    $get->execute([91]); $validWompi=$get->fetch(PDO::FETCH_ASSOC);
    t18Assert(brvtalTicketAdminPaymentEvidence($pdo,$validWompi)['channel']==='wompi',
        'Wompi proof survives optional POS schema absence');
    $pdo->exec('DROP TABLE ticket_courtesy_invites');
    $get->execute([98]); $missingCourtesy=$get->fetch(PDO::FETCH_ASSOC);
    t18Assert(brvtalTicketAdminPaymentEvidence($pdo,$missingCourtesy)['state']==='unavailable',
        'missing courtesy table remains unknown, not a paid order');
    $pdo->exec('DROP TABLE ticket_payment_webhook_events');
    t18Assert(brvtalTicketAdminPaymentEvidence($pdo,$validWompi)['state']==='unavailable',
        'approved without verified Wompi ledger is never certified');

    // #2044: same creation-date cohort as T20, including microsecond edges.
    $pdo->exec("INSERT INTO ticket_orders VALUES
        (102,3,9,'paid','COP',10000,'2026-11-19 04:59:59.999999'),
        (103,3,9,'paid','COP',20000,'2026-11-19 05:00:00.000000'),
        (104,4,9,'paid','COP',30000,'2026-11-20 04:59:59.999999'),
        (105,4,9,'paid','COP',40000,'2026-11-20 05:00:00.000000')");
    $calendar=['kind'=>'orders','event_id'=>'9','from'=>'2026-11-19','to'=>'2026-11-19'];
    $cut=brvtalTicketAdminPage($pdo,brvtalTicketAdminFilters($calendar))['rows'];
    t18Assert(array_map(intval(...),array_column($cut,'id'))===[104,103]
        && array_sum(array_column($cut,'total_cop'))===50000,'Bogota inclusive day, UTC half-open');
    $calendar['to']='2026-11-20';
    $cut=brvtalTicketAdminPage($pdo,brvtalTicketAdminFilters($calendar))['rows'];
    t18Assert(array_map(intval(...),array_column($cut,'id'))===[105,104,103],'inclusive two-day range');
    $calendar['to']='2026-11-19'; $calendar['email']='synthetic@example.test';
    $cut=brvtalTicketAdminPage($pdo,brvtalTicketAdminFilters($calendar))['rows'];
    t18Assert(array_map(intval(...),array_column($cut,'id'))===[103],'calendar retains buyer filter');
    $legacy=brvtalTicketAdminPage($pdo,brvtalTicketAdminFilters(
        ['kind'=>'buyers','from'=>'2026-11-01','to'=>'2026-11-01']))['rows'];
    t18Assert(array_map(intval(...),array_column($legacy,'id'))===[4,3],'legacy buyer creation scope preserved');

    // #2043: a filtered synthetic snapshot is larger than one page.
    $savedConfig = $GLOBALS['config'] ?? null;
    $GLOBALS['config'] = [];
    t18Assert(!brvtalTicketAdminFullExportEnabled(),'full export disabled by default');
    $GLOBALS['config'] = $savedConfig;
    $insert=$pdo->prepare("INSERT INTO ticket_orders VALUES (?,3,12,'pending','COP',1200,'2026-11-19 05:00:00')");
    for ($id=200; $id<262; ++$id) $insert->execute([$id]);
    $filters=['kind'=>'orders','event_id'=>'12','status'=>'pending','from'=>'2026-11-19','to'=>'2026-11-19','email'=>'synthetic@example.test'];
    $list=brvtalTicketAdminFilters($filters+['limit'=>'50']);
    $first=brvtalTicketAdminPage($pdo,$list,false);
    $list['cursor']=$first['next_cursor'];
    $second=brvtalTicketAdminPage($pdo,$list,false);
    $expected=array_map(intval(...),array_column(array_merge($first['rows'],$second['rows']),'id'));
    $audit=[];
    $record=static function(array $meta) use (&$audit):void {$audit[]=$meta;};
    $full=brvtalTicketAdminFilters($filters+['format'=>'csv','export_scope'=>'all']);
    $export=brvtalTicketAdminExport($pdo,$full,$record);
    $lines=explode("\n",trim($export['csv']));
    $ids=array_map(static fn($line)=>(int)str_getcsv($line,escape:'')[0],array_slice($lines,1));
    t18Assert($export['count']===62 && $ids===$expected && count(array_unique($ids))===62,'full export equals filtered pages');
    t18Assert($audit[0]['count']===62 && $audit[0]['event_id']===12
        && !isset($audit[0]['email'],$audit[0]['q']) && !str_contains(json_encode($audit),'example.test'),'minimal audit');
    t18Assert(!str_contains($export['csv'],'example.test') && !str_contains($export['csv'],'tx-91'),'CSV allowlist');
    $pageExport=brvtalTicketAdminExport($pdo,brvtalTicketAdminFilters($filters+['format'=>'csv','limit'=>'50','cursor'=>(string)$first['next_cursor']]),$record);
    t18Assert($pageExport['count']===12,'explicit page cursor');
    $buyer=brvtalTicketAdminExport($pdo,brvtalTicketAdminFilters(['kind'=>'buyers','event_id'=>'12','format'=>'csv','export_scope'=>'all']),$record);
    t18Assert($buyer['count']===1 && str_starts_with($buyer['csv'],"id,status,order_count,paid_total_cop\n"),'buyer event scope');
    foreach (['=1+1',' +1','-1','@SUM(1)',"\ttext",'＝1'] as $hostile) {
        t18Assert(brvtalTicketAdminCsvCell($hostile)==="'".$hostile,'formula neutralized');
    }
    $before=count($audit);
    for ($id=262; $id<=1200; ++$id) $insert->execute([$id]);
    try {brvtalTicketAdminExport($pdo,$full,$record);throw new RuntimeException('truncated CSV');}
    catch (LengthException) {t18Assert(count($audit)===$before&&!$pdo->inTransaction(),'limit fails before audit or CSV');}
    try {
        brvtalTicketAdminExport($pdo,brvtalTicketAdminFilters($filters+['format'=>'csv']),static function():void {throw new DomainException('audit unavailable');});
        throw new RuntimeException('unaudited CSV');
    } catch (DomainException) {t18Assert(!$pdo->inTransaction(),'audit failure prevents delivery');}
} finally { $root->exec('DROP DATABASE IF EXISTS '.$name); }
echo "T18 MariaDB synthetic joins, filters, pagination and degraded mail OK.\n";

// #2082: isolate the courtesy read fixture and require the real MariaDB receipt.
$courtesyProcess = proc_open([PHP_BINARY, __DIR__.'/ticketing-admin-courtesy.php'],
    [0=>STDIN,1=>['pipe','w'],2=>STDERR], $courtesyPipes);
t18Assert(is_resource($courtesyProcess),'courtesy read fixture started');
$courtesyOutput=stream_get_contents($courtesyPipes[1]);
fclose($courtesyPipes[1]);
t18Assert(proc_close($courtesyProcess)===0,'courtesy read fixture passed');
$courtesyReceipt='BRVTAL courtesy admin read MariaDB PASS.';
t18Assert(is_string($courtesyOutput)
    && in_array($courtesyReceipt,preg_split('/\R/',trim($courtesyOutput))?:[],true),'courtesy MariaDB receipt required');
echo $courtesyReceipt."\n";
