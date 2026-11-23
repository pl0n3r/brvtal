<?php
declare(strict_types=1);

/** #1929: one order with several ticket types, one hold per type, server prices only. */
require_once __DIR__ . '/../../config/public_ticket_checkout.php';

function cartAssert(bool $ok, string $what): void
{
    if (!$ok) throw new RuntimeException('CART: ' . $what);
}
function cartRejects(callable $callback, string $what, ?string $code = null): void
{
    try {
        $callback();
    } catch (InvalidArgumentException | DomainException $error) {
        cartAssert($code === null || $error->getMessage() === $code,
            $what . ' (got ' . $error->getMessage() . ')');
        return;
    }
    cartAssert(false, $what . ' was accepted');
}

// Input validation never needs a database.
foreach ([
    [], [['ticket_type_id'=>1]], [['ticket_type_id'=>1,'quantity'=>0]],
    [['ticket_type_id'=>1,'quantity'=>21]], [['ticket_type_id'=>'1','quantity'=>1]],
    [['ticket_type_id'=>1,'quantity'=>1],['ticket_type_id'=>1,'quantity'=>2]],
    [['ticket_type_id'=>1,'quantity'=>15],['ticket_type_id'=>2,'quantity'=>6]],
    [['ticket_type_id'=>1,'quantity'=>1,'price'=>1]],
    array_map(static fn(int $i): array => ['ticket_type_id'=>$i,'quantity'=>1], range(1, 11)),
    ['a'=>['ticket_type_id'=>1,'quantity'=>1]],
] as $bad) {
    cartRejects(fn() => brvtal_public_ticket_checkout_cart_lines($bad), 'invalid lines ' . json_encode($bad));
}
cartAssert(brvtal_public_ticket_checkout_cart_lines([
    ['ticket_type_id'=>31,'quantity'=>2], ['ticket_type_id'=>30,'quantity'=>3],
]) === [30=>3, 31=>2], 'lines are canonicalised by type id');

if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "BRVTAL cart line validation passed; MariaDB checks run in disposable CI database.\n";
    exit(0);
}
$base = (string)getenv('BRVTAL_TEST_DB_NAME');
cartAssert((bool)preg_match('/^brvtal_test[A-Za-z0-9_]*$/D', $base), 'disposable DB namespace');
$host = (string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1');
$port = (int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306);
$user = (string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root');
$pass = (string)(getenv('BRVTAL_TEST_DB_PASS') ?: '');
$opt = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false];
$server = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, $opt);
$name = $base . '_cart_' . bin2hex(random_bytes(3));
$server->exec("CREATE DATABASE $name");
register_shutdown_function(static function () use ($server, $name): void {
    try { $server->exec("DROP DATABASE IF EXISTS $name"); } catch (Throwable) {}
});
$db = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $pass, $opt);
foreach ([
    "CREATE TABLE events (id INT UNSIGNED PRIMARY KEY,status VARCHAR(32),event_date DATETIME,published_at DATETIME)",
    "CREATE TABLE event_ticket_types (id INT UNSIGNED PRIMARY KEY,event_id INT UNSIGNED,
        name VARCHAR(120) NOT NULL,price INT UNSIGNED NOT NULL,sort_order INT NOT NULL DEFAULT 0,
        status VARCHAR(32),currency VARCHAR(3),available_from DATETIME,available_until DATETIME)",
    "CREATE TABLE ticket_inventory_events (event_id INT UNSIGNED PRIMARY KEY,capacity INT UNSIGNED)",
    "CREATE TABLE ticket_inventory_types (ticket_type_id INT UNSIGNED PRIMARY KEY,
        event_id INT UNSIGNED,capacity INT UNSIGNED,courtesy_capacity INT UNSIGNED DEFAULT 0)",
    "CREATE TABLE ticket_orders (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,event_id INT UNSIGNED,
        buyer_id BIGINT UNSIGNED,status VARCHAR(20),public_id BINARY(16),checkout_key_hash CHAR(64) UNIQUE,
        currency CHAR(3),total_cop BIGINT UNSIGNED,expires_at_utc DATETIME(6),updated_at_utc DATETIME(6))",
    "CREATE TABLE ticket_order_items (order_id BIGINT UNSIGNED,ticket_type_id INT UNSIGNED,
        quantity INT,event_id INT UNSIGNED,ticket_name_snapshot VARCHAR(120),
        unit_price_cop BIGINT UNSIGNED,subtotal_cop BIGINT UNSIGNED,currency CHAR(3))",
    "CREATE TABLE ticket_inventory_holds (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED,
        event_id INT UNSIGNED,ticket_type_id INT UNSIGNED,quantity INT,kind VARCHAR(20),status VARCHAR(20),
        expires_at_utc DATETIME(6),UNIQUE KEY uq_hold_order_type(order_id,ticket_type_id))",
    "CREATE TABLE ticket_payment_attempts (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_id BIGINT UNSIGNED,provider VARCHAR(24),provider_reference VARCHAR(190),
        idempotency_key_hash CHAR(64),gross_amount_cop BIGINT UNSIGNED,status VARCHAR(20))",
] as $ddl) $db->exec($ddl);

