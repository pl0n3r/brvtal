<?php
declare(strict_types=1);

/**
 * Read-only structural proof + controlled historical baseline planning.
 *
 * Proofs intentionally inspect information_schema only. They never read
 * application rows and they fail closed when a pending migration has no
 * explicit proof specification.
 */

function brvtalMigrationObjectRequirements(
    string $table,
    array $columns = [],
    array $indexes = [],
    bool $requireTable = false
): array {
    $requirements = $requireTable ? ['table:' . $table] : [];
    foreach ($columns as $column) {
        $requirements[] = 'column:' . $table . '.' . $column;
    }
    foreach ($indexes as $index) {
        $requirements[] = 'index:' . $table . '.' . $index;
    }
    return $requirements;
}

function brvtalMigrationTriggerRequirements(array $triggers): array
{
    $requirements = [];
    foreach ($triggers as $name => $bodyNeedle) {
        $requirements[] = 'trigger:' . $name;
        $requirements[] = 'trigger-body:' . $name . '=' . $bodyNeedle;
    }
    return $requirements;
}

function brvtalMigrationProofSpecifications(): array
{
    $releaseColumns = [
        'release_type', 'catalog_number', 'release_date', 'description',
        'seo_title', 'seo_description', 'artwork', 'spotify_url',
        'soundcloud_url', 'bandcamp_url', 'youtube_url', 'beatport_url',
        'status', 'featured', 'sort_order', 'published_at', 'created_at',
        'updated_at',
    ];
    $releaseColumnTypes = [
        "column-type:releases.release_type=enum('single','ep','album','compilation','other')",
        'column-type:releases.catalog_number=varchar(80)',
        'column-type:releases.release_date=date',
        'column-type:releases.description=text',
        'column-type:releases.artwork=varchar(500)',
        'column-type:releases.spotify_url=varchar(700)',
        'column-type:releases.soundcloud_url=varchar(700)',
        'column-type:releases.bandcamp_url=varchar(700)',
        'column-type:releases.youtube_url=varchar(700)',
        'column-type:releases.beatport_url=varchar(700)',
        "column-type:releases.status=enum('draft','published','archived')",
        'column-type:releases.published_at=datetime',
        'column-type:releases.created_at=datetime',
        'column-type:releases.updated_at=datetime',
        'column-type:release_artists.role=varchar(80)',
    ];
    $releaseIndexes = [
        'uq_releases_slug', 'idx_releases_public', 'idx_releases_catalog',
    ];
    $mediaGuardTriggers = [
        'brvtal_events_media_guard_bi' => 'new.cover_image',
        'brvtal_events_media_guard_bu' => 'new.ticket_qr',
        'brvtal_artists_media_guard_bi' => 'new.photo',
        'brvtal_artists_media_guard_bu' => 'new.photo',
        'brvtal_sets_media_guard_bi' => 'new.cover_image',
        'brvtal_sets_media_guard_bu' => 'new.cover_image',
        'brvtal_ticket_types_media_guard_bi' => 'new.qr_image',
        'brvtal_ticket_types_media_guard_bu' => 'new.qr_image',
        'brvtal_releases_media_guard_bi' => 'new.artwork',
        'brvtal_releases_media_guard_bu' => 'new.artwork',
        'brvtal_blog_media_guard_bi' => 'new.cover_image',
        'brvtal_blog_media_guard_bu' => 'new.cover_image',
        'brvtal_pages_media_guard_bi' => 'new.content_json',
        'brvtal_pages_media_guard_bu' => 'new.content_json',
        'brvtal_settings_media_guard_bi' => 'new.setting_value',
        'brvtal_settings_media_guard_bu' => 'new.setting_value',
        'brvtal_media_delete_guard_lock' => 'media_reference_mutex',
        'brvtal_media_delete_guard_recheck' => 'old.file_path',
        'brvtal_media_delete_guard_tombstone' => 'media_deleted_paths',
    ];

    return [
        'migration_admin_activity_01.sql' => brvtalMigrationObjectRequirements(
            'admin_activity_log',
            ['id', 'admin_id', 'action', 'resource', 'created_at'],
            ['idx_admin_activity_created', 'idx_admin_activity_resource', 'idx_admin_activity_admin'],
            true
        ),
        'migration_admin_password_security_01.sql' => array_merge(
            brvtalMigrationObjectRequirements('admins', ['credential_epoch']),
            brvtalMigrationObjectRequirements(
                'admin_password_reset_tokens',
                ['admin_id', 'token_hash', 'expires_at', 'consumed_at', 'revoked_at', 'created_at'],
                ['uq_admin_password_reset_hash', 'idx_admin_password_reset_admin_active'],
                true
            )
        ),
        'migration_artist_collective_membership_01.sql' => brvtalMigrationObjectRequirements(
            'artists',
            ['is_collective_member'],
            ['idx_artists_collective_member']
        ),
        'migration_blog_01.sql' => array_merge(
            brvtalMigrationObjectRequirements(
                'blog_posts',
                [],
                ['uq_blog_posts_slug', 'idx_blog_posts_public', 'idx_blog_posts_featured'],
                true
            ),
            brvtalMigrationObjectRequirements('blog_tags', [], ['uq_blog_tags_slug'], true),
            brvtalMigrationObjectRequirements('blog_post_tags', [], ['idx_blog_post_tags_tag'], true),
            brvtalMigrationObjectRequirements(
                'blog_post_relations',
                [],
                ['idx_blog_post_relations_target'],
                true
            )
        ),
        'migration_content_core_01.sql' => array_merge(
            brvtalMigrationObjectRequirements(
                'events',
                [
                    'published_at', 'cancelled_at', 'finished_at', 'featured',
                    'ticket_instructions', 'ticket_qr', 'archive_year',
                ],
                ['idx_events_city_date']
            ),
            brvtalMigrationObjectRequirements(
                'artists',
                ['collective_status', 'collective_order', 'collective_joined_at', 'collective_left_at'],
                ['idx_artists_collective']
            ),
            ['table:event_ticket_types', 'table:artist_collective_history']
        ),
        'migration_media_content_hash_01.sql' => brvtalMigrationObjectRequirements(
            'media',
            ['content_hash'],
            ['uq_media_content_hash']
        ),
        'migration_memories_01.sql' => brvtalMigrationObjectRequirements(
            'memories',
            ['media_id', 'status'],
            ['uniq_memories_media', 'idx_memories_public'],
            true
        ),
        'migration_memory_relations_01.sql' => brvtalMigrationObjectRequirements(
            'memory_relations',
            ['related_type', 'related_id'],
            ['idx_memory_relations_target'],
            true
        ),
        'migration_releases_01.sql' => array_merge(
            brvtalMigrationObjectRequirements(
                'releases',
                ['slug', 'status'],
                $releaseIndexes,
                true
            ),
            brvtalMigrationObjectRequirements(
                'release_artists',
                ['artist_id'],
                ['idx_release_artists_artist'],
                true
            )
        ),
        'migration_releases_02.sql' => array_merge(
            brvtalMigrationObjectRequirements('releases', $releaseColumns, $releaseIndexes),
            brvtalMigrationObjectRequirements(
                'release_artists',
                ['role', 'sort_order'],
                ['idx_release_artists_artist']
            ),
            $releaseColumnTypes
        ),
        'migration_seo_01.sql' => array_merge(
            brvtalMigrationObjectRequirements('events', ['seo_title', 'seo_description']),
            brvtalMigrationObjectRequirements('artists', ['seo_title', 'seo_description']),
            brvtalMigrationObjectRequirements('sets_media', ['seo_title', 'seo_description']),
            brvtalMigrationObjectRequirements('releases', ['seo_title', 'seo_description'])
        ),
        'migration_totp_foundation.sql' => array_merge(
            brvtalMigrationObjectRequirements(
                'admins',
                ['totp_enabled', 'totp_secret_enc', 'totp_confirmed_at']
            ),
            brvtalMigrationObjectRequirements(
                'admin_recovery_codes',
                [],
                ['uq_admin_recovery_code', 'idx_admin_recovery_admin'],
                true
            )
        ),
        'migration_zz_media_reference_guard_01.sql' => array_merge(
            ['table:media_reference_mutex', 'table:media_deleted_paths'],
            brvtalMigrationTriggerRequirements($mediaGuardTriggers)
        ),
    ];
}

