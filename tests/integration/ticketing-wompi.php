<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/ticketing_wompi_client.php';

function promoWompiAssert(bool $ok,string $what):void {
    if (!$ok) throw new RuntimeException('T07_COUPONS: '.$what);
}
function promoWompiReject(callable $action,string $expected):void {
    try { $action(); } catch (DomainException $e) {
        promoWompiAssert($e->getMessage()===$expected,
            'expected '.$expected.' not '.$e->getMessage());
        return;
    }
    throw new RuntimeException('T07_COUPONS: missing denial '.$expected);
}
$source=(string)file_get_contents(__DIR__.'/../../config/ticketing_wompi_client.php');
promoWompiAssert(str_contains($source,'FROM ticket_promo_redemptions WHERE order_id=? FOR UPDATE'),
    'price adapter locks the exact persisted redemption');
if (getenv('BRVTAL_INTEGRATION_TESTS')!=='1') {
    echo "T07 coupon price checks: MariaDB runs only in disposable integration namespace.\n";
    return;
}
$prefix=(string)(getenv('BRVTAL_TEST_DB_NAME')?:'');
promoWompiAssert((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/D',$prefix),
    'disposable database only');
$host=(string)(getenv('BRVTAL_TEST_DB_HOST')?:'127.0.0.1');
$port=(int)(getenv('BRVTAL_TEST_DB_PORT')?:3306);
$user=(string)(getenv('BRVTAL_TEST_DB_USER')?:'root');
$pass=(string)(getenv('BRVTAL_TEST_DB_PASS')?:'');
$opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$root=new PDO("mysql:host=$host;port=$port;charset=utf8mb4",$user,$pass,$opts);
$name=$prefix.'_t07coupon_'.bin2hex(random_bytes(4));
$root->exec("CREATE DATABASE $name CHARACTER SET utf8mb4");
try {
    $db=new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",
        $user,$pass,$opts);
    foreach ([
        'CREATE TABLE ticket_orders (id BIGINT UNSIGNED PRIMARY KEY,
            buyer_id BIGINT UNSIGNED NOT NULL,event_id INT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL,currency CHAR(3) NOT NULL,
            total_cop BIGINT UNSIGNED NOT NULL,
            expires_at_utc DATETIME(6) NULL,updated_at_utc DATETIME(6) NULL) ENGINE=InnoDB',
        'CREATE TABLE ticket_order_items (order_id BIGINT UNSIGNED NOT NULL,
            ticket_type_id INT NOT NULL,quantity INT NOT NULL,
            unit_price_cop BIGINT NOT NULL,subtotal_cop BIGINT NOT NULL,
            currency CHAR(3) NOT NULL) ENGINE=InnoDB',
        'CREATE TABLE ticket_inventory_holds (order_id BIGINT UNSIGNED NOT NULL,
            ticket_type_id INT NOT NULL,quantity INT NOT NULL,
            status VARCHAR(16) NOT NULL,expires_at_utc DATETIME(6) NOT NULL) ENGINE=InnoDB',
        'CREATE TABLE ticket_payment_attempts (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,provider VARCHAR(24) NOT NULL,
            provider_reference VARCHAR(190) NOT NULL,idempotency_key_hash CHAR(64) NOT NULL,
            gross_amount_cop BIGINT UNSIGNED NOT NULL,status VARCHAR(20) NOT NULL) ENGINE=InnoDB',
        'CREATE TABLE ticket_promo_redemptions (order_id BIGINT UNSIGNED PRIMARY KEY,
            promo_id BIGINT UNSIGNED NOT NULL,buyer_id BIGINT UNSIGNED NOT NULL,
            event_id INT UNSIGNED NOT NULL,ticket_type_id INT NOT NULL,
            quantity INT NOT NULL,subtotal_cop BIGINT UNSIGNED NOT NULL,
            discount_cop BIGINT UNSIGNED NOT NULL,total_cop BIGINT UNSIGNED NOT NULL,
            code_digest CHAR(64) NOT NULL) ENGINE=InnoDB',
        "INSERT INTO ticket_orders VALUES
            (101,7,5,'draft','COP',20000,DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 10 MINUTE),NULL),
            (102,7,5,'draft','COP',20000,DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 10 MINUTE),NULL)",
        "INSERT INTO ticket_order_items VALUES
            (101,9,2,15000,30000,'COP'),(102,9,2,15000,30000,'COP')",
        "INSERT INTO ticket_inventory_holds VALUES
            (101,9,2,'active',DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 10 MINUTE)),
            (102,9,2,'active',DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 10 MINUTE))",
        "INSERT INTO ticket_promo_redemptions VALUES
            (101,10,7,5,9,2,30000,10000,20000,
             'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')",
    ] as $sql) $db->exec($sql);
    $cfg=['mode'=>'mock','methods'=>['CARD'],'public'=>'pub_test_disposable',
        'integrity'=>'test_integrity_disposable','returnUrl'=>'https://example.test/return'];
    $key='t07-coupon-test-key-0001';
    $db->beginTransaction();
    $payload=brvtal_ticket_wompi_prepare($db,7,101,$key,'CARD',$cfg);
    $again=brvtal_ticket_wompi_prepare($db,7,101,$key,'CARD',$cfg);
    promoWompiAssert($payload===$again,'frozen same-key retry');
    promoWompiAssert($payload['amount_in_cents']===2000000
        && $payload['currency']==='COP','provider receives net COP only');
    promoWompiAssert($payload['signature_integrity']===
        hash('sha256',$payload['reference'].'2000000COP'.$cfg['integrity']),
        'signature covers net amount, not gross items');
    $db->commit();
    promoWompiAssert((int)$db->query('SELECT gross_amount_cop FROM ticket_payment_attempts WHERE order_id=101')
        ->fetchColumn()===20000,'attempt records net Wompi gross');
    promoWompiAssert((int)$db->query("SELECT COUNT(*) FROM ticket_payment_attempts WHERE order_id=101")
        ->fetchColumn()===1,'one provider attempt');
    // A missing or tampered ledger can never discount a provider attempt.
    $db->beginTransaction();
    promoWompiReject(static fn()=>brvtal_ticket_wompi_prepare(
        $db,7,102,'t07-coupon-test-key-0002','CARD',$cfg),
        'WOMPI_PRICE_MISMATCH');
    $db->rollBack();
    foreach ([
        'buyer_id=8','event_id=6','ticket_type_id=10','quantity=3',
        'subtotal_cop=31000','discount_cop=9000','total_cop=19000'
    ] as $change) {
        $db->beginTransaction();
        $db->exec('UPDATE ticket_promo_redemptions SET '.$change.' WHERE order_id=101');
        promoWompiReject(static fn()=>brvtal_ticket_wompi_prepare(
            $db,7,101,$key,'CARD',$cfg),'WOMPI_PRICE_MISMATCH');
        $db->rollBack();
    }
    promoWompiAssert((int)$db->query('SELECT COUNT(*) FROM ticket_payment_attempts')
        ->fetchColumn()===1,'tamper attempts never insert another transaction');
    echo "T07 coupon ledger, net signature, idempotency and tamper denials passed.\n";
} finally {
    $root->exec("DROP DATABASE IF EXISTS $name");
}
