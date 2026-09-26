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
dashboard_v2_assert(str_contains($controller, "activity:'/api/admin-activity.php?limit=5'"), 'Dashboard V2 must load Recent Changes five at a time');
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

dashboard_v2_assert(
    str_contains($api, "require_once __DIR__ . '/../config/schema_catalog.php';"),
    'Dashboard overview must use the shared request-local schema catalog'
);
dashboard_v2_assert(
    substr_count($api, 'information_schema.TABLES') === 0,
    'Dashboard overview must not issue per-table information_schema queries'
);
dashboard_v2_assert(
    str_contains($api, "COALESCE(SUM(status='draft'),0)")
        && str_contains($api, 'brvtal_dashboard_media_counts'),
    'Dashboard overview must aggregate related counters instead of issuing one COUNT per metric'
);

$core = (string)file_get_contents(__DIR__ . '/../discadmin/index-core.php');
dashboard_v2_assert(
    str_contains($core, 'if(window.BRVTALDashboardV2)')
        && str_contains($core, 'window.BRVTALDashboardV2?.mount?.()'),
    'Dashboard navigation must skip the redundant legacy dashboard data load when V2 owns the workspace'
);



$dashboardPreferences = (string)file_get_contents(__DIR__ . '/../config/admin_dashboard.php');
$dashboardPreferencesApi = (string)file_get_contents(__DIR__ . '/../api/admin-dashboard-preferences.php');
dashboard_v2_assert(str_contains($dashboardPreferences, "admin.dashboard."), 'Dashboard preferences must be namespaced per administrator');
dashboard_v2_assert(str_contains($dashboardPreferences, "'INVALID_' . \$prefix . '_WIDTH'"), 'Dashboard width spans must be strictly validated through the shared workspace contract');
dashboard_v2_assert(str_contains($dashboardPreferences, "'INVALID_' . \$prefix . '_HEIGHT'"), 'Dashboard height spans must be strictly validated through the shared workspace contract');
dashboard_v2_assert(str_contains($dashboardPreferencesApi, 'brvtal_admin_require();'), 'Dashboard preferences must require authentication');
dashboard_v2_assert(str_contains($dashboardPreferencesApi, 'brvtal_admin_require_csrf();'), 'Dashboard preference mutation must retain CSRF');
dashboard_v2_assert(str_contains($controller, "preferences:'/api/admin-dashboard-preferences.php'"), 'Dashboard must load private per-admin layout preferences');
dashboard_v2_assert(str_contains($controller, 'BRVTALAdminAuthBoundary?.csrfToken'), 'Dashboard mutation must reuse the canonical admin auth boundary');
dashboard_v2_assert(str_contains($controller, 'layoutSaveChain') && str_contains($controller, 'layoutSaveSerial'), 'Dashboard mutations must serialize and render latest-wins');
dashboard_v2_assert(str_contains($controller, 'data-dashboard-reset'), 'Dashboard must expose Reset to default');
dashboard_v2_assert(str_contains($controller, 'data-dashboard-hide') && str_contains($controller, 'data-dashboard-show'), 'Dashboard module library must support hide/show');
dashboard_v2_assert(str_contains($controller, 'data-dashboard-move="up"') && str_contains($controller, 'data-dashboard-move="down"'), 'Dashboard must expose keyboard/touch reorder controls');
dashboard_v2_assert(str_contains($controller, 'draggable="true"'), 'Dashboard must expose drag reordering');
dashboard_v2_assert(str_contains($controller, 'data-dashboard-resize="wider"') && str_contains($controller, 'data-dashboard-resize="taller"'), 'Dashboard must expose snap-grid resize controls');
dashboard_v2_assert(str_contains($controller, 'data-dashboard-activity-more'), 'Recent Changes must expose progressive View more');
dashboard_v2_assert(str_contains($css, 'repeat(4,minmax(0,1fr))'), 'Dashboard grid must support up to four desktop columns');
dashboard_v2_assert(str_contains($css, '.dashboard-v2-module-controls'), 'Dashboard module controls must be styled');
$genericSettingsApi = (string)file_get_contents(__DIR__ . '/../api/index.php');
dashboard_v2_assert(str_contains($genericSettingsApi, "setting_key NOT LIKE 'admin.dashboard.%'"), 'generic Settings listing must hide private Dashboard preferences');
dashboard_v2_assert(substr_count($genericSettingsApi, "str_starts_with(\$key, 'admin.dashboard.')") >= 2, 'generic Settings mutations must protect private Dashboard preferences');

dashboard_v2_assert(str_contains($controller, 'function analyticsPanel()'), 'Dashboard module library must include Analytics');
dashboard_v2_assert(str_contains($controller, 'DATA UNAVAILABLE') && str_contains($controller, 'does not invent GA4'), 'Analytics must fail closed when no admin-safe source exists');
dashboard_v2_assert(str_contains($controller, "summaryCard('Active events'") && str_contains($controller, "'events')"), 'Active Events summary must navigate to Events');
dashboard_v2_assert(str_contains($controller, "summaryCard('Media assets'") && str_contains($controller, "'media')"), 'Media Assets summary must navigate to Media Library');

echo "BRVTAL Dashboard V2 contract tests passed.\n";
