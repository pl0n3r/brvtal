<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$reliability = file_get_contents($root . '/discadmin/theme-studio-reliability.js');
$reliabilityCss = file_get_contents($root . '/discadmin/theme-studio-reliability.css');
$adminEntry = file_get_contents($root . '/discadmin/index.php');
$brandingSync = file_get_contents($root . '/js/public-theme-branding-sync.js');
$loader = file_get_contents($root . '/js/public-runtime-loader.js');
$e2e = file_get_contents($root . '/tests/e2e/public-mobile-performance.spec.mjs');

$fail = static function (string $message): never {
    fwrite(STDERR, "Theme Studio reliability contract failed: {$message}\n");
    exit(1);
};
$mustContain = static function (string $haystack, string $needle, string $message) use ($fail): void {
    if (!str_contains($haystack, $needle)) $fail($message . " (missing {$needle})");
};
$mustNotContain = static function (string $haystack, string $needle, string $message) use ($fail): void {
    if (str_contains($haystack, $needle)) $fail($message . " (found {$needle})");
};

$mustContain($adminEntry, '/discadmin/theme-studio-reliability.css', 'DISCADMIN must load degraded-source Theme Studio styles');
$mustContain($adminEntry, '/discadmin/theme-studio-reliability.js', 'DISCADMIN must load Theme Studio reliability after V2');
$mustContain($reliability, 'Promise.allSettled', 'Settings and Media must be loaded independently');
$mustContain($reliability, 'SETTINGS UNAVAILABLE', 'Settings failure must render an explicit unavailable state');
$mustContain($reliability, 'No default theme is editable in this state', 'Settings failure must not become an editable fallback');
$mustContain($reliability, 'MEDIA UNAVAILABLE', 'Media-only failure must be visible without discarding Settings');
$mustContain($reliability, "button.disabled = true", 'Media picker actions must be disabled when Media is unavailable');
$mustContain($reliability, "beforeunload", 'unsaved Theme Studio changes must be protected on page exit');
$mustContain($reliability, "Discard unsaved Theme Studio changes?", 'module navigation must confirm discarding dirty Theme Studio edits');
$mustContain($reliability, "typeof state !== 'undefined'", 'leave guard must use the canonical shell state');
$mustNotContain($reliability, "state.themeSettings = []", 'reliability layer must not convert Settings load failure into an empty authoritative collection');
$mustContain($reliabilityCss, '.tsv2-source-state', 'unavailable source state must be styled');

$mustContain($loader, "'js/public-theme-branding-sync.js'", 'public loader must include branding synchronization');
$mustContain($brandingSync, '.hero-logo-glitch', 'theme logo must synchronize the legacy glitch overlay');
$mustContain($brandingSync, "source.srcset = logo", 'responsive picture sources must use the active theme logo');
$mustContain($brandingSync, 'branding.mobileLogo', 'mobile logo must remain first-class below the breakpoint');
$mustContain($brandingSync, 'BRVTAL_PUBLIC_VERSION', 'favicon/preloader assets must support deploy-version cache busting');
$mustContain($brandingSync, 'data-theme-favicon', 'favicon must be updated through the managed theme surface');
$mustContain($brandingSync, '.theme-preloader-logo', 'preloader branding must stay synchronized with the selected asset');

$mustContain($loader, 'loadSequenceResilient', 'local public modules must fail independently');
$mustContain($loader, 'revealStaticFallback', 'runtime failure must reveal static HTML');
$mustContain($loader, "document.getElementById('loader')?.remove()", 'runtime fallback must never leave the preloader blocking the site');
$mustContain($loader, "dataset.runtimeIntegrity = 'degraded'", 'local module failures must be observable as degraded runtime');
$mustContain($e2e, "failLocal:'/js/menu-scroll-lock.js'", 'Chromium must exercise a failed local core script');
$mustContain($e2e, "data-runtime-fallback", 'Chromium must verify the static fallback signal');
$mustContain($e2e, "theme-branding-sync", 'Chromium must include the branding synchronization module in runtime order');

echo "Theme Studio reliability contract passed.\n";