function brvtalMigrationProbeRequirement(PDO $pdo, string $requirement): bool
{
    if (preg_match("/^column-type:([A-Za-z0-9_]+)\\.([A-Za-z0-9_]+)=([A-Za-z0-9_(),'.-]+)$/", $requirement, $match)) {
        $statement = $pdo->prepare(
            'SELECT LOWER(COLUMN_TYPE) FROM information_schema.COLUMNS ' .
            'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?'
        );
        $statement->execute([$match[1], $match[2]]);
        $observed = $statement->fetchColumn();
        return is_string($observed) && strtolower($observed) === strtolower($match[3]);
    }

    if (preg_match('/^trigger-body:([A-Za-z0-9_]+)=([A-Za-z0-9_.]+)$/', $requirement, $match)) {
        $statement = $pdo->prepare(
            'SELECT LOWER(ACTION_STATEMENT) FROM information_schema.TRIGGERS ' .
            'WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?'
        );
        $statement->execute([$match[1]]);
        $observed = $statement->fetchColumn();
        if (!is_string($observed)) {
            return false;
        }
        $normalized = preg_replace('/\\s+/', ' ', strtolower($observed));
        return is_string($normalized) && str_contains($normalized, strtolower($match[2]));
    }

    if (!preg_match('/^(table|column|index|trigger):([A-Za-z0-9_]+)(?:\\.([A-Za-z0-9_]+))?$/', $requirement, $match)) {
        throw new InvalidArgumentException('MIGRATION_SCHEMA_PROOF_INVALID_REQUIREMENT');
    }
    $kind = $match[1];
    $table = $match[2];
    $name = $match[3] ?? '';

    if ($kind === 'table') {
        $statement = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES " .
            "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND TABLE_TYPE='BASE TABLE'"
        );
        $statement->execute([$table]);
        return (int)$statement->fetchColumn() === 1;
    }
    if ($kind === 'column') {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS ' .
            'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?'
        );
        $statement->execute([$table, $name]);
        return (int)$statement->fetchColumn() === 1;
    }
    if ($kind === 'index') {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS ' .
            'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?'
        );
        $statement->execute([$table, $name]);
        return (int)$statement->fetchColumn() > 0;
    }

    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TRIGGERS ' .
        'WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?'
    );
    $statement->execute([$table]);
    return (int)$statement->fetchColumn() === 1;
}

