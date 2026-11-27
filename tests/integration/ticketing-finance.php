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
    ['id'=>2,'order_id'=>1,'status'=>'approved','provider'=>'wompi','provider_transaction_id'=>'TX-2',
     'gross_amount_cop'=>90000,'fee_amount_cop'=>9999,
     'fee_vat_cop'=>0,'withheld_amount_cop'=>0,'net_amount_cop'=>0],
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
    && $s['unverified_orders']===1,'duplicate webhook and unverified order not double counted');
t20($s['fee_cop']===3000 && $s['fee_vat_cop']===570 && $s['payout_cop']===86430
    && $s['difference_cop']===0,'actual fee and VAT are read, not guessed');
t20(count($s['transactions'])===1 && $s['transactions'][0]['transaction_id']==='TX-1',
    'one payment evidence for repeated approvals');
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
                subtotal_cop BIGINT NOT NULL,ticket_name_snapshot VARCHAR(120) NOT NULL DEFAULT "") ENGINE=InnoDB',
            'CREATE TABLE ticket_payment_attempts (
                id INT PRIMARY KEY,order_id INT NOT NULL,provider VARCHAR(20) NOT NULL,
                provider_transaction_id VARCHAR(30),status VARCHAR(20) NOT NULL,
                gross_amount_cop BIGINT,fee_amount_cop BIGINT,fee_vat_cop BIGINT,
                withheld_amount_cop BIGINT,net_amount_cop BIGINT) ENGINE=InnoDB',
            'CREATE TABLE ticket_pos_shifts (
                id INT PRIMARY KEY,event_id INT NOT NULL,operator_admin_id INT NOT NULL
                ) ENGINE=InnoDB',
            'CREATE TABLE ticket_pos_sales (
                id INT PRIMARY KEY,shift_id INT NOT NULL,event_id INT NOT NULL,
                operator_admin_id INT NOT NULL,order_id INT NOT NULL,
                ticket_type_id INT NOT NULL,quantity INT NOT NULL,amount_cop BIGINT NOT NULL,
                method VARCHAR(20),status VARCHAR(20) NOT NULL,
                completed_at_utc DATETIME(6)) ENGINE=InnoDB',
            "INSERT INTO ticket_orders VALUES
                (41,1,'paid',10000,'COP',UTC_TIMESTAMP(6)),
                (42,2,'paid',20000,'COP',UTC_TIMESTAMP(6))",
            'INSERT INTO ticket_order_items (order_id,ticket_type_id,quantity,subtotal_cop) VALUES (41,7,1,10000),(42,7,1,20000)',
            "INSERT INTO ticket_payment_attempts VALUES
                (51,41,'wompi','WOMPI-41','approved',10000,300,57,0,9643)",
            'INSERT INTO ticket_pos_shifts VALUES (1,2,7)',
            "INSERT INTO ticket_pos_sales VALUES
                (61,1,2,7,42,7,1,20000,'cash','paid',UTC_TIMESTAMP(6))",
        ] as $sql) $db->exec($sql);
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
            && $posCut['net_after_refunds_cop']===20000,'POS stays separate without Wompi refund');
        t20(brvtalFinanceReport($db,1,'2026-04-01','2026-04-30','wompi')===[],
            'refund in different month does not rewrite sale date');
        // #2044: exact same creation-date cohort as T18, independent refund cut.
        $db->exec("INSERT INTO ticket_orders VALUES
            (43,3,'paid',10000,'COP','2026-11-19 04:59:59.999999'),
            (44,3,'paid',20000,'COP','2026-11-19 05:00:00.000000'),
            (45,3,'paid',30000,'COP','2026-11-20 04:59:59.999999'),
            (46,3,'paid',40000,'COP','2026-11-20 05:00:00.000000')");
        $db->exec('INSERT INTO ticket_order_items (order_id,ticket_type_id,quantity,subtotal_cop) VALUES (43,7,1,10000),(44,7,1,20000),(45,7,1,30000),(46,7,1,40000)');
        $db->exec("INSERT INTO ticket_payment_attempts VALUES
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
            (51,4,'paid',5000,'COP',UTC_TIMESTAMP(6))");
        $db->exec("INSERT INTO ticket_order_items VALUES
            (47,8,2,20000,'General'),(48,9,3,30000,'VIP'),
            (49,8,1,10000,'Preventa'),(50,9,4,40000,'VIP'),(51,8,1,5000,'')");
        $db->exec("INSERT INTO ticket_payment_attempts VALUES
            (57,47,'wompi','W-47','approved',20000,0,0,0,20000),
            (58,48,'wompi',NULL,'pending',30000,NULL,NULL,NULL,NULL),
            (59,49,'wompi','W-49','approved',10000,0,0,0,10000),
            (60,50,'wompi','W-50','approved',40000,0,0,0,40000),
            (61,51,'wompi','W-51','approved',5000,0,0,0,5000)");
        $db->exec("INSERT INTO ticket_pos_shifts VALUES (2,4,7)");
        $db->exec("INSERT INTO ticket_pos_sales VALUES
            (62,2,4,7,48,9,3,30000,'cash','paid',UTC_TIMESTAMP(6)),
            (63,2,4,7,50,9,4,40000,'cash','paid',UTC_TIMESTAMP(6))");
        $all=brvtalFinanceReport($db,4,null,null,'all')[0];
        $wompi=brvtalFinanceReport($db,4,null,null,'wompi')[0];
        $cash=brvtalFinanceReport($db,4,null,null,'pos')[0];
        t20($all['approved_cop']===35000 && $all['pos_cop']===30000 && $all['tickets']===7
            && $all['paid_orders']===4 && $all['unverified_orders']===1,'mixed sources count only verified sales');
        t20($wompi['approved_cop']===35000 && $wompi['pos_cop']===0 && $wompi['tickets']===4
            && $cash['approved_cop']===0 && $cash['pos_cop']===30000 && $cash['tickets']===3,
            'channel membership does not reattribute completed POS to an earlier pending Wompi attempt');
        foreach ([$all,$wompi,$cash] as $cohort) {
            t20($cohort['unverified_orders']===1 && $cohort['refund_cop']===null
                && $cohort['net_after_refunds_cop']===null,'both filters preserve conflicting evidence as UNKNOWN');
        }
        t20($all['types'][0]===['ticket_type_id'=>8,'quantity'=>4,'gross_cop'=>35000,
            'ticket_names'=>['General','Preventa'],'unnamed_quantity'=>1]
            && $all['types'][1]===['ticket_type_id'=>9,'quantity'=>3,'gross_cop'=>30000,
            'ticket_names'=>['VIP'],'unnamed_quantity'=>0],
            'historical aliases and missing names grouped by ID without duplicate quantities');
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
                t20($e->getCode()==='42S02','missing POS table must fail closed for every channel');
            }
        }
    } finally {
        $server->exec('DROP DATABASE IF EXISTS '.$name);
    }
}

echo "T20 finance + POS synthetic invariants OK\n";
// T21 synthetic MariaDB regressions execute in the existing finance integration harness.
require __DIR__.'/ticketing-refunds.php';
