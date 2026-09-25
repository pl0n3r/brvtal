<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/health.php';

function factory_health_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FACTORY HEALTH CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$sha = str_repeat('a', 40);
$applied = static fn(string $name): array => ['migration' => $name, 'state' => 'applied'];
$pending = static fn(string $name): array => ['migration' => $name, 'state' => 'pending'];
$mismatch = static fn(string $name): array => ['migration' => $name, 'state' => 'checksum_mismatch'];
$status = static fn(array $rows, bool $registry = true, array $orphans = []): array => [
    'registry_exists' => $registry,
    'migrations' => $rows,
    'orphaned_records' => $orphans,
];

$healthyStatus = $status([
    $applied('migration_schema_migrations_01.sql'),
    $applied('migration_existing_01.sql'),
]);
$healthy = brvtalFactoryHealthState(true, $sha, $healthyStatus);
factory_health_expect($healthy['ready'] === true, 'exact identity + clean schema must be ready');
factory_health_expect($healthy['status'] === 'ok', 'ready state must expose Factory status=ok');
factory_health_expect($healthy['health_status'] === 'healthy', 'ready state must preserve human healthy descriptor');
factory_health_expect($healthy['release_sha'] === $sha, 'ready state must expose exact SHA');
factory_health_expect($healthy['schema_up_to_date'] === true, 'clean schema must be up to date');

$nonExact = brvtalFactoryHealthState(false, $sha, $healthyStatus);
factory_health_expect($nonExact['ready'] === false, 'non-exact identity must fail closed');
factory_health_expect($nonExact['release_sha'] === null, 'non-exact identity must not publish a release SHA');
factory_health_expect($nonExact['schema_up_to_date'] === true, 'identity failure must not falsify schema diagnostics');

$invalidSha = brvtalFactoryHealthState(true, 'not-a-sha', $healthyStatus);
factory_health_expect($invalidSha['ready'] === false && $invalidSha['release_sha'] === null, 'invalid exact SHA must fail closed');

$missingRegistry = brvtalFactoryHealthState(true, $sha, $status([], false));
factory_health_expect($missingRegistry['schema_up_to_date'] === false, 'missing migration registry must not be ready');

$pendingSchema = brvtalFactoryHealthState(true, $sha, $status([
    $applied('migration_schema_migrations_01.sql'),
    $pending('migration_pending_01.sql'),
]));
factory_health_expect($pendingSchema['schema_up_to_date'] === false, 'pending migration must not be ready');

$mismatchedSchema = brvtalFactoryHealthState(true, $sha, $status([
    $applied('migration_schema_migrations_01.sql'),
    $mismatch('migration_existing_01.sql'),
]));
factory_health_expect($mismatchedSchema['schema_up_to_date'] === false, 'checksum mismatch must not be ready');

$orphanedSchema = brvtalFactoryHealthState(
    true,
    $sha,
    $status([$applied('migration_schema_migrations_01.sql')], true, [['migration' => 'migration_orphan_01.sql']])
);
factory_health_expect($orphanedSchema['schema_up_to_date'] === false, 'orphaned registry record must not be ready');

$summary = brvtalMigrationHealthSummary($status([
    $applied('migration_schema_migrations_01.sql'),
    $pending('migration_pending_01.sql'),
    $mismatch('migration_existing_01.sql'),
], true, [['migration' => 'migration_orphan_01.sql']]));
factory_health_expect($summary['registry_exists'] === true, 'summary must expose registry state');
factory_health_expect($summary['applied'] === 1, 'summary must count applied migrations');
factory_health_expect($summary['pending'] === 1, 'summary must count pending migrations');
factory_health_expect($summary['checksum_mismatch'] === 1, 'summary must count checksum mismatches');
factory_health_expect($summary['orphaned_records'] === 1, 'summary must count orphan records');

$endpoint = (string)file_get_contents(__DIR__ . '/../api/health.php');
foreach ([
    "'status' => \$factoryHealth['status']",
    "'version' => BRVTAL_APP_VERSION",
    "'release_sha' => \$factoryHealth['release_sha']",
    "'schema_up_to_date' => \$factoryHealth['schema_up_to_date']",
    'brvtal_migration_status($pdo',
] as $needle) {
    factory_health_expect(str_contains($endpoint, $needle), "health endpoint must contain {$needle}");
}
factory_health_expect(
    !preg_match('/\\b(?:INSERT|UPDATE|DELETE|REPLACE)\\s+(?:INTO\\s+|FROM\\s+)?[a-z_]/i', $endpoint),
    'health endpoint must remain read-only'
);
factory_health_expect(!str_contains($endpoint, 'BRVTAL_MIGRATIONS_ALLOW_WRITE'), 'health must never opt into migration writes');
factory_health_expect(!str_contains($endpoint, 'brvtal_migration_apply_file'), 'health must never apply migrations');
factory_health_expect(!str_contains($endpoint, 'brvtal_migration_baseline_file'), 'health must never baseline migrations');

echo "BRVTAL Factory health contract passed.\n";