function brvtalMigrationEvaluateRequirements(array $requirements, callable $probe): array
{
    if ($requirements === [] || count($requirements) > 200) {
        throw new InvalidArgumentException('MIGRATION_SCHEMA_PROOF_REQUIREMENTS_INVALID');
    }
    $checks = [];
    foreach ($requirements as $requirement) {
        if (!is_string($requirement) || strlen($requirement) > 180) {
            throw new InvalidArgumentException('MIGRATION_SCHEMA_PROOF_REQUIREMENT_INVALID');
        }
        $checks[$requirement] = (bool)$probe($requirement);
    }
    return [
        'complete' => !in_array(false, $checks, true),
        'checks' => $checks,
    ];
}

function brvtalMigrationSchemaProof(PDO $pdo, string $migrationName): array
{
    $specifications = brvtalMigrationProofSpecifications();
    if (!isset($specifications[$migrationName])) {
        throw new RuntimeException('MIGRATION_SCHEMA_PROOF_MISSING:' . $migrationName);
    }
    return brvtalMigrationEvaluateRequirements(
        $specifications[$migrationName],
        static fn(string $requirement): bool => brvtalMigrationProbeRequirement($pdo, $requirement)
    );
}

function brvtalMigrationBuildReconciliationPlan(array $status, array $proofs): array
{
    $rows = $status['migrations'] ?? null;
    if (!is_array($rows) || !is_array($status['orphaned_records'] ?? null)) {
        throw new RuntimeException('MIGRATION_RECONCILIATION_DRIFT');
    }
    if ($status['orphaned_records'] !== []) {
        throw new RuntimeException('MIGRATION_RECONCILIATION_ORPHAN');
    }

    $registryExists = ($status['registry_exists'] ?? false) === true;
    $registryState = null;
    $baseline = [];
    $pending = [];
    $usedProofs = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            throw new RuntimeException('MIGRATION_RECONCILIATION_DRIFT');
        }
        $name = (string)($row['migration'] ?? '');
        $state = (string)($row['state'] ?? '');
        if (!preg_match('/^migration_[a-z0-9_]+\.sql$/', $name)) {
            throw new RuntimeException('MIGRATION_RECONCILIATION_DRIFT');
        }
        if ($state === 'checksum_mismatch') {
            throw new RuntimeException('MIGRATION_CHECKSUM_MISMATCH');
        }
        if ($name === 'migration_schema_migrations_01.sql') {
            $registryState = $state;
            if ($state !== 'applied' && $state !== 'pending') {
                throw new RuntimeException('MIGRATION_RECONCILIATION_DRIFT');
            }
            if ($state === 'pending') {
                $pending[] = $name;
            }
            continue;
        }
        if ($state === 'applied') {
            continue;
        }
        if ($state !== 'pending') {
            throw new RuntimeException('MIGRATION_RECONCILIATION_DRIFT');
        }

        $pending[] = $name;
        $proof = $proofs[$name] ?? null;
        if (!is_array($proof) || ($proof['complete'] ?? null) !== true || !is_array($proof['checks'] ?? null)) {
            throw new RuntimeException('MIGRATION_SCHEMA_PROOF_FAILED:' . $name);
        }
        if (in_array(false, $proof['checks'], true)) {
            throw new RuntimeException('MIGRATION_SCHEMA_PROOF_FAILED:' . $name);
        }
        $usedProofs[$name] = $proof;
        $baseline[] = $name;
    }

    if ($registryState === null) {
        throw new RuntimeException('MIGRATION_REGISTRY_FILE_MISSING');
    }
    if (!$registryExists && $registryState !== 'pending') {
        throw new RuntimeException('MIGRATION_RECONCILIATION_DRIFT');
    }

    return [
        'registry_exists' => $registryExists,
        'record_registry_migration' => $registryState !== 'applied',
        'pending' => $pending,
        'baseline' => $baseline,
        'proofs' => $usedProofs,
    ];
}

