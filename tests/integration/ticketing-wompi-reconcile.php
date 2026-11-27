<?php
declare(strict_types=1);

/** #1952: confirm lost-webhook payments by asking Wompi (simulated transport, disposable MariaDB). */
require_once __DIR__ . '/../../config/ticketing_wompi_reconcile.php';

function reconcileAssert(bool $ok, string $what): void
{
    if (!$ok) throw new RuntimeException('WOMPI_RECONCILE: ' . $what);
}

$reference = 'BW7-42-CARD-' . str_repeat('a', 24);
$config = ['mode'=>'sandbox', 'apiBase'=>'https://sandbox.wompi.co/v1', 'private'=>'prv_test_synthetic_only'];
$calls = [];
$wompi = static function (array $transactions, int $status = 200) use (&$calls): callable {
    return static function (string $url, array $headers) use ($transactions, $status, &$calls): array {
        $calls[] = [$url, $headers];
        return ['status'=>$status, 'body'=>json_encode(['data'=>$transactions], JSON_THROW_ON_ERROR)];
    };
};
$tx = static fn(string $state, int $cents = 5000000, string $ref = '') => ['id'=>'12261959-1795504284-15491',
    'reference'=>$ref !== '' ? $ref : $reference, 'status'=>$state, 'currency'=>'COP', 'amount_in_cents'=>$cents];

// Contract (no DB): the private key and the environment's API base are used.
reconcileAssert(brvtal_wompi_reconcile_fetch($config, $reference, $wompi([$tx('APPROVED')]))['status'] === 'APPROVED',
    'approved transaction found by reference');
reconcileAssert($calls[0][0] === 'https://sandbox.wompi.co/v1/transactions?reference=' . $reference
    && $calls[0][1] === ['Authorization'=>'Bearer prv_test_synthetic_only'], 'server-side authenticated query');
reconcileAssert(brvtal_wompi_reconcile_fetch($config, $reference,
    $wompi([$tx('DECLINED'), $tx('APPROVED')]))['status'] === 'APPROVED', 'an approved retry wins over a declined one');
reconcileAssert(brvtal_wompi_reconcile_fetch($config, $reference, $wompi([$tx('APPROVED', 5000000, 'BW7-9-CARD-' . str_repeat('b', 24))])) === null,
    'another reference is ignored');

if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "BRVTAL #1952 reconcile contract passed; MariaDB checks need BRVTAL_INTEGRATION_TESTS=1.\n";
    exit(0);
}
$base = (string)getenv('BRVTAL_TEST_DB_NAME');
reconcileAssert((bool)preg_match('/^brvtal_test[A-Za-z0-9_]*$/D', $base), 'disposable DB namespace');
$host = (string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1');
$port = (int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306);
$user = (string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root');
$pass = (string)(getenv('BRVTAL_TEST_DB_PASS') ?: '');
$opt = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false];
$server = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, $opt);
$name = $base . '_reconcile_' . bin2hex(random_bytes(3));
$server->exec("CREATE DATABASE $name");
register_shutdown_function(static function () use ($server, $name): void {
    try { $server->exec("DROP DATABASE IF EXISTS $name"); } catch (Throwable) {}
});
$db = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $pass, $opt);
$db->exec("CREATE TABLE events(id INT UNSIGNED PRIMARY KEY,status VARCHAR(32) NOT NULL) ENGINE=InnoDB");
$db->exec("CREATE TABLE event_ticket_types(id INT UNSIGNED PRIMARY KEY,event_id INT UNSIGNED NOT NULL,
 UNIQUE KEY uq_t03_ticket_type_event(id,event_id)) ENGINE=InnoDB");
$db->exec("CREATE TABLE ticket_orders(id BIGINT UNSIGNED PRIMARY KEY,event_id INT UNSIGNED NOT NULL,
 status VARCHAR(16) NOT NULL,currency CHAR(3) NOT NULL,total_cop BIGINT NOT NULL,
 expires_at_utc DATETIME(6) NULL,updated_at_utc DATETIME(6) NULL,
 UNIQUE KEY uq_ticket_orders_id_event(id,event_id)) ENGINE=InnoDB");
