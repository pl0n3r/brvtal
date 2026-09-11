<?php
declare(strict_types=1);

function releases_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "RELEASES CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$migration = (string)file_get_contents(__DIR__ . '/../database/migration_releases_01.sql');
releases_assert(str_contains($migration, 'CREATE TABLE IF NOT EXISTS releases'), 'migration must create releases table');
releases_assert(str_contains($migration, 'CREATE TABLE IF NOT EXISTS release_artists'), 'migration must create release_artists table');
releases_assert(str_contains($migration, "ENUM('draft','published','archived')"), 'release lifecycle must include draft/published/archived');
releases_assert(str_contains($migration, 'FOREIGN KEY (artist_id) REFERENCES artists(id)'), 'release artists must link to existing artists');

$api = (string)file_get_contents(__DIR__ . '/../api/releases.php');
releases_assert(str_contains($api, 'brvtal_admin_require();'), 'releases API must require admin authentication');
releases_assert(str_contains($api, 'brvtal_admin_require_csrf();'), 'releases mutations must require CSRF');
releases_assert(str_contains($api, 'RELEASES_SCHEMA_MISSING'), 'releases API must fail explicitly when migration is missing');
releases_assert(str_contains($api, 'brvtal_release_sync_artists'), 'releases API must persist artist relationships');
foreach (['spotify_url','soundcloud_url','bandcamp_url','youtube_url','beatport_url'] as $platformField) {
    releases_assert(str_contains($api, $platformField), "releases API must support {$platformField}");
}

$fragment = (string)file_get_contents(__DIR__ . '/../discadmin/releases.php');
releases_assert(str_contains($fragment, 'data-admin-module="releases"'), 'releases must render as a canonical shell module');
releases_assert(str_contains($fragment, 'X_BRVTAL_ADMIN_FRAGMENT'), 'direct releases fragment requests must redirect to DISCADMIN shell');

$controller = (string)file_get_contents(__DIR__ . '/../discadmin/releases.js');
releases_assert(str_contains($controller, "'/api/releases.php'"), 'releases UI must use protected releases API');
releases_assert(str_contains($controller, 'BRVTALMediaLibrary?.openPicker'), 'artwork must use reusable Media Library picker');
releases_assert(str_contains($controller, 'data-release-artist'), 'release editor must support Artist relationships');
releases_assert(str_contains($controller, "method:id ? 'PUT' : 'POST'"), 'release editor must create and update records');

$adminModules = (string)file_get_contents(__DIR__ . '/../discadmin/admin-modules.js');
releases_assert(str_contains($adminModules, "releases: {url:'/discadmin/releases.php'"), 'canonical shell loader must register releases module');
releases_assert(str_contains($adminModules, "section==='releases'"), 'canonical navigation must route releases through module workspace');
releases_assert(str_contains($adminModules, 'data-admin-nav'), 'releases navigation must be injected into the existing canonical sidebar');

$publicApi = (string)file_get_contents(__DIR__ . '/../api/public.php');
releases_assert(str_contains($publicApi, 'brvtal_public_releases'), 'public API must expose published releases');
releases_assert(str_contains($publicApi, "'releases' => " . '$releases'), 'public payload must contain releases');
releases_assert(str_contains($publicApi, "WHERE status='published'"), 'public releases must be publication-filtered');
releases_assert(str_contains($publicApi, 'brvtal_public_table_exists'), 'public API must stay backward-compatible before migration');

echo "BRVTAL Releases contract tests passed.\n";
