<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$admin = file_get_contents($root . '/discadmin/theme-studio-v2.js');
$adminCss = file_get_contents($root . '/discadmin/theme-studio-v2.css');
$adminEntry = file_get_contents($root . '/discadmin/index.php');
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

$mustContain($loader, "'js/public-theme-runtime.js'", 'public loader must include the theme runtime');
$mustContain($e2e, "'/js/public-theme-runtime.js': 'theme-runtime'", 'Chromium runtime contract must include Theme runtime');
$mustContain($runtime, 'window.BRVTALPublicDataPromise', 'theme runtime should reuse the existing public data request');
$mustContain($runtime, 'data-theme-favicon', 'runtime must wire favicon');
$mustContain($runtime, '.hero-logo', 'runtime must wire the selected public logo');
$mustContain($runtime, "setToken('--bg'", 'runtime must wire background color token');
$mustContain($runtime, "setToken('--theme-display-font'", 'runtime must wire typography');
$mustContain($runtime, 'themeMenuStyle', 'runtime must map menu presentation');
$mustContain($runtime, 'themeSoundToggle', 'runtime must map sound-control visibility');
$mustNotContain($runtime, 'customCode', 'public runtime must not execute Theme Studio custom code');
$mustNotContain($runtime, 'analytics', 'public runtime must not execute theme analytics settings');

$mustContain($publicApi, "'branding'", 'public theme allowlist must expose branding');
$mustContain($publicApi, "'colors'", 'public theme allowlist must expose colors');
$mustContain($publicApi, "'typography'", 'public theme allowlist must expose typography');
$mustContain($publicApi, "unset(\$themeOut['analytics'], \$themeOut['customCode'])", 'public API must keep analytics and custom code private');

echo "Theme Studio V2 contract passed.\n";
