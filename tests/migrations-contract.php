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
$reconcileLibrary = (string)file_get_contents(__DIR__ . '/../config/migration_reconcile.php');
require_once __DIR__ . '/../config/migrations.php';
require_once __DIR__ . '/../config/migration_reconcile.php';

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
migrations_assert(
    !$rejectsNonAdditive("CREATE TABLE child (id INT, parent_id INT, CONSTRAINT fk_child_parent FOREIGN KEY (parent_id) REFERENCES parent(id) ON DELETE CASCADE);"),
    'foreign-key ON DELETE clauses are additive and must not be treated as DELETE statements'
);
migrations_assert(
    !$rejectsNonAdditive("CREATE TABLE timestamps_probe (updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP);"),
    'ON UPDATE timestamp clauses are additive and must not be treated as UPDATE statements'
);
migrations_assert($rejectsNonAdditive("DROP TABLE users;"), 'DROP must be rejected');
migrations_assert($rejectsNonAdditive("INSERT INTO swatches VALUES ('#fff');"), 'automatic reconciliation must reject INSERT data writes');
migrations_assert($rejectsNonAdditive("INSERT users VALUES (1);"), 'INSERT without INTO must be rejected');
migrations_assert($rejectsNonAdditive("INSERT LOW_PRIORITY INTO users VALUES (1);"), 'INSERT modifiers must be rejected');
migrations_assert($rejectsNonAdditive("REPLACE users VALUES (1);"), 'REPLACE without INTO must be rejected');
migrations_assert($rejectsNonAdditive("LOAD DATA INFILE '/tmp/users.csv' INTO TABLE users;"), 'LOAD DATA must be rejected');
migrations_assert(
    !$rejectsNonAdditive("CREATE TRIGGER users_before_insert BEFORE INSERT ON users FOR EACH ROW SET NEW.created_at = CURRENT_TIMESTAMP;"),
    'BEFORE INSERT trigger events must not be confused with INSERT data writes'
);
migrations_assert($rejectsNonAdditive("UPDATE users SET active=0;"), 'automatic reconciliation must reject UPDATE data writes');
migrations_assert($rejectsNonAdditive("DELETE IGNORE FROM users;"), 'DELETE modifiers must be rejected');

