<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/bootstrap.php';
require_once dirname(__DIR__) . '/config/deployment.php';
require_once dirname(__DIR__) . '/config/migrations.php';
require_once dirname(__DIR__) . '/config/migration_reconcile.php';

$root = dirname(__DIR__);
$directory = $root . '/database';
$command = strtolower((string)($argv[1] ?? 'status'));
$json = in_array('--json', $argv, true);
$confirmed = in_array('--confirm', $argv, true);
$writeAllowed = (string)getenv('BRVTAL_MIGRATIONS_ALLOW_WRITE') === '1';
$actor = trim((string)getenv('BRVTAL_MIGRATION_ACTOR'));
if ($actor === '') {
    $actor = get_current_user() ?: 'cli';
}
$deploySha = brvtal_deployment_sha();
$pdo = db();

function migration_cli_fail(string $message, int $code = 1): never
{
    fwrite(STDERR, "BRVTAL MIGRATIONS: {$message}\n");
    exit($code);
}

function migration_cli_require_write(bool $writeAllowed, bool $confirmed): void
{
    if (!$writeAllowed) {
        migration_cli_fail('write commands require BRVTAL_MIGRATIONS_ALLOW_WRITE=1');
    }
    if (!$confirmed) {
        migration_cli_fail('write commands require --confirm');
    }
}

function migration_cli_find(string $directory, string $name): string
{
    if (!preg_match('/^migration_[a-z0-9_]+\.sql$/', $name)) {
        migration_cli_fail('invalid migration name');
    }
    $files = brvtal_migrations_discover($directory);
    if (!isset($files[$name])) {
        migration_cli_fail("migration not found: {$name}");
    }
    return $files[$name];
}

try {
    if ($command === 'status') {
        $status = brvtal_migration_status($pdo, $directory);
        if ($json) {
            echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        } else {
            echo 'Registry: ' . ($status['registry_exists'] ? 'present' : 'missing') . PHP_EOL;
            foreach ($status['migrations'] as $row) {
                printf("%-20s %s\n", strtoupper((string)$row['state']), (string)$row['migration']);
            }
            foreach ($status['orphaned_records'] as $row) {
                printf("%-20s %s\n", 'RECORD_WITHOUT_FILE', (string)$row['migration']);
            }
        }

        $hasMismatch = false;
        foreach ($status['migrations'] as $row) {
            if (($row['state'] ?? '') === 'checksum_mismatch') {
                $hasMismatch = true;
                break;
            }
        }
        if ($status['orphaned_records'] !== []) {
            $hasMismatch = true;
        }
        exit($hasMismatch ? 2 : 0);
    }

    if ($command === 'reconcile-plan') {
        $plan = brvtalMigrationReconciliationPlan($pdo, $directory);
        if ($json) {
            echo json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        } else {
            echo 'Registry: ' . ($plan['registry_exists'] ? 'present' : 'missing') . PHP_EOL;
            echo 'Registry record: ' . ($plan['record_registry_migration'] ? 'required' : 'current') . PHP_EOL;
            foreach ($plan['baseline'] as $name) {
                echo 'BASELINEABLE ' . $name . PHP_EOL;
            }
        }
        exit(0);
    }

    if ($command === 'reconcile') {
        migration_cli_require_write($writeAllowed, $confirmed);
        if ((string)getenv('BRVTAL_MIGRATION_RECONCILE_BACKUP_READY') !== '1') {
            migration_cli_fail('reconcile requires BRVTAL_MIGRATION_RECONCILE_BACKUP_READY=1');
        }
        $result = brvtalMigrationReconcileHistorical($pdo, $directory, $actor, $deploySha);
        if ($json) {
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        } else {
            echo 'RECONCILED ' . count($result['baselined']) . ' historical migrations' . PHP_EOL;
        }
        exit(0);
    }

    if ($command === 'verify-plan') {
        $expected = (string)($argv[2] ?? '');
        if ($expected === '') {
            migration_cli_fail('usage: verify-plan <migration_name.sql|__NONE__>');
        }
        $status = brvtal_migration_status($pdo, $directory);
        brvtalMigrationVerifyPlanStatus($status, $expected);
        echo "VERIFIED {$expected}" . PHP_EOL;
        exit(0);
    }

    if ($command === 'init') {
        migration_cli_require_write($writeAllowed, $confirmed);
        $path = migration_cli_find($directory, 'migration_schema_migrations_01.sql');
        $result = brvtal_migration_apply_file($pdo, $path, $actor, $deploySha);
        echo strtoupper((string)$result['status']) . ' ' . $result['migration'] . PHP_EOL;
        exit(0);
    }

    if ($command === 'apply') {
        migration_cli_require_write($writeAllowed, $confirmed);
        $name = (string)($argv[2] ?? '');
        if ($name === '') {
            migration_cli_fail('usage: apply <migration_name.sql> --confirm');
        }
        if ($name === 'migration_schema_migrations_01.sql') {
            migration_cli_fail('use init for the migration registry');
        }
        $path = migration_cli_find($directory, $name);
        $result = brvtal_migration_apply_file($pdo, $path, $actor, $deploySha);
        echo strtoupper((string)$result['status']) . ' ' . $result['migration'] . PHP_EOL;
        exit(0);
    }

    if ($command === 'baseline') {
        migration_cli_require_write($writeAllowed, $confirmed);
        $name = (string)($argv[2] ?? '');
        if ($name === '') {
            migration_cli_fail('usage: baseline <migration_name.sql> --confirm');
        }
        if ($name === 'migration_schema_migrations_01.sql') {
            migration_cli_fail('the registry migration is recorded by init');
        }
        $path = migration_cli_find($directory, $name);
        $result = brvtal_migration_baseline_file($pdo, $path, $actor, $deploySha);
        echo strtoupper((string)$result['status']) . ' ' . $result['migration'] . PHP_EOL;
        exit(0);
    }

    migration_cli_fail(
        'supported commands: status [--json], reconcile-plan [--json], reconcile --confirm [--json], ' .
        'verify-plan <name|__NONE__>, init --confirm, apply <name> --confirm, baseline <name> --confirm'
    );
} catch (Throwable $exception) {
    brvtal_log('MIGRATION_ERROR', 'Migration command failed.', [
        'command' => $command,
        'message' => $exception->getMessage(),
    ]);
    migration_cli_fail($exception->getMessage());
}
