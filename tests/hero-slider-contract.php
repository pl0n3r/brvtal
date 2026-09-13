<?php
declare(strict_types=1);

$endpoint = file_get_contents(__DIR__ . '/../api/hero-slider.php');
$public = file_get_contents(__DIR__ . '/../js/hero-slider.js');
$admin = file_get_contents(__DIR__ . '/../discadmin/hero-slider.js');
$publicCss = file_get_contents(__DIR__ . '/../css/hero-slider.css');
$adminCss = file_get_contents(__DIR__ . '/../discadmin/hero-slider.css');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "Hero Slider contract failed: {$message}\n");
        exit(1);
    }
};

$assert(str_contains($endpoint, "setting_key = ?"), 'public endpoint must use a prepared setting lookup');
$assert(str_contains($endpoint, "home.hero.slider"), 'public endpoint must target only the hero setting');
$assert(!str_contains($endpoint, 'SELECT * FROM settings'), 'public endpoint must not expose raw settings');
$assert(str_contains($public, 'hero-slider-active'), 'public runtime must activate only after valid data');
$assert(str_contains($public, 'prefers-reduced-motion'), 'public runtime must respect reduced motion');
$assert(str_contains($admin, "const KEY = 'home.hero.slider'"), 'admin must persist to the canonical hero setting');
$assert(str_contains($admin, 'mobileSrc'), 'admin must support mobile media override');
$assert(str_contains($publicCss, 'min-height:44px') || str_contains($publicCss, 'height:44px'), 'public controls must preserve touch targets');
$assert(str_contains($adminCss, 'min-height:44px'), 'admin controls must preserve touch targets');

fwrite(STDOUT, "Hero Slider contract OK\n");
