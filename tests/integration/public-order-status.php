<?php
declare(strict_types=1);

/** #1958: purchase confirmation by opaque token (disposable MariaDB). */
require_once __DIR__ . '/../../config/public_order_status.php';

function orderStatusAssert(bool $ok, string $what): void
{
    if (!$ok) throw new RuntimeException('ORDER_STATUS: ' . $what);
}

orderStatusAssert(brvtal_public_order_mask_email('Ana.Gomez@Example.TEST') === 'a***@example.test', 'email masked');
orderStatusAssert(brvtal_public_order_mask_email('no-email') === '', 'invalid email gives no hint');

if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "BRVTAL #1958 order status mask passed; MariaDB checks need BRVTAL_INTEGRATION_TESTS=1.\n";
    exit(0);
}
$base = (string)getenv('BRVTAL_TEST_DB_NAME');
orderStatusAssert((bool)preg_match('/^brvtal_test[A-Za-z0-9_]*$/D', $base), 'disposable DB namespace');
$host = (string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1');
$port = (int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306);
$user = (string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root');
$pass = (string)(getenv('BRVTAL_TEST_DB_PASS') ?: '');
$opt = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false];
$server = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, $opt);
$name = $base . '_orderstatus_' . bin2hex(random_bytes(3));
$server->exec("CREATE DATABASE $name");
register_shutdown_function(static function () use ($server, $name): void {
    try { $server->exec("DROP DATABASE IF EXISTS $name"); } catch (Throwable) {}
});
$db = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $pass, $opt);
foreach ([
    "CREATE TABLE events(id INT UNSIGNED PRIMARY KEY,title VARCHAR(180) NOT NULL,slug VARCHAR(190) NOT NULL)",
    "CREATE TABLE ticket_buyers(id BIGINT UNSIGNED PRIMARY KEY,email VARCHAR(190) NULL)",
    "CREATE TABLE ticket_orders(id BIGINT UNSIGNED PRIMARY KEY,public_id BINARY(16) NOT NULL,event_id INT UNSIGNED NOT NULL,
        buyer_id BIGINT UNSIGNED NOT NULL,status VARCHAR(20) NOT NULL,total_cop BIGINT NOT NULL)",
    "CREATE TABLE ticket_order_items(id BIGINT UNSIGNED PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,
        ticket_name_snapshot VARCHAR(120) NOT NULL,quantity INT UNSIGNED NOT NULL)",
    "CREATE TABLE ticket_payment_attempts(id BIGINT UNSIGNED PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,
        provider VARCHAR(24) NOT NULL,status VARCHAR(16) NOT NULL,updated_at_utc DATETIME(6) NOT NULL)",
] as $ddl) $db->exec($ddl);
$mine = str_repeat('ab', 16);
$other = str_repeat('cd', 16);
$db->exec("INSERT INTO events VALUES (1,'Genesis','genesis')");
$db->exec("INSERT INTO ticket_buyers VALUES (8,'ana@example.test'),(9,'beto@example.test')");
$db->exec("INSERT INTO ticket_orders VALUES (6,UNHEX('$mine'),1,8,'paid',50000),(7,UNHEX('$other'),1,9,'pending',25000)");
$db->exec("INSERT INTO ticket_order_items VALUES (1,6,'Preventa',2),(2,7,'VIP',1)");

$order = brvtal_public_order_status($db, $mine);
orderStatusAssert($order === ['order_number'=>6, 'state'=>'approved', 'event_title'=>'Genesis',
    'event_url'=>'/events/genesis', 'total_cop'=>50000, 'items'=>[['name'=>'Preventa','quantity'=>2]],
    'email_hint'=>'a***@example.test'], 'own order by token, masked email only');
orderStatusAssert(!str_contains(json_encode($order), 'beto') && !str_contains(json_encode($order), 'VIP'),
    'no data from other orders');
// A pending order without an open Wompi attempt stays pending (no reconcile call is possible).
orderStatusAssert(brvtal_public_order_status($db, $other)['state'] === 'pending', 'pending order');
foreach (['', 'xyz', str_repeat('A', 32), str_repeat('0', 32), $mine . '00'] as $bad) {
    orderStatusAssert(brvtal_public_order_status($db, $bad) === null, 'invalid or unknown token reveals nothing: ' . $bad);
}
echo "BRVTAL #1958 order status by token passed against disposable MariaDB.\n";
