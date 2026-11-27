<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/ticketing_finance.php';
require_once __DIR__.'/../../config/ticketing_staff_permissions.php';
function t20(bool $ok,string $label): void {
    if (!$ok) throw new RuntimeException('T20: '.$label);
}
$orders=[
    ['id'=>1,'event_id'=>9,'status'=>'paid','total_cop'=>90000,'currency'=>'COP'],
    ['id'=>2,'event_id'=>9,'status'=>'pending','total_cop'=>20000,'currency'=>'COP'],
    ['id'=>3,'event_id'=>9,'status'=>'refunded','total_cop'=>10000,'currency'=>'COP'],
    ['id'=>4,'event_id'=>9,'status'=>'paid','total_cop'=>5000,'currency'=>'COP']
];
$items=[
    ['order_id'=>1,'ticket_type_id'=>7,'quantity'=>2,'subtotal_cop'=>100000],
    ['order_id'=>2,'ticket_type_id'=>7,'quantity'=>1,'subtotal_cop'=>20000],
    ['order_id'=>3,'ticket_type_id'=>7,'quantity'=>1,'subtotal_cop'=>10000],
    ['order_id'=>4,'ticket_type_id'=>7,'quantity'=>1,'subtotal_cop'=>5000]
];
$payments=[
    ['id'=>1,'order_id'=>1,'status'=>'approved','provider'=>'wompi','provider_transaction_id'=>'TX-1',
     'gross_amount_cop'=>90000,'fee_amount_cop'=>3000,
     'fee_vat_cop'=>570,'withheld_amount_cop'=>0,'net_amount_cop'=>86430],
    ['id'=>3,'order_id'=>2,'status'=>'pending','provider'=>'wompi','provider_transaction_id'=>null,
     'gross_amount_cop'=>20000,'fee_amount_cop'=>null,
     'fee_vat_cop'=>null,'withheld_amount_cop'=>null,'net_amount_cop'=>null]
];
$r=brvtalFinanceSummarize($orders,$items,$payments);
t20(count($r)===1,'one event');
$s=$r[0];
t20($s['approved_cop']===90000 && $s['gross_cop']===100000 && $s['discount_cop']===10000,
    'COP gross and discount are separate from approved net sales');
t20($s['paid_orders']===1 && $s['pending_orders']===1 && $s['refunded_orders']===1
    && $s['unverified_orders']===1,'one proven sale and unverified order not counted');
t20($s['fee_cop']===3000 && $s['fee_vat_cop']===570 && $s['payout_cop']===86430
    && $s['difference_cop']===0,'actual fee and VAT are read, not guessed');
t20(count($s['transactions'])===1 && $s['transactions'][0]['transaction_id']==='TX-1',
    'one payment evidence for the sale');
$duplicate=$payments[0]; $duplicate['id']=2; $duplicate['provider_transaction_id']='TX-2';
$conflict=brvtalFinanceSummarize([$orders[0]],[$items[0]],[$payments[0],$duplicate])[0];
t20($conflict['paid_orders']===0 && $conflict['unverified_orders']===1
    && $conflict['transactions']===[] && $conflict['net_after_refunds_cop']===null,
    'two approved receipts are not an arbitrary first sale');
t20($s['capacity']===null && $s['checkins']===null,'optional operational fields unknown without database');
t20($s['expenses_cop']===null && $s['profit_cop']===null
    && $s['settlement_status']==='unverified','no invented profitability or reconciliation');
$refundRows=[1=>[
    ['refund_id'=>71,'order_id'=>1,'event_id'=>9,'amount_cop'=>35000,'in_period'=>true],
    ['refund_id'=>71,'order_id'=>1,'event_id'=>9,'amount_cop'=>35000,'in_period'=>true],
]];
$partial=brvtalFinanceSummarize([$orders[0]],[$items[0]],[$payments[0]],[], $refundRows)[0];
t20($partial['gross_cop']===100000 && $partial['approved_cop']===90000
    && $partial['refund_cop']===35000 && $partial['refund_in_period_cop']===35000
    && $partial['net_after_refunds_cop']===55000,'partial confirmed refund counted once, never alters historical gross');
$refundRows[1][]=['refund_id'=>72,'order_id'=>1,'event_id'=>9,
    'amount_cop'=>55000,'in_period'=>false];
$fullyRefunded=$orders[0];$fullyRefunded['status']='refunded';
$full=brvtalFinanceSummarize([$fullyRefunded],[$items[0]],[$payments[0]],[], $refundRows)[0];
t20($full['refunded_orders']===1 && $full['gross_cop']===100000
    && $full['refund_cop']===90000 && $full['net_after_refunds_cop']===0
    && $full['refund_in_period_cop']===35000,'full refund retains gross sale and separate posting cut');
