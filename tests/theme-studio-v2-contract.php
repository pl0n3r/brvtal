<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$admin = file_get_contents($root . '/discadmin/theme-studio-v2.js');
$adminCss = file_get_contents($root . '/discadmin/theme-studio-v2.css');
$adminEntry = file_get_contents($root . '/discadmin/index.php');
$adminCore = file_get_contents($root . '/discadmin/index-core.php');
$runtime = file_get_contents($root . '/js/public-theme-runtime.js');
$loader = file_get_contents($root . '/js/public-runtime-loader.js');
$publicApi = file_get_contents($root . '/api/public.php');
$e2e = file_get_contents($root . '/tests/e2e/public-mobile-performance.spec.mjs');

$fail = static function (string $message): never {
    fwrite(STDERR, "Theme Studio V2 contract failed: {$message}\n");
    exit(1);
};
$mustContain = static function (string $haystack, string $needle, string $message) use ($fail): void {
    if (!str_contains($haystack, $needle)) $fail($message . " (missing {$needle})");
};
$mustNotContain = static function (string $haystack, string $needle, string $message) use ($fail): void {
    if (str_contains($haystack, $needle)) $fail($message . " (found {$needle})");
};

$mustContain($adminEntry, '/discadmin/theme-studio-v2.css', 'DISCADMIN must load Theme Studio V2 styles');
$mustContain($adminEntry, '/discadmin/theme-studio-v2.js', 'DISCADMIN must load Theme Studio V2 behavior');
$mustContain($admin, 'CHOOSE FROM MEDIA', 'branding assets must use the Media picker');
$mustContain($admin, "assetField('favicon'", 'favicon must be selectable from Media');
$mustContain($admin, "assetField('logo'", 'logo must be selectable from Media');
$mustContain($admin, "if (activate)", 'draft save and activation must be separate operations');
$mustContain($admin, "setting_key:'theme.active'", 'activation must explicitly update theme.active');
$mustContain($admin, 'Save stores a draft. Activate publishes', 'editor must explain save versus activate semantics');
$mustContain($admin, 'LEGACY SETTINGS PRESERVED', 'unsupported legacy settings must be preserved rather than silently deleted');
$mustContain($adminCss, '@media(max-width:760px)', 'Theme Studio V2 must have a mobile layout');
$mustContain($adminCss, 'tsv2-picker-overlay', 'visual Media picker must be styled');
$mustContain($admin, 'FONT_CATALOG', 'Theme Studio must expose a curated font catalog rather than requiring raw CSS stacks');
$mustContain($admin, 'Space Grotesk · recommended', 'Space Grotesk must be the recommended public family');
$mustContain($admin, 'Space Mono · recommended', 'Space Mono must remain the recommended technical family');
$mustContain($admin, 'RESET VISUALS TO CONCEPT 05', 'Concept 05 must be the canonical visual reset');
$mustNotContain($admin, 'LOAD GENESIS PRESET', 'event-specific Genesis preset must not compete with the canonical BRVTAL mother theme');
$mustContain($admin, 'BLACK · canvas', 'palette controls must explain the semantic BLACK role');
$mustContain($admin, 'PAPER · foreground', 'palette controls must explain the semantic PAPER role');
$mustContain($admin, 'RED · mother signal', 'palette controls must explain the semantic RED role');
$mustContain($admin, 'SIGNAL · neon accent', 'palette controls must explain the bounded SIGNAL role');
$mustContain($admin, 'TEXT / BLACK CONTRAST', 'Theme Studio must expose a basic primary-pair contrast check');
$mustContain($admin, 'LOCAL THEME PREVIEW', 'Theme Studio must label its local token preview honestly');
$mustNotContain($admin, "['seo','SEO']", 'Theme Studio must not expose SEO controls until server-side SEO consumes them');
$mustNotContain($admin, "toggleField('sceneIndicator'", 'Theme Studio must not expose the legacy scene indicator neutralized by Concept 05');
$mustContain($admin, 't.seo = { ...t.seo };', 'hidden legacy SEO payload must round-trip without destructive blanking');
$mustContain($admin, 'sceneIndicator:t.navigation?.sceneIndicator ?? false', 'hidden legacy scene-indicator state must round-trip safely');
$mustContain($admin, '>1440<', 'Theme Studio must expose the canonical desktop preview target');
$mustContain($admin, '>390<', 'Theme Studio must expose the canonical mobile preview target');
$mustContain($admin, 'data-preview-favicon', 'persistent local preview must include the configured favicon surface');
$mustContain($admin, "V2.previewMode === 'mobile' ? b.mobileLogo", 'mobile preview must use the configured mobile logo before falling back to the main logo');
$mustContain($adminCss, 'aspect-ratio:390/844', 'mobile preview must model the canonical 390 viewport proportion');
$mustContain($admin, 'data-theme-reset="palette"', 'palette group must offer a Concept 05 reset without resetting unrelated controls');
$mustContain($admin, 'data-theme-reset="type"', 'typography group must offer a Concept 05 reset without resetting unrelated controls');
$mustContain($admin, 'function resetGroup(group)', 'group reset behavior must stay bounded to supported visual groups');
$mustNotContain($admin, "textField('h1'", 'authored Concept 05 hero scale must remain design-system owned rather than presented as a live control');
$mustNotContain($admin, "textField('tracking'", 'authored Concept 05 display tracking must remain design-system owned rather than presented as a live control');
$mustContain($admin, "bodySize:value('bodySize')", 'the global body-size baseline may remain editable because the runtime consumes it');
$mustContain($adminCss, '.theme-v2 .btn,.tsv2-preview-head button{min-height:44px}', 'mobile Theme Studio actions must expose touch-sized targets');
$mustContain($adminCore, 'name:"BRVTAL CONCEPT 05"', 'core theme default must identify Concept 05');
$mustContain($adminCore, '"Space Grotesk", Arial, sans-serif', 'core theme default must use the recommended Space Grotesk fallback stack');