$db->exec("CREATE TABLE ticket_order_items(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,event_id INT UNSIGNED NOT NULL,ticket_type_id INT UNSIGNED NOT NULL,
 quantity INT UNSIGNED NOT NULL,currency CHAR(3) NOT NULL,
 UNIQUE KEY uq_ticket_order_item_type(order_id,ticket_type_id)) ENGINE=InnoDB");
$db->exec("CREATE TABLE ticket_refund_event_decisions(event_id INT UNSIGNED PRIMARY KEY,cause VARCHAR(20) NOT NULL) ENGINE=InnoDB");
$db->exec("INSERT INTO events VALUES(1,'published')");
$db->exec("INSERT INTO event_ticket_types VALUES(10,1)");
$holdsSql=(string)file_get_contents(__DIR__.'/../../database/migration_ticketing_reservations_01.sql');
foreach(explode(';',(string)preg_replace('/^\\h*--[^\\r\\n]*$/m','',$holdsSql)) as $ddl)
    if(trim($ddl)!=='') $db->exec($ddl);
$db->exec("INSERT INTO ticket_inventory_events VALUES(1,100)");
$db->exec("INSERT INTO ticket_inventory_types(ticket_type_id,event_id,capacity,courtesy_capacity)
 VALUES(10,1,100,0)");
$db->exec("CREATE TABLE ticket_payment_attempts(id BIGINT UNSIGNED PRIMARY KEY,order_id INT NOT NULL,
 provider VARCHAR(24) NOT NULL,provider_reference VARCHAR(190) NOT NULL,
 provider_transaction_id VARCHAR(190) NULL,gross_amount_cop BIGINT NOT NULL,status ENUM('created','pending','approved','declined','error','refunded') NOT NULL,
 created_at_utc DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6)),
 updated_at_utc DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6) - INTERVAL 5 MINUTE),
 UNIQUE KEY uq_tx (provider,provider_transaction_id),UNIQUE KEY uq_ref (provider,provider_reference)) ENGINE=InnoDB");