$mediaGuardSql = (string)file_get_contents(__DIR__ . '/../database/migration_zz_media_reference_guard_01.sql');
$mediaGuardProofChecks = array_fill_keys(
    brvtalMigrationProofSpecifications()['migration_zz_media_reference_guard_01.sql'],
    false
);
$fullyAbsentMediaGuardProof = [
    'complete' => false,
    'checks' => $mediaGuardProofChecks,
];
migrations_assert(
    $rejectsNonAdditive($mediaGuardSql),
    'canonical media guard must remain non-additive under the strict default scanner'
);
try {
    brvtalMigrationAssertReconciliationSql(
        'migration_zz_media_reference_guard_01.sql',
        $mediaGuardSql,
        $fullyAbsentMediaGuardProof
    );
} catch (RuntimeException $exception) {
    migrations_assert(false, 'exact media guard may bootstrap only when its proof is fully absent');
}
$partialMediaGuardProof = $fullyAbsentMediaGuardProof;
$partialMediaGuardProof['checks']['table:media_reference_mutex'] = true;
try {
    brvtalMigrationAssertReconciliationSql(
        'migration_zz_media_reference_guard_01.sql',
        $mediaGuardSql,
        $partialMediaGuardProof
    );
    migrations_assert(false, 'partial media guard state must remain blocked');
} catch (RuntimeException $exception) {
    migrations_assert(
        $exception->getMessage() === 'MIGRATION_NON_ADDITIVE_SQL',
        'partial media guard state must fail through the strict scanner'
    );
}
try {
    brvtalMigrationAssertReconciliationSql(
        'migration_zz_media_reference_guard_01.sql',
        $mediaGuardSql . "\nDROP TABLE users;\n",
        $fullyAbsentMediaGuardProof
    );
    migrations_assert(false, 'modified media guard SQL must never inherit the canonical exception');
} catch (RuntimeException $exception) {
    migrations_assert(
        $exception->getMessage() === 'MIGRATION_MEDIA_GUARD_BOOTSTRAP_MISMATCH',
        'modified media guard SQL must fail exact-blob validation'
    );
}
try {
    brvtalMigrationAssertReconciliationSql(
        'migration_other.sql',
        $mediaGuardSql,
        $fullyAbsentMediaGuardProof
    );
    migrations_assert(false, 'canonical exception must not transfer to another migration name');
} catch (RuntimeException $exception) {
    migrations_assert(
        $exception->getMessage() === 'MIGRATION_NON_ADDITIVE_SQL',
        'other migration names must retain the strict scanner'
    );
}
foreach (['brvtal_migrations_discover', 'brvtal_migration_status', 'brvtal_migration_apply_file', 'brvtal_migration_baseline_file', 'brvtalMigrationAssertAdditiveSql', 'brvtalMigrationVerifyPlanStatus'] as $function) {
    migrations_assert(str_contains($library, "function {$function}"), "migration library must expose {$function}");
}
migrations_assert(str_contains($library, 'MIGRATION_CHECKSUM_MISMATCH'), 'changed applied migrations must fail closed');
migrations_assert(str_contains($library, 'MIGRATION_REGISTRY_MISSING'), 'writes must fail when registry state is unavailable');
migrations_assert(str_contains($library, 'MIGRATION_NON_ADDITIVE_SQL'), 'automatic migration safety must reject destructive SQL');
$reconcileApply = substr(
    $reconcileLibrary,
    (int)strpos($reconcileLibrary, 'function brvtalMigrationReconcileHistorical')
);
$executionProofPosition = strpos($reconcileApply, 'brvtalMigrationSchemaProof($pdo, $name)');
$executionDispositionPosition = strpos(
    $reconcileApply,
    'brvtalMigrationProofDisposition($name, $executionProof)',
    $executionProofPosition === false ? 0 : $executionProofPosition
);
$executionAssertPosition = strpos(
    $reconcileApply,
    'brvtalMigrationAssertReconciliationSql($name, $sql, $executionProof)',
    $executionDispositionPosition === false ? 0 : $executionDispositionPosition
);
$execPosition = strpos(
    $reconcileApply,
    '$pdo->exec($sql);',
    $executionAssertPosition === false ? 0 : $executionAssertPosition
);
$postProofPosition = strpos(
    $reconcileApply,
    'brvtalMigrationSchemaProof($pdo, $name)',
    $execPosition === false ? 0 : $execPosition
);
$recordPosition = strpos(
    $reconcileApply,
    'brvtal_migration_baseline_file($pdo, $path, $actor, $deploySha)',
    $postProofPosition === false ? 0 : $postProofPosition
);
migrations_assert(
    $executionProofPosition !== false
        && $executionDispositionPosition !== false
        && $executionAssertPosition !== false
        && $execPosition !== false
        && $postProofPosition !== false
        && $recordPosition !== false
        && $executionProofPosition < $executionDispositionPosition
        && $executionDispositionPosition < $executionAssertPosition
        && $executionAssertPosition < $execPosition
        && $execPosition < $postProofPosition
        && $postProofPosition < $recordPosition,
    'automatic apply must re-probe fully absent schema before SQL and verify baseline before recording'
);


$applied = static fn(string $name): array => ['migration' => $name, 'state' => 'applied'];
$pending = static fn(string $name): array => ['migration' => $name, 'state' => 'pending'];
$planStatus = static fn(array $rows, bool $registry = true, array $orphans = []): array => [
    'registry_exists' => $registry,
    'migrations' => $rows,
    'orphaned_records' => $orphans,
];

$expectReconciliationFailure = static function (
    array $status,
    array $proofs,
    string $expected,
    string $message
): void {
    try {
        brvtalMigrationBuildReconciliationPlan($status, $proofs);
    } catch (RuntimeException $exception) {
        migrations_assert(
            $exception->getMessage() === $expected
                || str_starts_with($exception->getMessage(), $expected),
            $message
        );
        return;
    }
    migrations_assert(false, $message);
};

