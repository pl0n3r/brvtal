<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/ticketing_wompi_client.php';
function wompiCheck(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException('T07: ' . $label);
}
function wompiReject(callable $cb, string $code): void {
    try { $cb(); } catch (DomainException $e) {
        wompiCheck($e->getMessage() === $code, 'expected ' . $code);
        return;
    }
    throw new RuntimeException('T07 expected rejection: ' . $code);
}
$env = [
    'BRVTAL_TICKET_WOMPI_MODE'=>'mock',
    'BRVTAL_TICKET_WOMPI_TESTING'=>'1',
    'BRVTAL_TICKET_WOMPI_PUBLIC_KEY'=>'pub_test_fixture_abc',
    'BRVTAL_TICKET_WOMPI_PRIVATE_KEY'=>'prv_test_fixture_abc',
    'BRVTAL_TICKET_WOMPI_INTEGRITY_SECRET'=>'test_integrity_fixture_abc',
    'BRVTAL_TICKET_WOMPI_ENABLED_METHODS'=>'CARD,NEQUI,PSE',
    'BRVTAL_TICKET_WOMPI_RETURN_URL'=>'https://example.invalid/payment-return',
];
wompiReject(fn()=>brvtal_ticket_wompi_config([]),'WOMPI_DISABLED');
wompiReject(fn()=>brvtal_ticket_wompi_config(array_replace($env,
    ['BRVTAL_TICKET_WOMPI_MODE'=>'live'])),'WOMPI_DISABLED');
wompiReject(fn()=>brvtal_ticket_wompi_config(array_replace($env,
    ['BRVTAL_TICKET_WOMPI_PRIVATE_KEY'=>''])),'WOMPI_CREDENTIALS_MISSING');
wompiReject(fn()=>brvtal_ticket_wompi_config(array_replace($env,
    ['BRVTAL_TICKET_WOMPI_PRIVATE_KEY'=>'prv_prod_test123'])),'WOMPI_CREDENTIALS_MISSING');
wompiReject(fn()=>brvtal_ticket_wompi_config(array_replace($env,
    ['BRVTAL_TICKET_WOMPI_RETURN_URL'=>'https://evil.invalid/#token'])),'WOMPI_RETURN_URL_INVALID');
wompiReject(fn()=>brvtal_ticket_wompi_config(array_replace($env,
    ['BRVTAL_TICKET_WOMPI_ENABLED_METHODS'=>'CARD,BOGUS'])),'WOMPI_METHODS_UNAVAILABLE');
$settings = brvtal_ticket_wompi_config($env);
$ref = 'BW7-42-CARD-' . str_repeat('a',24);
$payload = brvtal_ticket_wompi_payload($settings,$ref,25000,'CARD');
wompiCheck($payload['amount_in_cents'] === 2500000, 'COP to cents');
wompiCheck($payload['signature_integrity'] === hash('sha256',
    $ref . '2500000COP' . $env['BRVTAL_TICKET_WOMPI_INTEGRITY_SECRET']), 'signed server amount');
wompiCheck($payload['state']==='awaiting_provider', 'redirect never confirms payment');
wompiCheck(!str_contains(json_encode($payload,JSON_THROW_ON_ERROR), 'prv_test_'), 'private key hidden');
wompiReject(fn()=>brvtal_ticket_wompi_payload($settings,$ref,0,'CARD'),'WOMPI_INVALID_CHECKOUT');
wompiReject(fn()=>brvtal_ticket_wompi_payload($settings,$ref,25000,'OTHER'),'WOMPI_INVALID_CHECKOUT');
wompiCheck(brvtal_ticket_wompi_observe($settings,'tx_fixture',null)['state']==='unverified', 'no transport');
wompiCheck(brvtal_ticket_wompi_observe($settings,'tx_fixture',
    fn()=>['status'=>503,'body'=>''])['state']==='unverified', '5xx fails closed');
wompiCheck(brvtal_ticket_wompi_observe($settings,'tx_fixture',
    fn()=>throw new RuntimeException('timeout'))['state']==='unverified', 'timeout fails closed');
wompiCheck(brvtal_ticket_wompi_observe($settings,'tx_fixture',
    fn()=>['status'=>200,'body'=>'{"data":{"status":"APPROVED"}}'])['state']==='observed_only',
    'poll result cannot confirm sale');
