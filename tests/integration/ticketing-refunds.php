<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/ticketing_refunds.php';
function t21(bool $ok,string $message):void {
    if(!$ok) throw new RuntimeException('T21: '.$message);
}
function t21Reject(callable $f,string $expected):void {
    try { $f(); } catch(DomainException $e) {
        t21($e->getMessage()===$expected,'expected '.$expected); return;
    }
    throw new RuntimeException('T21: expected rejection '.$expected);
}
function t21Invalid(callable $f):void {
    try { $f(); } catch(InvalidArgumentException $e) {
        t21($e->getMessage()==='REFUND_INVALID','invalid input remains 422');return;
    }
    throw new RuntimeException('T21: expected invalid input');
}
$reflect=new ReflectionFunction('brvtalRefundGuard');
t21($reflect->getNumberOfParameters()===1,'guard requires DB');
t21(brvtalTicketingCan('superadmin','refund'),'superadmin refund');
t21(!brvtalTicketingCan('admin','refund'),'staff role denied');
t21(!brvtalTicketingCan('door','refund'),'door denied');
t21(brvtalRefundProrate(25000,1,4)===6250,'partial refund integer COP');
t21(brvtalRefundProrate(100,2,3)===66,'partial refund floors without float');
t21(brvtalRefundProrate(1000000000000,500000000000,1000000000000)===500000000000,
    '12-digit COP and per-entry prices do not overflow PHP integers');
t21(brvtalRefundProrate(1000000000000,1000000000000,1000000000000)===1000000000000,
    'exact full refund at bound');
t21Reject(static fn()=>brvtalRefundProrate(100,101,100),'AMOUNT_UNVERIFIED');
t21Reject(static fn()=>brvtalRefundProrate(100,1,0),'AMOUNT_UNVERIFIED');
t21Reject(static fn()=>brvtalRefundProrate(100,1,1000),'AMOUNT_UNVERIFIED');

// #2087: net allocations are exact, integer-only and stable across partitions.
t21(brvtalRefundAllocation(10000,[4000,4000,4000])===[3333,3333,3334],
    'coupon residual assigned by stable ID, not request order');
t21(brvtalRefundAllocation(10000,[2000,3000,7000])===[1666,2500,5834],
    'unequal prices and coupon');
t21(brvtalRefundAllocation(999999999999,[333333333333,333333333333,333333333334])
    ===[333333333332,333333333333,333333333334],'allocation does not overflow');
t21Reject(static fn()=>brvtalRefundAllocation(1,[1,1,1]),'AMOUNT_UNVERIFIED');
t21Reject(static fn()=>brvtalRefundAllocation(10,[1000000000000,1]),'AMOUNT_UNVERIFIED');

// Both list surfaces must fail closed on non-synthetic databases, including GET.
$refundCode=(string)file_get_contents(__DIR__.'/../../config/ticketing_refunds.php');
$adminCode=(string)file_get_contents(__DIR__.'/../../api/ticketing/admin-refunds.php');
t21(str_contains($refundCode,"function brvtalRefundBuyerList(PDO \$db,int \$buyer):array {\n    brvtalRefundGuard(\$db);"),
    'buyer refund GET remains sandbox-only');
t21(str_contains($adminCode,'$db=db(); brvtalRefundGuard($db);'),
    'staff refund GET remains sandbox-only');
t21(strpos($refundCode,"if (\$order['status']!=='paid'||brvtalRefundIncident(\$db,\$eventId)==='')")
    > strpos($refundCode,'if ($existing) {'),
    'idempotent replay precedes incident gate');
t21(str_contains($refundCode,'function brvtalRefundSandboxGuard(PDO $db):void'),
    'Wompi transport remains test-only');
t21(!str_contains($adminCode,'sandbox_dispatch'), 'admin API never dispatches Wompi refund');

$testWompi = ['mode'=>'sandbox','private'=>'prv_test_synthetic_only'];
$testTx = 'trx_synthetic_01';
$testRef = 'BRVTAL-T21-1';
$testPayload = brvtalRefundSandboxV2Payload($testWompi,$testTx,500,'Solicitud sintética',$testRef);
t21($testPayload['endpoint']==='https://sandbox.wompi.co/v1/refunds',
    'refund V2 must use the sandbox-only endpoint');
t21($testPayload['body']['amount_in_cents']===50000,
    'refund amounts use cents without floating-point rounding');
t21(!str_contains(json_encode($testPayload,JSON_THROW_ON_ERROR),'prv_test_'),
    'private sandbox key must not appear in the request payload');
t21Reject(static fn()=>brvtalRefundSandboxV2Payload(
    ['mode'=>'sandbox','private'=>'prv_prod_fake'],$testTx,500,'Motivo',$testRef
),'REFUND_SANDBOX_ONLY');
t21Reject(static fn()=>brvtalRefundSandboxV2Payload(
    ['mode'=>'production','private'=>'prv_test_synthetic_only'],$testTx,500,'Motivo',$testRef
),'REFUND_SANDBOX_ONLY');
$receipt = static fn(string $status,int $amount,string $reference):string => json_encode(['data'=>[
    'status'=>$status,'transaction_id'=>$testTx,'amount_in_cents'=>$amount,
    'reference'=>$reference,'v2_refund_id'=>'v2_refund_synthetic_01'
]],JSON_THROW_ON_ERROR);
$confirmed = brvtalRefundSandboxV2Receipt(201,$receipt('APPROVED',50000,$testRef),$testTx,500,$testRef);
t21($confirmed['state']==='confirmed','provider must confirm an exact approved refund');
$declined = brvtalRefundSandboxV2Receipt(201,$receipt('DECLINED',50000,$testRef),$testTx,500,$testRef);
t21($declined['state']==='failed','declined refund must not revoke tickets');
t21Reject(static fn()=>brvtalRefundSandboxV2Receipt(
    201,$receipt('APPROVED',50100,$testRef),$testTx,500,$testRef
),'REFUND_PROVIDER_UNVERIFIED');
t21Reject(static fn()=>brvtalRefundSandboxV2Receipt(
    201,$receipt('APPROVED',50000,'other_ref'),$testTx,500,$testRef
),'REFUND_PROVIDER_UNVERIFIED');


t21(brvtalRefundProviderNextStatus('approved','confirmed')==='refunded',
    'approved sandbox receipt is the only route to provider-settled');
t21(brvtalRefundProviderNextStatus('processing','confirmed')==='refunded',
    'pending sandbox receipt can later be approved');
t21(brvtalRefundProviderNextStatus('approved','failed')==='failed',
    'failed sandbox refund never triggers credential revocation');
t21(brvtalRefundProviderNextStatus('failed','confirmed')==='failed',
    'late contradictory approved event cannot revoke failed refund');
t21(brvtalRefundProviderNextStatus('refunded','failed')==='refunded',
    'late failure cannot undo a confirmed refund');
t21Reject(static fn()=>brvtalRefundProviderNextStatus('requested','confirmed'),
    'REFUND_INVALID_STATE');
t21Reject(static fn()=>brvtalRefundProviderNextStatus('approved','untrusted'),
    'REFUND_PROVIDER_UNVERIFIED');


$captured = [];
$mock = static function(string $url,array $headers,array $body) use (&$captured,$receipt,$testRef):array {
    $captured=['url'=>$url,'headers'=>$headers,'body'=>$body];
    return ['status'=>201,'body'=>$receipt('APPROVED',50000,$testRef)];
};
$exchange=brvtalRefundSandboxExchange($testWompi,$testTx,500,'Motivo sintético',$testRef,$mock);
t21($exchange['status']===201,'sandbox server transport returns validated HTTP receipt');
t21($captured['url']==='https://sandbox.wompi.co/v1/refunds',
    'server transport cannot move to production host');
t21($captured['headers'][0]==='Authorization: Bearer '.$testWompi['private'],
    'test-only private bearer is server-side');
