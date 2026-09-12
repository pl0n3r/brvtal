<?php
declare(strict_types=1);

function seo_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "SEO CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$migration = (string)file_get_contents(__DIR__ . '/../database/migration_seo_01.sql');
foreach (['events','artists','sets_media','releases'] as $table) {
    seo_assert(str_contains($migration, 'ALTER TABLE ' . $table), "SEO migration must alter {$table}");
}
seo_assert(substr_count($migration, 'ADD COLUMN IF NOT EXISTS seo_title') === 4, 'SEO title migration must be idempotent for four content tables');
seo_assert(substr_count($migration, 'ADD COLUMN IF NOT EXISTS seo_description') === 4, 'SEO description migration must be idempotent for four content tables');
seo_assert(!preg_match('/\b(?:DROP|TRUNCATE|DELETE)\b/i', $migration), 'SEO migration must stay additive');

$api = (string)file_get_contents(__DIR__ . '/../api/seo-metadata.php');
seo_assert(str_contains($api, 'brvtal_admin_require();'), 'SEO API must require admin authentication');
seo_assert(str_contains($api, 'brvtal_admin_require_csrf();'), 'SEO mutations must require CSRF');
seo_assert(str_contains($api, 'SEO_SCHEMA_MISSING'), 'SEO API must fail explicitly before migration');
seo_assert(str_contains($api, "'events' => ['table'=>'events'"), 'SEO API must support Events');
seo_assert(str_contains($api, "'artists' => ['table'=>'artists'"), 'SEO API must support Artists');
seo_assert(str_contains($api, "'sets' => ['table'=>'sets_media'"), 'SEO API must support Sets');
seo_assert(str_contains($api, "'releases' => ['table'=>'releases'"), 'SEO API must support Releases');
seo_assert(str_contains($api, "UPDATE `{$table}` SET seo_title=?,seo_description=?") || str_contains($api, 'SET seo_title=?,seo_description=?'), 'SEO API must persist both metadata fields');

$controller = (string)file_get_contents(__DIR__ . '/../discadmin/seo-metadata.js');
foreach (['f_seo_title','e_seo_title','release_seo_title','f_seo_description','e_seo_description','release_seo_description'] as $id) {
    seo_assert(str_contains($controller, $id), "SEO editor integration must contain {$id}");
}
seo_assert(str_contains($controller, 'SEARCH PREVIEW'), 'SEO UI must provide search preview');
seo_assert(str_contains($controller, "'/api/seo-metadata.php'"), 'SEO UI must use protected metadata endpoint');
seo_assert(str_contains($controller, "'/api/index.php/events'"), 'Content Core SEO must reuse canonical Events data');
seo_assert(str_contains($controller, "url.pathname.endsWith('/api/releases.php')"), 'Releases mutations must persist SEO through the shared layer');
seo_assert(str_contains($controller, 'window.fetch = async function'), 'SEO persistence must enhance existing mutation flow rather than add a second editor');

$entry = (string)file_get_contents(__DIR__ . '/../discadmin/index.php');
seo_assert(str_contains($entry, "require __DIR__ . '/index-core.php';"), 'canonical DISCADMIN shell must remain index-core based');
seo_assert(str_contains($entry, '/discadmin/seo-metadata.js'), 'canonical shell must load SEO metadata enhancement');

$public = (string)file_get_contents(__DIR__ . '/../api/public.php');
seo_assert(str_contains($public, 'description,seo_title,seo_description,skin'), 'public Events must expose SEO metadata');
seo_assert(str_contains($public, 'bio,seo_title,seo_description,photo'), 'public Artists must expose SEO metadata');
seo_assert(str_contains($public, 's.description,s.seo_title,s.seo_description'), 'public Sets must expose SEO metadata');
seo_assert(str_contains($public, 'description,seo_title,seo_description,artwork'), 'public Releases must expose SEO metadata');
seo_assert(str_contains($public, 'content_json,seo_title,seo_description'), 'Pages SEO metadata must remain exposed');
seo_assert(str_contains($public, 'cover_image,seo_title,seo_description'), 'Blog SEO metadata must remain exposed');

echo "BRVTAL SEO metadata contract tests passed.\n";

require __DIR__ . '/seo-defaults-contract.php';
require __DIR__ . '/deployment-traceability-contract.php';
require __DIR__ . '/public-seo-delivery-contract.php';
require __DIR__ . '/public-entity-pages-contract.php';
