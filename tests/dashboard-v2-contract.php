<?php
declare(strict_types=1);

function dashboard_v2_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "DASHBOARD V2 CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$api = (string)file_get_contents(__DIR__ . '/../api/dashboard-overview.php');
dashboard_v2_assert(str_contains($api, 'brvtal_admin_require();'), 'Dashboard overview must require admin authentication');
dashboard_v2_assert(str_contains($api, "require_once __DIR__ . '/../config/public_visibility.php';"), 'Dashboard overview must reuse canonical public visibility policy');
dashboard_v2_assert(str_contains($api, 'brvtal_public_event_statuses()'), 'active Event counts must use canonical lifecycle states');
dashboard_v2_assert(str_contains($api, "'next_event'"), 'Dashboard overview must expose the next active Event');
dashboard_v2_assert(str_contains($api, "'draft_records'"), 'Dashboard overview must expose editorial draft backlog');
dashboard_v2_assert(str_contains($api, "'available'=>false"), 'missing optional data sources must be distinguishable from zero');
dashboard_v2_assert(!str_contains($api, 'analytics_events'), 'Dashboard V2 must not use the legacy analytics_events table');
dashboard_v2_assert(!preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE)\s+(?:INTO\s+|FROM\s+)?[`a-z_]/i', $api), 'Dashboard overview must be read-only');

$healthApi = (string)file_get_contents(__DIR__ . '/../api/content-health.php');
dashboard_v2_assert(str_contains($healthApi, "'public' => \$public"), 'Content Health must expose public readiness separately');
dashboard_v2_assert(str_contains($healthApi, "'drafts' => \$drafts"), 'Content Health must expose draft completeness separately');
dashboard_v2_assert(str_contains($healthApi, "'is_public'"), 'Content Health records must classify public visibility');
dashboard_v2_assert(str_contains($healthApi, "'is_draft'"), 'Content Health records must classify drafts');

$controller = (string)file_get_contents(__DIR__ . '/../discadmin/dashboard-v2.js');
dashboard_v2_assert(str_contains($controller, "overview:'/api/dashboard-overview.php'"), 'Dashboard V2 must read the dedicated overview source');
dashboard_v2_assert(str_contains($controller, "content:'/api/content-health.php'"), 'Dashboard V2 must integrate Content Health');
dashboard_v2_assert(str_contains($controller, "storage:'/discadmin/storage-metrics.php'"), 'Dashboard V2 must use managed storage metrics');
dashboard_v2_assert(str_contains($controller, "activity:'/api/admin-activity.php?limit=4'"), 'Dashboard V2 must surface recent Admin Activity');
dashboard_v2_assert(str_contains($controller, 'Promise.allSettled'), 'independent source failures must not collapse the full Dashboard');
dashboard_v2_assert(str_contains($controller, 'SOURCE UNAVAILABLE'), 'source failures must stay explicit instead of becoming zero');
dashboard_v2_assert(str_contains($controller, "data-dashboard-go=\"media\""), 'Media shortcut must route to canonical Media Library');
dashboard_v2_assert(!str_contains($controller, "data-dashboard-create=\"media\""), 'Dashboard V2 must not expose the legacy Media create modal');
dashboard_v2_assert(str_contains($controller, "health?.php"), 'PHP runtime must come from live health data rather than a hard-coded version');
dashboard_v2_assert(str_contains($controller, 'dashboard-degraded') && str_contains($controller, 'dashboard-offline'), 'Dashboard source failures must update shell status semantics');

$css = (string)file_get_contents(__DIR__ . '/../discadmin/dashboard-v2.css');
dashboard_v2_assert(str_contains($css, '.dashboard-v2-grid'), 'Dashboard V2 must define its operational layout');
dashboard_v2_assert(str_contains($css, '@media(max-width:720px)'), 'Dashboard V2 must include mobile layout rules');
dashboard_v2_assert(str_contains($css, 'min-height:42px'), 'mobile Dashboard actions must retain practical touch targets');

$entry = (string)file_get_contents(__DIR__ . '/../discadmin/index.php');
dashboard_v2_assert(str_contains($entry, '/discadmin/dashboard-v2.css'), 'canonical shell must load Dashboard V2 CSS');
dashboard_v2_assert(str_contains($entry, '/discadmin/dashboard-v2.js'), 'canonical shell must load Dashboard V2 controller');
dashboard_v2_assert(str_contains($entry, "require __DIR__ . '/index-core.php';"), 'Dashboard V2 must remain inside the one canonical shell');
dashboard_v2_assert(!str_contains($entry, 'admin-sidebar.php'), 'Dashboard V2 must not introduce a parallel sidebar');

echo "BRVTAL Dashboard V2 contract tests passed.\n";