$legacyMissing=brvtalFinanceSummarize([$fullyRefunded],[$items[0]],[$payments[0]])[0];
t20($legacyMissing['gross_cop']===100000 && $legacyMissing['refund_cop']===null
    && $legacyMissing['net_after_refunds_cop']===null,
    'fully refunded Wompi order without confirmed adjustment stays UNKNOWN');
$invalidRefund=brvtalFinanceSummarize([$orders[0]],[$items[0]],[$payments[0]],[],
    [1=>[['refund_id'=>73,'order_id'=>1,'event_id'=>9,'amount_cop'=>90001]]])[0];
t20($invalidRefund['refund_cop']===null && $invalidRefund['net_after_refunds_cop']===null,
    'over-refund cannot be projected as confirmed net');
$noLedger=brvtalFinanceSummarize([$orders[0]],[$items[0]],[$payments[0]],[],[],false)[0];
t20($noLedger['refund_cop']===null && $noLedger['net_after_refunds_cop']===null,
    'unavailable refund ledger stays UNKNOWN');
$payments[0]['fee_vat_cop']=null;
$missing=brvtalFinanceSummarize($orders,$items,$payments)[0];
t20($missing['fee_vat_cop']===null && $missing['difference_cop']===null,
    'missing provider fee VAT remains unknown');
t20(brvtalTicketingCan('admin','finance') && brvtalTicketingCan('admin','export')
    && !brvtalTicketingCan('door','finance') && !brvtalTicketingCan('door','export'),
    'financial RBAC');
$payments[0]['gross_amount_cop']=89999;
$invalid=brvtalFinanceSummarize($orders,$items,$payments)[0];
t20($invalid['paid_orders']===0 && $invalid['unverified_orders']===2,
    'provider gross mismatch cannot certify revenue');
$posOrder=['id'=>5,'event_id'=>9,'status'=>'paid','total_cop'=>20000,'currency'=>'COP'];
$posItem=['order_id'=>5,'ticket_type_id'=>7,'quantity'=>1,'subtotal_cop'=>20000];
$posSale=['id'=>15,'order_id'=>5,'event_id'=>9,'ticket_type_id'=>7,'quantity'=>1,
    'amount_cop'=>20000,'method'=>'cash','status'=>'paid'];
$posRefunded=$posOrder;$posRefunded['status']='refunded';
$posUnknown=brvtalFinanceSummarize([$posRefunded],[$posItem],[],[$posSale])[0];
t20($posUnknown['gross_cop']===20000 && $posUnknown['refund_cop']===null
    && $posUnknown['net_after_refunds_cop']===null,
    'fully refunded POS order without confirmed adjustment stays UNKNOWN');
$summary=brvtalFinanceSummarize([$posOrder],[$posItem],[],[$posSale])[0];
t20($summary['pos_cop']===20000 && $summary['pos_orders']===1
    && $summary['approved_cop']===0 && $summary['paid_orders']===1,
    'POS counted apart from Wompi');
t20($summary['fee_cop']===0 && $summary['payout_cop']===0
    && $summary['transactions'][0]['fee_cop']===null,
    'no invented POS commission or settlement');
$bad=$posSale;$bad['amount_cop']=20001;
$invalidPos=brvtalFinanceSummarize([$posOrder],[$posItem],[],[$bad])[0];
t20($invalidPos['unverified_orders']===1 && $invalidPos['pos_cop']===0,
    'mismatched cash evidence rejected');
