<?php
declare(strict_types=1);

/** #1864: labels for DISCADMIN shell and banners are Spanish, without internal jargon. */
function brvtal_spanish_labels_assert(bool $ok, string $reason): void
{
    if (!$ok) {
        fwrite(STDERR, "DISCADMIN SPANISH LABELS FAILED: {$reason}\n");
        exit(1);
    }
}
$core = (string) file_get_contents(__DIR__ . '/../discadmin/index-core.php');
$banners = (string) file_get_contents(__DIR__ . '/../discadmin/hero-slider.js');
$navigation = (string) file_get_contents(__DIR__ . '/../discadmin/admin-information-architecture.js');
brvtal_spanish_labels_assert($core !== '' && $banners !== '' && $navigation !== '', 'missing source');
brvtal_spanish_labels_assert(str_contains($navigation, 'buttonKey(button) === section'), 'route restore must reuse the real user-visible Spanish navigation label');
$usesV3 = str_contains($core, ' const adminMenuRoots=[');
$legacyNavOnly = [
    "['dashboard','INICIO']", "['pages','PÁGINAS']", "['events','EVENTOS']",
    "['ticketing','BOLETERÍA']", '>APARIENCIA</button>',
    '>CONFIGURACIÓN</button>', '>ESTADO DEL SISTEMA</button>', '>TEMA VISUAL</button>',
];
if ($usesV3) {
    $start = strpos($core, ' const adminMenuRoots=[');
    $end = strpos($core, ' const moduleTitles=', $start);
    $roots = $end !== false ? substr($core, $start, $end - $start) : '';
    preg_match_all("/\\['([^']+)','([^']+)'\\]/", $roots, $rootPairs, PREG_SET_ORDER);
    $actualRoots = array_map(static fn(array $p): array => [$p[1], $p[2]], $rootPairs);
    brvtal_spanish_labels_assert($actualRoots === [
        ['dashboard','ESCRITORIO'], ['events','EVENTOS'], ['artists','ARTISTAS'],
        ['music','MÚSICA'], ['ticketing','BOLETERÍA'], ['mail','CORREOS'],
        ['website','SITIO WEB'], ['settings','CONFIGURACIÓN'],
    ], 'V3 must render eight canonical Spanish root labels');
}
foreach ([
    "['dashboard','INICIO']",
    "['pages','PÁGINAS']",
    "['events','EVENTOS']",
    "['ticketing','BOLETERÍA']",
    "ticketing:'BOLETERÍA'",
    "'hero-slider':'BANNERS DE PORTADA'",
    "system:'ESTADO DEL SISTEMA'",
    '>APARIENCIA</button>',
    '>CONFIGURACIÓN</button>',
    '>ESTADO DEL SISTEMA</button>',
    '>TEMA VISUAL</button>',
    'aria-label="Cerrar navegación"',
    'aria-label="Navegación principal"',
    '+ NUEVO EVENTO',
    "btn.textContent='VERIFICANDO…'",
    'data-testid="admin-product-version"',
    'data-testid="admin-deploy-source"',
] as $fragment) {
    if ($usesV3 && in_array($fragment, $legacyNavOnly, true)) continue;
    brvtal_spanish_labels_assert(
        str_contains($core, $fragment),
        'missing translated shell label or stable contract: ' . $fragment
    );
}
foreach (['>APPEARANCE</button>', '>SETTINGS</button>', '>SYSTEM STATUS</button>',
    '>THEME STUDIO</button>', '>+ NEW EVENT</button>', 'DISCADMIN / CONTROL ROOM',
    "'CONTENT CORE'", "'SECURITY / 2FA'", "?'BANNERS'",
] as $obsolete) {
    brvtal_spanish_labels_assert(
        !str_contains($core, $obsolete),
        'obsolete English shell copy: ' . $obsolete
    );
}
brvtal_spanish_labels_assert(
    str_contains($core, '<span hidden style="display:none"')
        && str_contains($core, "BRVTAL_APP_ENV === 'PRODUCTION' ? 'Producción'"),
    'deployment diagnostics must be available without exposing internal wording'
);
foreach ([
    'Banners de portada',
    'Organiza banners y capas con ajustes independientes para móvil.',
    'ELEGIR ARCHIVO',
    "return 'GUARDAR'",
    '+ NUEVO BANNER',
    'ESCRITORIO',
    'MÓVIL',
    'CAPAS',
    'CONFIGURACIÓN DEL BANNER',
    'BORRADOR LOCAL DISPONIBLE',
    'VISTA PREVIA',
    "const KEY = 'home.hero.slider'",
    'data-save-slider',
    'data-add-layer',
    'layerTypeLabel',
] as $fragment) {
    brvtal_spanish_labels_assert(
        str_contains($banners, $fragment),
        'missing Spanish banner copy or stable editor behavior: ' . $fragment
    );
}
foreach ([
    'SLIDER MANAGER V2', 'LayerSlider-inspired', 'SELECT MEDIA',
    "return 'SAVE'", 'MEDIA LOADING…',
    'LOCAL DRAFT AVAILABLE', 'HOME / HERO',
    '>DESKTOP</button>', '>MOBILE</button>',
    'LIVE PREVIEW · drag selected layers',
] as $obsolete) {
    brvtal_spanish_labels_assert(
        !str_contains($banners, $obsolete),
        'obsolete banner copy: ' . $obsolete
    );
}
echo "DISCADMIN Spanish labels contract passed.\n";
