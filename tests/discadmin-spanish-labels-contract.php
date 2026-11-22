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
