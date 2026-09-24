<?php
declare(strict_types=1);

function admin_perf_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("ADMIN PERFORMANCE CONTRACT FAILED: {$message}");
    }
}

$auth = (string)file_get_contents(__DIR__ . '/../config/admin_auth.php');
$index = (string)file_get_contents(__DIR__ . '/../api/index.php');
$schema = (string)file_get_contents(__DIR__ . '/../config/schema_catalog.php');
$search = (string)file_get_contents(__DIR__ . '/../api/admin-search.php');
$health = (string)file_get_contents(__DIR__ . '/../api/content-health.php');
$gridPrefs = (string)file_get_contents(__DIR__ . '/../api/admin-grid-preferences.php');

admin_perf_expect(
    str_contains($auth, 'session_write_close();'),
    'read-only admin traffic must have a canonical session-lock release'
);
admin_perf_expect(
    str_contains($index, '$csrfToken=$authenticated?brvtal_admin_csrf_token():null;')
        && str_contains($index, 'brvtal_admin_release_session();'),
    'GET /auth must copy CSRF before releasing its session lock'
);
admin_perf_expect(
    substr_count($schema, 'information_schema.TABLES') === 1,
    'schema catalog must own exactly one information_schema table-list query'
);
admin_perf_expect(
    !str_contains($search, 'information_schema.TABLES')
        && !str_contains($health, 'information_schema.TABLES'),
    'search and content health must reuse the schema catalog instead of probing tables independently'
);
admin_perf_expect(
    !str_contains($gridPrefs, 'brvtal_admin_session_start();'),
    'grid preference GET must not reacquire the session lock after authentication'
);
admin_perf_expect(
    str_contains($index, 'brvtalAdminCollectionPagination($resource, $_GET)')
        && str_contains($index, 'LIMIT {$limit} OFFSET {$offset}')
        && str_contains($index, "\$response['pagination'] = \$pagination;"),
    'generic admin collection reads must be server-paginated while preserving the data array envelope'
);
admin_perf_expect(
    !str_contains($health, 'SELECT * FROM'),
    'Content Health must use explicit projections instead of full-row SELECT * scans'
);

$authBoundary = (string)file_get_contents(__DIR__ . '/../discadmin/admin-auth-boundary.js');
admin_perf_expect(
    str_contains($authBoundary, 'let authPromise = null;')
        && str_contains($authBoundary, 'async function csrfToken()')
        && str_contains($authBoundary, "originalFetch('/api/index.php/auth'"),
    'browser auth must have one shared memoized auth/CSRF owner'
);
admin_perf_expect(
    str_contains($index, "\$pagination['query'] = (string)\$pagePlan['query'];"),
    'paginated admin collections must report the canonical server-side search query'
);

$authConsumers = [
    'admin-data-grid.js',
    'blog.js',
    'backups.js',
    'bulk-actions.js',
    'content-ordering.js',
    'event-workflow.js',
    'releases.js',
    'public-preview.js',
    'seo-workspace.js',
    'seo-metadata.js',
];
foreach ($authConsumers as $file) {
    $source = (string)file_get_contents(__DIR__ . '/../discadmin/' . $file);
    admin_perf_expect(
        !str_contains($source, '/api/index.php/auth')
            && str_contains($source, 'BRVTALAdminAuthBoundary'),
        "{$file} must reuse the shared auth/CSRF boundary instead of issuing a private /auth request"
    );
}

$core = (string)file_get_contents(__DIR__ . '/../discadmin/index-core.php');
admin_perf_expect(
    str_contains($core, "nativeListUrl(s,1,'')")
        && str_contains($core, "page_size:'50'")
        && str_contains($core, 'changeNativePage(1,query)'),
    'canonical native Admin lists must request bounded server pages and server-side search'
);

admin_perf_expect(
    str_contains($core, 'if(window.BRVTALDashboardV2)')
        && str_contains($core, 'window.BRVTALDashboardV2?.mount?.()'),
    'canonical Dashboard V2 must bypass the redundant legacy /dashboard preload'
);

echo "BRVTAL Admin performance contract passed.\n";
