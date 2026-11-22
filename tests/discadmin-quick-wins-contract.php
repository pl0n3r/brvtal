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
    !brvtal_public_page_is_visible(['status'=>'published','locale'=>'en']),
    'published English Pages must not be public'
);
quick_win_assert(
    brvtal_public_page_is_visible(['status'=>'published','locale'=>'es']),
    'published Spanish Pages must be public'
);
quick_win_assert(
    !brvtal_public_page_is_visible(['status'=>'draft','locale'=>'es']),
    'draft Spanish Pages must not be public'
);
quick_win_assert(
    !brvtal_public_page_is_visible(['status'=>'published','locale'=>'es','private'=>true])
        && !brvtal_public_page_is_visible(['status'=>'published','locale'=>'es','trusted'=>false]),
    'private or untrusted Spanish Pages must remain non-public'
);

$dashboard = (string)file_get_contents(__DIR__ . '/../api/dashboard-overview.php');
quick_win_assert(
    str_contains($dashboard, "status='published' AND locale='es'"),
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
        && str_contains($reliability, "if (type === 'sets') {")
        && str_contains($reliability, "await hydrateSetRelations();")
        && str_contains($reliability, "return false;"),
    'direct Set editor entry must hydrate Artist/Event references first and fail closed when hydration fails'
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

$indexCore = (string)file_get_contents(__DIR__ . '/../discadmin/index-core.php');
if (str_contains($indexCore, ' const adminMenuRoots=[')) {
    $navStart = strpos($indexCore, ' const adminMenuRoots=[');
    $navEnd = strpos($indexCore, ' const moduleTitles=', $navStart);
    $navSource = $navEnd !== false ? substr($indexCore, $navStart, $navEnd - $navStart) : '';
    preg_match_all("/\\['([^']+)','([^']+)'\\]/", $navSource, $pairs, PREG_SET_ORDER);
    $actual = array_map(static fn(array $p): array => [$p[1], $p[2]], $pairs);
    $expected = [
        ['dashboard','ESCRITORIO'], ['events','EVENTOS'], ['artists','ARTISTAS'],
        ['music','MÚSICA'], ['ticketing','BOLETERÍA'], ['mail','CORREOS'],
        ['website','SITIO WEB'], ['settings','CONFIGURACIÓN'],
    ];
    quick_win_assert($actual === $expected, 'V3 sidebar must declare exactly eight ordered roots');
    $adminIa = (string)file_get_contents(__DIR__ . '/../discadmin/admin-information-architecture.js');
    $website = strpos($adminIa, "{key:'website'");
    $pages = $website !== false ? strpos($adminIa, "{key:'pages'", $website) : false;
    $blog = $pages !== false ? strpos($adminIa, "{key:'blog'", $pages) : false;
    $media = $blog !== false ? strpos($adminIa, "{key:'media'", $blog) : false;
    quick_win_assert($website !== false && $pages !== false && $blog !== false && $media !== false
        && $website < $pages && $pages < $blog && $blog < $media,
        'V3 Site web must keep Pages, Blog and Multimedia in canonical order');
} else {
$navItems = [
    "['dashboard','INICIO']",
    "['pages','PÁGINAS']",
    "['blog','BLOG']",
    "['media','MEDIOS']",
    "['events','EVENTOS']",
    "['artists','ARTISTAS']",
    "['releases','LANZAMIENTOS']",
    "['sets','SESIONES']",
];
$navStart = strpos($indexCore, ' const editorialSections=[');
$navEnd = strpos($indexCore, ' const moduleTitles={');
$navSource = $navStart !== false && $navEnd !== false && $navEnd > $navStart
    ? substr($indexCore, $navStart, $navEnd - $navStart)
    : '';
$lastPosition = -1;
$orderedNavigation = true;
foreach ($navItems as $item) {
    $position = strpos($navSource, $item);
    if ($position === false || $position <= $lastPosition) {
        $orderedNavigation = false;
        break;
    }
    $lastPosition = $position;
}
$appearance = strpos($navSource, '>APARIENCIA</button>');
$banners = strpos($navSource, '>BANNERS DE PORTADA</button>');
$theme = strpos($navSource, '>TEMA VISUAL</button>');
$seo = strpos($navSource, '>SEO</button>');
$settings = strpos($navSource, 'data-admin-nav="settings"');
$system = strpos($navSource, 'data-admin-nav="system"');
quick_win_assert(
    $orderedNavigation
        && !str_contains($navSource, 'SITE / EDITORIAL')
        && !str_contains($navSource, 'CONFIGURATION / TECHNICAL')
        && $appearance !== false
        && $banners !== false
        && $theme !== false
        && $seo !== false
        && $settings !== false
        && $system !== false
        && $appearance < $banners
        && $banners < $theme
        && $theme < $seo
        && $seo < $settings
        && $settings < $system
        && str_contains($navSource, 'data-admin-nav="appearance"')
        && str_contains($navSource, 'data-admin-nav="hero-slider"')
        && str_contains($navSource, 'data-admin-nav="theme"'),
    'canonical shell must render the approved WordPress-like navigation before enhancements boot'
);
}
quick_win_assert(
    preg_match('/<div class="nav">\\s*\\$\\{navHtml\\}\\s*<\\/div>/', $indexCore) === 1,
    'canonical sidebar must render approved links inside the .nav container'
);
quick_win_assert(
    !str_contains($indexCore, '<span class="status"><i></i>ONLINE</span>'),
    'canonical header must not expose an ambiguous decorative ONLINE badge'
);
quick_win_assert(
    str_contains($indexCore, 'data-admin-logout'),
    'canonical sidebar must expose a stable Logout anchor for persistent search placement'
);

$entry = (string)file_get_contents(__DIR__ . '/../discadmin/index.php');
$iaPosition = strpos($entry, '/discadmin/admin-information-architecture.js');
$aliasPosition = strpos($entry, '/discadmin/admin-route-aliases.js');
quick_win_assert(
    is_int($iaPosition) && is_int($aliasPosition) && $iaPosition < $aliasPosition,
    'route alias wrapper must load after the canonical information architecture wrapper'
);

echo "BRVTAL DISCADMIN quick-win contract tests passed.\n";