if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "BRVTAL T07 Wompi mock contract passed; DB checks run in disposable MariaDB.\n";
    exit(0);
}
$prefix = (string)(getenv('BRVTAL_TEST_DB_NAME') ?: '');
wompiCheck((bool)preg_match('/^brvtal_test[A-Za-z0-9_]*$/D',$prefix),'disposable DB only');
$host=(string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1');
$port=(int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306);
$user=(string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root');
$pass=(string)(getenv('BRVTAL_TEST_DB_PASS') ?: '');
$server=new PDO("mysql:host={$host};port={$port};charset=utf8mb4",$user,$pass,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$dbname=$prefix.'_wompi_'.bin2hex(random_bytes(3));
$server->exec('CREATE DATABASE ' . $dbname);
register_shutdown_function(static function() use($server,$dbname): void {
    try {$server->exec('DROP DATABASE IF EXISTS ' . $dbname);} catch(Throwable) {}
});
$db=new PDO("mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4",$user,$pass,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
     PDO::ATTR_EMULATE_PREPARES=>false]);
$db->exec("CREATE TABLE ticket_orders(id INT PRIMARY KEY,buyer_id INT NOT NULL,
 status VARCHAR(16) NOT NULL,currency CHAR(3) NOT NULL,total_cop BIGINT UNSIGNED NOT NULL,
 expires_at_utc DATETIME(6) NULL,updated_at_utc DATETIME(6) NULL) ENGINE=InnoDB");
$db->exec("CREATE TABLE ticket_order_items(order_id INT NOT NULL,ticket_type_id INT NOT NULL,
 quantity INT NOT NULL,unit_price_cop BIGINT NOT NULL,subtotal_cop BIGINT NOT NULL,
 currency CHAR(3) NOT NULL) ENGINE=InnoDB");
$db->exec("CREATE TABLE ticket_inventory_holds(order_id INT NOT NULL,ticket_type_id INT NOT NULL,
 quantity INT NOT NULL,status VARCHAR(16) NOT NULL,expires_at_utc DATETIME(6) NOT NULL) ENGINE=InnoDB");
$db->exec("CREATE TABLE ticket_payment_attempts(id INT AUTO_INCREMENT PRIMARY KEY,
 order_id INT NOT NULL,provider VARCHAR(24) NOT NULL,provider_reference VARCHAR(190) NOT NULL,
 idempotency_key_hash CHAR(64) NOT NULL,gross_amount_cop BIGINT NOT NULL,status VARCHAR(16) NOT NULL,
 UNIQUE KEY uq_reference(provider,provider_reference),UNIQUE KEY uq_digest(idempotency_key_hash)) ENGINE=InnoDB");
$db->exec("INSERT INTO ticket_orders VALUES (42,7,'draft','COP',50000,DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 10 MINUTE),NULL)");
$db->exec("INSERT INTO ticket_order_items VALUES (42,5,2,25000,50000,'COP')");
$db->exec("INSERT INTO ticket_inventory_holds VALUES
 (42,5,2,'active',DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 10 MINUTE))");
$key='checkout-intent-same-0001';
$db->beginTransaction();
$one=brvtal_ticket_wompi_prepare($db,7,42,$key,'CARD',$settings);
$retry=brvtal_ticket_wompi_prepare($db,7,42,$key,'CARD',$settings);
wompiCheck($one===$retry,'idempotent checkout exact reference');
wompiReject(fn()=>brvtal_ticket_wompi_prepare($db,7,42,'checkout-new-key-0002','CARD',$settings),
    'WOMPI_ATTEMPT_CONFLICT');
wompiReject(fn()=>brvtal_ticket_wompi_prepare($db,8,42,$key,'CARD',$settings),
    'WOMPI_ORDER_NOT_FOUND');
$db->commit();
wompiCheck((int)$db->query('SELECT COUNT(*) FROM ticket_orders')->fetchColumn()===1, 'no duplicate order');
wompiCheck((int)$db->query('SELECT COUNT(*) FROM ticket_payment_attempts')->fetchColumn()===1, 'no duplicate attempt');
wompiCheck($db->query('SELECT status FROM ticket_orders WHERE id=42')->fetchColumn()==='pending',
    'never marked paid');
$db->exec('UPDATE ticket_order_items SET subtotal_cop=1 WHERE order_id=42');
$db->beginTransaction();
wompiReject(fn()=>brvtal_ticket_wompi_prepare($db,7,42,$key,'CARD',$settings), 'WOMPI_PRICE_MISMATCH');
$db->rollBack();
$db->exec('UPDATE ticket_order_items SET subtotal_cop=50000 WHERE order_id=42');
$db->exec("UPDATE ticket_inventory_holds SET expires_at_utc=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 SECOND)");
$db->beginTransaction();
wompiReject(fn()=>brvtal_ticket_wompi_prepare($db,7,42,$key,'CARD',$settings), 'WOMPI_RESERVATION_EXPIRED');
$db->rollBack();
echo "BRVTAL T07 Wompi exact DB price, ownership, idempotency, TTL and sandbox-only passed.\n";
