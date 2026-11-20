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

    $specifications = [
        'migration_events_venue_address_01.sql' => [
            'column:events.venue_address',
            'column-type:events.venue_address=varchar(300)',
        ],
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
            ),
            brvtalMigrationObjectRequirements(
                'admin_password_mail_outbox',
                ['admin_id', 'kind', 'available_at', 'claimed_at', 'delivered_at', 'attempts', 'last_error_code'],
                ['uq_admin_password_mail_kind', 'idx_admin_password_mail_ready'],
                true
            )
        ),
        'migration_admin_staff_ops_01.sql' => [
            'column:admins.staff_role',
            'column-type:admins.staff_role=varchar(32)',
            'column:admins.staff_invitation_state',
            'column-type:admins.staff_invitation_state=varchar(16)',
        ],
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
        'migration_blog_trash_01.sql' => brvtalMigrationObjectRequirements(
            'blog_posts',
            ['deleted_at', 'deleted_by_admin_id'],
            ['idx_blog_posts_trash']
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
        'migration_ticketing_webhook_01.sql' => array_merge(
            brvtalMigrationObjectRequirements('ticket_payment_webhook_events',
                ['attempt_id','provider_transaction_id','event_checksum',
                 'signed_status','event_timestamp','received_at_utc'],
                ['uq_ticket_payment_webhook_event','idx_ticket_payment_webhook_transaction'], true),
            brvtalMigrationObjectRequirements('ticket_issue_outbox',
                ['order_id','payment_attempt_id','state','attempts',
                 'next_attempt_at_utc','created_at_utc','updated_at_utc'],
                ['uq_ticket_issue_outbox_order','idx_ticket_issue_outbox_due'], true)
        ),
        'migration_ticketing_reservations_01.sql' => array_merge(
            brvtalMigrationObjectRequirements('ticket_inventory_events',
                ['event_id','capacity'], ['PRIMARY'], true),
            brvtalMigrationObjectRequirements('ticket_inventory_types',
                ['ticket_type_id','event_id','capacity','courtesy_capacity'],
                ['PRIMARY','idx_inventory_type_event'], true),
            brvtalMigrationObjectRequirements('ticket_inventory_holds',
                ['id','order_id','event_id','ticket_type_id','quantity','kind','status',
                 'expires_at_utc','created_at_utc'],
                ['PRIMARY','uq_inventory_hold_order_type','idx_inventory_event_expiry',
                 'idx_inventory_type_expiry'], true)
        ),
        'migration_ticketing_admin_notes_01.sql' => brvtalMigrationObjectRequirements(
            'ticket_admin_actions',
            ['order_id','actor_admin_id','action','request_key_hash','request_hash',
             'note_text','status','created_at_utc','retention_until_utc'],
            ['uq_ticket_admin_request','idx_ticket_admin_order','idx_ticket_admin_retention'],
            true
        ),
        'migration_ticketing_mail_history_01.sql' => array_merge(
            brvtalMigrationObjectRequirements(
                'ticket_mail_history',
                ['outbox_id','message_key','template_key','recipient_hint','recipient_digest',
                 'event_id','buyer_id','order_id','ticket_id','actor_kind','resend_email_id',
                 'delivery_state','state_at','retention_until','redacted_at','created_at','updated_at'],
                ['uq_mail_history_outbox','uq_mail_history_key','uq_mail_history_provider',
                 'idx_mail_history_event','idx_mail_history_buyer','idx_mail_history_order',
                 'idx_mail_history_ticket','idx_mail_history_retention'], true
            ),
            brvtalMigrationObjectRequirements(
                'ticket_mail_provider_events',
                ['webhook_id','resend_email_id','delivery_state','occurred_at','payload_sha256','received_at'],
                ['uq_mail_provider_webhook','idx_mail_provider_email'], true
            )
        ),
        'migration_ticketing_mail_outbox_01.sql' => brvtalMigrationObjectRequirements(
            'ticket_mail_outbox',
            [
                'message_key', 'payload_hash', 'related_ref', 'recipient_email',
                'subject', 'body_text', 'state', 'attempts', 'first_attempt_at',
                'next_attempt_at', 'locked_until', 'provider_message_id',
                'last_error_code', 'accepted_at', 'created_at', 'updated_at',
            ],
            ['uq_ticket_mail_message_key', 'idx_ticket_mail_ready', 'idx_ticket_mail_relation'],
            true
        ),
        'migration_ticketing_entries_01.sql' => array_merge(
            brvtalMigrationObjectRequirements('ticket_entries',
                ['order_item_id','event_id','ticket_type_id','unit_number','issued_at_utc'],
                ['uq_ticket_entries_public_id','uq_ticket_entries_order_unit','uq_ticket_entries_id_event'], true),
            brvtalMigrationObjectRequirements('ticket_entry_credentials',
                ['entry_id','version','token_hash','active_entry_id','revoked_at_utc'],
                ['uq_ticket_credential_hash','uq_ticket_credential_entry_version','uq_ticket_credential_one_active'], true),
            brvtalMigrationObjectRequirements('ticket_checkins',
                ['request_id','event_id','entry_id','credential_version','operator_admin_id','device_id','outcome'],
                ['uq_ticket_checkin_request','idx_ticket_checkins_entry_time'], true)
        ),
        'migration_event_timetable_01.sql' => brvtalMigrationObjectRequirements(
            'event_timetable_items',
            [
                'event_id', 'artist_id', 'label', 'starts_at_utc',
                'ends_at_utc', 'timezone', 'status', 'sort_order',
                'created_at', 'updated_at',
            ],
            [
                'idx_event_timetable_event_time',
                'idx_event_timetable_event_status',
                'idx_event_timetable_artist',
            ],
            true
        ),
        'migration_set_artists_01.sql' => brvtalMigrationObjectRequirements(
            'set_artists',
            ['set_id', 'artist_id', 'sort_order'],
            ['PRIMARY', 'idx_set_artists_artist'],
            true
        ),
        'migration_set_duration_01.sql' => [
            'column:sets_media.duration_seconds',
            'column-unsigned-nullable:sets_media.duration_seconds',
        ],
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
        'migration_memories_youtube_01.sql' => [
            'column:memories.youtube_id',
            'column-type:memories.youtube_id=char(11)',
        ],
        'migration_memory_relations_01.sql' => brvtalMigrationObjectRequirements(
            'memory_relations',
            ['related_type', 'related_id'],
            ['idx_memory_relations_target'],
            true
        ),
        'migration_public_translation_cache_01.sql' => brvtalMigrationObjectRequirements(
            'public_translation_cache',
            [
                'source_hash', 'source_locale', 'target_locale',
                'translator_version', 'translated_text', 'created_at', 'updated_at',
            ],
            ['uq_public_translation_cache_identity', 'idx_public_translation_cache_target'],
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
    // T03 is a separate PR. Advertise its proof only once the migration file
    // is in this exact checkout; the strict disk-vs-spec contract remains intact.
    if (is_file(__DIR__ . '/../database/migration_ticketing_orders_01.sql')) {
        $specifications['migration_ticketing_orders_01.sql'] = array_merge(
            brvtalMigrationObjectRequirements('ticket_buyers',
                ['email','password_hash','email_verified_at','consent_version','consent_recorded_at','security_epoch'],
                ['uq_ticket_buyers_public','uq_ticket_buyers_email'], true),
            brvtalMigrationObjectRequirements('ticket_buyer_tokens',
                ['token_hash','expires_at_utc','consumed_at_utc','revoked_at_utc'], ['uq_ticket_buyer_token_hash'], true),
            brvtalMigrationObjectRequirements('ticket_buyer_sessions',
                ['session_hash','revoked_at_utc'], ['uq_ticket_buyer_session_hash'], true),
            brvtalMigrationObjectRequirements('ticket_orders',
                ['buyer_id','event_id','checkout_key_hash','total_cop'], ['uq_ticket_orders_checkout','uq_ticket_orders_public'], true),
            brvtalMigrationObjectRequirements('ticket_order_items',
                ['order_id','ticket_type_id','quantity','unit_price_cop','subtotal_cop'], ['uq_ticket_order_item_type'], true),
            brvtalMigrationObjectRequirements('ticket_payment_attempts',
                ['order_id','provider','provider_transaction_id','gross_amount_cop'],
                ['uq_ticket_payment_transaction','uq_ticket_payment_reference','uq_ticket_payment_idempotency'], true)
        );
    }
    return $specifications;
}

/** Strict cross-MySQL/MariaDB Set duration proof (INT display width varies). */
function brvtalMigrationDurationColumnMatches(mixed $row): bool
{
    return is_array($row)
        && strtolower((string)($row['DATA_TYPE'] ?? '')) === 'int'
        && preg_match('/\\bunsigned\\b/i', (string)($row['COLUMN_TYPE'] ?? '')) === 1
        && strtoupper((string)($row['IS_NULLABLE'] ?? '')) === 'YES';
}

function brvtalMigrationProbeRequirement(PDO $pdo, string $requirement): bool
{
    if (preg_match('/^column-unsigned-nullable:([A-Za-z0-9_]+)\\.([A-Za-z0-9_]+)$/', $requirement, $match)) {
        $statement = $pdo->prepare(
            'SELECT DATA_TYPE, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?'
        );
        $statement->execute([$match[1], $match[2]]);
        return brvtalMigrationDurationColumnMatches($statement->fetch(PDO::FETCH_ASSOC));
    }
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

function brvtalMigrationProofIsFullyAbsent(array $proof): bool
{
    if (($proof['complete'] ?? null) !== false) {
        return false;
    }
    $checks = $proof['checks'] ?? null;
    if (!is_array($checks) || $checks === []) {
        return false;
    }
    foreach ($checks as $present) {
        if ($present !== false) {
            return false;
        }
    }
    return true;
}

function brvtalMigrationAssertReconciliationSql(
    string $migrationName,
    string $sql,
    array $proof
): void {
    $mediaGuard = 'migration_zz_media_reference_guard_01.sql';
    $allowCanonicalBootstrap = $migrationName === $mediaGuard
        && brvtalMigrationProofIsFullyAbsent($proof);
    brvtalMigrationAssertAdditiveSql($sql, $allowCanonicalBootstrap);
}

function brvtalMigrationProofDisposition(string $migrationName, array $proof): string
{
    $complete = $proof['complete'] ?? null;
    $checks = $proof['checks'] ?? null;
    if (!is_bool($complete) || !is_array($checks) || $checks === []) {
        throw new RuntimeException('MIGRATION_SCHEMA_PROOF_INVALID:' . $migrationName);
    }
    foreach ($checks as $present) {
        if (!is_bool($present)) {
            throw new RuntimeException('MIGRATION_SCHEMA_PROOF_INVALID:' . $migrationName);
        }
    }

    $hasTrue = in_array(true, $checks, true);
    $hasFalse = in_array(false, $checks, true);
    $derivedComplete = !$hasFalse;
    if ($complete !== $derivedComplete) {
        throw new RuntimeException('MIGRATION_SCHEMA_PROOF_INVALID:' . $migrationName);
    }
    if ($derivedComplete) {
        return 'baseline';
    }
    if (!$hasTrue) {
        return 'apply';
    }
    return 'blocked';
}

function brvtalMigrationBuildReconciliationPlan(
    array $status,
    array $proofs,
    array $safeApply = []
): array {
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
    $apply = [];
    $blocked = [];
    $actions = [];
    $pending = [];
    $usedProofs = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            throw new RuntimeException('MIGRATION_RECONCILIATION_DRIFT');
        }
        $name = (string)($row['migration'] ?? '');
        $state = (string)($row['state'] ?? '');
        if (!preg_match('/^migration_[a-z0-9_]+\\.sql$/', $name)) {
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
        if (!is_array($proof)) {
            throw new RuntimeException('MIGRATION_SCHEMA_PROOF_MISSING:' . $name);
        }
        $usedProofs[$name] = $proof;
        $disposition = brvtalMigrationProofDisposition($name, $proof);

        if ($disposition === 'baseline') {
            $baseline[] = $name;
            $actions[] = ['migration' => $name, 'action' => 'baseline'];
            continue;
        }
        if ($disposition === 'apply' && ($safeApply[$name] ?? false) === true) {
            $apply[] = $name;
            $actions[] = ['migration' => $name, 'action' => 'apply'];
            continue;
        }
        $blocked[] = $name;
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
        'apply' => $apply,
        'blocked' => $blocked,
        'actions' => $actions,
        'proofs' => $usedProofs,
    ];
}

function brvtalMigrationReconciliationPlan(PDO $pdo, string $directory): array
{
    $status = brvtal_migration_status($pdo, $directory);
    $files = brvtal_migrations_discover($directory);
    $proofs = [];
    $safeApply = [];

    foreach ($status['migrations'] ?? [] as $row) {
        if (!is_array($row) || ($row['state'] ?? '') !== 'pending') {
            continue;
        }
        $name = (string)($row['migration'] ?? '');
        if ($name === 'migration_schema_migrations_01.sql') {
            continue;
        }

        $proof = brvtalMigrationSchemaProof($pdo, $name);
        $proofs[$name] = $proof;
        if (brvtalMigrationProofDisposition($name, $proof) !== 'apply') {
            continue;
        }

        $path = $files[$name] ?? null;
        if (!is_string($path) || !is_file($path)) {
            throw new RuntimeException('MIGRATION_RECONCILIATION_DRIFT');
        }
        $sql = file_get_contents($path);
        if (!is_string($sql)) {
            throw new RuntimeException('MIGRATION_SQL_READ_FAILED:' . $name);
        }
        try {
            brvtalMigrationAssertReconciliationSql($name, $sql, $proof);
            $safeApply[$name] = true;
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== 'MIGRATION_NON_ADDITIVE_SQL') {
                throw $exception;
            }
            $safeApply[$name] = false;
        }
    }

    return brvtalMigrationBuildReconciliationPlan($status, $proofs, $safeApply);
}

