<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/seo_workspace.php';

function seo_workspace_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "SEO WORKSPACE CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$static = brvtalSeoWorkspaceStaticDefinitions();
seo_workspace_assert(array_keys($static) === ['home','contact'], 'static SEO registry must be a closed Home/Contact allowlist');

$home = brvtalSeoWorkspaceStaticState(null, 'home', 'https://www.brvtal.com.co');
seo_workspace_assert($home['mode'] === 'AUTO', 'Home without persisted overrides must be AUTO');
seo_workspace_assert($home['canonical'] === 'https://www.brvtal.com.co/', 'Home canonical must be derived from the fixed route');
seo_workspace_assert(str_contains($home['effective_title'], 'BRVTAL'), 'Home automatic title must stay usable');

$contact = brvtalSeoWorkspaceStaticState(null, 'contact', 'https://www.brvtal.com.co');
seo_workspace_assert($contact['canonical'] === 'https://www.brvtal.com.co/contact', 'Contact canonical must stay fixed');
seo_workspace_assert($contact['effective_title'] === 'Contact — BRVTAL', 'Contact must preserve its server-rendered default');

seo_workspace_assert(brvtalSeoWorkspaceImageValue('/uploads/share.webp') === '/uploads/share.webp', 'uploaded media paths are valid static SEO images');
seo_workspace_assert(brvtalSeoWorkspaceImageValue('/assets/brvtal-logo.jpeg') === '/assets/brvtal-logo.jpeg', 'bundled assets are valid static SEO images');
seo_workspace_assert(brvtalSeoWorkspaceImageValue('https://cdn.example.com/share.webp') === 'https://cdn.example.com/share.webp', 'HTTP(S) share images are valid');
seo_workspace_assert(brvtalSeoWorkspaceImageValue('javascript:alert(1)') === '', 'script URLs must be rejected');
seo_workspace_assert(brvtalSeoWorkspaceImageValue('/private/secret.jpg') === '', 'arbitrary root paths must be rejected');
seo_workspace_assert(brvtalSeoWorkspaceImageValue('/uploads/../config.php') === '', 'asset traversal paths must be rejected');
seo_workspace_assert(brvtalSeoWorkspaceImageValue('/uploads/share.webp?x=1') === '', 'asset query strings must be rejected');

$entities = brvtalSeoWorkspaceEntityDefinitions();
seo_workspace_assert(
    array_keys($entities) === ['events','artists','sets','releases','blog','pages'],
    'entity SEO registry must follow the canonical public content registry'
);
seo_workspace_assert(($entities['pages']['description'] ?? '') === 'content_json', 'Pages must keep Content JSON as the canonical fallback source');
seo_workspace_assert(($entities['blog']['description'] ?? '') === 'excerpt', 'Blog must keep excerpt as the canonical fallback source');

$api = (string)file_get_contents(__DIR__ . '/../api/seo-workspace.php');
seo_workspace_assert(str_contains($api, 'brvtal_admin_require();'), 'workspace API must require admin authentication');
seo_workspace_assert(str_contains($api, 'brvtal_admin_require_csrf();'), 'workspace mutations must require CSRF');
seo_workspace_assert(str_contains($api, 'brvtalSeoPersistOverrides'), 'entity writes must reuse the audited SEO persistence boundary');
seo_workspace_assert(str_contains($api, 'brvtalSeoWorkspacePersistStatic'), 'static writes must use the allowlisted static persistence boundary');
seo_workspace_assert(str_contains($api, 'brvtal_public_seo_document'), 'inventory previews must reuse the public server SEO renderer');
seo_workspace_assert(!str_contains($api, "\$_GET['table']"), 'workspace API must never accept arbitrary table names');

$module = (string)file_get_contents(__DIR__ . '/../discadmin/seo-workspace.php');
$controller = (string)file_get_contents(__DIR__ . '/../discadmin/seo-workspace.js');
$modules = (string)file_get_contents(__DIR__ . '/../discadmin/admin-modules.js');
$ia = (string)file_get_contents(__DIR__ . '/../discadmin/admin-information-architecture.js');
$settings = (string)file_get_contents(__DIR__ . '/../discadmin/settings-v2.js');
seo_workspace_assert(str_contains($module, 'data-admin-module="seo"'), 'SEO workspace must mount inside the canonical Admin module host');
seo_workspace_assert(str_contains($module, '<dialog'), 'SEO editor must use the native dialog element');
seo_workspace_assert(str_contains($controller, "'/api/seo-workspace.php'"), 'SEO workspace UI must use the compact protected endpoint');
seo_workspace_assert(
    str_contains($module, 'RESET TO AUTO') && str_contains($controller, 'data-seo-reset'),
    'SEO workspace must expose field-level reset-to-auto controls'
);
seo_workspace_assert(str_contains($controller, 'SEO save failed:'), 'failed writes must remain visible and recoverable');
seo_workspace_assert(str_contains($modules, "'/discadmin/seo-workspace.php'"), 'Admin module router must register the SEO destination');
seo_workspace_assert(str_contains($ia, "'media','releases','blog','seo'"), 'SEO must participate in canonical dynamic navigation');
seo_workspace_assert(!str_contains($ia, "'security','seo','backups'"), 'SEO must no longer be hidden from the canonical sidebar');
seo_workspace_assert(str_contains($settings, 'OPEN SEO WORKSPACE'), 'Settings SEO must defer metadata editing to the canonical workspace');
seo_workspace_assert(!str_contains($settings, 'data-settings-save="seo"'), 'Settings must not remain a second Home SEO write surface');

$publicSeo = (string)file_get_contents(__DIR__ . '/../config/public_seo.php');
$contactPage = (string)file_get_contents(__DIR__ . '/../config/public_contact_page.php');
$publicIndex = (string)file_get_contents(__DIR__ . '/../index.php');
seo_workspace_assert(str_contains($publicSeo, "brvtalSeoWorkspaceStaticValues(\$pdo, 'home')"), 'Home server-rendered SEO must use the static workspace source');
seo_workspace_assert(str_contains($contactPage, "brvtalSeoWorkspaceStaticState(\$pdo, 'contact', \$base)"), 'Contact server-rendered SEO must use the static workspace source');
seo_workspace_assert(str_contains($publicIndex, 'brvtal_public_contact_seo($baseUrl, db())'), 'Contact renderer must use database-backed static SEO');

echo "BRVTAL centralized SEO workspace contract passed.\n";
