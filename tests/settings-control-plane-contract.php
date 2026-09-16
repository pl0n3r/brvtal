<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/public_analytics.php';
require_once __DIR__ . '/../config/public_seo.php';

function control_plane_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

control_plane_assert(brvtal_public_ga_id_value('g-abcd1234') === 'G-ABCD1234', 'GA4 IDs normalize and validate');
control_plane_assert(brvtal_public_ga_id_value('UA-123') === '', 'legacy/invalid analytics IDs fail closed');

$seo = brvtal_public_seo_document(null, 'https://www.brvtal.com.co', [
    'site_title' => 'BRVTAL / UNDERGROUND SIGNAL',
    'description' => 'Canonical description from typed Settings.',
    'share_image' => '/uploads/media/share.webp',
]);
control_plane_assert($seo['title'] === 'BRVTAL / UNDERGROUND SIGNAL', 'Home title uses server-owned Settings default');
control_plane_assert($seo['description'] === 'Canonical description from typed Settings.', 'Home description uses server-owned Settings default');
control_plane_assert($seo['image'] === 'https://www.brvtal.com.co/uploads/media/share.webp', 'Home share image resolves from Settings');

$entitySeo = brvtal_public_seo_document([
    'route_type' => 'events',
    'slug' => 'signal-night',
    'title' => 'Signal Night',
    'description' => 'Entity description',
    'seo_title' => 'Signal Night SEO',
    'seo_description' => 'Entity SEO description',
    'image' => '/event.webp',
    'schema_type' => 'MusicEvent',
], 'https://www.brvtal.com.co', [
    'site_title' => 'GLOBAL TITLE',
    'description' => 'GLOBAL DESCRIPTION',
]);
control_plane_assert($entitySeo['title'] === 'Signal Night SEO — BRVTAL', 'entity SEO remains authoritative over global Home defaults');
control_plane_assert($entitySeo['description'] === 'Entity SEO description', 'entity description remains authoritative');

$settingsUi = file_get_contents(__DIR__ . '/../discadmin/settings-v2.js') ?: '';
control_plane_assert(str_contains($settingsUi, "['general','GENERAL']"), 'Settings exposes General typed section');
control_plane_assert(str_contains($settingsUi, "['social','SOCIAL & CONTACT']"), 'Settings exposes Social typed section');
control_plane_assert(str_contains($settingsUi, "['seo','SEO']"), 'Settings exposes canonical SEO section');
control_plane_assert(str_contains($settingsUi, "['analytics','ANALYTICS & PRIVACY']"), 'Settings exposes Analytics & Privacy section');
control_plane_assert(str_contains($settingsUi, "const next = { ...current, ...patch }"), 'typed saves preserve unknown sibling JSON keys');
control_plane_assert(str_contains($settingsUi, 'reserved for #212'), 'language metadata is not misrepresented as implemented i18n');

$themeExtension = file_get_contents(__DIR__ . '/../discadmin/theme-studio-configuration.js') ?: '';
control_plane_assert(str_contains($themeExtension, 'branding = { ...(state.theme.branding || {}), wordmark:'), 'Theme Studio stores wordmark in theme branding');
control_plane_assert(str_contains($themeExtension, 'data-theme-tab="seo"'), 'Theme Studio extension removes duplicate primary SEO editing');
control_plane_assert(str_contains($themeExtension, 'PRESERVED / UNWIRED'), 'Theme Studio visibly classifies stored non-runtime options');

$wordmarkRuntime = file_get_contents(__DIR__ . '/../js/public-theme-wordmark.js') ?: '';
control_plane_assert(str_contains($wordmarkRuntime, 'branding.wordmark'), 'public runtime consumes theme branding wordmark');
control_plane_assert(str_contains($wordmarkRuntime, 'image.addEventListener(\'error\''), 'wordmark keeps a text fallback on asset error');
control_plane_assert(!str_contains($wordmarkRuntime, 'outerHTML'), 'wordmark runtime does not inject raw SVG/HTML strings');

$api = file_get_contents(__DIR__ . '/../api/public.php') ?: '';
control_plane_assert(!str_contains($api, "setting_key IN ('site','social','appearance','analytics'"), 'standalone analytics is not exposed by public settings API');
control_plane_assert(!str_contains($api, "setting_key IN ('site','social','appearance','seo'"), 'standalone canonical SEO config is not exposed by public settings API');

$uploadApi = file_get_contents(__DIR__ . '/../api/index.php') ?: '';
control_plane_assert(!str_contains($uploadApi, "'image/svg+xml'=>'svg'"), 'generic upload allowlist is not weakened for unsanitized SVG');

echo "settings-control-plane-contract: ok\n";
