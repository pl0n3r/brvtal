<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/public_analytics.php';

function analytics_expect(bool $ok, string $message): void
{
    if (!$ok) { fwrite(STDERR, "PUBLIC ANALYTICS FAILED: {$message}\n"); exit(1); }
}

analytics_expect(brvtal_public_ga_id_from_theme(['analytics' => ['google' => ' g-ab12cd34 ']]) === 'G-AB12CD34', 'valid GA4 ID');
foreach (['', 'G-<script>', 'G-A', 'G-ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'G-ABCD&x=1'] as $bad) {
    analytics_expect(brvtal_public_ga_id_from_theme(['analytics' => ['google' => $bad]]) === '', 'reject invalid GA4 ID');
}
analytics_expect(brvtal_public_analytics_markup('', '1234567') === '', 'no UI or tracking without an ID');
$markup = brvtal_public_analytics_markup('G-AB12CD34', '1234567');
analytics_expect(str_contains($markup, 'data-ga-id="G-AB12CD34"'), 'validated ID reaches the browser');
analytics_expect(str_contains($markup, '/js/public-analytics.js?v=1234567'), 'script is deploy-versioned');
$entry = file_get_contents(__DIR__ . '/../index.php');
analytics_expect(str_contains($entry, 'brvtal_public_analytics_markup'), 'public pages use controlled analytics');
analytics_expect(!str_contains($entry, 'headCode'), 'arbitrary theme snippets never execute');
echo "BRVTAL public analytics contract tests passed.\n";