/**
 * Keep the authoritative plan in deterministic filename order for inspection,
 * but execute T03 before any uncreated ticketing tables that reference it.
 * Preserves relative order for unrelated migrations and all proof/backup gates.
 */
function brvtalMigrationOrderDependencyActions(array $actions): array
{
    $parent = 'migration_ticketing_orders_01.sql';
    $dependents = [
        'migration_ticketing_entries_01.sql',
        'migration_ticketing_reservations_01.sql',
        'migration_ticketing_webhook_01.sql',
        'migration_ticketing_admin_notes_01.sql',
    ];
    $parentIndex = null;
    $firstDependentIndex = null;
    foreach ($actions as $index => $action) {
        if (!is_array($action) || !is_string($action['migration'] ?? null)) {
            throw new RuntimeException('MIGRATION_RECONCILIATION_DRIFT');
        }
        $name = $action['migration'];
        if ($name === $parent) {
            $parentIndex = $index;
        } elseif ($firstDependentIndex === null && in_array($name, $dependents, true)) {
            $firstDependentIndex = $index;
        }
    }
    if ($parentIndex !== null && $firstDependentIndex !== null
        && $parentIndex > $firstDependentIndex) {
        $parentAction = $actions[$parentIndex];
        array_splice($actions, $parentIndex, 1);
        array_splice($actions, $firstDependentIndex, 0, [$parentAction]);
    }
    return $actions;
}

