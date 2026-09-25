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
foreach (['brvtal_migrations_discover', 'brvtal_migration_status', 'brvtal_migration_apply_file', 'brvtal_migration_baseline_file', 'brvtal_migration_assert_additive_sql'] as $function) {
    migrations_assert(str_contains($library, "function {$function}"), "migration library must expose {$function}");
}
migrations_assert(str_contains($library, 'MIGRATION_CHECKSUM_MISMATCH'), 'changed applied migrations must fail closed');
migrations_assert(str_contains($library, 'MIGRATION_REGISTRY_MISSING'), 'writes must fail when registry state is unavailable');
migrations_assert(str_contains($library, 'MIGRATION_NON_ADDITIVE_SQL'), 'automatic migration safety must reject destructive SQL');

$cli = (string)file_get_contents(__DIR__ . '/../scripts/migrations.php');
migrations_assert(str_contains($cli, "PHP_SAPI !== 'cli'"), 'migration tool must be CLI-only');
migrations_assert(str_contains($cli, "BRVTAL_MIGRATIONS_ALLOW_WRITE"), 'writes must require an explicit environment gate');
migrations_assert(str_contains($cli, "--confirm"), 'writes must require explicit confirmation flag');
migrations_assert(str_contains($cli, "if (\$command === 'apply')"), 'tool must support explicit one-migration apply');
migrations_assert(!str_contains($cli, 'apply-all'), 'tool must not provide automatic apply-all behavior');
migrations_assert(str_contains($cli, "if (\$command === 'baseline')"), 'existing environments need an explicit baseline operation');

echo "BRVTAL migration-state contract tests passed.\n";
