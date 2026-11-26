<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/migrations.php';
function ordersCheck(bool $ok, string $why): void {
    if (!$ok) throw new RuntimeException('T03 orders: ' . $why);
}
function ordersStatements(PDO $pdo, string $sql): void {
    $sql = preg_replace('/^\s*--[^\n]*$/m', '', $sql);
    foreach (array_filter(array_map(trim(...), explode(';', (string)$sql))) as $statement) $pdo->exec($statement);
}
function ordersRejected(PDO $pdo, string $sql, array $params, string $constraint): void {
    try { $pdo->prepare($sql)->execute($params); }
    catch (PDOException $e) {
        $vendorError = (int)($e->errorInfo[1] ?? 0);
        ordersCheck((string)$e->getCode() === '23000' && in_array($vendorError, [1062,1452,3819,4025], true),
            $constraint . ': expected duplicate, foreign-key or check rejection');
        ordersCheck(str_contains(strtolower($e->getMessage()), strtolower($constraint)),
            $constraint . ': rejected by a different constraint');
        return;
    }
    throw new RuntimeException('T03 expected reject by ' . $constraint);
}
function ordersHasPaymentSecretIdentifier(string $text): bool {
    return preg_match('/(?<![A-Za-z0-9])(?:pan|cvv|cvc|card_number)(?![A-Za-z0-9])/i', $text) === 1;
}
if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "BRVTAL T03 orders integration skipped. Set BRVTAL_INTEGRATION_TESTS=1.\n";
    exit(0);
}
$prefix = (string)(getenv('BRVTAL_TEST_DB_NAME') ?: '');
ordersCheck((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/', $prefix), 'disposable database namespace required');
$host = (string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1');
$port = (int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306);
$user = (string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root');
$pass = (string)(getenv('BRVTAL_TEST_DB_PASS') ?: '');
$server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$dbNames = [];
register_shutdown_function(static function () use ($server, &$dbNames): void {
    foreach ($dbNames as $db) try { $server->exec("DROP DATABASE IF EXISTS `{$db}`"); } catch (Throwable) {}
});
$open = static function (string $kind) use ($prefix, $server, $host, $port, $user, $pass, &$dbNames): PDO {
    $db = $prefix . '_orders_' . $kind . '_' . bin2hex(random_bytes(4));
    $server->exec("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $dbNames[] = $db;
    return new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
};
$migration = (string)file_get_contents(__DIR__ . '/../../database/migration_ticketing_orders_01.sql');
brvtalMigrationAssertAdditiveSql($migration);
ordersCheck(!ordersHasPaymentSecretIdentifier($migration), 'no card secrets in schema');
ordersCheck(ordersHasPaymentSecretIdentifier('payment_pan') && !ordersHasPaymentSecretIdentifier('provider_reference'),
    'PAN detection covers underscore-bound identifiers without false positives');
$fresh = $open('fresh');
$schema = (string)file_get_contents(__DIR__ . '/../../database/schema.sql');
$withoutCreateDb = preg_replace('/\ACREATE DATABASE IF NOT EXISTS brvtal[^;]*;\s*USE brvtal;\s*/i', '', $schema);
ordersCheck(is_string($withoutCreateDb) && $withoutCreateDb !== $schema, 'fresh fixture must not use production database');
ordersStatements($fresh, $withoutCreateDb);
// Fresh install = base schema + canonical additive migrations, not duplicated DDL.
ordersStatements($fresh, (string)file_get_contents(__DIR__ . '/../../database/migration_content_core_01.sql'));
ordersStatements($fresh, $migration);
ordersStatements($fresh, $migration);
ordersCheck((int)$fresh->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'ticket_%'")->fetchColumn() === 6, 'fresh schema includes six ticketing tables');
$upgrade = $open('upgrade');
ordersStatements($upgrade, <<<'SQL'
CREATE TABLE events (id INT UNSIGNED NOT NULL PRIMARY KEY, title VARCHAR(180) NOT NULL) ENGINE=InnoDB;
CREATE TABLE event_ticket_types (id INT UNSIGNED NOT NULL PRIMARY KEY, event_id INT UNSIGNED NOT NULL, name VARCHAR(120) NOT NULL, price DECIMAL(12,2) NULL, currency CHAR(3) NOT NULL DEFAULT 'COP', FOREIGN KEY(event_id) REFERENCES events(id)) ENGINE=InnoDB;
INSERT INTO events(id,title) VALUES (1,'Existing synthetic night');
INSERT INTO event_ticket_types(id,event_id,name,price) VALUES (10,1,'Preventa',25000.00);
INSERT INTO events(id,title) VALUES (2,'Other synthetic event');
INSERT INTO event_ticket_types(id,event_id,name,price) VALUES (20,2,'Other presale',30000.00);
SQL);
ordersStatements($upgrade, $migration);
ordersStatements($upgrade, $migration);
ordersCheck($upgrade->query('SELECT title FROM events WHERE id=1')->fetchColumn() === 'Existing synthetic night', 'existing event preserved');
ordersCheck($upgrade->query('SELECT price FROM event_ticket_types WHERE id=10')->fetchColumn() === '25000.00', 'existing editorial ticket preserved');
$upgrade->exec("SET time_zone = '-05:00'");
$upgrade->prepare('INSERT INTO ticket_buyers(public_id,email) VALUES (?,?)')->execute([random_bytes(16), 'buyer@example.invalid']);
$buyer = (int)$upgrade->lastInsertId();
$upgrade->prepare('INSERT INTO ticket_orders(public_id,buyer_id,event_id,checkout_key_hash,total_cop) VALUES (?,?,?,?,?)')->execute([random_bytes(16),$buyer,1,hash('sha256','checkout-1'),50000]);
$order = (int)$upgrade->lastInsertId();
ordersCheck((int)$upgrade->query("SELECT ABS(TIMESTAMPDIFF(SECOND,created_at_utc,UTC_TIMESTAMP(6))) FROM ticket_orders WHERE id={$order}")->fetchColumn() < 10,
    'created_at_utc stores UTC even when the session uses a negative offset');
$upgrade->exec("UPDATE ticket_orders SET status='pending',updated_at_utc=UTC_TIMESTAMP(6) WHERE id={$order}");
ordersCheck((int)$upgrade->query("SELECT ABS(TIMESTAMPDIFF(SECOND,updated_at_utc,UTC_TIMESTAMP(6))) FROM ticket_orders WHERE id={$order}")->fetchColumn() < 10,
    'caller writes UTC explicitly on status updates');
$upgrade->prepare('INSERT INTO ticket_order_items(order_id,event_id,ticket_type_id,ticket_name_snapshot,quantity,unit_price_cop,subtotal_cop) VALUES (?,?,?,?,?,?,?)')->execute([$order,1,10,'Preventa',2,25000,50000]);
$insert = 'INSERT INTO ticket_payment_attempts(order_id,provider,provider_reference,provider_transaction_id,idempotency_key_hash,gross_amount_cop) VALUES (?,?,?,?,?,?)';
$upgrade->prepare($insert)->execute([$order,'wompi','ref-1','txn-1',hash('sha256','attempt-1'),50000]);
$dbName = (string)$upgrade->query('SELECT DATABASE()')->fetchColumn();
$second = new PDO("mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
ordersRejected($second, $insert, [$order,'wompi','ref-2','txn-1',hash('sha256','attempt-2'),50000], 'uq_ticket_payment_transaction');
ordersRejected($second, $insert, [$order,'wompi','ref-1','txn-2',hash('sha256','attempt-3'),50000], 'uq_ticket_payment_reference');
ordersRejected($second, $insert, [$order,'wompi','ref-3','txn-3',hash('sha256','attempt-1'),50000], 'uq_ticket_payment_idempotency');
ordersRejected($upgrade,
    'INSERT INTO ticket_order_items(order_id,event_id,ticket_type_id,ticket_name_snapshot,quantity,unit_price_cop,subtotal_cop) VALUES (?,?,?,?,?,?,?)',
    [9999,1,10,'Missing order',1,25000,25000], 'fk_ticket_items_order');
ordersRejected($upgrade,
    'INSERT INTO ticket_order_items(order_id,event_id,ticket_type_id,ticket_name_snapshot,quantity,unit_price_cop,subtotal_cop) VALUES (?,?,?,?,?,?,?)',
    [$order,1,9999,'Missing type',1,25000,25000], 'fk_ticket_items_type');
ordersRejected($upgrade,
    'INSERT INTO ticket_order_items(order_id,event_id,ticket_type_id,ticket_name_snapshot,quantity,unit_price_cop,subtotal_cop) VALUES (?,?,?,?,?,?,?)',
    [$order,1,20,'Wrong event type',1,30000,30000], 'fk_ticket_items_type');
ordersRejected($upgrade,
    'INSERT INTO ticket_order_items(order_id,event_id,ticket_type_id,ticket_name_snapshot,quantity,unit_price_cop,subtotal_cop) VALUES (?,?,?,?,?,?,?)',
    [$order,2,20,'Wrong order event',1,30000,30000], 'fk_ticket_items_order');
ordersRejected($upgrade, 'UPDATE ticket_order_items SET quantity=0 WHERE order_id=?', [$order], 'chk_ticket_item_quantity');
$upgrade->prepare($insert)->execute([$order,'other-provider','ref-1','txn-1',hash('sha256','attempt-other'),50000]);
ordersCheck((int)$upgrade->query("SELECT COUNT(*) FROM ticket_payment_attempts WHERE provider='wompi'")->fetchColumn() === 1,
    'duplicate requests for one provider still create only one attempt');
ordersCheck((int)$upgrade->query('SELECT COUNT(*) FROM ticket_payment_attempts')->fetchColumn() === 2,
    'other providers may reuse provider-scoped transaction and reference identifiers');
ordersCheck((int)$upgrade->query('SELECT COUNT(*) FROM ticket_order_items')->fetchColumn() === 1, 'failed writes did not alter legacy order lines');
ordersCheck((int)$upgrade->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'ticket_%' AND COLUMN_NAME REGEXP '(^|_)(pan|cvv|cvc|card_number)($|_)'")->fetchColumn() === 0, 'no PAN/CVV storage columns');
echo "BRVTAL T03 orders MariaDB integration passed.\n";

// #2116: run the standalone delivery fixture in the canonical database suite.
// A separate PHP process isolates globals, environment and disposable DB cleanup.
$deliveryProcess = proc_open([PHP_BINARY, __DIR__ . '/ticketing-issue-delivery.php'],
    [0=>STDIN, 1=>STDOUT, 2=>STDERR], $deliveryPipes);
ordersCheck(is_resource($deliveryProcess), 'delivery fixture process started');
ordersCheck(proc_close($deliveryProcess) === 0, 'delivery fixture passed');
