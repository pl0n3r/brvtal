<?php
declare(strict_types=1);

/**
 * Read-only structural proof + controlled historical baseline planning.
 *
 * Proofs intentionally inspect information_schema only. They never read
 * application rows and they fail closed when a pending migration has no
 * explicit proof specification.
 */

function brvtalMigrationProofSpecifications(): array
{
    return [
        'migration_admin_activity_01.sql' => [
            'table:admin_activity_log',
            'column:admin_activity_log.id',
            'column:admin_activity_log.admin_id',
            'column:admin_activity_log.action',
            'column:admin_activity_log.resource',
            'column:admin_activity_log.created_at',
            'index:admin_activity_log.idx_admin_activity_created',
            'index:admin_activity_log.idx_admin_activity_resource',
            'index:admin_activity_log.idx_admin_activity_admin',
        ],
        'migration_artist_collective_membership_01.sql' => [
            'column:artists.is_collective_member',
            'index:artists.idx_artists_collective_member',
        ],
        'migration_blog_01.sql' => [
            'table:blog_posts',
            'table:blog_tags',
            'table:blog_post_tags',
            'table:blog_post_relations',
            'index:blog_posts.uq_blog_posts_slug',
            'index:blog_posts.idx_blog_posts_public',
            'index:blog_posts.idx_blog_posts_featured',
            'index:blog_tags.uq_blog_tags_slug',
            'index:blog_post_tags.idx_blog_post_tags_tag',
            'index:blog_post_relations.idx_blog_post_relations_target',
        ],
        'migration_content_core_01.sql' => [
            'column:events.published_at',
            'column:events.cancelled_at',
            'column:events.finished_at',
            'column:events.featured',
            'column:events.ticket_instructions',
            'column:events.ticket_qr',
            'column:events.archive_year',
            'table:event_ticket_types',
            'column:artists.collective_status',
            'column:artists.collective_order',
            'column:artists.collective_joined_at',
            'column:artists.collective_left_at',
            'table:artist_collective_history',
            'index:events.idx_events_city_date',
            'index:artists.idx_artists_collective',
        ],
        'migration_media_content_hash_01.sql' => [
            'column:media.content_hash',
            'index:media.uq_media_content_hash',
        ],
        'migration_memories_01.sql' => [
            'table:memories',
            'column:memories.media_id',
            'column:memories.status',
            'index:memories.uniq_memories_media',
            'index:memories.idx_memories_public',
        ],
        'migration_memory_relations_01.sql' => [
            'table:memory_relations',
            'column:memory_relations.related_type',
            'column:memory_relations.related_id',
            'index:memory_relations.idx_memory_relations_target',
        ],
        'migration_releases_01.sql' => [
            'table:releases',
            'table:release_artists',
            'column:releases.slug',
            'column:releases.status',
            'column:release_artists.artist_id',
            'index:releases.uq_releases_slug',
            'index:releases.idx_releases_public',
            'index:releases.idx_releases_catalog',
            'index:release_artists.idx_release_artists_artist',
        ],
        'migration_releases_02.sql' => [
            'column:releases.release_type',
            'column:releases.catalog_number',
            'column:releases.release_date',
            'column:releases.description',
            'column:releases.seo_title',
            'column:releases.seo_description',
            'column:releases.artwork',
            'column:releases.spotify_url',
            'column:releases.soundcloud_url',
            'column:releases.bandcamp_url',
            'column:releases.youtube_url',
            'column:releases.beatport_url',
            'column:releases.status',
            'column:releases.featured',
            'column:releases.sort_order',
            'column:releases.published_at',
            'column:releases.created_at',
            'column:releases.updated_at',
            'column:release_artists.role',
            'column:release_artists.sort_order',
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
            'index:releases.uq_releases_slug',
            'index:releases.idx_releases_public',
            'index:releases.idx_releases_catalog',
            'index:release_artists.idx_release_artists_artist',
        ],
        'migration_seo_01.sql' => [
            'column:events.seo_title',
            'column:events.seo_description',
            'column:artists.seo_title',
            'column:artists.seo_description',
            'column:sets_media.seo_title',
            'column:sets_media.seo_description',
            'column:releases.seo_title',
            'column:releases.seo_description',
        ],
        'migration_totp_foundation.sql' => [
            'column:admins.totp_enabled',
            'column:admins.totp_secret_enc',
            'column:admins.totp_confirmed_at',
            'table:admin_recovery_codes',
            'index:admin_recovery_codes.uq_admin_recovery_code',
            'index:admin_recovery_codes.idx_admin_recovery_admin',
        ],
        'migration_zz_media_reference_guard_01.sql' => [
            'table:media_reference_mutex',
            'table:media_deleted_paths',
            'trigger:brvtal_events_media_guard_bi',
            'trigger-body:brvtal_events_media_guard_bi=new.cover_image',
            'trigger:brvtal_events_media_guard_bu',
            'trigger-body:brvtal_events_media_guard_bu=new.ticket_qr',
            'trigger:brvtal_artists_media_guard_bi',
            'trigger-body:brvtal_artists_media_guard_bi=new.photo',
            'trigger:brvtal_artists_media_guard_bu',
            'trigger-body:brvtal_artists_media_guard_bu=new.photo',
            'trigger:brvtal_sets_media_guard_bi',
            'trigger-body:brvtal_sets_media_guard_bi=new.cover_image',
            'trigger:brvtal_sets_media_guard_bu',
            'trigger-body:brvtal_sets_media_guard_bu=new.cover_image',
            'trigger:brvtal_ticket_types_media_guard_bi',
            'trigger-body:brvtal_ticket_types_media_guard_bi=new.qr_image',
            'trigger:brvtal_ticket_types_media_guard_bu',
            'trigger-body:brvtal_ticket_types_media_guard_bu=new.qr_image',
            'trigger:brvtal_releases_media_guard_bi',
            'trigger-body:brvtal_releases_media_guard_bi=new.artwork',
            'trigger:brvtal_releases_media_guard_bu',
            'trigger-body:brvtal_releases_media_guard_bu=new.artwork',
            'trigger:brvtal_blog_media_guard_bi',
            'trigger-body:brvtal_blog_media_guard_bi=new.cover_image',
            'trigger:brvtal_blog_media_guard_bu',
            'trigger-body:brvtal_blog_media_guard_bu=new.cover_image',
            'trigger:brvtal_pages_media_guard_bi',
            'trigger-body:brvtal_pages_media_guard_bi=new.content_json',
            'trigger:brvtal_pages_media_guard_bu',
            'trigger-body:brvtal_pages_media_guard_bu=new.content_json',
            'trigger:brvtal_settings_media_guard_bi',
            'trigger-body:brvtal_settings_media_guard_bi=new.setting_value',
            'trigger:brvtal_settings_media_guard_bu',
            'trigger-body:brvtal_settings_media_guard_bu=new.setting_value',
            'trigger:brvtal_media_delete_guard_lock',
            'trigger-body:brvtal_media_delete_guard_lock=media_reference_mutex',
            'trigger:brvtal_media_delete_guard_recheck',
            'trigger-body:brvtal_media_delete_guard_recheck=old.file_path',
            'trigger:brvtal_media_delete_guard_tombstone',
            'trigger-body:brvtal_media_delete_guard_tombstone=media_deleted_paths',
        ],
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