$sql = (string)preg_replace('/^\h*--[^\r\n]*$/m', '', (string)file_get_contents(__DIR__ . '/../../database/migration_ticketing_webhook_01.sql'));
foreach (explode(';', $sql) as $ddl) if (trim($ddl) !== '') $db->exec($ddl);
$db->exec("INSERT INTO ticket_orders(id,event_id,status,currency,total_cop) VALUES
 (42,1,'pending','COP',50000),(43,1,'pending','COP',50000)");
$db->exec("INSERT INTO ticket_order_items(order_id,event_id,ticket_type_id,quantity,currency)
 VALUES(42,1,10,1,'COP'),(43,1,10,1,'COP')");
$db->exec("INSERT INTO ticket_inventory_holds(order_id,event_id,ticket_type_id,quantity,kind,status,expires_at_utc)
 VALUES(42,1,10,1,'paid','active',UTC_TIMESTAMP(6)+INTERVAL 15 MINUTE)");
$db->prepare("INSERT INTO ticket_payment_attempts(id,order_id,provider,provider_reference,gross_amount_cop,status)
    VALUES (1,42,'wompi',?,50000,'created'),(2,43,'wompi',?,50000,'created')")
    ->execute([$reference, 'BW7-43-CARD-' . str_repeat('c', 24)]);
$count = static fn(string $q): int => (int)$db->query($q)->fetchColumn();
$order = static fn(int $id): string => (string)$db->query("SELECT status FROM ticket_orders WHERE id=$id")->fetchColumn();
$touch = static fn() => $db->exec("UPDATE ticket_payment_attempts SET updated_at_utc=UTC_TIMESTAMP(6) - INTERVAL 5 MINUTE");

// Nothing confirms except an exact APPROVED match.
foreach ([
    'declined' => [$wompi([$tx('DECLINED')]), 'not_approved'],
    'pending' => [$wompi([$tx('PENDING')]), 'not_approved'],
    'wrong amount' => [$wompi([$tx('APPROVED', 4900000)]), 'not_approved'],
    'not found' => [$wompi([]), 'not_found'],
    'network' => [$wompi([], 503), 'unavailable'],
] as $case => [$transport, $expected]) {
    $touch();
    reconcileAssert(brvtal_wompi_reconcile_attempt($db, 1, $config, $transport) === $expected, $case . ' -> ' . $expected);
    reconcileAssert($order(42) === 'pending' && $count('SELECT COUNT(*) FROM ticket_issue_outbox') === 0, $case . ' changes nothing');
}
reconcileAssert(brvtal_wompi_reconcile_attempt($db, 1, ['mode'=>'mock'] + $config, $wompi([$tx('APPROVED')])) === 'skipped',
    'mock never confirms money');

// Late exact APPROVED: same effects as a signed webhook, once, if the hold is valid.
$db->exec("UPDATE ticket_payment_attempts SET created_at_utc=UTC_TIMESTAMP(6)-INTERVAL 3 DAY,
    updated_at_utc=UTC_TIMESTAMP(6)-INTERVAL 20 MINUTE WHERE id=1");
reconcileAssert(brvtal_wompi_reconcile_attempt($db, 1, $config, $wompi([], 503)) === 'unavailable', 'old attempt provider unavailable');
reconcileAssert($order(42) === 'pending' && $count("SELECT COUNT(*) FROM ticket_inventory_holds WHERE order_id=42 AND status='active'") === 1,
    'outage preserves pending order and hold');
$db->exec('UPDATE ticket_payment_attempts SET updated_at_utc=UTC_TIMESTAMP(6)-INTERVAL 20 MINUTE WHERE id=1');
reconcileAssert(brvtal_wompi_reconcile_attempt($db, 1, $config, $wompi([$tx('APPROVED')])) === 'approved', 'approved');
reconcileAssert($order(42) === 'paid', 'order paid');
reconcileAssert($db->query('SELECT status FROM ticket_payment_attempts WHERE id=1')->fetchColumn() === 'approved'
    && $db->query('SELECT provider_transaction_id FROM ticket_payment_attempts WHERE id=1')->fetchColumn() === '12261959-1795504284-15491',
    'attempt approved with the Wompi transaction id');
reconcileAssert($count("SELECT COUNT(*) FROM ticket_payment_webhook_events WHERE attempt_id=1 AND signed_status='APPROVED'
    AND event_checksum='" . brvtal_wompi_reconcile_checksum('12261959-1795504284-15491') . "'") === 1, 'journalled as API-verified');
reconcileAssert($count('SELECT COUNT(*) FROM ticket_issue_outbox WHERE order_id=42') === 1, 'issuance queued once');
$touch();
reconcileAssert(brvtal_wompi_reconcile_attempt($db, 1, $config, $wompi([$tx('APPROVED')])) === 'skipped', 'a closed attempt is skipped');
reconcileAssert($count('SELECT COUNT(*) FROM ticket_issue_outbox') === 1, 'no second issuance');

// A webhook arriving late for the same transaction is a harmless duplicate.
$late = ['id'=>'12261959-1795504284-15491', 'reference'=>$reference, 'status'=>'APPROVED', 'currency'=>'COP',
    'amount_in_cents'=>5000000, '_event_checksum'=>str_repeat('d', 64), '_event_timestamp'=>time()];
$db->beginTransaction();
reconcileAssert(brvtal_wompi_hook_apply($db, $late, $late) === 'duplicate', 'late webhook is a duplicate');
$db->commit();
reconcileAssert($count('SELECT COUNT(*) FROM ticket_issue_outbox') === 1, 'late webhook issues nothing new');

// Worker step: the oldest open attempt, with backoff.
$calls = [];
$db->exec('UPDATE ticket_payment_attempts SET updated_at_utc=UTC_TIMESTAMP(6) - INTERVAL 5 MINUTE WHERE id=2');
reconcileAssert(brvtal_wompi_reconcile_next($db, $config, $wompi([$tx('DECLINED', 5000000, 'BW7-43-CARD-' . str_repeat('c', 24))])) === 'not_approved',
    'worker checks the open attempt');
reconcileAssert(brvtal_wompi_reconcile_next($db, $config, $wompi([])) === 'idle', 'backoff: no immediate re-query');
reconcileAssert(count($calls) === 1, 'one provider call per backoff window');
$db->exec('UPDATE ticket_payment_attempts SET created_at_utc=UTC_TIMESTAMP(6) - INTERVAL 3 DAY,
    updated_at_utc=UTC_TIMESTAMP(6) - INTERVAL 5 MINUTE WHERE id=2');
reconcileAssert(brvtal_wompi_reconcile_next($db, $config, $wompi([])) === 'idle', 'old attempt has longer backoff');
$db->exec('UPDATE ticket_payment_attempts SET updated_at_utc=UTC_TIMESTAMP(6)-INTERVAL 20 MINUTE WHERE id=2');
$db->prepare("INSERT INTO ticket_payment_attempts(id,order_id,provider,provider_reference,gross_amount_cop,status)
    VALUES (3,43,'wompi',?,50000,'pending')")->execute(['BW7-43-PSE-' . str_repeat('e', 24)]);
$calls = [];
$other = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $pass, $opt);
$oldTransport = $wompi([]);
reconcileAssert(brvtal_wompi_reconcile_next($db, $config,
    static function ($url, $headers) use ($other, $config, $oldTransport, $wompi, $db) {
        reconcileAssert(!$db->inTransaction(), 'no transaction across provider call');
        reconcileAssert(brvtal_wompi_reconcile_attempt($other, 2, $config, $wompi([])) === 'skipped', 'second worker cannot claim same attempt');
        return $oldTransport($url, $headers);
    }) === 'not_found', '72-hour attempt is queried');
reconcileAssert(count($calls) === 1 && str_contains($calls[0][0], 'BW7-43-CARD-'), 'one query per worker cycle with two due candidates');
$db->exec("UPDATE ticket_payment_attempts SET status='declined' WHERE id=3");
$db->exec("UPDATE ticket_payment_attempts SET created_at_utc=UTC_TIMESTAMP(6)-INTERVAL 8 DAY,
    updated_at_utc=UTC_TIMESTAMP(6)-INTERVAL 7 HOUR,status='pending' WHERE id=2");
reconcileAssert(brvtal_wompi_reconcile_next($db, $config, $wompi([])) === 'stale_pending', 'stale operational signal');
reconcileAssert($order(43) === 'pending' && $db->query('SELECT status FROM ticket_payment_attempts WHERE id=2')->fetchColumn() === 'pending', 'stale preserves financial state');
reconcileAssert(brvtal_wompi_reconcile_next($db, $config, $wompi([])) === 'idle', 'stale backoff prevents immediate query');
$db->exec('UPDATE ticket_payment_attempts SET updated_at_utc=UTC_TIMESTAMP(6)-INTERVAL 7 HOUR WHERE id=2');
$lateNoHold = $tx('APPROVED', 5000000, 'BW7-43-CARD-' . str_repeat('c', 24));
$lateNoHold['id'] = 'synthetic-late-43';
reconcileAssert(brvtal_wompi_reconcile_next($db, $config, $wompi([$lateNoHold])) === 'approved_unfulfilled', 'late confirmed payment without hold is recorded but not fulfilled');
reconcileAssert($db->query('SELECT status FROM ticket_payment_attempts WHERE id=2')->fetchColumn() === 'approved'
    && $count('SELECT COUNT(*) FROM ticket_issue_outbox WHERE order_id=43') === 0, 'confirmed money without inventory never issues');
reconcileAssert(brvtal_wompi_reconcile_next($db, $config, $wompi([])) === 'idle', 'confirmed attempt never replayed');

echo "BRVTAL #1952 Wompi reconciliation passed against disposable MariaDB.\n";