$mustContain($loader, "'js/public-theme-runtime.js'", 'public loader must include the theme runtime');
$mustContain($e2e, "'/js/public-theme-runtime.js': 'theme-runtime'", 'Chromium runtime contract must include Theme runtime');
$mustContain($runtime, 'window.BRVTALPublicDataPromise', 'theme runtime should reuse the existing public data request');
$mustContain($runtime, 'data-theme-favicon', 'runtime must wire favicon');
$mustContain($runtime, '.hero-logo', 'runtime must wire the selected public logo');
$mustContain($runtime, "setToken('--bg'", 'runtime must wire background color token');
$mustContain($runtime, "setToken('--theme-display-font'", 'runtime must wire typography');
$mustContain($runtime, 'GOOGLE_FONT_QUERY', 'runtime must allow only curated remotely loaded font families');
$mustContain($runtime, 'https://fonts.googleapis.com/css2?', 'runtime must load selected curated Google Fonts when required');
$mustContain($runtime, '&display=swap', 'remote typography must preserve render with display=swap');
$mustContain($runtime, 'families.map(family', 'runtime must load only the families selected by the active theme');
$mustContain($runtime, 'themeMenuStyle', 'runtime must map menu presentation');
$mustContain($runtime, 'themeSoundToggle', 'runtime must map sound-control visibility');
$mustNotContain($runtime, 'customCode', 'public runtime must not execute Theme Studio custom code');
$mustNotContain($runtime, 'analytics', 'public runtime must not execute theme analytics settings');

$mustContain($publicApi, "'branding'", 'public theme allowlist must expose branding');
$mustContain($publicApi, "'colors'", 'public theme allowlist must expose colors');
$mustContain($publicApi, "'typography'", 'public theme allowlist must expose typography');
$mustContain($publicApi, "unset(\$themeOut['analytics'], \$themeOut['customCode'])", 'public API must keep analytics and custom code private');

echo "Theme Studio V2 contract passed.\n";
