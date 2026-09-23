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
seo_workspace_assert(!str_contains($api, "$_GET['table']"), 'workspace API must never accept arbitrary table names');

echo "BRVTAL centralized SEO workspace backend contract passed.\n";