foreach ([
    'BRVTAL_TICKET_WOMPI_MODE=mock', 'BRVTAL_TICKET_WOMPI_TESTING=1',
    'BRVTAL_TICKET_WOMPI_PUBLIC_KEY=pub_test_cart_fixture', 'BRVTAL_TICKET_WOMPI_PRIVATE_KEY=prv_test_cart_fixture',
    'BRVTAL_TICKET_WOMPI_INTEGRITY_SECRET=test_integrity_cart_fixture',
    'BRVTAL_TICKET_WOMPI_ENABLED_METHODS=CARD,NEQUI', 'BRVTAL_TICKET_WOMPI_RETURN_URL=https://example.test/return',
] as $setting) putenv($setting);

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$eventAt = $now->modify('+5 days')->setTimezone(new DateTimeZone('America/Bogota'))->format('Y-m-d H:i:s');
$from = $now->modify('-1 day')->format('Y-m-d H:i:s');
$until = $now->modify('+2 days')->format('Y-m-d H:i:s');
$db->prepare("INSERT INTO events VALUES (1,'published',?,?),(2,'published',?,?)")
    ->execute([$eventAt, $from, $eventAt, $from]);
$types = $db->prepare("INSERT INTO event_ticket_types
    (id,event_id,name,price,status,currency,available_from,available_until) VALUES (?,?,?,?, 'active','COP',?,?)");
foreach ([[10,1,'Preventa',25000],[11,1,'VIP',60000],[12,1,'Mesa',150000],[20,2,'Otro evento',10000]] as $row) {
    $types->execute([...$row, $from, $until]);
}
$db->exec('INSERT INTO ticket_inventory_events VALUES (1,10),(2,10)');
$db->exec('INSERT INTO ticket_inventory_types VALUES (10,1,6,0),(11,1,3,0),(12,1,1,0),(20,2,5,0)');

$cart = ['lines'=>[['ticket_type_id'=>11,'quantity'=>1],['ticket_type_id'=>10,'quantity'=>2]],
    'idempotency_key'=>str_repeat('c', 24), 'method'=>'CARD'];
$first = brvtal_public_ticket_checkout_prepare($db, 1, 7, $cart);
$orderId = (int)$first['order_id'];
cartAssert($orderId > 0, 'order created');
$items = $db->query("SELECT ticket_type_id,quantity,unit_price_cop,subtotal_cop,ticket_name_snapshot
    FROM ticket_order_items WHERE order_id=$orderId ORDER BY ticket_type_id")->fetchAll();
cartAssert(count($items) === 2, 'one item per ticket type');
cartAssert((int)$items[0]['ticket_type_id'] === 10 && (int)$items[0]['quantity'] === 2
    && (int)$items[0]['subtotal_cop'] === 50000 && $items[0]['ticket_name_snapshot'] === 'Preventa', 'Preventa line');
cartAssert((int)$items[1]['ticket_type_id'] === 11 && (int)$items[1]['subtotal_cop'] === 60000, 'VIP line');
cartAssert((int)$db->query("SELECT total_cop FROM ticket_orders WHERE id=$orderId")->fetchColumn() === 110000,
    'order total is the sum of server prices');
$holds = $db->query("SELECT ticket_type_id,quantity FROM ticket_inventory_holds
    WHERE order_id=$orderId AND status='active' ORDER BY ticket_type_id")->fetchAll();
cartAssert(array_map(fn($h) => [(int)$h['ticket_type_id'], (int)$h['quantity']], $holds) === [[10,2],[11,1]],
    'one active hold per ticket type');
cartAssert($first['checkout']['amount_in_cents'] === 11000000, 'provider amount equals the order total');

// Idempotent retry: same lines in any order => same order and reference.
$retry = $cart;
$retry['lines'] = array_reverse($cart['lines']);
$again = brvtal_public_ticket_checkout_prepare($db, 1, 7, $retry);
cartAssert($again === $first, 'retry returns the same order and payment reference');
cartAssert((int)$db->query('SELECT COUNT(*) FROM ticket_orders')->fetchColumn() === 1, 'no duplicate order');
$changed = $cart;
$changed['lines'][1]['quantity'] = 3;
cartRejects(fn() => brvtal_public_ticket_checkout_prepare($db, 1, 7, $changed),
    'retry with different lines', 'CHECKOUT_RETRY_CONFLICT');

// Insufficient capacity, foreign types and unknown methods leave no order or hold behind.
$before = (int)$db->query('SELECT COUNT(*) FROM ticket_orders')->fetchColumn();
$holdsBefore = (int)$db->query('SELECT COUNT(*) FROM ticket_inventory_holds')->fetchColumn();
foreach ([
    [[['ticket_type_id'=>10,'quantity'=>1],['ticket_type_id'=>12,'quantity'=>2]], 'too many Mesa'],
    [[['ticket_type_id'=>10,'quantity'=>1],['ticket_type_id'=>20,'quantity'=>1]], 'type from another event'],
    [[['ticket_type_id'=>10,'quantity'=>1],['ticket_type_id'=>99,'quantity'=>1]], 'unknown type'],
] as $i => [$lines, $what]) {
    cartRejects(fn() => brvtal_public_ticket_checkout_prepare($db, 1, 9,
        ['lines'=>$lines, 'idempotency_key'=>str_repeat((string)$i, 20), 'method'=>'CARD']), $what);
}
cartRejects(fn() => brvtal_public_ticket_checkout_prepare($db, 1, 9,
    ['lines'=>[['ticket_type_id'=>10,'quantity'=>1]], 'idempotency_key'=>str_repeat('p', 20), 'method'=>'PSE']),
    'method outside the enabled list');
cartRejects(fn() => brvtal_public_ticket_checkout_prepare($db, 1, 9,
    ['lines'=>[['ticket_type_id'=>10,'quantity'=>1]], 'idempotency_key'=>str_repeat('q', 20), 'method'=>'CARD',
     'promo_code'=>'PROMO2026']), 'promo codes stay single-type');
cartAssert((int)$db->query('SELECT COUNT(*) FROM ticket_orders')->fetchColumn() === $before, 'failed carts roll back orders');
cartAssert((int)$db->query('SELECT COUNT(*) FROM ticket_inventory_holds')->fetchColumn() === $holdsBefore,
    'failed carts roll back holds');

// Capacity already held by the first cart is respected: 3 VIP minus 1 held leaves 2.
cartRejects(fn() => brvtal_public_ticket_checkout_prepare($db, 1, 10,
    ['lines'=>[['ticket_type_id'=>11,'quantity'=>3]], 'idempotency_key'=>str_repeat('v', 20), 'method'=>'NEQUI']),
    'held VIP seats are not oversold');
$ok = brvtal_public_ticket_checkout_prepare($db, 1, 10,
    ['lines'=>[['ticket_type_id'=>11,'quantity'=>2],['ticket_type_id'=>12,'quantity'=>1]],
     'idempotency_key'=>str_repeat('w', 20), 'method'=>'NEQUI']);
cartAssert((int)$ok['order_id'] > $orderId, 'remaining capacity can still be sold');

// The single-type form keeps working unchanged.
$single = brvtal_public_ticket_checkout_prepare($db, 1, 11,
    ['ticket_type_id'=>10,'quantity'=>1,'idempotency_key'=>str_repeat('s', 20),'method'=>'CARD']);
cartAssert((int)$single['order_id'] > 0, 'single-type checkout still works');
echo "BRVTAL #1929 cart: multi-type orders, holds, idempotency, capacity and rollback passed.\n";