$terminal=$posSale;$terminal['method']='terminal';
$terminalSummary=brvtalFinanceSummarize([$posOrder],[$posItem],[],[$terminal])[0];
t20($terminalSummary['pos_cop']===20000,'operator-confirmed terminal accepted without provider fee');
// Real MariaDB negative cases: channel selection can remove all matched orders.
// Only run on a disposable DB with the integration-test flag.
if (getenv('BRVTAL_INTEGRATION_TESTS') === '1') {
    $prefix=(string)(getenv('BRVTAL_TEST_DB_NAME')?:'');
    t20((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/D', $prefix),
        'filtered channel regressions require disposable DB');
    $name=$prefix.'_t20filter_'.bin2hex(random_bytes(4));
    $host=(string)(getenv('BRVTAL_TEST_DB_HOST')?:'127.0.0.1');
    $port=(int)(getenv('BRVTAL_TEST_DB_PORT')?:3306);
    $user=(string)(getenv('BRVTAL_TEST_DB_USER')?:'root');
    $pass=(string)(getenv('BRVTAL_TEST_DB_PASS')?:'');
    $opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false];
    $server=new PDO("mysql:host={$host};port={$port};charset=utf8mb4",$user,$pass,$opts);
    $server->exec('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4');
    try {
        $db=new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
            $user,$pass,$opts);
        foreach ([
            'CREATE TABLE ticket_orders (
                id INT PRIMARY KEY,event_id INT NOT NULL,status VARCHAR(20) NOT NULL,
                total_cop BIGINT NOT NULL,currency CHAR(3) NOT NULL,
                created_at_utc DATETIME(6) NOT NULL) ENGINE=InnoDB',
            'CREATE TABLE ticket_order_items (
                order_id INT NOT NULL,ticket_type_id INT NOT NULL,quantity INT NOT NULL,
                subtotal_cop BIGINT NOT NULL,ticket_name_snapshot VARCHAR(120) NOT NULL DEFAULT "",
                event_id INT,unit_price_cop BIGINT) ENGINE=InnoDB',
            'CREATE TABLE ticket_payment_attempts (
                id INT PRIMARY KEY,order_id INT NOT NULL,provider VARCHAR(20) NOT NULL,
                provider_transaction_id VARCHAR(30),status VARCHAR(20) NOT NULL,
                gross_amount_cop BIGINT,fee_amount_cop BIGINT,fee_vat_cop BIGINT,
                withheld_amount_cop BIGINT,net_amount_cop BIGINT,provider_reference VARCHAR(40)) ENGINE=InnoDB',
            'CREATE TABLE ticket_payment_webhook_events (
                id INT AUTO_INCREMENT PRIMARY KEY,attempt_id INT NOT NULL,provider_transaction_id VARCHAR(30),
                event_checksum VARCHAR(64),UNIQUE KEY proof(attempt_id,provider_transaction_id,event_checksum)) ENGINE=InnoDB',
            'CREATE TABLE ticket_courtesy_invites (order_id INT NOT NULL) ENGINE=InnoDB',
            'CREATE TABLE ticket_pos_shifts (
                id INT PRIMARY KEY,event_id INT NOT NULL,operator_admin_id INT NOT NULL
                ) ENGINE=InnoDB',
            'CREATE TABLE ticket_pos_sales (
                id INT PRIMARY KEY,shift_id INT NOT NULL,event_id INT NOT NULL,
                operator_admin_id INT NOT NULL,order_id INT NOT NULL,
                ticket_type_id INT NOT NULL,quantity INT NOT NULL,amount_cop BIGINT NOT NULL,
                method VARCHAR(20),status VARCHAR(20) NOT NULL,
                completed_at_utc DATETIME(6),unit_price_cop BIGINT) ENGINE=InnoDB',
            "INSERT INTO ticket_orders VALUES
                (41,1,'paid',10000,'COP',UTC_TIMESTAMP(6)),
                (42,2,'paid',20000,'COP',UTC_TIMESTAMP(6))",
            'INSERT INTO ticket_order_items (order_id,ticket_type_id,quantity,subtotal_cop) VALUES (41,7,1,10000),(42,7,1,20000)',
            "INSERT INTO ticket_payment_attempts (id,order_id,provider,provider_transaction_id,status,gross_amount_cop,fee_amount_cop,fee_vat_cop,withheld_amount_cop,net_amount_cop) VALUES
                (51,41,'wompi','WOMPI-41','approved',10000,300,57,0,9643)",
            'INSERT INTO ticket_pos_shifts VALUES (1,2,7)',
            "INSERT INTO ticket_pos_sales (id,shift_id,event_id,operator_admin_id,order_id,ticket_type_id,quantity,amount_cop,method,status,completed_at_utc) VALUES
                (61,1,2,7,42,7,1,20000,'cash','paid',UTC_TIMESTAMP(6))",
        ] as $sql) $db->exec($sql);
        // Synthetic journal and line/price proof expected by the canonical classifier.
        $completeProof=static function () use ($db): void {
            $db->exec("UPDATE ticket_payment_attempts SET provider_reference=CONCAT('FIXTURE-',id)");
            $db->exec("INSERT IGNORE INTO ticket_payment_webhook_events (attempt_id,provider_transaction_id,event_checksum)
                SELECT id,provider_transaction_id,'fixture' FROM ticket_payment_attempts WHERE status='approved'");
            $db->exec("UPDATE ticket_order_items i JOIN ticket_orders o ON o.id=i.order_id
                SET i.event_id=o.event_id,i.unit_price_cop=i.subtotal_cop DIV i.quantity");
            $db->exec("UPDATE ticket_pos_sales SET unit_price_cop=amount_cop DIV quantity");
        };
        $completeProof();
        t20(brvtalFinanceReport($db,1,null,null,'pos')===[],
            'POS channel excludes Wompi-only event without generating IN ()');
        t20(brvtalFinanceReport($db,2,null,null,'wompi')===[],
            'Wompi channel excludes POS-only event without generating IN ()');
        $beforeLedger=brvtalFinanceReport($db,1,null,null,'wompi')[0];
        t20($beforeLedger['refund_cop']===null && $beforeLedger['net_after_refunds_cop']===null,
            'missing adjustment table cannot masquerade as zero');
        $db->exec("CREATE TABLE ticket_refunds (
            id INT PRIMARY KEY,order_id INT NOT NULL,event_id INT NOT NULL,
            status VARCHAR(24) NOT NULL) ENGINE=InnoDB");
        $db->exec("CREATE TABLE ticket_refund_adjustments (
            refund_id INT PRIMARY KEY,order_id INT NOT NULL,event_id INT NOT NULL,
            amount_cop BIGINT NOT NULL,recorded_at_utc DATETIME(6) NOT NULL
            ) ENGINE=InnoDB");
        $db->exec("UPDATE ticket_orders SET created_at_utc='2026-03-10 10:00:00',
            status='refunded' WHERE id=41");
        $db->exec("INSERT INTO ticket_refunds VALUES
            (71,41,1,'refunded'),(72,41,1,'refunded'),(73,41,1,'approved')");
        $db->exec("INSERT INTO ticket_refund_adjustments VALUES
            (71,41,1,4000,'2026-03-12 10:00:00'),
            (72,41,1,6000,'2026-04-02 10:00:00'),
            (73,41,1,123,'2026-03-15 10:00:00')");
        $march=brvtalFinanceReport($db,1,'2026-03-01','2026-03-31','wompi')[0];
        t20($march['gross_cop']===10000 && $march['approved_cop']===10000
            && $march['refund_cop']===4000 && $march['refund_in_period_cop']===4000
            && $march['net_after_refunds_cop']===6000 && $march['refunded_orders']===1,
            'March cohort excludes April refund and unconfirmed adjustment');
        $aprilCut=brvtalFinanceReport($db,1,'2026-03-01','2026-04-30','wompi')[0];
        t20($aprilCut['gross_cop']===10000 && $aprilCut['refund_cop']===10000
            && $aprilCut['net_after_refunds_cop']===0
            && $aprilCut['refund_in_period_cop']===10000,'later full refund changes only adjustment date');
        $posCut=brvtalFinanceReport($db,2,null,null,'pos')[0];
        t20($posCut['pos_cop']===20000 && $posCut['refund_cop']===0
            && $posCut['net_after_refunds_cop']===20000 && $posCut['discrepancies']===[],
            'POS stays separate without Wompi refund');
        $db->exec("ALTER TABLE ticket_pos_sales ADD request_hash CHAR(64)");
        $db->exec("CREATE TABLE ticket_order_notes (id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT,actor_admin_id INT,note_text VARCHAR(255)) ENGINE=InnoDB");
        $db->exec("UPDATE ticket_pos_sales SET method='transfer',request_hash=REPEAT('a',64) WHERE order_id=42");
        $transfer=brvtalFinanceReport($db,2,null,null,'pos')[0];
        t20($transfer['paid_orders']===0 && $transfer['pos_cop']===0
            && $transfer['discrepancies'][0]['reason']==='invalid_pos'
            && $transfer['discrepancies'][0]['authenticated_cop']===null,'transfer without audited note is not a sale');
        $db->exec("INSERT INTO ticket_order_notes (order_id,actor_admin_id,note_text)
            VALUES (42,7,CONCAT('manual-payment|transfer|FIXTURE|',REPEAT('a',64)))");
        $transfer=brvtalFinanceReport($db,2,null,null,'pos')[0];
        t20($transfer['paid_orders']===1 && $transfer['pos_cop']===20000
            && $transfer['discrepancies']===[],'transfer with canonical audited note is a sale');
        t20(brvtalFinanceReport($db,1,'2026-04-01','2026-04-30','wompi')===[],
            'refund in different month does not rewrite sale date');
        // #2044: exact same creation-date cohort as T18, independent refund cut.
        $db->exec("INSERT INTO ticket_orders VALUES
            (43,3,'paid',10000,'COP','2026-11-19 04:59:59.999999'),
            (44,3,'paid',20000,'COP','2026-11-19 05:00:00.000000'),
            (45,3,'paid',30000,'COP','2026-11-20 04:59:59.999999'),
            (46,3,'paid',40000,'COP','2026-11-20 05:00:00.000000')");
        $db->exec('INSERT INTO ticket_order_items (order_id,ticket_type_id,quantity,subtotal_cop) VALUES (43,7,1,10000),(44,7,1,20000),(45,7,1,30000),(46,7,1,40000)');
        $db->exec("INSERT INTO ticket_payment_attempts (id,order_id,provider,provider_transaction_id,status,gross_amount_cop,fee_amount_cop,fee_vat_cop,withheld_amount_cop,net_amount_cop) VALUES
            (53,43,'wompi','WOMPI-43','approved',10000,0,0,0,10000),
            (54,44,'wompi','WOMPI-44','approved',20000,0,0,0,20000),
            (55,45,'wompi','WOMPI-45','approved',30000,0,0,0,30000),
            (56,46,'wompi','WOMPI-46','approved',40000,0,0,0,40000)");
        $db->exec("INSERT INTO ticket_refunds VALUES
            (74,44,3,'refunded'),(75,44,3,'refunded'),(76,44,3,'refunded'),(77,44,3,'refunded')");
        $db->exec("INSERT INTO ticket_refund_adjustments VALUES
            (74,44,3,1000,'2026-11-19 04:59:59.999999'),
            (75,44,3,2000,'2026-11-19 05:00:00.000000'),
            (76,44,3,3000,'2026-11-20 04:59:59.999999'),
            (77,44,3,4000,'2026-11-20 05:00:00.000000')");
        $completeProof();
        $cut=brvtalFinanceReport($db,3,'2026-11-19','2026-11-19','wompi')[0];
        t20($cut['paid_orders']===2 && $cut['gross_cop']===50000
            && $cut['approved_cop']===50000 && $cut['refund_cop']===6000
            && $cut['refund_in_period_cop']===5000 && $cut['net_after_refunds_cop']===44000,
            'Bogota creation cohort matches T18; pre-period adjustment and exclusive end separate');
        $range=brvtalFinanceReport($db,3,'2026-11-19','2026-11-20','wompi')[0];
        t20($range['paid_orders']===3 && $range['approved_cop']===90000
            && $range['refund_cop']===10000 && $range['refund_in_period_cop']===9000,
            'inclusive two-day range with next-day adjustment');
        // #2054: same event, two types, renamed historical snapshots and contradictory evidence.
        $db->exec("INSERT INTO ticket_orders VALUES
            (47,4,'paid',20000,'COP',UTC_TIMESTAMP(6)),(48,4,'paid',30000,'COP',UTC_TIMESTAMP(6)),
            (49,4,'paid',10000,'COP',UTC_TIMESTAMP(6)),(50,4,'paid',40000,'COP',UTC_TIMESTAMP(6)),
            (51,4,'paid',5000,'COP',UTC_TIMESTAMP(6)),(52,4,'paid',30000,'COP',UTC_TIMESTAMP(6))");
        $db->exec("INSERT INTO ticket_order_items (order_id,ticket_type_id,quantity,subtotal_cop,ticket_name_snapshot) VALUES
            (47,8,2,20000,'General'),(48,9,3,30000,'VIP'),
            (49,8,1,10000,'Preventa'),(50,9,4,40000,'VIP'),(51,8,1,5000,''),(52,9,3,30000,'VIP')");
        $db->exec("INSERT INTO ticket_payment_attempts (id,order_id,provider,provider_transaction_id,status,gross_amount_cop,fee_amount_cop,fee_vat_cop,withheld_amount_cop,net_amount_cop) VALUES
            (57,47,'wompi','W-47','approved',20000,0,0,0,20000),
            (58,48,'wompi',NULL,'pending',30000,NULL,NULL,NULL,NULL),
            (59,49,'wompi','W-49','approved',10000,0,0,0,10000),
            (60,50,'wompi','W-50','approved',40000,0,0,0,40000),
            (61,51,'wompi','W-51','approved',5000,0,0,0,5000)");
        $db->exec("INSERT INTO ticket_pos_shifts VALUES (2,4,7)");
        $db->exec("INSERT INTO ticket_pos_sales (id,shift_id,event_id,operator_admin_id,order_id,ticket_type_id,quantity,amount_cop,method,status,completed_at_utc) VALUES
            (62,2,4,7,48,9,3,30000,'cash','paid',UTC_TIMESTAMP(6)),
            (63,2,4,7,50,9,4,40000,'cash','paid',UTC_TIMESTAMP(6)),
            (64,2,4,7,52,9,3,30000,'cash','paid',UTC_TIMESTAMP(6))");
        $completeProof();
        $all=brvtalFinanceReport($db,4,null,null,'all')[0];
        $wompi=brvtalFinanceReport($db,4,null,null,'wompi')[0];
        $cash=brvtalFinanceReport($db,4,null,null,'pos')[0];
        t20($all['approved_cop']===35000 && $all['pos_cop']===30000 && $all['tickets']===7
            && $all['paid_orders']===4 && $all['unverified_orders']===2,'mixed sources count only verified sales');
        t20($wompi['approved_cop']===35000 && $wompi['pos_cop']===0 && $wompi['tickets']===4
            && $cash['approved_cop']===0 && $cash['pos_cop']===30000 && $cash['tickets']===3,
            'pending Wompi plus POS remains a conflict, not a verified sale in either filter');
        foreach ([$all,$wompi,$cash] as $cohort) {
            t20($cohort['unverified_orders']===2 && $cohort['refund_cop']===null
                && $cohort['net_after_refunds_cop']===null,'both filters preserve conflicting evidence as UNKNOWN');
        }
        $incidents=array_column($all['discrepancies'],null,'order_id');
        t20(count($incidents)===2 && $incidents[48]['authenticated_cop']===null
            && $incidents[50]['authenticated_cop']===40000
            && $incidents[50]['wompi_receipts']===[['transaction_id'=>'W-50','gross_cop'=>40000]],
            'pending POS conflict and authenticated conflicting payment remain separate from income');
        foreach ([$wompi,$cash] as $cohort) {
            t20(array_column($cohort['discrepancies'],null,'order_id')===$incidents,
                'same incident evidence independent of display filter');
        }
        t20($all['types'][0]===['ticket_type_id'=>8,'quantity'=>4,'gross_cop'=>35000,
            'ticket_names'=>['General','Preventa'],'unnamed_quantity'=>1]
            && $all['types'][1]===['ticket_type_id'=>9,'quantity'=>3,'gross_cop'=>30000,
            'ticket_names'=>['VIP'],'unnamed_quantity'=>0],
            'historical aliases and missing names grouped by ID without duplicate quantities');
        // Check membership and complete evidence of the same orders, not just totals.
        $candidates=$db->query('SELECT * FROM ticket_orders WHERE event_id=4 ORDER BY id')->fetchAll();
        $evidence=brvtalFinanceChannelEvidence($db,$candidates);
        $byOrder=$evidence['by_order'];
        foreach (['all','wompi','pos'] as $channel) {
            $selected=brvtalFinanceFilterOrders($candidates,$byOrder,$channel);
            $selectedIds=array_map(intval(...),array_column($selected,'id'));
            t20(in_array(48,$selectedIds,true) && in_array(50,$selectedIds,true),
                'conflict remains in every relevant cohort');
            foreach ([48,50] as $id) {
                t20($byOrder[$id]['classification']['reason']==='channel_conflict'
                    && count($byOrder[$id]['attempts'])===1 && count($byOrder[$id]['pos_sales'])===1,
                    'all source rows survive filtering');
            }
        }
        $db->exec("INSERT INTO ticket_payment_attempts (id,order_id,provider,provider_transaction_id,status,gross_amount_cop)
            VALUES (62,47,'wompi','W-47-SECOND','approved',20000),(63,52,'other',NULL,'pending',30000)");
        $completeProof();
        $db->exec("INSERT INTO ticket_pos_sales (id,shift_id,event_id,operator_admin_id,order_id,ticket_type_id,
            quantity,amount_cop,method,status,completed_at_utc,unit_price_cop)
            VALUES (65,999,4,7,49,8,1,10000,'cash','pending',NULL,10000)");
        $evidence=brvtalFinanceChannelEvidence($db,$candidates)['by_order'];
        t20(count($evidence[47]['attempts'])===2
            && $evidence[47]['classification']['reason']==='duplicate_wompi','duplicate retained');
        t20($evidence[52]['classification']['reason']==='channel_conflict'
            && $evidence[52]['attempts'][0]['provider']==='other','all providers retained');
        t20($evidence[49]['classification']['reason']==='channel_conflict'
            && count($evidence[49]['pos_sales'])===1,'incomplete POS not erased by join');
        $db->exec("INSERT INTO ticket_payment_webhook_events (attempt_id,provider_transaction_id,event_checksum)
            VALUES (57,'W-47','replay')");
        $mixed=brvtalFinanceReport($db,4,null,null,'wompi')[0];
        $incidents=array_column($mixed['discrepancies'],null,'order_id');
        t20($incidents[47]['reason']==='duplicate_wompi'
            && $incidents[47]['authenticated_cop']===40000
            && $incidents[47]['wompi_receipts']===[
                ['transaction_id'=>'W-47','gross_cop'=>20000],
                ['transaction_id'=>'W-47-SECOND','gross_cop'=>20000]]
            && $mixed['paid_orders']===1 && $mixed['approved_cop']===5000,
            'distinct authentic payments reported separately; journal replay never counts twice');
        $db->exec("INSERT INTO ticket_payment_attempts
            (id,order_id,provider,provider_transaction_id,status,gross_amount_cop,provider_reference)
            VALUES (64,47,'wompi','W-47-UNPROVEN','approved',20000,'FIXTURE-64')");
        $unproven=array_column(brvtalFinanceReport($db,4,null,null,'all')[0]['discrepancies'],null,'order_id')[47];
        t20($unproven['authenticated_cop']===null && $unproven['unproven_attempts']===1
            && count($unproven['wompi_receipts'])===2,'unknown total with two known receipts and unproven attempt');
        $db->exec("DELETE FROM ticket_payment_attempts WHERE id=64");
        $db->exec('RENAME TABLE ticket_payment_webhook_events TO journal_unavailable');
        $unknown=brvtalFinanceChannelEvidence($db,$candidates)['by_order'][51];
        t20(!$unknown['availability']['wompi_events']
            && $unknown['classification']['state']==='unavailable','missing signed journal is unknown');
        foreach (['all','wompi'] as $channel) {
            $unavailable=brvtalFinanceReport($db,4,null,null,$channel)[0];
            t20($unavailable['paid_orders']===0 && $unavailable['net_after_refunds_cop']===null,
                'missing proof never becomes verified zero');
            $incidents=array_column($unavailable['discrepancies'],null,'order_id');
            t20($incidents[51]['authenticated_cop']===null && $incidents[51]['wompi_receipts']===[],
                'journal unavailable does not create an authenticated receipt');
        }
        $db->exec('RENAME TABLE journal_unavailable TO ticket_payment_webhook_events');
        t20(brvtalFinanceReport($db,999,null,null,'all')===[] && !$db->inTransaction(),
            'empty universe releases report transaction');
        $db->beginTransaction();
        t20(brvtalFinanceReport($db,999,null,null,'all')===[] && $db->inTransaction(),
            'report never commits a caller-owned transaction');
        $db->rollBack();
        // The 5000-order cap is applied to the universe, not only selected rows.
        $oversized=[];
        for ($id=1000; $id<6001; $id++) {
            $oversized[]="($id,99,'pending',1000,'COP',UTC_TIMESTAMP(6))";
        }
        $db->exec('INSERT INTO ticket_orders VALUES '.implode(',',$oversized));
        foreach (['all','wompi','pos'] as $channel) {
            try {
                brvtalFinanceReport($db,99,null,null,$channel);
                throw new RuntimeException('T20: report limit bypassed by filter');
            } catch (LengthException $e) {
                t20($e->getMessage()==='REPORT_RANGE_TOO_LARGE','universe limit preserved');
            }
        }
        $db->exec('DELETE FROM ticket_orders WHERE event_id=99');
        foreach ([['2026-02-30','2026-03-01'],['2026-11-20','2026-11-19']] as [$from,$to]) {
            try {
                brvtalFinanceReport($db,3,$from,$to,'wompi');
                throw new RuntimeException('T20: accepted invalid calendar range');
            } catch (InvalidArgumentException) { /* expected */ }
        }
        // A missing POS ledger cannot be treated as empty when checking Wompi.
        $db->exec('RENAME TABLE ticket_pos_sales TO ticket_pos_sales_unavailable');
        foreach (['all','wompi','pos'] as $channel) {
            try {
                brvtalFinanceReport($db,4,null,null,$channel);
                throw new RuntimeException('T20: finance accepted missing POS evidence');
            } catch (PDOException $e) {
                t20($e->getCode()==='42S02' && !$db->inTransaction(),
                    'missing POS table fails closed and rolls back report snapshot');
            }
        }
    } finally {
        $server->exec('DROP DATABASE IF EXISTS '.$name);
    }
}

echo "T20 finance + POS synthetic invariants OK\n";
// T21 synthetic MariaDB regressions execute in the existing finance integration harness.
require __DIR__.'/ticketing-refunds.php';

// #2190: real, disposable database; no live or provider credentials.
if (getenv('BRVTAL_INTEGRATION_TESTS') === '1') {
    t20((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/D', (string)getenv('BRVTAL_TEST_DB_NAME')),
        'ledger regression requires a disposable database');
    $ledgerDsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4',
        getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1', (int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306));
    $ledgerUser = (string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root');
    $ledgerPass = (string)(getenv('BRVTAL_TEST_DB_PASS') ?: '');
    $ledgerOptions = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false];
    $ledgerServer = new PDO($ledgerDsn, $ledgerUser, $ledgerPass, $ledgerOptions);
    $ledgerName = 'brvtal_test_ledger_' . bin2hex(random_bytes(6));
    $ledgerServer->exec('CREATE DATABASE ' . $ledgerName . ' CHARACTER SET utf8mb4');
    try {
        $ledger = new PDO($ledgerDsn . ';dbname=' . $ledgerName, $ledgerUser, $ledgerPass, $ledgerOptions);
        $ledger->exec('CREATE TABLE events (id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
        $ledger->exec('CREATE TABLE admins (id INT UNSIGNED PRIMARY KEY, staff_role VARCHAR(32), is_active TINYINT) ENGINE=InnoDB');
        $ledger->exec("INSERT INTO events VALUES (1),(2)");
        $ledger->exec("INSERT INTO admins VALUES (1,'admin',1),(2,'door',1),(3,'admin',0)");
        foreach (['migration_admin_activity_01.sql','migration_ticketing_finance_expenses_01.sql'] as $file) {
            $ledger->exec((string)file_get_contents(__DIR__.'/../../database/'.$file));
        }
        $expense = ['category'=>'artistas','concept'=>'Synthetic artist','amount_cop'=>150000,
            'entry_date'=>'2026-11-27','status'=>'committed'];
        $first = brvtalFinanceLedgerWrite($ledger,'expense',1,1,'create',null,$expense,'initial');
        $paid = array_replace($expense,['status'=>'paid','amount_cop'=>160000]);
        $second = brvtalFinanceLedgerWrite($ledger,'expense',1,1,'adjust',$first,$paid,'corrected');
        $third = brvtalFinanceLedgerWrite($ledger,'expense',1,1,'void',$second,[],'cancelled');
        $rows = $ledger->query('SELECT * FROM event_expenses ORDER BY id')->fetchAll();
        t20(count($rows)===3 && (int)$rows[0]['amount_cop']===150000
            && (int)$rows[1]['supersedes_id']===$first && $rows[1]['status']==='paid'
            && (int)$rows[2]['supersedes_id']===$second && $rows[2]['status']==='void'
            && (int)$rows[2]['amount_cop']===160000 && $rows[0]['entry_date']==='2026-11-27',
            'expense history and Bogotá calendar date preserved');
        $audit = $ledger->query("SELECT * FROM admin_activity_log WHERE resource='event_expenses' ORDER BY id")->fetchAll();
        t20(count($audit)===3 && (int)$audit[1]['admin_id']===1
            && (int)$audit[2]['resource_id']===$third
            && json_decode($audit[1]['meta_json'],true)['reason']==='corrected','atomic actor and reason audit');
        $budget = ['category'=>'artistas','amount_cop'=>200000,'entry_date'=>'2026-11-27','status'=>'active'];
        $b = brvtalFinanceLedgerWrite($ledger,'budget',1,1,'create',null,$budget,'initial');
        $b2 = brvtalFinanceLedgerWrite($ledger,'budget',1,1,'adjust',$b,array_replace($budget,['amount_cop'=>0]),'adjusted');
        brvtalFinanceLedgerWrite($ledger,'budget',1,1,'create',null,array_replace($budget,['category'=>'lugar']),'venue');
        brvtalFinanceLedgerWrite($ledger,'budget',2,1,'create',null,$budget,'other event');
        t20((int)$ledger->query('SELECT COUNT(*) FROM event_budgets')->fetchColumn()===4,'budget per event/category and revisions');
        $reject = static function (callable $f, string $expected): void {
            try { $f(); } catch (InvalidArgumentException|DomainException $e) {
                t20($e->getMessage()===$expected,'ledger rejection '.$expected); return;
            }
            throw new RuntimeException('T20: ledger unexpectedly accepted '.$expected);
        };
        foreach ([0,-1,1.5,'100',true,PHP_INT_MAX+1] as $bad) {
            $reject(fn()=>brvtalFinanceLedgerWrite($ledger,'expense',1,1,'create',null,
                array_replace($expense,['amount_cop'=>$bad]),'bad amount'),'INVALID_LEDGER_VALUES');
        }
        $reject(fn()=>brvtalFinanceLedgerWrite($ledger,'expense',1,1,'create',null,
            array_replace($expense,['entry_date'=>'2026-02-30']),'bad date'),'INVALID_DATE');
        $reject(fn()=>brvtalFinanceLedgerWrite($ledger,'expense',1,1,'adjust',$first,$paid,'stale'),'LEDGER_STALE_REVISION');
        $reject(fn()=>brvtalFinanceLedgerWrite($ledger,'expense',2,1,'void',$third,[],'wrong event'),'LEDGER_STALE_REVISION');
        $reject(fn()=>brvtalFinanceLedgerWrite($ledger,'budget',1,1,'create',null,$budget,'duplicate'),'LEDGER_BUDGET_EXISTS');
        $reject(fn()=>brvtalFinanceLedgerWrite($ledger,'budget',1,1,'adjust',$b2,
            array_replace($budget,['category'=>'lugar']),'wrong category'),'LEDGER_BUDGET_CATEGORY_IMMUTABLE');
        $b3 = brvtalFinanceLedgerWrite($ledger,'budget',1,1,'void',$b2,[],'budget void');
        $budgetRows = $ledger->query("SELECT * FROM event_budgets WHERE id IN ($b,$b2,$b3) ORDER BY id")->fetchAll();
        t20(count($budgetRows)===3 && (int)$budgetRows[0]['amount_cop']===200000
            && (int)$budgetRows[1]['amount_cop']===0 && $budgetRows[2]['status']==='void',
            'budget void preserves original and zero revision');
        brvtalFinanceLedgerWrite($ledger,'budget',1,1,'create',null,$budget,'new budget after void');
        foreach ([2,3] as $actor) {
            $reject(fn()=>brvtalFinanceLedgerWrite($ledger,'expense',1,$actor,'create',null,$expense,'forbidden'),'LEDGER_FORBIDDEN');
        }
        $ledger->exec('RENAME TABLE admin_activity_log TO ledger_audit_unavailable');
        try {
            brvtalFinanceLedgerWrite($ledger,'expense',1,1,'create',null,$expense,'must roll back');
            throw new RuntimeException('T20: audit missing but write committed');
        } catch (PDOException $e) {
            t20(!$ledger->inTransaction() && (int)$ledger->query('SELECT COUNT(*) FROM event_expenses')->fetchColumn()===3,
                'missing audit rolls back ledger write');
        }
    } finally {
        $ledgerServer->exec('DROP DATABASE ' . $ledgerName);
    }
}
