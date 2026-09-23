<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/media.php';
require_once __DIR__ . '/../config/media_dedup.php';

function media_dedup_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "MEDIA DEDUP CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$temp = tempnam(sys_get_temp_dir(), 'brvtal-media-hash-');
media_dedup_assert(is_string($temp), 'temporary hash fixture must be created');
file_put_contents($temp, 'BRVTAL exact duplicate fixture');
$expected = hash('sha256', 'BRVTAL exact duplicate fixture');
media_dedup_assert(brvtalMediaContentHash($temp) === $expected, 'content hash must derive from bytes, not filename');
@unlink($temp);

$migration = (string)file_get_contents(__DIR__ . '/../database/migration_media_content_hash_01.sql');
media_dedup_assert(str_contains($migration, "COLUMN_NAME='content_hash'"), 'migration must check content_hash idempotently');
media_dedup_assert(str_contains($migration, 'ADD COLUMN content_hash CHAR(64) NULL'), 'migration must add nullable SHA-256 storage');
media_dedup_assert(str_contains($migration, "INDEX_NAME='uq_media_content_hash'"), 'migration must check the canonical unique index');
media_dedup_assert(str_contains($migration, 'ADD UNIQUE KEY uq_media_content_hash (content_hash)'), 'migration must enforce race-safe uniqueness');

$schema = (string)file_get_contents(__DIR__ . '/../database/schema.sql');
media_dedup_assert(str_contains($schema, 'content_hash CHAR(64) NULL'), 'clean schema must include content hash');
media_dedup_assert(str_contains($schema, 'UNIQUE KEY uq_media_content_hash (content_hash)'), 'clean schema must include unique content hash index');

$api = (string)file_get_contents(__DIR__ . '/../api/media-library.php');
media_dedup_assert(str_contains($api, "require_once __DIR__ . '/../config/media_dedup.php';"), 'Media API must load dedup helpers');
media_dedup_assert(str_contains($api, 'MEDIA_DEDUP_MIGRATION_REQUIRED'), 'upload must fail explicitly when migration is missing');
media_dedup_assert(str_contains($api, 'MEDIA_DEDUP_LEGACY_SCAN_LIMIT'), 'bounded legacy scan must fail closed instead of silently duplicating');
media_dedup_assert(str_contains($api, 'brvtalMediaFindDuplicate($pdo, $contentHash, $size, $mime)'), 'upload must dedupe after validation and before moving');
media_dedup_assert(str_contains($api, "'concurrent_race'"), 'unique-index race must resolve to the winning Media row');
media_dedup_assert(str_contains($api, '@unlink($absolute);'), 'losing concurrent upload must remove its moved orphan');
media_dedup_assert(str_contains($api, "'duplicate' => true") && str_contains($api, "'reused' => true"), 'reuse response must be explicit');

$controller = (string)file_get_contents(__DIR__ . '/../discadmin/media-library.js');
media_dedup_assert(str_contains($controller, 'Duplicate detected — existing asset reused.'), 'DISCADMIN must report reuse as a non-error');
media_dedup_assert(str_contains($controller, 'item.content_hash'), 'inspector must expose hash traceability when available');
media_dedup_assert(str_contains($controller, 'DEDUP MIGRATION REQUIRED'), 'Media Library must expose pending migration state');

$package = (string)file_get_contents(__DIR__ . '/../package.json');
media_dedup_assert(str_contains($package, 'tests/integration/media-dedup.php'), 'MariaDB integration suite must exercise exact dedup behavior');

echo "BRVTAL Media dedup contract tests passed.\n";
