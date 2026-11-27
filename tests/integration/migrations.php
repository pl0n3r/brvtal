<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/migrations.php';
require_once __DIR__ . '/../../config/migration_reconcile.php';

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

// Shadow any existing events table with an isolated pre-migration fixture.
$pdo->exec("CREATE TEMPORARY TABLE events (id INT PRIMARY KEY, status VARCHAR(30) NOT NULL)");
try {
    $pdo->exec("INSERT INTO events VALUES (1,'published'),(2,'draft')");
    $eventVisibilityMigration = (string)file_get_contents(__DIR__ . '/../../database/migration_event_visibility_01.sql');
    $pdo->exec($eventVisibilityMigration);
    migrations_it_assert((int)$pdo->query("SELECT COUNT(*) FROM events WHERE visibility='public'")->fetchColumn() === 2, 'existing events remain public');
    $pdo->exec("UPDATE events SET visibility='unlisted' WHERE id=1");
    $pdo->exec($eventVisibilityMigration);
    migrations_it_assert($pdo->query("SELECT visibility FROM events WHERE id=1")->fetchColumn() === 'unlisted', 'second migration must preserve private visibility');
    $pdo->exec("INSERT INTO events(id,status) VALUES (3,'published')");
    migrations_it_assert($pdo->query("SELECT visibility FROM events WHERE id=3")->fetchColumn() === 'public', 'new events default to public');
} finally {
    $pdo->exec('DROP TEMPORARY TABLE events');
}

/** #2246: same real information_schema probes for a file proof and a central entry. */
function migrations_it_file_proofs(PDO $pdo, string $directory): void
{
    $proofs = $directory . '/proofs';
    $name = 'migration_file_probe_01.sql';
    $table = 'brvtal_file_probe_' . bin2hex(random_bytes(6));
    $sql = "CREATE TABLE $table (id INT PRIMARY KEY)";
    $requirements = brvtalMigrationObjectRequirements($table, ['id'], [], true);
    $path = $proofs . '/' . $name . '.php';
    $source = '<?php return ' . var_export($requirements, true) . ';';
    $reject = static function (array $central, string $error) use ($directory): void {
        try {
            brvtalMigrationLoadProofFiles($central, $directory);
        } catch (RuntimeException $exception) {
            migrations_it_assert($exception->getMessage() === $error, 'file proof failure code');
            return;
        }
        migrations_it_assert(false, 'invalid file proof accepted');
    };
    $central = ['migration_central_fixture.sql' => ['table:central_fixture']];
    migrations_it_assert(brvtalMigrationLoadProofFiles($central, $directory) === $central, 'missing proof directory preserves central map');
    migrations_it_assert(mkdir($proofs, 0700), 'create disposable proof directory');
    try {
        migrations_it_assert(file_put_contents($proofs . '/README.md', 'fixture') !== false, 'write fixture README');
        migrations_it_assert(file_put_contents($directory . '/' . $name, $sql) !== false, 'write fixture SQL');
        migrations_it_assert(file_put_contents($path, $source) !== false, 'write fixture proof');
        $loaded = brvtalMigrationLoadProofFiles($central, $directory);
        migrations_it_assert($loaded === $central + [$name => $requirements], 'file adds only its own entry');
        $probe = static fn(string $requirement): bool => brvtalMigrationProbeRequirement($pdo, $requirement);
        $evaluate = static fn(): array => brvtalMigrationEvaluateRequirements($loaded[$name], $probe);
        $absent = $evaluate();
        migrations_it_assert($absent === brvtalMigrationEvaluateRequirements($requirements, $probe), 'absent proof equals central proof');
        migrations_it_assert(brvtalMigrationProofDisposition($name, $absent) === 'apply', 'file proof permits absent additive schema');
        brvtalMigrationAssertReconciliationSql($name, $sql, $absent);
        $pdo->exec("CREATE TABLE $table (other_id INT PRIMARY KEY)");
        migrations_it_assert(brvtalMigrationProofDisposition($name, $evaluate()) === 'blocked', 'partial file proof blocks reconciliation');
        $pdo->exec("DROP TABLE $table");
        $pdo->exec($sql);
        $complete = $evaluate();
        migrations_it_assert($complete === brvtalMigrationEvaluateRequirements($requirements, $probe), 'complete proof equals central proof');
        migrations_it_assert(brvtalMigrationProofDisposition($name, $complete) === 'baseline', 'file proof baselines complete schema');
        $reject($central + [$name => $requirements], 'MIGRATION_SCHEMA_PROOF_DUPLICATE:' . $name);

        $earlier = 'migration_a_fixture.sql';
        file_put_contents($directory . '/' . $earlier, $sql);
        file_put_contents($proofs . '/' . $earlier . '.php', $source);
        migrations_it_assert(array_keys(brvtalMigrationLoadProofFiles($central, $directory))
            === ['migration_central_fixture.sql', $earlier, $name], 'file order is deterministic');
        unlink($proofs . '/' . $earlier . '.php');
        unlink($directory . '/' . $earlier);

        unlink($directory . '/' . $name);
        $reject($central, 'MIGRATION_SCHEMA_PROOF_ORPHAN:' . $name);
        migrations_it_assert(symlink($path, $directory . '/' . $name), 'create synthetic SQL symlink');
        $reject($central, 'MIGRATION_SCHEMA_PROOF_ORPHAN:' . $name);
        unlink($directory . '/' . $name);
        file_put_contents($directory . '/' . $name, $sql);

        foreach (['false', '[]', "['key' => 'table:x']", '[1]', "['column:x']", "['table:x.y']",
            "['unknown:x']", "['table:x', 'table:x']", 'array_fill(0, 201, "table:x")',
            "['table:' . str_repeat('x', 180)]"] as $invalid) {
            file_put_contents($path, '<?php return ' . $invalid . ';');
            $reject($central, 'MIGRATION_SCHEMA_PROOF_FILE_INVALID');
        }
        file_put_contents($path, '<?php return [;');
        $reject($central, 'MIGRATION_SCHEMA_PROOF_FILE_INVALID');
        unlink($path);
        migrations_it_assert(symlink($directory . '/' . $name, $path), 'create synthetic proof symlink');
        $reject($central, 'MIGRATION_SCHEMA_PROOF_FILE_INVALID');
        unlink($path);
        file_put_contents($proofs . '/unexpected.php', $source);
        $reject($central, 'MIGRATION_SCHEMA_PROOF_FILE_INVALID');
    } finally {
        $pdo->exec("DROP TABLE IF EXISTS $table");
        foreach (glob($proofs . '/*') ?: [] as $fixture) {
            unlink($fixture);
        }
        rmdir($proofs);
        if (is_file($directory . '/' . $name)) {
            unlink($directory . '/' . $name);
        }
        if (isset($earlier) && is_file($directory . '/' . $earlier)) {
            unlink($directory . '/' . $earlier);
        }
    }
}

