<?php
declare(strict_types=1);

function migrations_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "MIGRATIONS CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$registrySql = (string)file_get_contents(__DIR__ . '/../database/migration_schema_migrations_01.sql');
migrations_assert(str_contains($registrySql, 'CREATE TABLE IF NOT EXISTS schema_migrations'), 'registry migration must be idempotent');
migrations_assert(str_contains($registrySql, 'migration_name VARCHAR(190) NOT NULL'), 'registry must persist migration name');
migrations_assert(str_contains($registrySql, 'checksum_sha256 CHAR(64) NOT NULL'), 'registry must persist SHA-256 checksum');
migrations_assert(str_contains($registrySql, 'deploy_sha CHAR(40) NULL'), 'registry must retain deploy provenance when known');

$library = (string)file_get_contents(__DIR__ . '/../config/migrations.php');
require_once __DIR__ . '/../config/migrations.php';

$rejectsNonAdditive = static function (string $sql): bool {
    try {
        brvtalMigrationAssertAdditiveSql($sql);
        return false;
    } catch (RuntimeException $exception) {
        return $exception->getMessage() === 'MIGRATION_NON_ADDITIVE_SQL';
    }
};

migrations_assert(!$rejectsNonAdditive("CREATE TABLE IF NOT EXISTS additive_probe (id INT);"), 'additive DDL must pass the automatic scanner');
migrations_assert(!$rejectsNonAdditive("-- DROP TABLE ignored_probe\nCREATE TABLE additive_probe_2 (id INT);"), 'commented destructive tokens must be ignored');
migrations_assert($rejectsNonAdditive("DROP TABLE users;"), 'DROP must be rejected');
migrations_assert($rejectsNonAdditive("INSERT INTO swatches VALUES ('#fff'); DROP TABLE users;"), 'string literals containing # must not mask later destructive SQL');
migrations_assert($rejectsNonAdditive("DELETE IGNORE FROM users;"), 'DELETE modifiers must be rejected');
foreach (['brvtal_migrations_discover', 'brvtal_migration_status', 'brvtal_migration_apply_file', 'brvtal_migration_baseline_file', 'brvtalMigrationAssertAdditiveSql', 'brvtal_migration_verify_plan_status'] as $function) {
    migrations_assert(str_contains($library, "function {$function}"), "migration library must expose {$function}");
}
migrations_assert(str_contains($library, 'MIGRATION_CHECKSUM_MISMATCH'), 'changed applied migrations must fail closed');
migrations_assert(str_contains($library, 'MIGRATION_REGISTRY_MISSING'), 'writes must fail when registry state is unavailable');
migrations_assert(str_contains($library, 'MIGRATION_NON_ADDITIVE_SQL'), 'automatic migration safety must reject destructive SQL');

$applied = static fn(string $name): array => ['migration' => $name, 'state' => 'applied'];
$pending = static fn(string $name): array => ['migration' => $name, 'state' => 'pending'];
$planStatus = static fn(array $rows, bool $registry = true, array $orphans = []): array => [
    'registry_exists' => $registry,
    'migrations' => $rows,
    'orphaned_records' => $orphans,
];

brvtal_migration_verify_plan_status(
    $planStatus([$applied('migration_schema_migrations_01.sql'), $applied('migration_existing_01.sql')]),
    '__NONE__'
);
brvtal_migration_verify_plan_status(
    $planStatus([$applied('migration_schema_migrations_01.sql'), $pending('migration_new_01.sql')]),
    'migration_new_01.sql'
);
brvtal_migration_verify_plan_status(
    $planStatus([$applied('migration_schema_migrations_01.sql'), $applied('migration_new_01.sql')]),
    'migration_new_01.sql'
);

$expectPlanFailure = static function (array $status, string $expected, string $error): bool {
    try {
        brvtal_migration_verify_plan_status($status, $expected);
        return false;
    } catch (RuntimeException $exception) {
        return $exception->getMessage() === $error;
    }
};
migrations_assert(
    $expectPlanFailure($planStatus([$pending('migration_existing_01.sql')]), '__NONE__', 'MIGRATION_PLAN_DB_DRIFT'),
    'no-op must fail when any candidate migration is still pending'
);
migrations_assert(
    $expectPlanFailure(
        $planStatus([$pending('migration_new_01.sql'), $pending('migration_other_01.sql')]),
        'migration_new_01.sql',
        'MIGRATION_PLAN_DB_DRIFT'
    ),
    'selected migration must be the only pending migration'
);
migrations_assert(
    $expectPlanFailure($planStatus([], false), '__NONE__', 'MIGRATION_REGISTRY_MISSING'),
    'automatic deploy must fail when migration registry is missing'
);
migrations_assert(
    $expectPlanFailure(
        $planStatus([$applied('migration_existing_01.sql')], true, [['migration' => 'migration_orphan_01.sql']]),
        '__NONE__',
        'MIGRATION_PLAN_DB_DRIFT'
    ),
    'automatic deploy must fail on orphaned registry records'
);

$cli = (string)file_get_contents(__DIR__ . '/../scripts/migrations.php');
migrations_assert(str_contains($cli, "PHP_SAPI !== 'cli'"), 'migration tool must be CLI-only');
migrations_assert(str_contains($cli, "BRVTAL_MIGRATIONS_ALLOW_WRITE"), 'writes must require an explicit environment gate');
migrations_assert(str_contains($cli, "--confirm"), 'writes must require explicit confirmation flag');
migrations_assert(str_contains($cli, "if (\$command === 'verify-plan')"), 'tool must support read-only deploy-plan verification');
migrations_assert(str_contains($cli, "if (\$command === 'apply')"), 'tool must support explicit one-migration apply');
migrations_assert(!str_contains($cli, 'apply-all'), 'tool must not provide automatic apply-all behavior');
migrations_assert(str_contains($cli, "if (\$command === 'baseline')"), 'existing environments need an explicit baseline operation');

echo "BRVTAL migration-state contract tests passed.\n";