function brvtalMigrationReconcileHistorical(
    PDO $pdo,
    string $directory,
    ?string $actor,
    ?string $deploySha
): array {
    $plan = brvtalMigrationReconciliationPlan($pdo, $directory);
    if ($plan['blocked'] !== []) {
        throw new RuntimeException(
            'MIGRATION_RECONCILIATION_BLOCKED:' . implode(',', $plan['blocked'])
        );
    }

    $files = brvtal_migrations_discover($directory);
    if ($plan['record_registry_migration']) {
        $registry = $files['migration_schema_migrations_01.sql'] ?? null;
        if (!is_string($registry)) {
            throw new RuntimeException('MIGRATION_REGISTRY_FILE_MISSING');
        }
        brvtal_migration_apply_file($pdo, $registry, $actor, $deploySha);
    }

    $baselined = [];
    $applied = [];
    foreach (brvtalMigrationOrderDependencyActions($plan['actions']) as $action) {
        if (!is_array($action)) {
            throw new RuntimeException('MIGRATION_RECONCILIATION_DRIFT');
        }
        $name = (string)($action['migration'] ?? '');
        $kind = (string)($action['action'] ?? '');
        $path = $files[$name] ?? null;
        if (!is_string($path)) {
            throw new RuntimeException('MIGRATION_RECONCILIATION_DRIFT');
        }

        if ($kind === 'baseline') {
            brvtal_migration_baseline_file($pdo, $path, $actor, $deploySha);
            $baselined[] = $name;
            continue;
        }
        if ($kind !== 'apply') {
            throw new RuntimeException('MIGRATION_RECONCILIATION_DRIFT');
        }

        $sql = file_get_contents($path);
        if (!is_string($sql)) {
            throw new RuntimeException('MIGRATION_SQL_READ_FAILED:' . $name);
        }
        $executionProof = brvtalMigrationSchemaProof($pdo, $name);
        if (brvtalMigrationProofDisposition($name, $executionProof) !== 'apply') {
            throw new RuntimeException('MIGRATION_SCHEMA_PROOF_STALE:' . $name);
        }
        brvtalMigrationAssertReconciliationSql($name, $sql, $executionProof);
        $pdo->exec($sql);
        $postApplyProof = brvtalMigrationSchemaProof($pdo, $name);
        if (brvtalMigrationProofDisposition($name, $postApplyProof) !== 'baseline') {
            throw new RuntimeException('MIGRATION_SCHEMA_PROOF_FAILED_AFTER_APPLY:' . $name);
        }
        brvtal_migration_baseline_file($pdo, $path, $actor, $deploySha);
        $applied[] = $name;
    }

    $final = brvtal_migration_status($pdo, $directory);
    brvtalMigrationVerifyPlanStatus($final, '__NONE__');
    return [
        'registry_recorded' => (bool)$plan['record_registry_migration'],
        'baselined' => $baselined,
        'applied' => $applied,
        'verified' => true,
    ];
}