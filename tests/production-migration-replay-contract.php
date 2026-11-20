<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/migrations.php';
require_once __DIR__ . '/../config/migration_reconcile.php';

function replayAssert(bool $condition, string $reason): void
{
    if (!$condition) {
        throw new RuntimeException('MIGRATION_REPLAY_TEST_FAILED: ' . $reason);
    }
}

// The original discovery order is lexicographic: T04 entries precedes T03 orders.
// The production reconciler must preserve all safety checks but correct this FK order.
$names = [
    'migration_memories_youtube_01.sql',
    'migration_ticketing_entries_01.sql',
    'migration_ticketing_mail_history_01.sql',
    'migration_ticketing_mail_outbox_01.sql',
    'migration_ticketing_orders_01.sql',
    'migration_ticketing_reservations_01.sql',
];
$actions = array_map(static fn(string $name): array => [
    'migration' => $name, 'action' => 'apply',
], $names);
$ordered = brvtalMigrationOrderDependencyActions($actions);
$orderedNames = array_column($ordered, 'migration');
replayAssert(
    array_search('migration_ticketing_orders_01.sql', $orderedNames, true)
        < array_search('migration_ticketing_entries_01.sql', $orderedNames, true),
    'T03 orders must precede T04 entries'
);
replayAssert(
    array_search('migration_ticketing_orders_01.sql', $orderedNames, true)
        < array_search('migration_ticketing_reservations_01.sql', $orderedNames, true),
    'T03 orders must precede T05 inventory holds'
);
replayAssert(
    count($orderedNames) === count($names) && count(array_unique($orderedNames)) === count($names),
    'no migration may be lost or duplicated'
);
replayAssert(
    brvtalMigrationOrderDependencyActions($ordered) === $ordered,
    'dependency ordering must be idempotent'
);

// Fast runs the pure ordering contract. Real DDL is strictly confined to CI's
// disposable brvtal_test* MariaDB namespace, never production.
if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "T03/T04 migration ordering contract passed (MariaDB replay CI-only).\n";
    exit(0);
}
$prefix = (string)(getenv('BRVTAL_TEST_DB_NAME') ?: '');
replayAssert((bool)preg_match('/^brvtal_test[A-Za-z0-9_]*$/D', $prefix), 'test-only namespace');
$host = (string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1');
$port = (int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306);
$user = (string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root');
$pass = (string)(getenv('BRVTAL_TEST_DB_PASS') ?: '');
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];
$server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, $options);
$database = $prefix . '_migration_replay_' . bin2hex(random_bytes(4));
$directory = sys_get_temp_dir() . '/brvtal-test-migration-replay-' . bin2hex(random_bytes(6));
replayAssert(mkdir($directory, 0700), 'create synthetic migration directory');
register_shutdown_function(static function () use ($server, $database, $directory): void {
    try {
        $server->exec('DROP DATABASE IF EXISTS ' . $database);
    } catch (Throwable) {
        // Do not mask the original test failure.
    }
    foreach (glob($directory . '/*') ?: [] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    rmdir($directory);
});
$server->exec('CREATE DATABASE ' . $database . ' CHARACTER SET utf8mb4');
$db = new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4", $user, $pass, $options);
foreach ([
    'CREATE TABLE events(id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB',
    'CREATE TABLE admins(id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB',
    'CREATE TABLE event_ticket_types(id INT UNSIGNED PRIMARY KEY,event_id INT UNSIGNED NOT NULL) ENGINE=InnoDB',
    'CREATE TABLE memories(id INT UNSIGNED PRIMARY KEY,media_id INT UNSIGNED NULL) ENGINE=InnoDB',
] as $ddl) {
    $db->exec($ddl);
}
foreach (array_merge(['migration_schema_migrations_01.sql'], $names) as $name) {
    replayAssert(copy(__DIR__ . '/../database/' . $name, $directory . '/' . $name), 'copy only canonical migration fixture');
}

// Use the REAL reconciliation engine with the same alphabetical plan and
// structural proof functions used on Hostinger, not a mocked ordering routine.
$before = brvtalMigrationReconciliationPlan($db, $directory);
replayAssert($before['blocked'] === [] && count($before['apply']) === 6, 'all six additive migrations classified');
replayAssert(
    array_search('migration_ticketing_entries_01.sql', array_column($before['actions'], 'migration'), true)
        < array_search('migration_ticketing_orders_01.sql', array_column($before['actions'], 'migration'), true),
    'fixture must reproduce the original lexical conflict'
);
$first = brvtalMigrationReconcileHistorical($db, $directory, 'synthetic-replay', str_repeat('a', 40));
replayAssert($first['verified'] === true && count($first['applied']) === 6, 'six migrations applied once');
replayAssert(
    (int)$db->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === 7,
    'registry records each of six migrations plus registry itself'
);

// A second real reconciliation must be a no-op, and all six DDL files must be
// replay-safe independently (including ALTER ADD COLUMN IF NOT EXISTS).
$second = brvtalMigrationReconcileHistorical($db, $directory, 'synthetic-replay', str_repeat('a', 40));
replayAssert(
    $second['verified'] === true && $second['applied'] === [] && $second['baselined'] === [],
    'second reconciliation does not reapply or rebaseline'
);
foreach ($orderedNames as $name) {
    $sql = file_get_contents($directory . '/' . $name);
    replayAssert(is_string($sql) && $sql !== '', 'read canonical replay SQL');
    $sql = (string)preg_replace('/^\h*--[^\r\n]*$/m', '', $sql);
    foreach (explode(';', $sql) as $statement) {
        if (trim($statement) !== '') {
            $db->exec($statement);
        }
    }
}
replayAssert(
    (int)$db->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === 7,
    'replay preserves migration registry without duplicates'
);
echo "Six canonical migrations applied, replayed and verified in isolated MariaDB.\n";
