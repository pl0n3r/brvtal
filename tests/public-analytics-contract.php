<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/public_analytics.php';

function analytics_expect(bool $ok, string $message): void
{
    if (!$ok) { fwrite(STDERR, "PUBLIC ANALYTICS FAILED: {$message}\n"); exit(1); }
}

analytics_expect(brvtal_public_gtm_id_value(' gtm-w23phgjg ') === 'GTM-W23PHGJG', 'valid GTM ID');
foreach (['', 'G-AB12CD34', 'GTM-<script>', 'GTM-A', 'GTM-ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'GTM-ABCD&x=1'] as $bad) {
    analytics_expect(brvtal_public_gtm_id_value($bad) === '', 'reject invalid GTM ID');
}
analytics_expect(
    brvtal_public_gtm_id_from_theme(['analytics' => ['tag_manager' => ' gtm-ab12cd34 ']]) === 'GTM-AB12CD34',
    'legacy theme GTM key remains a migration fallback'
);
analytics_expect(brvtal_public_analytics_markup('', '1234567') === '', 'no tracking without a GTM ID');
$markup = brvtal_public_analytics_markup('GTM-W23PHGJG', '1234567');
analytics_expect(str_contains($markup, 'data-gtm-id="GTM-W23PHGJG"'), 'validated GTM ID reaches the browser');
analytics_expect(str_contains($markup, '/js/public-analytics.js?v=1234567'), 'GTM bootstrap is deploy-versioned');
analytics_expect(str_contains($markup, '/js/public-measurement.js?v=1234567'), 'measurement runtime is deploy-versioned');
analytics_expect(!str_contains($markup, 'public-analytics.css'), 'consent UI stylesheet is no longer shipped');

$entry = file_get_contents(__DIR__ . '/../index.php');
analytics_expect(is_string($entry), 'read public entry point');
analytics_expect(str_contains($entry, 'brvtal_public_gtm_id'), 'public pages resolve the canonical GTM container');
analytics_expect(!str_contains($entry, 'brvtal_public_ga_id'), 'public pages do not resolve direct GA4');
analytics_expect(!str_contains($entry, 'headCode'), 'arbitrary theme snippets never execute');

$runtime = file_get_contents(__DIR__ . '/../js/public-analytics.js');
analytics_expect(is_string($runtime), 'read public analytics runtime');
analytics_expect(str_contains($runtime, 'googletagmanager.com/gtm.js'), 'runtime loads Google Tag Manager');
analytics_expect(str_contains($runtime, "analytics_storage: 'granted'"), 'analytics storage is granted at bootstrap');
analytics_expect(str_contains($runtime, "ad_storage: 'denied'"), 'advertising storage remains denied');
analytics_expect(str_contains($runtime, "ad_user_data: 'denied'"), 'advertising user data remains denied');
analytics_expect(str_contains($runtime, "ad_personalization: 'denied'"), 'advertising personalization remains denied');
analytics_expect(!str_contains($runtime, 'brvtal.analytics.choice.v1'), 'runtime has no analytics acceptance gate');
analytics_expect(!str_contains($runtime, 'ALLOW ANALYTICS'), 'runtime has no analytics acceptance UI');
analytics_expect(!str_contains($runtime, 'googletagmanager.com/gtag/js'), 'runtime does not load GA4 directly');
analytics_expect(!str_contains($runtime, "gtag('config'"), 'runtime does not configure GA4 directly');

echo "BRVTAL public analytics contract tests passed.\n";
