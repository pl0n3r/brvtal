<?php
declare(strict_types=1);

function quick_win_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "DISCADMIN QUICK WINS CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

require_once __DIR__ . '/../config/public_visibility.php';

quick_win_assert(
    brvtal_public_page_is_visible(['status'=>'published','locale'=>'en']),
    'published English Pages must remain public'
);
quick_win_assert(
    !brvtal_public_page_is_visible(['status'=>'published','locale'=>'es']),
    'published non-English Pages must not be classified as public'
);
quick_win_assert(
    !brvtal_public_page_is_visible(['status'=>'draft','locale'=>'en']),
    'draft English Pages must not be classified as public'
);

$dashboard = (string)file_get_contents(__DIR__ . '/../api/dashboard-overview.php');
quick_win_assert(
    str_contains($dashboard, "status='published' AND locale='en'"),
    'Dashboard Page public counts must match the canonical public locale'
);
quick_win_assert(
    str_contains($dashboard, 'brvtal_dashboard_page_status_counts'),
    'Dashboard must use a Page-specific public counter'
);

$health = (string)file_get_contents(__DIR__ . '/../api/content-health.php');
quick_win_assert(
    str_contains($health, "\$type === 'pages'") && str_contains($health, 'brvtal_public_page_is_visible($row)'),
    'Content Health must reuse canonical Page visibility'
);

$reliability = (string)file_get_contents(__DIR__ . '/../discadmin/admin-reliability.js');
quick_win_assert(
    str_contains($reliability, 'nativeOpenModal')
        && str_contains($reliability, "if (type === 'sets') await hydrateSetRelations();"),
    'direct Set editor entry must hydrate Artist/Event references first'
);
quick_win_assert(
    str_contains($reliability, 'brvtalReliableLogout')
        && str_contains($reliability, 'LOGOUT COULD NOT BE CONFIRMED'),
    'logout failure must remain visible instead of presenting a false local logout'
);
quick_win_assert(
    str_contains($reliability, 'input.readOnly = true')
        && str_contains($reliability, "input.setAttribute('aria-readonly', 'true')"),
    'existing Setting keys must be immutable in the legacy editor'
);

$routeAliases = (string)file_get_contents(__DIR__ . '/../discadmin/admin-route-aliases.js');
quick_win_assert(
    str_contains($routeAliases, "value === 'backups'") && str_contains($routeAliases, "return 'system'"),
    'legacy Backups deep links must resolve to System Status'
);
quick_win_assert(
    str_contains($routeAliases, "value === 'activity'") && str_contains($routeAliases, "return 'dashboard'"),
    'legacy Activity deep links must resolve to Dashboard'
);

$entry = (string)file_get_contents(__DIR__ . '/../discadmin/index.php');
$iaPosition = strpos($entry, '/discadmin/admin-information-architecture.js');
$aliasPosition = strpos($entry, '/discadmin/admin-route-aliases.js');
quick_win_assert(
    is_int($iaPosition) && is_int($aliasPosition) && $iaPosition < $aliasPosition,
    'route alias wrapper must load after the canonical information architecture wrapper'
);

echo "BRVTAL DISCADMIN quick-win contract tests passed.\n";