brvtalMigrationVerifyPlanStatus(
    $planStatus([$applied('migration_schema_migrations_01.sql'), $applied('migration_existing_01.sql')]),
    '__NONE__'
);
brvtalMigrationVerifyPlanStatus(
    $planStatus([$applied('migration_schema_migrations_01.sql'), $pending('migration_new_01.sql')]),
    'migration_new_01.sql'
);
brvtalMigrationVerifyPlanStatus(
    $planStatus([$applied('migration_schema_migrations_01.sql'), $applied('migration_new_01.sql')]),
    'migration_new_01.sql'
);

$expectPlanFailure = static function (array $status, string $expected, string $error): bool {
    try {
        brvtalMigrationVerifyPlanStatus($status, $expected);
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
migrations_assert(str_contains($cli, "if (\$command === 'reconcile-plan')"), 'tool must expose one read-only historical reconciliation plan');
migrations_assert(str_contains($cli, "if (\$command === 'reconcile')"), 'tool must expose one controlled reconciliation command');
migrations_assert(str_contains($cli, 'BRVTAL_MIGRATION_RECONCILE_BACKUP_READY'), 'historical reconciliation writes must require backup evidence');

$specifications = brvtalMigrationProofSpecifications();
$diskMigrations = array_map(basename(...), glob(__DIR__ . '/../database/migration_*.sql') ?: []);
sort($diskMigrations);
$proofMigrations = array_keys($specifications);
$expectedHistorical = array_values(array_filter(
    $diskMigrations,
    static fn(string $name): bool => $name !== 'migration_schema_migrations_01.sql'
));
sort($expectedHistorical);
sort($proofMigrations);
migrations_assert($proofMigrations === $expectedHistorical, 'every historical migration must have an explicit proof specification');

$proofAll = brvtalMigrationEvaluateRequirements(
    ['table:alpha', 'column:alpha.beta'],
    static fn(string $requirement): bool => true
);
migrations_assert(($proofAll['complete'] ?? false) === true, 'all structural proof requirements true must be complete');
$proofPartial = brvtalMigrationEvaluateRequirements(
    ['table:alpha', 'column:alpha.beta'],
    static fn(string $requirement): bool => $requirement === 'table:alpha'
);
migrations_assert(($proofPartial['complete'] ?? true) === false, 'one missing structural proof must fail closed');

$pendingHistorical = 'migration_blog_01.sql';
$plan = brvtalMigrationBuildReconciliationPlan(
    [
        'registry_exists' => false,
        'migrations' => [
            $pending('migration_schema_migrations_01.sql'),
            $pending($pendingHistorical),
        ],
        'orphaned_records' => [],
    ],
    [
        $pendingHistorical => ['complete' => true, 'checks' => ['table:blog_posts' => true]],
    ]
);
migrations_assert($plan['record_registry_migration'] === true, 'missing registry must require registry recording');
migrations_assert($plan['baseline'] === [$pendingHistorical], 'only structurally proven pending history may be baselined');
migrations_assert($plan['apply'] === [], 'fully represented history must not be applied');
migrations_assert($plan['blocked'] === [], 'fully represented history must not be blocked');
migrations_assert(
    $plan['actions'] === [['migration' => $pendingHistorical, 'action' => 'baseline']],
    'reconciliation actions must preserve canonical migration order'
);

$absentPlan = brvtalMigrationBuildReconciliationPlan(
    $planStatus(
        [$pending('migration_schema_migrations_01.sql'), $pending($pendingHistorical)],
        false
    ),
    [$pendingHistorical => ['complete' => false, 'checks' => ['table:blog_posts' => false]]],
    [$pendingHistorical => true]
);
migrations_assert($absentPlan['baseline'] === [], 'fully absent schema must not be baselined');
migrations_assert($absentPlan['apply'] === [$pendingHistorical], 'fully absent additive schema may be applied after backup');
migrations_assert($absentPlan['blocked'] === [], 'verified additive absence must be executable');
migrations_assert(
    $absentPlan['actions'] === [['migration' => $pendingHistorical, 'action' => 'apply']],
    'additive apply action must be explicit'
);

$unsafeAbsentPlan = brvtalMigrationBuildReconciliationPlan(
    $planStatus(
        [$pending('migration_schema_migrations_01.sql'), $pending($pendingHistorical)],
        false
    ),
    [$pendingHistorical => ['complete' => false, 'checks' => ['table:blog_posts' => false]]],
    [$pendingHistorical => false]
);
migrations_assert(
    $unsafeAbsentPlan['blocked'] === [$pendingHistorical],
    'fully absent schema with unsafe SQL must remain blocked before backup/write'
);

$partialPlan = brvtalMigrationBuildReconciliationPlan(
    $planStatus(
        [$pending('migration_schema_migrations_01.sql'), $pending($pendingHistorical)],
        false
    ),
    [$pendingHistorical => [
        'complete' => false,
        'checks' => ['table:blog_posts' => true, 'index:blog_posts.idx_blog_posts_public' => false],
    ]],
    [$pendingHistorical => true]
);
migrations_assert(
    $partialPlan['blocked'] === [$pendingHistorical],
    'partially represented schema must remain blocked even when SQL is additive'
);
$expectReconciliationFailure(
    $planStatus(
        [$applied('migration_schema_migrations_01.sql')],
        true,
        [['migration' => 'migration_orphan_01.sql']]
    ),
    [],
    'MIGRATION_RECONCILIATION_ORPHAN',
    'orphan registry records must abort reconciliation'
);
$expectReconciliationFailure(
    $planStatus([
        $applied('migration_schema_migrations_01.sql'),
        ['migration' => $pendingHistorical, 'state' => 'checksum_mismatch'],
    ]),
    [],
    'MIGRATION_CHECKSUM_MISMATCH',
    'checksum mismatch must abort reconciliation'
);
$expectReconciliationFailure(
    $planStatus([
        $applied('migration_schema_migrations_01.sql'),
        $pending($pendingHistorical),
    ]),
    [],
    'MIGRATION_SCHEMA_PROOF_MISSING:' . $pendingHistorical,
    'pending migration without proof must abort'
);
$expectReconciliationFailure(
    $planStatus([
        $applied('migration_schema_migrations_01.sql'),
        $pending($pendingHistorical),
    ]),
    [$pendingHistorical => ['complete' => true, 'checks' => ['table:blog_posts' => false]]],
    'MIGRATION_SCHEMA_PROOF_INVALID:' . $pendingHistorical,
    'inconsistent proof completeness must abort'
);

$registryAppliedPlan = brvtalMigrationBuildReconciliationPlan(
    [
        'registry_exists' => true,
        'migrations' => [
            $applied('migration_schema_migrations_01.sql'),
            $applied($pendingHistorical),
        ],
        'orphaned_records' => [],
    ],
    []
);
migrations_assert($registryAppliedPlan['record_registry_migration'] === false, 'applied registry migration must not be recorded again');

migrations_assert(str_contains($reconcileLibrary, 'information_schema.TABLES'), 'proofs must use structural metadata');
migrations_assert(str_contains($reconcileLibrary, 'information_schema.COLUMNS'), 'proofs must inspect columns');
migrations_assert(str_contains($reconcileLibrary, 'COLUMN_TYPE'), 'column proofs must verify stable definitions when declared');
migrations_assert(str_contains($reconcileLibrary, 'information_schema.STATISTICS'), 'proofs must inspect indexes');
migrations_assert(str_contains($reconcileLibrary, 'information_schema.TRIGGERS'), 'proofs must inspect triggers');
migrations_assert(str_contains($reconcileLibrary, 'ACTION_STATEMENT'), 'trigger proofs must verify body semantics when declared');
migrations_assert(!str_contains($reconcileLibrary, 'SELECT * FROM'), 'proofs must never inspect application rows');
migrations_assert(
    !str_contains($reconcileLibrary, 'array_all('),
    'migration reconciliation must stay compatible with production PHP 8.2'
);
migrations_assert(
    brvtalMigrationProofIsFullyAbsent(['complete' => false, 'checks' => ['table:x' => false]]) === true,
    'fully absent proof must remain true without PHP 8.4 array_all'
);
migrations_assert(
    brvtalMigrationProofIsFullyAbsent(['complete' => false, 'checks' => ['table:x' => false, 'table:y' => true]]) === false,
    'mixed proof must remain fail-closed'
);

echo "BRVTAL migration-state contract tests passed.\n";