migrations_it_file_proofs($pdo, $tempDir);
echo "BRVTAL migration proof-file MariaDB integration passed.\n";

$cleanup();
echo "BRVTAL migration-state MariaDB integration passed.\n";


/** #2062: isolated schema fixture. No real payloads or mail transport. */
function forms_migration_fixture(PDO $server, string $dsn, string $user, string $pass): void
{
    $formsDb = 'brvtal_test_forms_' . bin2hex(random_bytes(6));
    $server->exec('CREATE DATABASE ' . $formsDb . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    try {
        $forms = new PDO($dsn . ';dbname=' . $formsDb, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $path = __DIR__ . '/../../database/migration_forms_entries_01.sql';
        $name = basename($path);
        $sql = (string)file_get_contents($path);
        $disposition = static fn(): string => brvtalMigrationProofDisposition($name, brvtalMigrationSchemaProof($forms, $name));
        migrations_it_assert($disposition() === 'apply', 'forms schema initially absent');
        brvtalMigrationAssertReconciliationSql($name, $sql, brvtalMigrationSchemaProof($forms, $name));
        $parts = preg_split('/;\s*(?=CREATE TABLE|$)/i', $sql);
        $forms->exec($parts[0]);
        migrations_it_assert($disposition() === 'blocked', 'partial forms schema must fail closed');
        $forms->exec('DROP TABLE form_definition_versions');
        brvtal_migration_apply_file($forms, __DIR__ . '/../../database/migration_schema_migrations_01.sql', 'ci', 'abcdef1');
        migrations_it_assert(brvtal_migration_apply_file($forms, $path, 'ci', 'abcdef1')['status'] === 'applied', 'forms migration applied');
        migrations_it_assert($disposition() === 'baseline', 'complete structural proof');
        migrations_it_assert(brvtal_migration_apply_file($forms, $path, 'ci', 'abcdef1')['status'] === 'already_applied', 'forms checksum guard');
        $reject = static function (callable $action, string $reason): void {
            try {
                $action();
            } catch (PDOException $exception) {
                migrations_it_assert(in_array((int)($exception->errorInfo[1] ?? 0), [1062, 1451, 1452, 4025, 3819], true), $reason . ': expected constraint violation');
                return;
            }
            migrations_it_assert(false, $reason);
        };
        $created = '2026-01-01 00:00:00';
        $until = '2026-01-02 00:00:00'; // Synthetic dates, not a retention policy.
        $definition = '{"fields":["subject"]}';
        $digest = hash('sha256', $definition, true);
        $version = $forms->prepare('INSERT INTO form_definition_versions VALUES (?,?,?,?,?)');
        foreach ([['contact',1],['contact',2],['other',1]] as [$key,$number]) {
            $version->execute([$key,$number,$digest,$definition,$created]);
        }
        $reject(static fn() => $version->execute(['bad',1,str_repeat('z',32),$definition,$created]), 'version digest mismatch');
        $insert = $forms->prepare('INSERT INTO form_entries (entry_id,form_key,version_number,definition_sha256,idempotency_digest,payload_json,created_at_utc,purpose_key,notice_version,retention_policy_key,retention_until_utc,state,redacted_at_utc) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $entry = static fn(string $id, string $key = 'contact', int $version = 1, ?string $hash = null, ?string $idem = null, ?string $payload = '{"subject":"fixture"}', ?string $retention = null, string $state = 'received', ?string $redacted = null): array => [$id,$key,$version,$hash ?? $digest,$idem ?? str_repeat('k',32),$payload,$created,'fixture','fixture-notice','fixture-policy',$retention ?? $until,$state,$redacted];
        $a = str_repeat('a',16);
        $b = str_repeat('b',16);
        $c = str_repeat('c',16);
        $insert->execute($entry($a));
        $reject(static fn() => $insert->execute($entry($b, version:2)), 'idempotency scoped to form not version');
        $insert->execute($entry($b, 'other'));
        $reject(static fn() => $insert->execute($entry($a, 'other', idem:str_repeat('x',32))), 'opaque id unique');
        $idem = str_repeat('x',32);
        $reject(static fn() => $insert->execute($entry($c, version:99, idem:$idem)), 'missing definition version');
        $reject(static fn() => $insert->execute($entry($c, hash:str_repeat('z',32), idem:$idem)), 'wrong definition digest');
        $reject(static fn() => $insert->execute($entry($c, idem:$idem, payload:'[]')), 'payload object required');
        $reject(static fn() => $insert->execute($entry($c, idem:$idem, retention:$created)), 'retention must follow capture');
        $reject(static fn() => $insert->execute($entry($c, idem:$idem, state:'redacted')), 'redaction must remove payload');
        $intent = $forms->prepare('INSERT INTO form_notification_intents VALUES (?, ?, ?, ?)');
        $intent->execute([$a,'notify','pending',$created]);
        $reject(static fn() => $intent->execute([$a,'notify','pending',$created]), 'one intent per entry/action');
        $reject(static fn() => $intent->execute([$c,'notify','pending',$created]), 'orphan intent rejected');
        $reject(static fn() => $forms->exec("DELETE FROM form_definition_versions WHERE form_key='contact' AND version_number=1"), 'referenced definition cannot be deleted');
        $update = $forms->prepare("UPDATE form_definition_versions SET definition_json=?, definition_sha256=? WHERE form_key='contact' AND version_number=1");
        $reject(static fn() => $update->execute(['{}',hash('sha256','{}',true)]), 'referenced definition cannot be rewritten');
        $reject(static fn() => $forms->exec("DELETE FROM form_entries WHERE form_key='contact'"), 'intent evidence cannot cascade');
        $forms->beginTransaction();
        try {
            $insert->execute($entry($c, idem:$idem));
            $intent->execute([$c,'notify','pending',$created]);
        } finally {
            $forms->rollBack();
        }
        migrations_it_assert((int)$forms->query('SELECT COUNT(*) FROM form_entries')->fetchColumn() === 2, 'entry rollback');
        migrations_it_assert((int)$forms->query('SELECT COUNT(*) FROM form_notification_intents')->fetchColumn() === 1, 'intent rollback');
        $forms->exec($sql);
        migrations_it_assert((int)$forms->query('SELECT COUNT(*) FROM form_entries')->fetchColumn() === 2, 'repeat DDL preserves entries');
        $redact = $forms->prepare("UPDATE form_entries SET state='redacted', payload_json=NULL, redacted_at_utc=? WHERE entry_id=?");
        $forms->beginTransaction();
        try {
            $redact->execute([$until,$a]);
            $suppress = $forms->prepare("UPDATE form_notification_intents SET state='suppressed' WHERE entry_id=? AND state='pending'");
            $suppress->execute([$a]);
            $forms->commit();
        } catch (Throwable $exception) {
            $forms->rollBack();
            throw $exception;
        }
        migrations_it_assert((int)$forms->query("SELECT COUNT(*) FROM form_entries WHERE state='redacted' AND payload_json IS NULL")->fetchColumn() === 1, 'redaction removes only payload');
        migrations_it_assert((int)$forms->query('SELECT COUNT(*) FROM form_notification_intents')->fetchColumn() === 1, 'redaction preserves intent identity');
        migrations_it_assert($forms->query('SELECT state FROM form_notification_intents')->fetchColumn() === 'suppressed', 'redaction suppresses pending intent atomically');
    } finally {
        $server->exec('DROP DATABASE ' . $formsDb);
    }
}

$formsDsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4',
    (string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1'),
    (int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306));
$formsUser = (string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root');
$formsPass = (string)(getenv('BRVTAL_TEST_DB_PASS') ?: '');
$formsServer = new PDO($formsDsn, $formsUser, $formsPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
forms_migration_fixture($formsServer, $formsDsn, $formsUser, $formsPass);
echo "BRVTAL forms-entry schema MariaDB integration passed.\n";

// #2175: exercise the real additive DDL twice on a disposable pre-migration table.
$doorDb = 'brvtal_test_door_' . bin2hex(random_bytes(6));
$formsServer->exec('CREATE DATABASE ' . $doorDb . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
try {
    $doorPdo = new PDO($formsDsn . ';dbname=' . $doorDb, $formsUser, $formsPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $doorPdo->exec('CREATE TABLE event_ticket_types (id INT UNSIGNED PRIMARY KEY, price DECIMAL(12,2) NULL)');
    $doorPdo->exec('INSERT INTO event_ticket_types (id,price) VALUES (1,20000),(2,35000)');
    $doorMigration = 'migration_ticketing_door_price_01.sql';
    $doorSql = (string)file_get_contents(__DIR__ . '/../../database/' . $doorMigration);
    $doorProof = brvtalMigrationSchemaProof($doorPdo, $doorMigration);
    migrations_it_assert(brvtalMigrationProofDisposition($doorMigration, $doorProof) === 'apply', 'door column absent');
    brvtalMigrationAssertReconciliationSql($doorMigration, $doorSql, $doorProof);
    $doorPdo->exec($doorSql);
    migrations_it_assert(brvtalMigrationSchemaProof($doorPdo, $doorMigration)['complete'], 'door nullable unsigned INT proof');
    migrations_it_assert((int)$doorPdo->query('SELECT COUNT(*) FROM event_ticket_types WHERE door_price_cop IS NULL')->fetchColumn() === 2,
        'existing types default to NULL');
    $doorPdo->exec('UPDATE event_ticket_types SET door_price_cop=50000 WHERE id=1');
    $doorPdo->exec($doorSql);
    migrations_it_assert((int)$doorPdo->query('SELECT door_price_cop FROM event_ticket_types WHERE id=1')->fetchColumn() === 50000,
        'repeat DDL preserves explicit door tariff');
    migrations_it_assert((int)$doorPdo->query('SELECT COUNT(*) FROM event_ticket_types WHERE id=2 AND door_price_cop IS NULL AND price=35000')->fetchColumn() === 1,
        'repeat DDL preserves existing type and fallback');
    migrations_it_assert(brvtalMigrationProofDisposition($doorMigration, brvtalMigrationSchemaProof($doorPdo, $doorMigration)) === 'baseline',
        'repeated migration has complete proof');
    define('BRVTAL_INVENTORY_PRICE_CONTRACT_ONLY', true);
    require_once __DIR__ . '/ticketing-inventory.php';
    inventoryChannelQuoteContract((int)$doorPdo->query('SELECT door_price_cop FROM event_ticket_types WHERE id=1')->fetchColumn());
} finally {
    $formsServer->exec('DROP DATABASE ' . $doorDb);
}
echo "BRVTAL door tariff migration and channel quote MariaDB integration passed.\n";

// #1886: synthetic, isolated mail-template schema and transactional store tests.
require __DIR__ . '/mail-template-store.php';
