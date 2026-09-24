<?php
declare(strict_types=1);

$endpoint = file_get_contents(__DIR__ . '/../api/hero-slider.php');
$public = file_get_contents(__DIR__ . '/../js/hero-slider.js');
$admin = file_get_contents(__DIR__ . '/../discadmin/hero-slider.js');
$publicIndex = file_get_contents(__DIR__ . '/../index.php');
$adminIndex = file_get_contents(__DIR__ . '/../discadmin/index.php');
$publicCss = file_get_contents(__DIR__ . '/../css/hero-slider.css');
$publicV2Css = file_get_contents(__DIR__ . '/../css/hero-slider-v2.css');
$adminCss = file_get_contents(__DIR__ . '/../discadmin/hero-slider.css');
$adminV2Css = file_get_contents(__DIR__ . '/../discadmin/hero-slider-v2.css');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "Hero Slider contract failed: {$message}\n");
        exit(1);
    }
};

$assert(str_contains($endpoint, "setting_key = ?"), 'public endpoint must use a prepared setting lookup');
$assert(str_contains($endpoint, "home.hero.slider"), 'public endpoint must target only the hero setting');
$assert(!str_contains($endpoint, 'SELECT * FROM settings'), 'public endpoint must not expose raw settings');
$assert(str_contains($endpoint, "['text','image','logo','cta']"), 'public endpoint must allowlist layer types');
$assert(str_contains($endpoint, 'array_slice') && str_contains($endpoint, ', 0, 12'), 'public endpoint must cap layers per slide');
$assert(str_contains($public, 'hero-slider-active'), 'public runtime must activate only after valid data');
$assert(str_contains($public, 'prefers-reduced-motion'), 'public runtime must respect reduced motion');
$assert(str_contains($public, 'brvtal-hero-layer'), 'public runtime must render sanitized visual layers');
$assert(str_contains($public, 'hiddenMobile'), 'public runtime must honor per-layer mobile visibility');
$assert(str_contains($admin, "const KEY = 'home.hero.slider'"), 'admin must persist to the canonical hero setting');
$assert(str_contains($admin, 'mobileSrc'), 'admin must support mobile media override');
$assert(str_contains($admin, 'data-add-layer'), 'admin must expose constrained visual layer creation');
$assert(str_contains($admin, 'pointerdown'), 'admin preview must support direct pointer positioning');
$assert(str_contains($admin, 'data-duplicate-slide'), 'admin must support safe slide duplication');
$assert(str_contains($admin, 'SETTINGS_READ_ATTEMPTS = 2'), 'admin Banners settings read must use one bounded retry');
$assert(str_contains($admin, 'recoverWorkspaceHost(revision)'), 'admin Banners load must recover its host only while the same navigation revision is active');
$assert(str_contains($admin, 'BRVTALHeroSliderDiagnostics'), 'admin Banners load must expose bounded production diagnostics');
$assert(str_contains($publicIndex, 'hero-slider-v2.css') && str_contains($publicIndex, 'data-hero-v2-public'), 'public wrapper must deliver v2 styles before runtime mount');
$assert(str_contains($adminIndex, 'hero-slider-v2.css') && str_contains($adminIndex, 'data-hero-v2'), 'admin wrapper must deliver v2 styles before editor mount');
$assert(str_contains($publicCss, 'min-height:44px') || str_contains($publicCss, 'height:44px'), 'public controls must preserve touch targets');
$assert(str_contains($publicV2Css, 'min-height:44px'), 'public v2 CTA must preserve touch targets');
$assert(str_contains($adminCss, 'min-height:44px'), 'admin controls must preserve touch targets');
$assert(str_contains($adminV2Css, 'min-height:44px'), 'admin v2 layer controls must preserve touch targets');

fwrite(STDOUT, "Hero Slider v2 contract OK\n");
