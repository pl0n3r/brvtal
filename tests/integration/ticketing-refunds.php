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
        'CREATE TABLE ticket_buyers (id BIGINT UNSIGNED PRIMARY KEY,email VARCHAR(190)) ENGINE=InnoDB',
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
        "INSERT INTO ticket_buyers VALUES (3,'comprador@brvtal.invalid'),(4,'otro@brvtal.invalid')",
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
    t21Reject(static fn()=>brvtalRefundDeclareIncident($pdo,7,'superadmin',1,'invalid'),'REFUND_INVALID');
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
    putenv('BRVTAL_TICKET_MAIL_TESTING=0');
    putenv('BRVTAL_INTEGRATION_TESTS=0');
    try { t21Reject(static fn()=>brvtalRefundGuard($pdo),'REFUND_SANDBOX_ONLY'); }
    finally { putenv('BRVTAL_INTEGRATION_TESTS=1'); }
} finally {
    $root->exec("DROP DATABASE IF EXISTS $dbName");
}
echo "T21 synthetic MariaDB partial refunds, provider receipts, QR revocation, audit and idempotency passed.\n";
