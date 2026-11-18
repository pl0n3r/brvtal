<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/migrations.php';

function migrations_it_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("MIGRATIONS INTEGRATION FAILED: {$message}");
    }
}

if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "BRVTAL migration integration skipped. Set BRVTAL_INTEGRATION_TESTS=1 to run.\n";
    exit(0);
}

$dbName = (string)(getenv('BRVTAL_TEST_DB_NAME') ?: '');
migrations_it_assert((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/', $dbName), 'test database name must start with brvtal_test');

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    (string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1'),
    (int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306),
    $dbName
);
$pdo = new PDO($dsn, (string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root'), (string)(getenv('BRVTAL_TEST_DB_PASS') ?: ''), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$tempDir = sys_get_temp_dir() . '/brvtal-migrations-' . bin2hex(random_bytes(6));
if (!mkdir($tempDir, 0700, true) && !is_dir($tempDir)) {
    throw new RuntimeException('MIGRATIONS INTEGRATION FAILED: could not create temp directory');
}

$cleanup = static function () use ($pdo, $tempDir): void {
    try {
        $pdo->exec('DROP TABLE IF EXISTS brvtal_migration_probe');
        $pdo->exec('DROP TABLE IF EXISTS brvtal_baseline_probe');
        $pdo->exec('DROP TABLE IF EXISTS schema_migrations');
    } catch (Throwable) {
    }
    foreach (glob($tempDir . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($tempDir);
};
register_shutdown_function($cleanup);

$pdo->exec('DROP TABLE IF EXISTS brvtal_migration_probe');
$pdo->exec('DROP TABLE IF EXISTS brvtal_baseline_probe');
$pdo->exec('DROP TABLE IF EXISTS schema_migrations');

$statusBefore = brvtal_migration_status($pdo, __DIR__ . '/../../database');
migrations_it_assert($statusBefore['registry_exists'] === false, 'status must report a missing registry without creating it');

$registryPath = __DIR__ . '/../../database/migration_schema_migrations_01.sql';
$registryResult = brvtal_migration_apply_file($pdo, $registryPath, 'ci', 'abcdef1');
migrations_it_assert($registryResult['status'] === 'applied', 'registry migration must apply');
migrations_it_assert(brvtal_migration_registry_exists($pdo), 'registry table must exist after init');

$probePath = $tempDir . '/migration_probe_01.sql';
file_put_contents($probePath, <<<'SQL'
CREATE TABLE IF NOT EXISTS brvtal_migration_probe (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  marker VARCHAR(40) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO brvtal_migration_probe(marker) VALUES ('first');
SQL
);

$applyResult = brvtal_migration_apply_file($pdo, $probePath, 'ci', 'abcdef1');
migrations_it_assert($applyResult['status'] === 'applied', 'pending migration must apply once');
migrations_it_assert((int)$pdo->query("SELECT COUNT(*) FROM brvtal_migration_probe WHERE marker='first'")->fetchColumn() === 1, 'migration SQL must execute');

$secondResult = brvtal_migration_apply_file($pdo, $probePath, 'ci', 'abcdef1');
migrations_it_assert($secondResult['status'] === 'already_applied', 'same checksum must not reapply');
migrations_it_assert((int)$pdo->query('SELECT COUNT(*) FROM brvtal_migration_probe')->fetchColumn() === 1, 'reapply guard must prevent duplicate effects');

$statusApplied = brvtal_migration_status($pdo, $tempDir);
migrations_it_assert(($statusApplied['migrations'][0]['state'] ?? '') === 'applied', 'status must report matching checksum as applied');

file_put_contents($probePath, "\n-- changed after application\n", FILE_APPEND);
$statusChanged = brvtal_migration_status($pdo, $tempDir);
migrations_it_assert(($statusChanged['migrations'][0]['state'] ?? '') === 'checksum_mismatch', 'status must detect edited applied migration');

$mismatchBlocked = false;
try {
    brvtal_migration_apply_file($pdo, $probePath, 'ci', 'abcdef1');
} catch (RuntimeException $exception) {
    $mismatchBlocked = $exception->getMessage() === 'MIGRATION_CHECKSUM_MISMATCH';
}
migrations_it_assert($mismatchBlocked, 'checksum mismatch must fail closed');

$baselinePath = $tempDir . '/migration_baseline_probe_01.sql';
file_put_contents($baselinePath, "CREATE TABLE brvtal_baseline_probe (id INT PRIMARY KEY);\n");
$baselineResult = brvtal_migration_baseline_file($pdo, $baselinePath, 'ci', 'abcdef1');
migrations_it_assert($baselineResult['status'] === 'baselined', 'verified historical migration can be recorded without execution');
migrations_it_assert(
    (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='brvtal_baseline_probe'")->fetchColumn() === 0,
    'baseline must never execute migration SQL'
);

$cleanup();
echo "BRVTAL migration-state MariaDB integration passed.\n";
