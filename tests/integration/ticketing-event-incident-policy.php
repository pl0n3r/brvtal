<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/ticketing_event_incident_policy.php';

function incidentAssert(bool $ok, string $reason): void
{
    if (!$ok) throw new RuntimeException('T-2089 incident policy: ' . $reason);
}
incidentAssert(function_exists('brvtal_ticket_event_incident_policy'), 'read-only helper loaded');
if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "BRVTAL #2089 policy contract loaded; disposable MariaDB integration is CI-only.\n";
    exit(0);
}

$prefix = (string)(getenv('BRVTAL_TEST_DB_NAME') ?: '');
incidentAssert((bool)preg_match('/^brvtal_test[A-Za-z0-9_]*$/D', $prefix),
    'never use a non-synthetic database');
$host = (string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1');
$port = (int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306);
$user = (string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root');
$pass = (string)(getenv('BRVTAL_TEST_DB_PASS') ?: '');
$options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false];
$server = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, $options);
$name = $prefix . '_incident_' . bin2hex(random_bytes(3));
$server->exec('CREATE DATABASE ' . $name);
register_shutdown_function(static function () use ($server, $name): void {
    try { $server->exec('DROP DATABASE IF EXISTS ' . $name); } catch (Throwable) {}
});
$dsn = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
$db = new PDO($dsn, $user, $pass, $options);
$db->exec('CREATE TABLE events (id INT UNSIGNED PRIMARY KEY,
    status VARCHAR(32) NOT NULL) ENGINE=InnoDB');
$db->exec('CREATE TABLE admins (id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
$db->exec("INSERT INTO events VALUES (1,'published'),(2,'published'),(3,'cancelled')");
$db->exec('INSERT INTO admins VALUES (7)');
$migration = (string)file_get_contents(__DIR__ . '/../../database/migration_ticketing_refund_events_01.sql');
incidentAssert(str_contains($migration, 'ticket_refund_event_decisions'),
    'use canonical additive owner-decision migration');
$ddl = preg_replace('/^\h*--[^\r\n]*$/m', '', $migration);
foreach (explode(';', (string)$ddl) as $sql) {
    if (trim($sql) !== '') $db->exec($sql);
}
$policy = static fn(PDO $pdo, int $eventId): array =>
    brvtal_ticket_event_incident_policy($pdo, $eventId);
$allowed = ['state'=>'allowed','cause'=>null];
$unknown = ['state'=>'unknown','cause'=>null];
incidentAssert($policy($db,1)===$unknown,
    'no transaction or Event mutex is not an authorization');
$db->beginTransaction();
incidentAssert($policy($db,-1)===$unknown, 'invalid ID must fail closed');
incidentAssert($policy($db,99)===$unknown, 'missing Event must fail closed');
incidentAssert($policy($db,1)===$allowed, 'published Event without owner decision');
incidentAssert($policy($db,2)===$allowed, 'other Event has independent policy');
incidentAssert($policy($db,3)===$allowed,
    'no decision preserves channel rules, even when editorial status is cancelled');
$db->commit();

// The owner records the incident outside the read-only helper.
$db->prepare('INSERT INTO ticket_refund_event_decisions
    (event_id,cause,owner_admin_id) VALUES (?,?,?)')
    ->execute([1,'cancelled',7]);
putenv('BRVTAL_TICKET_REFUNDS_POLICY_ENABLED=0');
$db->beginTransaction();
$before = (int)$db->query('SELECT COUNT(*) FROM ticket_refund_event_decisions')->fetchColumn();
for ($repeat=0; $repeat<3; $repeat++) {
    incidentAssert($policy($db,1) === ['state'=>'blocked','cause'=>'cancelled'],
        'cancelled owner decision is authoritative and idempotent without refunds flag');
    incidentAssert($policy($db,2)===$allowed,
        'decision about another Event cannot block this Event');
}
incidentAssert($db->query('SELECT status FROM events WHERE id=1')->fetchColumn()==='published',
    'editorial status remains independent of authoritative decision');
incidentAssert((int)$db->query('SELECT COUNT(*) FROM ticket_refund_event_decisions')->fetchColumn()
    ===$before, 'helper does not insert, update or delete');
$db->commit();
$db->prepare("UPDATE ticket_refund_event_decisions SET cause='postponed' WHERE event_id=?")
    ->execute([1]);
$db->beginTransaction();
incidentAssert($policy($db,1) === ['state'=>'blocked','cause'=>'postponed'],
    'owner postponement blocks independent of editorial status');
$db->commit();

// Missing additive migration is UNKNOWN, never absence of an incident.
$db->exec('DROP TABLE ticket_refund_event_decisions');
$db->beginTransaction();
incidentAssert($policy($db,1)===$unknown,'missing decision schema must fail closed');
$db->rollBack();
foreach (explode(';', (string)$ddl) as $sql) {
    if (trim($sql) !== '') $db->exec($sql);
}

// Two PDO sessions validate Event mutex: a pending owner declaration cannot
// be mistaken for an incident-free Event during a lock timeout.
$owner = new PDO($dsn, $user, $pass, $options);
$other = new PDO($dsn, $user, $pass, $options);
$owner->beginTransaction();
$owner->prepare('SELECT id FROM events WHERE id=? FOR UPDATE')->execute([2]);
$owner->prepare('INSERT INTO ticket_refund_event_decisions
    (event_id,cause,owner_admin_id) VALUES (?,?,?)')->execute([2,'postponed',7]);
$other->exec('SET SESSION innodb_lock_wait_timeout=1');
$other->beginTransaction();
incidentAssert($policy($other,2)===$unknown,
    'DB lock-timeout during concurrent owner declaration fails closed');
$other->rollBack();
$owner->commit();
$other->beginTransaction();
incidentAssert($policy($other,2)===['state'=>'blocked','cause'=>'postponed'],
    'committed owner decision becomes authoritative after mutex release');
$other->commit();
incidentAssert((int)$db->query('SELECT COUNT(*) FROM ticket_refund_event_decisions')->fetchColumn()===1,
    'reader never creates a decision or payment record');
incidentAssert((int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ticket_payment_attempts'")->fetchColumn()===0,
    'no payment schema or provider is used by this helper');
echo "BRVTAL #2089 owner decision, fail-closed schema and two-connection mutex passed.\n";
