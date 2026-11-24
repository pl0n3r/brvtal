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
$db->exec("CREATE TABLE ticket_orders(id BIGINT UNSIGNED PRIMARY KEY,status VARCHAR(16) NOT NULL,
 currency CHAR(3) NOT NULL,total_cop BIGINT NOT NULL,updated_at_utc DATETIME(6) NULL) ENGINE=InnoDB");
$db->exec("CREATE TABLE ticket_payment_attempts(id BIGINT UNSIGNED PRIMARY KEY,order_id INT NOT NULL,
 provider VARCHAR(24) NOT NULL,provider_reference VARCHAR(190) NOT NULL,
 provider_transaction_id VARCHAR(190) NULL,gross_amount_cop BIGINT NOT NULL,status VARCHAR(16) NOT NULL,
 created_at_utc DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6)),
 updated_at_utc DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6) - INTERVAL 5 MINUTE),
 UNIQUE KEY uq_tx (provider,provider_transaction_id),UNIQUE KEY uq_ref (provider,provider_reference)) ENGINE=InnoDB");
$sql = (string)preg_replace('/^\h*--[^\r\n]*$/m', '', (string)file_get_contents(__DIR__ . '/../../database/migration_ticketing_webhook_01.sql'));
foreach (explode(';', $sql) as $ddl) if (trim($ddl) !== '') $db->exec($ddl);
$db->exec("INSERT INTO ticket_orders VALUES (42,'pending','COP',50000,NULL),(43,'pending','COP',50000,NULL)");
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

// Exact APPROVED: same effects as a signed webhook, once.
$touch();
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
reconcileAssert(brvtal_wompi_reconcile_next($db, $config, $wompi([])) === 'idle', 'attempts older than 48 h are left alone');

echo "BRVTAL #1952 Wompi reconciliation passed against disposable MariaDB.\n";