function brvtalMigrationReconciliationPlan(PDO $pdo, string $directory): array
{
    $status = brvtal_migration_status($pdo, $directory);
    $proofs = [];
    foreach ($status['migrations'] ?? [] as $row) {
        if (!is_array($row) || ($row['state'] ?? '') !== 'pending') {
            continue;
        }
        $name = (string)($row['migration'] ?? '');
        if ($name === 'migration_schema_migrations_01.sql') {
            continue;
        }
        $proofs[$name] = brvtalMigrationSchemaProof($pdo, $name);
    }
    return brvtalMigrationBuildReconciliationPlan($status, $proofs);
}

function brvtalMigrationReconcileHistorical(
    PDO $pdo,
    string $directory,
    ?string $actor,
    ?string $deploySha
): array {
    $plan = brvtalMigrationReconciliationPlan($pdo, $directory);
    $files = brvtal_migrations_discover($directory);

    if ($plan['record_registry_migration']) {
        $registry = $files['migration_schema_migrations_01.sql'] ?? null;
        if (!is_string($registry)) {
            throw new RuntimeException('MIGRATION_REGISTRY_FILE_MISSING');
        }
        brvtal_migration_apply_file($pdo, $registry, $actor, $deploySha);
    }

    foreach ($plan['baseline'] as $name) {
        $path = $files[$name] ?? null;
        if (!is_string($path)) {
            throw new RuntimeException('MIGRATION_RECONCILIATION_DRIFT');
        }
        brvtal_migration_baseline_file($pdo, $path, $actor, $deploySha);
    }

    $final = brvtal_migration_status($pdo, $directory);
    brvtalMigrationVerifyPlanStatus($final, '__NONE__');
    return [
        'registry_recorded' => (bool)$plan['record_registry_migration'],
        'baselined' => $plan['baseline'],
        'verified' => true,
    ];
}
