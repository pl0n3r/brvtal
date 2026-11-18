<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/migrations.php';

function ticket_entries_assert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException('T04 TICKETING ENTRIES: ' . $message);
    }
}

function ticket_entries_reject(PDO $pdo, string $sql, array $params, string $reason): void
{
    try {
        $pdo->prepare($sql)->execute($params);
    } catch (PDOException $error) {
        ticket_entries_assert(in_array((string)$error->getCode(), ['23000', 'HY000'], true), $reason . ': wrong SQLSTATE');
        return;
    }
    throw new RuntimeException('T04 TICKETING ENTRIES: expected DB rejection: ' . $reason);
}

if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "BRVTAL T04 entries integration skipped. Set BRVTAL_INTEGRATION_TESTS=1.\n";
    exit(0);
}

$prefix = (string)(getenv('BRVTAL_TEST_DB_NAME') ?: '');
ticket_entries_assert((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/', $prefix), 'disposable test namespace required');
$host = (string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1');
$port = (int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306);
$user = (string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root');
$pass = (string)(getenv('BRVTAL_TEST_DB_PASS') ?: '');
$db = $prefix . '_entries_' . bin2hex(random_bytes(4));
$server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$server->exec("CREATE DATABASE {$db} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
register_shutdown_function(static function () use ($server, $db): void {
    try { $server->exec("DROP DATABASE IF EXISTS {$db}"); } catch (Throwable) {}
});
$pdo = new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

// T03 has its own migration/issue. Synthetic upstream keys let T04 be tested in
// isolation; they do not assert T03 exists in main or deploy T03 to production.
$fixtureSql = <<<'SQL'
CREATE TABLE events (id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB;
CREATE TABLE event_ticket_types (
  id INT UNSIGNED PRIMARY KEY,
  event_id INT UNSIGNED NOT NULL,
  qr_image VARCHAR(500) NULL,
  FOREIGN KEY (event_id) REFERENCES events(id)
) ENGINE=InnoDB;
CREATE TABLE ticket_orders (
  id BIGINT UNSIGNED PRIMARY KEY,
  event_id INT UNSIGNED NOT NULL,
  status VARCHAR(20) NOT NULL,
  FOREIGN KEY (event_id) REFERENCES events(id)
) ENGINE=InnoDB;
CREATE TABLE ticket_order_items (
  id BIGINT UNSIGNED PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  ticket_type_id INT UNSIGNED NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  FOREIGN KEY (order_id) REFERENCES ticket_orders(id),
  FOREIGN KEY (ticket_type_id) REFERENCES event_ticket_types(id)
) ENGINE=InnoDB;
CREATE TABLE admins (id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB;
SQL;
foreach (array_filter(array_map('trim', explode(';', $fixtureSql))) as $statement) {
    $pdo->exec($statement);
}
$path = __DIR__ . '/../../database/migration_ticketing_entries_01.sql';
$sql = (string)file_get_contents($path);
brvtalMigrationAssertAdditiveSql($sql);
ticket_entries_assert(!str_contains($sql, 'ticket_qr') && !str_contains($sql, 'qr_image'), 'editorial QR must not be changed');
// Only CREATE TABLE statements; never touch live data or existing editorial tables.
$sqlWithoutComments = preg_replace('/^\s*--.*$/m', '', $sql);
$statements = array_filter(array_map('trim', explode(';', (string)$sqlWithoutComments)));
foreach ([1, 2] as $passNumber) {
    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }
}
ticket_entries_assert((int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('ticket_entries','ticket_entry_credentials','ticket_checkins')")->fetchColumn() === 3, 'fresh + idempotent migration');
$pdo->exec("INSERT INTO events(id) VALUES (1),(2)");
$pdo->exec("INSERT INTO event_ticket_types(id,event_id,qr_image) VALUES (11,1,'editorial.png')");
$pdo->exec("INSERT INTO ticket_orders(id,event_id,status) VALUES (100,1,'paid')");
$pdo->exec("INSERT INTO ticket_order_items(id,order_id,ticket_type_id,quantity) VALUES (200,100,11,2)");
$pdo->exec("INSERT INTO admins(id) VALUES (7)");

$entrySql = 'INSERT INTO ticket_entries(public_id,order_item_id,event_id,ticket_type_id,unit_number,issued_at_utc) VALUES (?,?,?,?,?,UTC_TIMESTAMP(6))';
$entry1 = random_bytes(16);
$entry2 = random_bytes(16);
ticket_entries_assert(!hash_equals($entry1, $entry2), 'opaque IDs generated independently');
$pdo->prepare($entrySql)->execute([$entry1, 200, 1, 11, 1]);
$first = (int)$pdo->lastInsertId();
$pdo->prepare($entrySql)->execute([$entry2, 200, 1, 11, 2]);
$second = (int)$pdo->lastInsertId();
ticket_entries_assert((int)$pdo->query('SELECT COUNT(*) FROM ticket_entries e JOIN ticket_order_items i ON i.id=e.order_item_id JOIN ticket_orders o ON o.id=i.order_id WHERE o.status="paid" AND e.event_id=o.event_id')->fetchColumn() === 2, 'paid order → item → event → distinct entries');
ticket_entries_reject($pdo, $entrySql, [$entry1, 200, 1, 11, 3], 'duplicate opaque public ID');
ticket_entries_reject($pdo, $entrySql, [random_bytes(16), 200, 1, 11, 2], 'duplicate order unit');
ticket_entries_reject($pdo, $entrySql, [random_bytes(16), 999, 1, 11, 3], 'missing order item FK');
ticket_entries_reject($pdo, $entrySql, [random_bytes(16), 200, 999, 11, 3], 'missing event FK');
ticket_entries_reject($pdo, $entrySql, [random_bytes(16), 200, 1, 999, 3], 'missing type FK');

$credentialSql = 'INSERT INTO ticket_entry_credentials(entry_id,version,token_hash,created_at_utc) VALUES (?,?,?,UTC_TIMESTAMP(6))';
$secret1 = random_bytes(32);
$secret2 = random_bytes(32);
$hash1 = hash('sha256', $secret1, true);
$hash2 = hash('sha256', $secret2, true);
$pdo->prepare($credentialSql)->execute([$first, 1, $hash1]);
$pdo->prepare($credentialSql)->execute([$second, 1, $hash2]);
ticket_entries_assert(!hash_equals($hash1, $hash2), 'independent CSPRNG secrets have distinct hashes');
$stored = $pdo->query('SELECT token_hash FROM ticket_entry_credentials ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
ticket_entries_assert(count($stored) === 2 && hash_equals($stored[0], $hash1) && !hash_equals($stored[0], $secret1), 'store only SHA256 binary digest, never QR secret');
ticket_entries_reject($pdo, $credentialSql, [$second, 2, $hash1], 'credential hash unique across entries');
ticket_entries_reject($pdo, $credentialSql, [$first, 2, hash('sha256', random_bytes(32), true)], 'only one active token per entry');
$pdo->prepare("UPDATE ticket_entry_credentials SET status='revoked',revoked_at_utc=UTC_TIMESTAMP(6) WHERE entry_id=? AND version=1")->execute([$first]);
$hashNext = hash('sha256', random_bytes(32), true);
$pdo->prepare($credentialSql)->execute([$first, 2, $hashNext]);
ticket_entries_assert((int)$pdo->query("SELECT COUNT(*) FROM ticket_entry_credentials WHERE entry_id={$first} AND status='active'")->fetchColumn() === 1, 'reissue rotates the unique live credential');
ticket_entries_assert((int)$pdo->query("SELECT COUNT(*) FROM ticket_entry_credentials WHERE entry_id={$first} AND version=1 AND status='revoked' AND revoked_at_utc IS NOT NULL")->fetchColumn() === 1, 'prior credential remains revoked/auditable');
$pdo->prepare("UPDATE ticket_entries SET status='revoked',revoked_at_utc=UTC_TIMESTAMP(6) WHERE id=?")->execute([$first]);
ticket_entries_assert((int)$pdo->query("SELECT COUNT(*) FROM ticket_entry_credentials c JOIN ticket_entries e ON e.id=c.entry_id WHERE c.entry_id={$first} AND c.status='active' AND e.status='issued'")->fetchColumn() === 0, 'revoked entry must not validate even if credential exists');

$checkin = 'INSERT INTO ticket_checkins(request_id,event_id,entry_id,credential_version,operator_admin_id,device_id,outcome,reason_code,attempted_at_utc) VALUES (?,?,?,?,?,?,?, ?,UTC_TIMESTAMP(6))';
$request = random_bytes(16);
$pdo->prepare($checkin)->execute([$request, 1, $second, 1, 7, 'synthetic-scanner', 'accepted', null]);
$pdo->prepare($checkin)->execute([random_bytes(16), 1, null, null, 7, 'synthetic-scanner', 'rejected', 'unknown_credential']);
ticket_entries_reject($pdo, $checkin, [$request, 1, $second, 1, 7, 'synthetic-scanner', 'accepted', null], 'idempotent scan request');
ticket_entries_reject($pdo, $checkin, [random_bytes(16), 2, $second, 1, 7, 'synthetic-scanner', 'accepted', null], 'cross-event scan FK');
ticket_entries_reject($pdo, $checkin, [random_bytes(16), 1, null, null, 7, 'synthetic-scanner', 'accepted', null], 'cannot accept unknown credential');
ticket_entries_assert((int)$pdo->query("SELECT COUNT(*) FROM ticket_checkins WHERE outcome='rejected' AND entry_id IS NULL AND reason_code='unknown_credential'")->fetchColumn() === 1, 'rejected scans remain auditable without token hash');
ticket_entries_assert($pdo->query('SELECT qr_image FROM event_ticket_types WHERE id=11')->fetchColumn() === 'editorial.png', 'existing editorial QR unchanged');
echo "BRVTAL T04 ticket entries MariaDB integration passed.\n";
