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
$persistence = (string)file_get_contents(__DIR__ . '/../config/seo_persistence.php');
seo_assert(str_contains($api, 'brvtal_admin_require();'), 'SEO API must require admin authentication');
seo_assert(str_contains($api, 'brvtal_admin_require_csrf();'), 'SEO mutations must require CSRF');
seo_assert(str_contains($api, 'SEO_SCHEMA_MISSING'), 'SEO API must fail explicitly before migration');
seo_assert(str_contains($api, 'brvtalSeoWorkspaceEntityDefinitions()'), 'SEO API must reuse the canonical entity registry');
require_once __DIR__ . '/../config/seo_workspace.php';
$seoResources = brvtalSeoWorkspaceEntityDefinitions();
foreach (['events','artists','sets','releases','blog','pages'] as $resource) {
    seo_assert(isset($seoResources[$resource]), "SEO registry must support {$resource}");
}
seo_assert(str_contains($api, 'brvtalSeoPersistOverrides'), 'SEO API must delegate writes to the shared persistence boundary');
seo_assert(str_contains($persistence, 'SET seo_title=?,seo_description=?'), 'SEO persistence boundary must write both metadata fields');

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

// Canonical host delivery must converge the bare production hostname on HTTPS + www
// before public/entity/API/admin routing. REQUEST_URI keeps the full path while Apache
// preserves the existing query string on redirects unless an explicit replacement is set.
$htaccess = (string)file_get_contents(__DIR__ . '/../.htaccess');
$hostCond = 'RewriteCond %{HTTP_HOST} ^brvtal\\.com\\.co(?::[0-9]+)?$ [NC]';
$hostRule = 'RewriteRule ^ https://www.brvtal.com.co%{REQUEST_URI} [R=301,L,NE]';
seo_assert(str_contains($htaccess, $hostCond), 'root .htaccess must match only the bare BRVTAL production host');
seo_assert(str_contains($htaccess, $hostRule), 'bare production host must permanently redirect to canonical HTTPS www while preserving the request path');
$canonicalPos = strpos($htaccess, $hostCond);
$entityRoutePos = strpos($htaccess, 'RewriteRule ^(events|artists|sets|releases|blog|pages)');
seo_assert($canonicalPos !== false && $entityRoutePos !== false && $canonicalPos < $entityRoutePos, 'canonical host redirect must run before entity routing');
seo_assert(substr_count($htaccess, 'https://www.brvtal.com.co') === 1, 'canonical host redirect should have one unambiguous www target');

echo "BRVTAL SEO metadata contract tests passed.\n";

require __DIR__ . '/seo-defaults-contract.php';
require __DIR__ . '/deployment-traceability-contract.php';
require __DIR__ . '/public-seo-delivery-contract.php';
require __DIR__ . '/public-entity-pages-contract.php';
require __DIR__ . '/public-analytics-contract.php';