t21($captured['body']['amount_in_cents']===50000,
    'only database-validated amount is passed to provider');
t21(!str_contains(json_encode($exchange,JSON_THROW_ON_ERROR),'prv_test_'),
    'transport never returns the private bearer');
t21Reject(static fn()=>brvtalRefundSandboxExchange(
    ['mode'=>'production','private'=>'prv_prod_fake'],$testTx,500,'Motivo sintético',$testRef,$mock
),'REFUND_SANDBOX_ONLY');

$schema=(string)file_get_contents(__DIR__.'/../../database/migration_ticketing_refunds_01.sql');
t21(str_contains($schema,'uq_ticket_refund_claim'),'no double-refund claim');
t21(str_contains($schema,'ticket_refund_adjustments'),'immutable finance witness');
t21(str_contains($schema,'ticket_refund_audit'),'audit witness');
t21(!preg_match('/\bDROP\b|\bTRUNCATE\b|\bDELETE\s+FROM\b/i',$schema),'additive schema');
if(getenv('BRVTAL_INTEGRATION_TESTS')!=='1') {
    echo "T21 refund contract checks OK; disposable database tests not enabled.\n"; exit(0);
}
$base=(string)getenv('BRVTAL_TEST_DB_NAME');
t21((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/D',$base),'disposable DB namespace');
$dbName=$base.'_t21_'.bin2hex(random_bytes(4));
$host=(string)(getenv('BRVTAL_TEST_DB_HOST')?:'127.0.0.1');
$port=(int)(getenv('BRVTAL_TEST_DB_PORT')?:3306);
$user=(string)(getenv('BRVTAL_TEST_DB_USER')?:'root');
$pass=(string)(getenv('BRVTAL_TEST_DB_PASS')?:'');
$opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$root=new PDO("mysql:host=$host;port=$port;charset=utf8mb4",$user,$pass,$opts);
$root->exec("CREATE DATABASE $dbName CHARACTER SET utf8mb4");
try {
    $pdo=new PDO("mysql:host=$host;port=$port;dbname=$dbName;charset=utf8mb4",$user,$pass,$opts);
    $statements=[
        'CREATE TABLE events (id INT UNSIGNED PRIMARY KEY,title VARCHAR(180) NOT NULL) ENGINE=InnoDB',
        'CREATE TABLE admins (id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB',
        "CREATE TABLE ticket_buyers (id BIGINT UNSIGNED PRIMARY KEY,email VARCHAR(190),
            status ENUM('guest','active','blocked') NOT NULL DEFAULT 'active',
            email_verified_at DATETIME(6) NULL,security_epoch BIGINT UNSIGNED NOT NULL DEFAULT 1) ENGINE=InnoDB",
        'CREATE TABLE ticket_buyer_sessions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            buyer_id BIGINT UNSIGNED NOT NULL,session_hash CHAR(64) NOT NULL UNIQUE,
            issued_epoch BIGINT UNSIGNED NOT NULL,expires_at_utc DATETIME(6) NOT NULL,
            revoked_at_utc DATETIME(6) NULL) ENGINE=InnoDB',
        "CREATE TABLE ticket_orders (
            id BIGINT UNSIGNED PRIMARY KEY, public_id BINARY(16) NOT NULL,
            buyer_id BIGINT UNSIGNED NOT NULL,event_id INT UNSIGNED NOT NULL,
            total_cop BIGINT UNSIGNED NOT NULL,status ENUM('paid','refunded') NOT NULL,
            currency CHAR(3) NOT NULL,updated_at_utc DATETIME(6) NULL
          ) ENGINE=InnoDB",
        'CREATE TABLE ticket_order_items (
            id BIGINT UNSIGNED PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,
            unit_price_cop BIGINT UNSIGNED NOT NULL
          ) ENGINE=InnoDB',
        "CREATE TABLE ticket_entries (
            id BIGINT UNSIGNED PRIMARY KEY,public_id BINARY(16) NOT NULL,
            order_item_id BIGINT UNSIGNED NOT NULL,
            status ENUM('issued','used','refunded') NOT NULL,
            revoked_at_utc DATETIME(6) NULL
          ) ENGINE=InnoDB",
        "CREATE TABLE ticket_entry_credentials (
            entry_id BIGINT UNSIGNED NOT NULL,
            status ENUM('active','revoked') NOT NULL,
            revoked_at_utc DATETIME(6) NULL
          ) ENGINE=InnoDB",
        "CREATE TABLE ticket_payment_attempts (
            id BIGINT UNSIGNED PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,
            provider VARCHAR(24) NOT NULL,provider_transaction_id VARCHAR(190) NULL,
            status ENUM('approved','refunded') NOT NULL,
            gross_amount_cop BIGINT UNSIGNED NOT NULL
          ) ENGINE=InnoDB",
        "CREATE TABLE ticket_payment_webhook_events (
            attempt_id BIGINT UNSIGNED NOT NULL,provider_transaction_id VARCHAR(190) NOT NULL,
            signed_status ENUM('APPROVED') NOT NULL
          ) ENGINE=InnoDB",
        "CREATE TABLE ticket_mail_outbox (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            message_key VARCHAR(128) NOT NULL UNIQUE,payload_hash CHAR(64) NOT NULL,
            related_ref VARCHAR(128) NOT NULL,recipient_email VARCHAR(254) NOT NULL,
            subject VARCHAR(255) NOT NULL,body_text TEXT NOT NULL,
            next_attempt_at DATETIME NOT NULL,created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
          ) ENGINE=InnoDB"
    ];
    foreach ($statements as $sql) $pdo->exec($sql);
    $ddl=(string)file_get_contents(__DIR__.'/../../database/migration_ticketing_refunds_01.sql');
    foreach (explode(';',$ddl) as $sql) {
        if (trim($sql)!=='') $pdo->exec($sql);
    }
    $incidentMigration=(string)file_get_contents(__DIR__.'/../../database/migration_ticketing_refund_events_01.sql');
    foreach(explode(';',$incidentMigration) as $sql) {
        if(trim($sql)!=='')$pdo->exec($sql);
    }
    putenv('BRVTAL_TICKET_MAIL_TESTING=1');
    foreach ([
        "INSERT INTO events VALUES (1,'Evento sintético cancelado')",
        "INSERT INTO admins VALUES (7),(8)",
        "INSERT INTO ticket_buyers(id,email,status,email_verified_at) VALUES
          (3,'comprador@brvtal.invalid','active',UTC_TIMESTAMP(6)),
          (4,'otro@brvtal.invalid','active',UTC_TIMESTAMP(6))",
        "INSERT INTO ticket_orders VALUES
          (91,UNHEX('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'),3,1,12000,'paid','COP',UTC_TIMESTAMP(6)),
          (92,UNHEX('ffffffffffffffffffffffffffffffff'),3,1,7000,'paid','COP',UTC_TIMESTAMP(6))",
        "INSERT INTO ticket_order_items VALUES (100,91,5000),(101,91,4000),(102,91,3000),(103,92,7000)",
        "INSERT INTO ticket_entries VALUES
          (201,UNHEX('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'),100,'issued',NULL),
          (202,UNHEX('cccccccccccccccccccccccccccccccc'),101,'issued',NULL),
          (203,UNHEX('dddddddddddddddddddddddddddddddd'),102,'used',NULL),
          (204,UNHEX('eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee'),103,'issued',NULL)",
        "INSERT INTO ticket_entry_credentials VALUES (201,'active',NULL),(202,'active',NULL),(204,'active',NULL)",
        "INSERT INTO ticket_payment_attempts VALUES
          (601,91,'wompi','trx_test_91','approved',12000),
          (602,92,'wompi','trx_test_92','approved',7000)",
        "INSERT INTO ticket_payment_webhook_events VALUES
          (601,'trx_test_91','APPROVED'),(602,'trx_test_92','APPROVED')"
    ] as $sql) $pdo->exec($sql);
    $pdo->exec('ALTER TABLE ticket_entries ADD ticket_type_id INT UNSIGNED NOT NULL DEFAULT 10');
    brvtalRefundGuard($pdo);
    $request=['order_id'=>str_repeat('a',32),'entry_ids'=>[str_repeat('b',32)],
        'reason'=>'Motivo de devolución sintética','idempotency_key'=>'t21-idempotent-key-001'];
    t21Reject(static fn()=>brvtalRefundRequest($pdo,3,$request),'ORDER_NOT_REFUNDABLE');
    t21Reject(static fn()=>brvtalRefundDeclareIncident($pdo,8,'admin',1,'cancelled'),'FORBIDDEN');
    t21Invalid(static fn()=>brvtalRefundDeclareIncident($pdo,7,'superadmin',1,'invalid'));
    t21(brvtalRefundDeclareIncident($pdo,7,'superadmin',1,'cancelled')['cause']==='cancelled',
        'owner declares incident');
    t21(brvtalRefundDeclareIncident($pdo,7,'superadmin',1,'cancelled')['replayed'],
        'incident declaration idempotent');
    t21Reject(static fn()=>brvtalRefundDeclareIncident($pdo,7,'superadmin',1,'postponed'),
        'REFUND_INCIDENT_CONFLICT');
    $one=brvtalRefundRequest($pdo,3,$request);$id=(int)$one['id'];
    t21($one['amount_cop']===5000 && $one['status']==='requested','partial COP request');
    $replay=brvtalRefundRequest($pdo,3,$request);
    t21($replay['replayed'] && (int)$replay['id']===$id,'same-key idempotency');
    t21Reject(static fn()=>brvtalRefundRequest($pdo,3,array_merge(
        $request,['entry_ids'=>[str_repeat('c',32)]])),'IDEMPOTENCY_CONFLICT');
    t21Reject(static fn()=>brvtalRefundRequest($pdo,4,$request),'ORDER_NOT_REFUNDABLE');
    t21Reject(static fn()=>brvtalRefundRequest($pdo,3,array_merge($request,[
        'entry_ids'=>[str_repeat('d',32)],'idempotency_key'=>'t21-used-entry-key-001'
    ])),'ENTRY_NOT_REFUNDABLE');
    // A different intent cannot claim the same unit or create a partial mixed request.
    $snapshot=static function()use($pdo):array{
        $rows=[];
        foreach (['ticket_refunds'=>'id','ticket_refund_entries'=>'refund_id,entry_id',
            'ticket_refund_audit'=>'id','ticket_refund_adjustments'=>'refund_id'] as $table=>$sort) {
            $rows[$table]=$pdo->query("SELECT * FROM $table ORDER BY $sort")->fetchAll();
        }
        return $rows;
    };
    $beforeConflict=$snapshot();
    foreach ([$request['entry_ids'],[str_repeat('b',32),str_repeat('c',32)]] as $selection) {
        t21Reject(static fn()=>brvtalRefundRequest($pdo,3,array_replace($request,[
            'entry_ids'=>$selection,'idempotency_key'=>'t2122-active-conflict'
        ])),'REFUND_CLAIM_CONFLICT');
    }
    t21Reject(static fn()=>brvtalRefundRequest($pdo,3,array_replace($request,[
        'entry_ids'=>[str_repeat('b',32),str_repeat('e',32)],
        'idempotency_key'=>'t2122-foreign-selection'
    ])),'ENTRY_NOT_REFUNDABLE');
    t21($snapshot()===$beforeConflict,'conflicts leave all ledger rows unchanged');
    $approved=brvtalRefundStaff($pdo,7,'superadmin',$id,'approve');
    t21($approved['status']==='approved','staff approval, no refund yet');
    t21(brvtalRefundStaff($pdo,7,'superadmin',$id,'approve')['replayed'],'double staff approval');
    t21($pdo->query('SELECT COUNT(*) FROM ticket_refund_adjustments')->fetchColumn()==0,
        'approval never moves money or revokes credentials');
    $receipt=static fn(int $refundId,string $status,int $amount,string $providerId):string=>
        json_encode(['data'=>[
            'status'=>$status,'transaction_id'=>'trx_test_91','amount_in_cents'=>$amount*100,
            'reference'=>'BRVTAL-T21-'.$refundId,'v2_refund_id'=>$providerId
        ]],JSON_THROW_ON_ERROR);
    $settled=brvtalRefundSandboxApply($pdo,$id,201,$receipt($id,'APPROVED',5000,'v2_refund_t21_1'));
    t21($settled['status']==='refunded','provider confirmed');
    t21(brvtalRefundSandboxApply($pdo,$id,201,$receipt(
        $id,'APPROVED',5000,'v2_refund_t21_1'))['replayed'],'provider duplicate idempotent');
    t21((int)$pdo->query('SELECT COUNT(*) FROM ticket_refund_adjustments')->fetchColumn()===1,
        'one immutable adjustment');
    t21($pdo->query('SELECT status FROM ticket_entries WHERE id=201')->fetchColumn()==='refunded',
        'selected entry revoked after verified receipt');
    t21($pdo->query('SELECT status FROM ticket_entry_credentials WHERE entry_id=201')->fetchColumn()==='revoked',
        'QR credential revoked');
    t21($pdo->query('SELECT status FROM ticket_orders WHERE id=91')->fetchColumn()==='paid',
        'remaining entries preserve paid order');
    t21(brvtalRefundCreditedInventory($pdo,1,0)===1,
        'fully refunded ticket reduces sold inventory by one unit');

    $beforeTerminal=$snapshot();
    t21(brvtalRefundRequest($pdo,3,$request)['replayed'],'terminal same-key replay');
    t21Reject(static fn()=>brvtalRefundRequest($pdo,3,array_replace($request,[
        'idempotency_key'=>'t2122-terminal-conflict'
    ])),'REFUND_CLAIM_CONFLICT');
    t21($snapshot()===$beforeTerminal,'terminal claim remains immutable');
    $other=brvtalRefundRequest($pdo,3,array_merge($request,[
        'entry_ids'=>[str_repeat('c',32)],'idempotency_key'=>'t21-second-entry-key-01'
    ]));$id2=(int)$other['id'];
    brvtalRefundStaff($pdo,7,'superadmin',$id2,'approve');
    $failed=brvtalRefundSandboxApply($pdo,$id2,201,$receipt($id2,'DECLINED',4000,'v2_refund_t21_2'));
    t21($failed['status']==='failed','provider decline leaves refund failed');
    t21(brvtalRefundSandboxApply($pdo,$id2,201,
        $receipt($id2,'APPROVED',4000,'v2_refund_t21_2'))['status']==='failed',
        'late contradictory approval cannot revoke failed refund');
    t21($pdo->query('SELECT status FROM ticket_entry_credentials WHERE entry_id=202')->fetchColumn()==='active',
        'declined refund never revokes remaining QR');
    $manual=brvtalRefundRequest($pdo,3,[
        'order_id'=>str_repeat('f',32),'entry_ids'=>[str_repeat('e',32)],
        'reason'=>'Operación sintética manual','idempotency_key'=>'t21-manual-approval-01'
    ]);
    $id3=(int)$manual['id'];brvtalRefundStaff($pdo,7,'superadmin',$id3,'approve');
    t21Reject(static fn()=>brvtalRefundStaff($pdo,8,'admin',$id3,'manual_confirm','','WOMPI-TEST-02'),
        'FORBIDDEN');
    t21Reject(static fn()=>brvtalRefundStaff($pdo,7,'superadmin',$id3,'manual_confirm','','x'),
        'REFUND_INVALID_STATE');
    // An owner reference cannot certify a transaction not proven by a signed Wompi webhook.
    $pdo->exec('DELETE FROM ticket_payment_webhook_events WHERE attempt_id=602');
    t21Reject(static fn()=>brvtalRefundStaff($pdo,7,'superadmin',$id3,'manual_confirm','','WOMPI-TEST-02'),
        'REFUND_PAYMENT_UNVERIFIED');
    t21($pdo->query('SELECT status FROM ticket_entries WHERE id=204')->fetchColumn()==='issued',
        'no premature QR revocation on unverified provider payment');
    $pdo->exec("INSERT INTO ticket_payment_webhook_events VALUES (602,'trx_test_92','APPROVED')");
    $confirmed=brvtalRefundStaff($pdo,7,'superadmin',$id3,'manual_confirm','','WOMPI-TEST-02');
    t21($confirmed['status']==='refunded','owner registers external Wompi panel refund');
    t21(brvtalRefundStaff($pdo,7,'superadmin',$id3,'manual_confirm','','WOMPI-TEST-02')['replayed'],
        'Wompi reference replay idempotent');
    t21Reject(static fn()=>brvtalRefundStaff($pdo,7,'superadmin',$id3,'manual_confirm','','WOMPI-OTHER-02'),
        'REFUND_RECEIPT_CONFLICT');
    t21((int)$pdo->query("SELECT COUNT(*) FROM ticket_mail_outbox WHERE message_key LIKE 'order:%:refund:%'")->fetchColumn()===1,
        'one refund email enqueued for confirmed manual claim');
    t21($pdo->query('SELECT provider_refund_id FROM ticket_refunds WHERE id='.$id3)->fetchColumn()
        ==='manual:WOMPI-TEST-02','reference stored with audited refund');
    t21($pdo->query('SELECT status FROM ticket_orders WHERE id=92')->fetchColumn()==='refunded',
        'full refund closes order');
    t21($pdo->query('SELECT status FROM ticket_entry_credentials WHERE entry_id=204')->fetchColumn()==='revoked',
        'manual witness revokes QR only after confirmation');

    // #2102: one synthetic buyer owns two paid orders; a checkout guest
    // may see/request ONLY the original server-granted order.
    foreach ([
        "INSERT INTO events VALUES (2,'Incidente de pruebas #2102')",
        "INSERT INTO ticket_orders VALUES
          (94,UNHEX('11111111111111111111111111111111'),3,2,2000,'paid','COP',UTC_TIMESTAMP(6)),
          (95,UNHEX('22222222222222222222222222222222'),3,2,3000,'paid','COP',UTC_TIMESTAMP(6)),
          (96,UNHEX('33333333333333333333333333333333'),4,2,4000,'paid','COP',UTC_TIMESTAMP(6))",
        'INSERT INTO ticket_order_items VALUES (105,94,2000),(106,95,3000),(107,96,4000)',
        "INSERT INTO ticket_entries(id,public_id,order_item_id,status,revoked_at_utc) VALUES
          (205,UNHEX('44444444444444444444444444444444'),105,'issued',NULL),
          (206,UNHEX('55555555555555555555555555555555'),106,'issued',NULL),
          (207,UNHEX('66666666666666666666666666666666'),107,'issued',NULL)",
        "INSERT INTO ticket_refund_event_decisions(event_id,cause,owner_admin_id)
          VALUES (2,'cancelled',7)"
    ] as $sql) $pdo->exec($sql);
    $order94=['order_id'=>str_repeat('1',32),'entry_ids'=>[str_repeat('4',32)],
        'reason'=>'Incidente sintético del pedido permitido',
        'idempotency_key'=>'t31-guest-scope-order94'];
    $order95=['order_id'=>str_repeat('2',32),'entry_ids'=>[str_repeat('5',32)],
        'reason'=>'Incidente sintético del pedido ajeno al grant',
        'idempotency_key'=>'t31-other-order-95-a'];
    $order96=['order_id'=>str_repeat('3',32),'entry_ids'=>[str_repeat('6',32)],
        'reason'=>'Incidente de otro comprador',
        'idempotency_key'=>'t31-other-buyer-96'];
    // Verified sessions retain all OWN orders; never a different buyer.
    $foreignRefund=brvtalRefundRequest($pdo,4,$order96);
    $ungrantedRefund=brvtalRefundRequest($pdo,3,$order95);
    t21((int)$ungrantedRefund['id']>0 && (int)$foreignRefund['id']>0,
        'synthetic refunds for both buyers created under verified scope');
    $pdo->exec("UPDATE ticket_buyers SET status='guest',email_verified_at=NULL WHERE id=3");
    $_SESSION=['buyer_id'=>3,'buyer_checkout_new_order'=>94];
    t21(brvtalBuyerAccountCheckoutScope($pdo,3)===94,'guest scope is single paid order');
    t21(brvtalRefundBuyerList($pdo,3)===[],
        'GET must not expose a prior refund on another paid order of the SAME buyer');
    $auditBefore=(int)$pdo->query('SELECT COUNT(*) FROM ticket_refund_audit')->fetchColumn();
    t21Reject(static fn()=>brvtalRefundRequest($pdo,3,array_merge($order95,[
        'idempotency_key'=>'t31-guest-foreign-95'
    ])),'ORDER_NOT_REFUNDABLE');
    t21Reject(static fn()=>brvtalRefundRequest($pdo,3,$order96),'ORDER_NOT_REFUNDABLE');
    t21((int)$pdo->query('SELECT COUNT(*) FROM ticket_refund_audit')->fetchColumn()===$auditBefore,
        'unauthorized guest POST must not create a refund or audit entry');
    $allowed=brvtalRefundRequest($pdo,3,$order94);
    t21($allowed['status']==='requested'&&!$allowed['replayed'],'granted order can request');
    t21(brvtalRefundRequest($pdo,3,$order94)['replayed'],'granted order replay preserved');
    $scoped=brvtalRefundBuyerList($pdo,3);
    t21(count($scoped)===1 && $scoped[0]['order_id']===str_repeat('1',32),
        'guest GET includes exactly the granted order, no other buyer/order leak');
    $_SESSION['buyer_checkout_new_order']=0;
    t21Reject(static fn()=>brvtalRefundBuyerList($pdo,3),'TICKET_UNAVAILABLE');
    t21Reject(static fn()=>brvtalRefundRequest($pdo,3,$order94),'TICKET_UNAVAILABLE');
    $_SESSION['buyer_checkout_new_order']=95;
    t21Reject(static fn()=>brvtalRefundRequest($pdo,3,$order94),'ORDER_NOT_REFUNDABLE');
    $_SESSION['buyer_checkout_new_order']=94;
    // Reproduce the stale REPEATABLE READ snapshot from the preliminary order
    // lookup; a separate connection blocks this buyer before the locking read.
    // The scope helper must observe the new blocked status, not the old snapshot.
    $pdo->beginTransaction();
    try {
        $pdo->query('SELECT event_id FROM ticket_orders WHERE id=94')->fetchColumn();
        $writer=new PDO("mysql:host=$host;port=$port;dbname=$dbName;charset=utf8mb4",
            $user,$pass,$opts);
        $writer->exec("UPDATE ticket_buyers SET status='blocked' WHERE id=3");
        t21Reject(static fn()=>brvtalRefundRequestScope($pdo,3,94),'TICKET_UNAVAILABLE');
        t21((int)$pdo->query('SELECT COUNT(*) FROM ticket_refund_audit')->fetchColumn()
            ===$auditBefore+1,'blocked buyer cannot create refund audit through stale snapshot');
    } finally {
        if($pdo->inTransaction())$pdo->rollBack();
        if(isset($writer))$writer->exec("UPDATE ticket_buyers SET status='guest' WHERE id=3");
    }
    $pdo->exec("UPDATE ticket_buyers SET status='active',email_verified_at=UTC_TIMESTAMP(6) WHERE id=3");
    t21(count(brvtalRefundBuyerList($pdo,3))>=3,
        'verified account retains access to own orders regardless of old guest grant');

    // Authenticated real HTTP GET/POST to the *actual* PHP endpoint against
    // disposable MariaDB. No simulated handler, real provider or live config.
    t21(function_exists('proc_open')&&function_exists('stream_socket_server'),
        'authenticated HTTP test requires local PHP and sockets');
    $temporary=sys_get_temp_dir().'/brvtal-refund-2102-'.bin2hex(random_bytes(5));
    t21(mkdir($temporary,0700),'temporary config directory');
    $serverProcess=null;$serverPipes=[];
    try {
        $configFile=$temporary.'/config.php';
        $configuration=['app'=>['timezone'=>'America/Bogota'],
            'db'=>['host'=>$host,'port'=>$port,'name'=>$dbName,'user'=>$user,
                'pass'=>$pass,'charset'=>'utf8mb4']];
        file_put_contents($configFile,'<?php return '.var_export($configuration,true).';');
        chmod($configFile,0600);
        $prepend=$temporary.'/prepend.php';
        file_put_contents($prepend,'<?php define("BRVTAL_SENTRY_TESTING",true);'
            .'$GLOBALS["brvtalBootstrapConfigPath"]='.var_export($configFile,true).';');
        chmod($prepend,0600);
        $listener=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);
        t21($listener!==false,'loopback-only HTTP port allocated');
        $name=(string)stream_socket_get_name($listener,false);
        fclose($listener);
        $httpPort=(int)substr(strrchr($name,':'),1);
        t21($httpPort>0,'HTTP port valid');
        $env=(array)getenv();
        $env['BRVTAL_INTEGRATION_TESTS']='1';
        $serverProcess=proc_open([PHP_BINARY,
            '-d','auto_prepend_file='.$prepend,
            '-d','session.save_path='.$temporary,
            '-d','session.serialize_handler=php',
            '-S','127.0.0.1:'.$httpPort,'-t',dirname(__DIR__,2)],
            [0=>['pipe','r'],1=>['file',$temporary.'/server.log','a'],
                2=>['file',$temporary.'/server.log','a']],
            $serverPipes,dirname(__DIR__,2),$env);
        t21(is_resource($serverProcess),'real API PHP server started');
        if(is_resource($serverPipes[0]??null))fclose($serverPipes[0]);
        $url='http://127.0.0.1:'.$httpPort.'/api/buyer/refunds.php';
        $requestHttp=static function(?array $session,string $method='GET',
            ?array $body=null,bool $csrf=true)use($url):array{
            $headers=['Connection: close'];
            if($session!==null) {
                $headers[]='Cookie: BRVTAL_BUYER='.$session['sid'];
                if($csrf)$headers[]='X-CSRF-Token: '.$session['csrf'];
            }
            $opts=['method'=>$method,'ignore_errors'=>true,'timeout'=>8,
                'header'=>implode("\r\n",$headers)];
            if($body!==null){
                $opts['header'].="\r\nContent-Type: application/json";
                $opts['content']=json_encode($body,JSON_THROW_ON_ERROR);
            }
            $ctx=stream_context_create(['http'=>$opts]);
            $response=@file_get_contents($url,false,$ctx);
            $headersOut=$http_response_header;
            preg_match('~^HTTP/\S+ ([0-9]{3})~',(string)($headersOut[0]??''),$matches);
            return ['status'=>(int)($matches[1]??0),
                'data'=>is_string($response)?json_decode($response,true):null];
        };
        $started=false;
        for($i=0;$i<30;$i++){
            if($requestHttp(null)['status']===401){$started=true;break;}
            usleep(100000);
        }
        t21($started,'authentic refund route ready on disposable local server');
        $newSession=static function(int $buyer,?int $grant,bool $expired=false)use($pdo,$temporary):array{
            // This suite is also included after another CLI test has printed.
            // Native session_start() would fail with headers already sent.
            // Write only synthetic PHP session-encoded scalars in a private dir;
            // the real HTTP endpoint still parses/validates the cookie and DB row.
            $sid=bin2hex(random_bytes(16));
            $csrf='t31-'.bin2hex(random_bytes(20));
            $session=['buyer_id'=>$buyer,'buyer_csrf'=>$csrf,
                'buyer_issued_at'=>time(),'buyer_last_at'=>time()];
            if($grant!==null)$session['buyer_checkout_new_order']=$grant;
            $serialized='';
            foreach($session as $key=>$value)$serialized.=$key.'|'.serialize($value);
            $sessionFile=$temporary.'/sess_'.$sid;
            t21(file_put_contents($sessionFile,$serialized)!==false,'write private synthetic session');
            chmod($sessionFile,0600);
            $pdo->prepare('INSERT INTO ticket_buyer_sessions
                (buyer_id,session_hash,issued_epoch,expires_at_utc)
                VALUES (?,?,1,?)')->execute([$buyer,hash('sha256',$sid),
                    $expired?'2000-01-01 00:00:00':gmdate('Y-m-d H:i:s',time()+3600)]);
            return ['sid'=>$sid,'csrf'=>$csrf];
        };
        // Guest and verified sessions authenticated with server-side session rows.
        $pdo->exec("UPDATE ticket_buyers SET status='guest',email_verified_at=NULL WHERE id=3");
        $guestHttp=$newSession(3,94);
        $verifiedHttp=$newSession(4,null);
        $expiredHttp=$newSession(3,94,true);
        $guestGet=$requestHttp($guestHttp);
        t21($guestGet['status']===200
            && count($guestGet['data']['data']??[])===1
            && ($guestGet['data']['data'][0]['order_id']??null)===str_repeat('1',32),
            'authenticated guest GET excludes same-buyer ungranted refund');
        $badPost=$requestHttp($guestHttp,'POST',array_merge($order95,[
            'idempotency_key'=>'t31-guest-http-foreign']),true);
        t21($badPost['status']===403
            && ($badPost['data']['error']??null)==='ORDER_NOT_REFUNDABLE',
            'real POST rejects ungranted paid order without information leakage');
        $validPost=$requestHttp($guestHttp,'POST',$order94);
        t21($validPost['status']===201
            && ($validPost['data']['data']['replayed']??false)===true,
            'real POST preserves idempotent replay within granted order');
        $conflict=$requestHttp($guestHttp,'POST',array_replace($order94,[
            'idempotency_key'=>'t2122-http-new-intent'
        ]));
        t21($conflict['status']===409 && $conflict['data']===['ok'=>false,'error'=>'REFUND_CLAIM_CONFLICT'],
            'real guest HTTP conflict is neutral and contains no ledger details');
        $missingCsrf=$requestHttp($guestHttp,'POST',$order94,false);
        t21($missingCsrf['status']===403
            && ($missingCsrf['data']['error']??null)==='CSRF_INVALID',
            'real POST retains CSRF barrier');
        $verifiedGet=$requestHttp($verifiedHttp);
        t21($verifiedGet['status']===200
            && count($verifiedGet['data']['data']??[])===1
            && ($verifiedGet['data']['data'][0]['order_id']??null)===str_repeat('3',32),
            'verified buyer GET cannot see another buyer refunds');
        t21($requestHttp($verifiedHttp,'POST',$order94)['status']===403,
            'verified buyer cannot submit another buyer order');
        t21($requestHttp($expiredHttp)['status']===401,
            'expired server-side session cannot read refunds');
        t21((int)$pdo->query('SELECT COUNT(*) FROM ticket_refund_audit')->fetchColumn()
            ===$auditBefore+1,
            'HTTP rejected POST and replay cause no extra database audit');

        // #2125: real staff GET using the canonical session and isolated evidence tables.
        $pdo->exec("ALTER TABLE admins ADD is_active TINYINT NOT NULL DEFAULT 1,
            ADD credential_epoch INT NOT NULL DEFAULT 1, ADD staff_role VARCHAR(24) NOT NULL DEFAULT 'door'");
        $pdo->exec("UPDATE admins SET staff_role='superadmin' WHERE id=7");
        $pdo->exec("UPDATE admins SET staff_role='admin' WHERE id=8");
        $pdo->exec("INSERT INTO admins(id) VALUES (9)");
        $pdo->exec("ALTER TABLE ticket_payment_attempts ADD provider_reference VARCHAR(190) NULL");
        $pdo->exec("UPDATE ticket_payment_attempts SET provider_reference=provider_transaction_id");
        $pdo->exec("ALTER TABLE ticket_order_items ADD event_id INT NOT NULL DEFAULT 0,
            ADD ticket_type_id INT NOT NULL DEFAULT 0, ADD quantity INT NOT NULL DEFAULT 1,
            ADD subtotal_cop BIGINT NOT NULL DEFAULT 0");
        $pdo->exec("UPDATE ticket_order_items SET event_id=2,ticket_type_id=10,subtotal_cop=3000 WHERE id=106");
        $pdo->exec("CREATE TABLE ticket_pos_shifts (id INT PRIMARY KEY,event_id INT,operator_admin_id INT)");
        $pdo->exec("CREATE TABLE ticket_pos_sales (order_id INT PRIMARY KEY,method VARCHAR(24),
            operator_admin_id INT,shift_id INT,event_id INT,amount_cop BIGINT,ticket_type_id INT,
            quantity INT,unit_price_cop BIGINT,status VARCHAR(24),completed_at_utc DATETIME)");
        $pdo->exec("INSERT INTO ticket_pos_shifts VALUES (1,2,7)");
        $pdo->exec("INSERT INTO ticket_pos_sales VALUES (95,'cash',7,1,2,3000,10,1,3000,'paid',UTC_TIMESTAMP())");
        $pdo->exec("INSERT INTO ticket_payment_attempts VALUES (603,94,'wompi','trx_test_94','approved',2000,'ref94')");
        $pdo->exec("INSERT INTO ticket_payment_webhook_events VALUES (603,'trx_test_94','APPROVED')");
        $ids=[(int)$allowed['id'],(int)$ungrantedRefund['id'],(int)$foreignRefund['id']];
        $pdo->exec("UPDATE ticket_refunds SET status='approved' WHERE id IN (".implode(',',$ids).")");
        $adminGet=static function(?int $admin)use($temporary,$httpPort):array{
            $headers='Connection: close';
            if($admin!==null){
                $sid=bin2hex(random_bytes(16));
                $session=['admin_id'=>$admin,'credential_epoch'=>1,'csrf'=>str_repeat('a',64),
                    'issued_at'=>time(),'last_activity'=>time()];
                $serialized='';
                foreach($session as $key=>$value)$serialized.=$key.'|'.serialize($value);
                $sessionFile=$temporary.'/sess_'.$sid;
                t21(file_put_contents($sessionFile,$serialized)!==false,'synthetic admin session');
                chmod($sessionFile,0600);
                $headers.="\r\nCookie: BRVTAL_ADMIN=".$sid;
            }
            $response=@file_get_contents('http://127.0.0.1:'.$httpPort.'/api/ticketing/admin-refunds.php',
                false,stream_context_create(['http'=>['ignore_errors'=>true,'header'=>$headers,'timeout'=>8]]));
            preg_match('~^HTTP/\S+ ([0-9]{3})~',(string)($http_response_header[0]??''),$m);
            return [(int)($m[1]??0),is_string($response)?json_decode($response,true):null];
        };
        $readOnlySnapshot=static function()use($pdo,$snapshot):array{
            return [$snapshot(),$pdo->query('SELECT * FROM ticket_orders ORDER BY id')->fetchAll(),
                $pdo->query('SELECT * FROM ticket_entry_credentials ORDER BY entry_id')->fetchAll(),
                $pdo->query('SELECT * FROM ticket_payment_attempts ORDER BY id')->fetchAll(),
                $pdo->query('SELECT * FROM ticket_pos_sales ORDER BY order_id')->fetchAll()];
        };
        $beforeGet=$readOnlySnapshot();
        [$code,$body]=$adminGet(7);
        t21($code===200&&$body['can_decide']===true,'real owner GET');
        $rows=array_column($body['data'],null,'id');
        t21($rows[$ids[0]]['payment_evidence']['state']==='verified'
            &&$rows[$ids[0]]['wompi_payment_ref']==='trx_test_94'
            &&$rows[$ids[0]]['can_manual_confirm']===true,'owner sees unique Wompi proof');
        t21($rows[$ids[1]]['payment_evidence']['state']==='registered_offline'
            &&$rows[$ids[1]]['can_manual_confirm']===false,'POS is not Wompi');
        t21($rows[$ids[2]]['payment_evidence']['reason']==='paid_without_proof'
            &&$rows[$ids[2]]['can_manual_confirm']===false,'missing payment proof');
        t21(!isset($rows[$ids[0]]['order_id'],$rows[$ids[0]]['buyer_id'])
            &&!array_key_exists('total_cop',$rows[$ids[0]]),'only necessary refund fields');
        [$code,$finance]=$adminGet(8);
        $financeRows=array_column($finance['data']??[],null,'id');
        t21($code===200&&$finance['can_decide']===false
            &&$financeRows[$ids[0]]['payment_evidence']['state']==='verified'
            &&$financeRows[$ids[0]]['wompi_payment_ref']===null
            &&$financeRows[$ids[0]]['can_manual_confirm']===false,'finance read only without reference');
        t21($adminGet(9)[0]===403&&$adminGet(null)[0]===401,'GET permissions remain restricted');
        t21($beforeGet===$readOnlySnapshot(),'GET leaves payments, refunds, credentials and POS unchanged');
        $pdo->exec("INSERT INTO ticket_payment_attempts VALUES (604,94,'wompi','trx_duplicate','refunded',2000,'ref94b')");
        $pdo->exec("INSERT INTO ticket_payment_webhook_events VALUES (604,'trx_duplicate','APPROVED')");
        $beforeGet=$readOnlySnapshot();
        [$code,$body]=$adminGet(7); $rows=array_column($body['data']??[],null,'id');
        t21($code===200&&$rows[$ids[0]]['payment_evidence']['reason']==='duplicate_wompi'
            &&$rows[$ids[0]]['wompi_payment_ref']===null
            &&$rows[$ids[0]]['can_manual_confirm']===false,'never choose among multiple signed attempts');
        t21($beforeGet===$readOnlySnapshot(),'conflict GET does not mutate ledger');
        $pdo->exec("DELETE FROM ticket_payment_webhook_events WHERE attempt_id IN (603,604)");
        $pdo->exec("DELETE FROM ticket_payment_attempts WHERE id IN (603,604)");
        $pdo->exec("UPDATE ticket_refunds SET status='requested' WHERE id IN (".implode(',',$ids).")");
    } finally {
        if(is_resource($serverProcess)){proc_terminate($serverProcess);proc_close($serverProcess);}
        foreach(glob($temporary.'/*')?:[] as $file){if(is_file($file))unlink($file);}
        rmdir($temporary);
    }

    // Direct service replay must remain idempotent after the granted order
    // is refunded, without creating a *new* refund. The HTTP auth layer still
    // requires paid checkout for guest login and is intentionally not widened.
    $_SESSION=['buyer_id'=>3,'buyer_checkout_new_order'=>94];
    $pdo->exec("UPDATE ticket_orders SET status='refunded' WHERE id=94");
    $afterRefund=brvtalRefundRequest($pdo,3,$order94);
    t21($afterRefund['replayed']===true && $afterRefund['status']==='requested',
        'granted order retains idempotent service replay after full refund');
    t21Reject(static fn()=>brvtalRefundRequest($pdo,3,array_merge($order94,[
        'idempotency_key'=>'t31-no-new-after-refunded'
    ])),'ORDER_NOT_REFUNDABLE');
    t21((int)$pdo->query('SELECT COUNT(*) FROM ticket_refund_audit')->fetchColumn()
        ===$auditBefore+1,'no new refund audit after fully refunded order');

    // #2087: every partition of three entries, both directions, mixed prices,
    // legacy evidence and zero-COP rejection, exclusively in this disposable DB.
    $pdo->exec("UPDATE ticket_buyers SET status='active',email_verified_at=UTC_TIMESTAMP(6) WHERE id=3");
    $_SESSION=[];
    $newOrder=static function(int $orderId,int $total,array $prices) use ($pdo):array {
        $ref=sprintf('%032x',$orderId);
        $pdo->prepare("INSERT INTO ticket_orders VALUES (?,UNHEX(?),3,1,?,'paid','COP',UTC_TIMESTAMP(6))")
            ->execute([$orderId,$ref,$total]);
        $refs=[];
        foreach ($prices as $i=>$price) {
            $entry=$orderId*10+$i; $entryRef=sprintf('%032x',$entry);
            $pdo->prepare('INSERT INTO ticket_order_items(id,order_id,unit_price_cop) VALUES (?,?,?)')->execute([$entry,$orderId,$price]);
            $pdo->prepare("INSERT INTO ticket_entries(id,public_id,order_item_id,status)
                VALUES (?,UNHEX(?),?,'issued')")->execute([$entry,$entryRef,$entry]);
            $refs[]=$entryRef;
        }
        return [$ref,$refs];
    };
    // Rejected claims are deliberately not released, even for mixed selections.
    [$rejectedRef,$rejectedEntries]=$newOrder(900,600,[300,300]);
    $rejectedInput=['order_id'=>$rejectedRef,'entry_ids'=>[$rejectedEntries[0]],
        'reason'=>'Motivo sintético de rechazo','idempotency_key'=>'t2122-rejected-original'];
    $rejected=brvtalRefundRequest($pdo,3,$rejectedInput);
    brvtalRefundStaff($pdo,7,'superadmin',(int)$rejected['id'],'reject','Motivo sintético');
    $beforeRejected=$snapshot();
    t21(brvtalRefundRequest($pdo,3,$rejectedInput)['replayed'],'rejected same-key replay');
    t21Reject(static fn()=>brvtalRefundRequest($pdo,3,array_replace($rejectedInput,[
        'entry_ids'=>$rejectedEntries,'idempotency_key'=>'t2122-rejected-mixed'
    ])),'REFUND_CLAIM_CONFLICT');
    t21($snapshot()===$beforeRejected,'rejected mixed selection leaves no partial request');

    // Real database failure in the second line insertion must not become a business conflict.
    [$failureRef,$failureEntries]=$newOrder(901,600,[300,300]);
    $failureInput=['order_id'=>$failureRef,'entry_ids'=>$failureEntries,
        'reason'=>'Fallo SQL sintético','idempotency_key'=>'t2122-db-failure'];
    $beforeFailure=$snapshot();
    $pdo->exec("CREATE TRIGGER t2122_fail BEFORE INSERT ON ticket_refund_entries FOR EACH ROW
        BEGIN IF NEW.entry_id=9011 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic failure'; END IF; END");
    try {
        try {
            brvtalRefundRequest($pdo,3,$failureInput);
            throw new RuntimeException('expected database error');
        } catch (PDOException $e) {
            t21(($e->errorInfo[0]??null)==='45000','unrelated SQL failure preserved');
        }
        t21(!$pdo->inTransaction() && $snapshot()===$beforeFailure,'SQL failure rolls back header and first line');
    } finally {
        $pdo->exec('DROP TRIGGER t2122_fail');
    }

    // Two independent PHP processes use their own database connections.
    // Hold the event mutex until both workers are ready, then release them together.
    $worker=<<<'PHP'
require $argv[1];
$db=new PDO('mysql:host='.(getenv('BRVTAL_TEST_DB_HOST')?:'127.0.0.1')
    .';port='.(getenv('BRVTAL_TEST_DB_PORT')?:3306).';dbname='.$argv[2].';charset=utf8mb4',
    getenv('BRVTAL_TEST_DB_USER')?:'root',getenv('BRVTAL_TEST_DB_PASS')?:'',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$db->exec('SET SESSION innodb_lock_wait_timeout=5');
echo "ready\n"; fflush(STDOUT);
if (fgets(STDIN)!=="go\n") exit(2);
try {
    $result=brvtalRefundRequest($db,3,json_decode($argv[3],true,512,JSON_THROW_ON_ERROR));
    echo ($result['replayed']?'replayed':'created')."\n";
} catch (DomainException $e) {
    echo $e->getMessage()."\n";
} catch (Throwable) {
    echo "unexpected\n";
}
PHP;
    foreach ([902=>false,903=>true] as $raceOrder=>$sameKey) {
        [$raceRef,$raceEntries]=$newOrder($raceOrder,600,[300,300]);
        $raceInput=['order_id'=>$raceRef,'entry_ids'=>[$raceEntries[0]],
            'reason'=>'Concurrencia sintética','idempotency_key'=>'t2122-race-'.$raceOrder.'-one'];
        $raceBefore=$snapshot(); $children=[]; $results=[];
        $pdo->beginTransaction();
        try {
            $pdo->query('SELECT id FROM events WHERE id=1 FOR UPDATE')->fetchColumn();
            foreach ([0,1] as $index) {
                $input=$raceInput;
                if ($index===1&&!$sameKey) $input['idempotency_key']='t2122-race-'.$raceOrder.'-two';
                $proc=proc_open([PHP_BINARY,'-r',$worker,dirname(__DIR__,2).'/config/ticketing_refunds.php',
                    $dbName,json_encode($input,JSON_THROW_ON_ERROR)],
                    [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
                t21(is_resource($proc),'concurrent worker started');
                $children[]=[$proc,$pipes];
                stream_set_timeout($pipes[1],10);
                t21(fgets($pipes[1])==="ready\n",'independent connection ready');
            }
            foreach ($children as [$proc,$pipes]) {fwrite($pipes[0],"go\n"); fflush($pipes[0]);}
            usleep(100000);
            foreach ($children as [$proc,$pipes]) t21(proc_get_status($proc)['running'],'worker awaits mutex');
            $pdo->commit();
            foreach ($children as [$proc,$pipes]) $results[]=trim((string)fgets($pipes[1]));
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            foreach ($children as [$proc,$pipes]) {
                foreach ($pipes as $pipe) fclose($pipe);
                if (proc_get_status($proc)['running']) proc_terminate($proc);
                proc_close($proc);
            }
        }
        sort($results,SORT_STRING);
        t21($results===($sameKey?['created','replayed']:['REFUND_CLAIM_CONFLICT','created']),
            'concurrent outcomes: '.implode(',',$results));
        $after=$snapshot();
        foreach (['ticket_refunds','ticket_refund_entries','ticket_refund_audit'] as $table)
            t21(count($after[$table])===count($raceBefore[$table])+1,'one concurrent '.$table.' row');
        t21($after['ticket_refund_adjustments']===$raceBefore['ticket_refund_adjustments'],
            'concurrent conflict creates no financial adjustment');
    }

    // Deliberately inconsistent synthetic history exercises the UNIQUE fallback:
    // the second line collides with a claim outside the current order's ledger.
    [$collisionRef,$collisionEntries]=$newOrder(904,600,[300,300]);
    $pdo->prepare('INSERT INTO ticket_refund_entries(refund_id,entry_id,amount_cop) VALUES(?,?,?)')
        ->execute([$rejected['id'],9041,300]);
    $beforeCollision=$snapshot();
    t21Reject(static fn()=>brvtalRefundRequest($pdo,3,[
        'order_id'=>$collisionRef,'entry_ids'=>$collisionEntries,
        'reason'=>'Colisión sintética UNIQUE','idempotency_key'=>'t2122-unique-fallback'
    ]),'REFUND_CLAIM_CONFLICT');
    t21(!$pdo->inTransaction() && $snapshot()===$beforeCollision,
        'exact unique collision rolls back header and first line');
    $partitions=[[[0,1,2]],[[0],[1,2]],[[1],[0,2]],[[2],[0,1]],[[0],[1],[2]]];
    $orderId=1000;
    foreach ([[10000,[4000,4000,4000],[3333,3333,3334]],
              [10000,[2000,3000,7000],[1666,2500,5834]],
              [999999999999,[333333333333,333333333333,333333333334],
                [333333333332,333333333333,333333333334]]] as [$total,$prices,$expected]) {
        foreach ($partitions as $partition) foreach ([false,true] as $reverse) {
            $orderId++; [$orderRef,$refs]=$newOrder($orderId,$total,$prices);
            $groups=$reverse?array_reverse($partition):$partition;
            $sum=0;
            foreach ($groups as $i=>$group) {
                $selected=array_map(static fn($index)=>$refs[$index],$reverse?array_reverse($group):$group);
                $input=['order_id'=>$orderRef,'entry_ids'=>$selected,'reason'=>'Reparto sintético exacto',
                    'idempotency_key'=>'t21-exact-'.$orderId.'-'.$i];
                $refund=brvtalRefundRequest($pdo,3,$input);
                $expectedAmount=array_sum(array_map(static fn($index)=>$expected[$index],$group));
                t21($refund['amount_cop']===$expectedAmount,'partial amount invariant across partitions');
                $line=$pdo->prepare('SELECT entry_id,amount_cop FROM ticket_refund_entries WHERE refund_id=?');
                $line->execute([$refund['id']]);
                $rows=$line->fetchAll(PDO::FETCH_KEY_PAIR);
                t21(count($rows)===count($group),'one net row per claimed entry');
                foreach ($group as $index) t21((int)$rows[$orderId*10+$index]===$expected[$index],
                    'persisted net COP, not gross');
                $sum+=$refund['amount_cop'];
                t21($sum<=$total,'no over-refund');
                $replay=brvtalRefundRequest($pdo,3,array_replace($input,['entry_ids'=>array_reverse($selected)]));
                t21($replay['replayed']&&(int)$replay['id']===(int)$refund['id'],'same-key replay');
                t21Reject(static fn()=>brvtalRefundRequest($pdo,3,array_replace($input,[
                    'idempotency_key'=>'t21-duplicate-'.$orderId.'-'.$i])),'REFUND_CLAIM_CONFLICT');
            }
            t21($sum===$total,'all disjoint claims reconcile paid total exactly');
        }
    }
    [$legacyRef,$legacyEntries]=$newOrder(++$orderId,10000,[4000,4000,4000]);
    $legacyInput=['order_id'=>$legacyRef,'entry_ids'=>[$legacyEntries[0]],'reason'=>'Legado sintético',
        'idempotency_key'=>'t21-legacy-existing'];
    $old=brvtalRefundRequest($pdo,3,$legacyInput);
    // Model the old persisted gross entry amount, without rewriting during requests.
    $pdo->prepare('UPDATE ticket_refund_entries SET amount_cop=4000 WHERE refund_id=?')->execute([$old['id']]);
    $before=$pdo->query('SELECT COUNT(*) FROM ticket_refunds')->fetchColumn();
    t21(brvtalRefundRequest($pdo,3,$legacyInput)['replayed'],'legacy replay is unchanged');
    t21Reject(static fn()=>brvtalRefundRequest($pdo,3,array_replace($legacyInput,[
        'entry_ids'=>[$legacyEntries[1]],'idempotency_key'=>'t21-legacy-new-claim'])),'AMOUNT_UNVERIFIED');
    t21($pdo->query('SELECT COUNT(*) FROM ticket_refunds')->fetchColumn()===$before,'legacy mismatch creates no claim');
    t21((int)$pdo->query('SELECT amount_cop FROM ticket_refund_entries WHERE refund_id='.(int)$old['id'])->fetchColumn()
        ===4000,'legacy line remains immutable');
    // Legacy header and finalized adjustment discrepancies are independent gates.
    [$auditRef,$auditEntries]=$newOrder(++$orderId,10000,[4000,4000,4000]);
    $auditOrder=$orderId;
    $auditInput=['order_id'=>$auditRef,'entry_ids'=>[$auditEntries[0]],'reason'=>'Conciliación sintética',
        'idempotency_key'=>'t21-audit-existing'];
    $record=brvtalRefundRequest($pdo,3,$auditInput);
    $recordId=(int)$record['id'];
    $nextInput=array_replace($auditInput,['entry_ids'=>[$auditEntries[1]],
        'idempotency_key'=>'t21-audit-next-claim']);
    $pdo->prepare('UPDATE ticket_refunds SET amount_cop=3332 WHERE id=?')->execute([$recordId]);
    t21Reject(static fn()=>brvtalRefundRequest($pdo,3,$nextInput),'AMOUNT_UNVERIFIED');
    t21((int)$pdo->query('SELECT amount_cop FROM ticket_refunds WHERE id='.$recordId)->fetchColumn()
        ===3332,'legacy header remains immutable');
    // Restore only this synthetic fixture, then model an already recorded receipt.
    $pdo->prepare("UPDATE ticket_refunds SET amount_cop=3333,status='refunded' WHERE id=?")->execute([$recordId]);
    $pdo->prepare("UPDATE ticket_entries SET status='refunded' WHERE id=?")->execute([$auditOrder*10]);
    $pdo->prepare('INSERT INTO ticket_refund_adjustments(refund_id,order_id,event_id,amount_cop)
        VALUES (?,?,1,3332)')->execute([$recordId,$auditOrder]);
    t21Reject(static fn()=>brvtalRefundRequest($pdo,3,$nextInput),'AMOUNT_UNVERIFIED');
    t21((int)$pdo->query('SELECT amount_cop FROM ticket_refund_adjustments WHERE refund_id='.$recordId)->fetchColumn()
        ===3332,'historic adjustment remains immutable');
    $pdo->prepare('UPDATE ticket_refund_adjustments SET amount_cop=3333 WHERE refund_id=?')->execute([$recordId]);
    t21(brvtalRefundRequest($pdo,3,$nextInput)['amount_cop']===3333,'consistent finalized net claim permits disjoint request');
    $before=$pdo->query('SELECT COUNT(*) FROM ticket_refunds')->fetchColumn();

    [$zeroRef,$zeroEntries]=$newOrder(++$orderId,1,[1,1,1]);
    t21Reject(static fn()=>brvtalRefundRequest($pdo,3,[
        'order_id'=>$zeroRef,'entry_ids'=>[$zeroEntries[2]],'reason'=>'Cero sintético',
        'idempotency_key'=>'t21-zero-net-claim']),'AMOUNT_UNVERIFIED');
    t21($pdo->query('SELECT COUNT(*) FROM ticket_refunds')->fetchColumn()===$before,'zero allocation creates no claim');

    putenv('BRVTAL_TICKET_MAIL_TESTING=0');
    putenv('BRVTAL_INTEGRATION_TESTS=0');
    try { t21Reject(static fn()=>brvtalRefundGuard($pdo),'REFUND_SANDBOX_ONLY'); }
    finally { putenv('BRVTAL_INTEGRATION_TESTS=1'); }
} finally {
    $root->exec("DROP DATABASE IF EXISTS $dbName");
}
echo "T21 synthetic MariaDB partial refunds, provider receipts, QR revocation, audit and idempotency passed.\n";
