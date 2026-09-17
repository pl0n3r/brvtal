<?php
declare(strict_types=1);

$seo = file_get_contents(__DIR__ . '/../discadmin/seo-editorial-defaults.js');
$measurement = file_get_contents(__DIR__ . '/../js/public-measurement.js');
$theme = file_get_contents(__DIR__ . '/../discadmin/theme-studio-v2.js');
if ($seo === false || $measurement === false || $theme === false) {
    fwrite(STDERR, "Could not read S8786 source files.\n");
    exit(1);
}

$themeCompact = preg_replace('/\s+/', '', $theme);
if ($themeCompact === null) {
    fwrite(STDERR, "Could not normalize Theme Studio source.\n");
    exit(1);
}

$failures = [];

if (str_contains($seo, "cut.replace(/\\s+\\S*$/")) {
    $failures[] = 'SEO truncation must not restore the backtracking word-boundary regex.';
}
if (str_contains($seo, "replace(/[\\s,.;:-]+$/g")) {
    $failures[] = 'SEO truncation must not restore the backtracking suffix regex.';
}
if (!str_contains($seo, "cut.lastIndexOf(' ')")) {
    $failures[] = 'SEO truncation must keep the linear last-space boundary lookup.';
}
if (!str_contains($seo, 'function trimSeoSuffix(value)')) {
    $failures[] = 'SEO truncation must keep the linear suffix cleanup helper.';
}

if (str_contains($measurement, "location.pathname.replace(/\\/+$/")) {
    $failures[] = 'Public measurement must not restore the trailing-slash regex.';
}
if (!str_contains($measurement, 'function trimTrailingSlashes(value)')) {
    $failures[] = 'Public measurement must keep the linear trailing-slash helper.';
}

if (str_contains($themeCompact, 'replace(/^-+|-+$/')) {
    $failures[] = 'Theme Studio slug cleanup must not restore the edge-dash regex, regardless of flags or formatting.';
}
if (!str_contains($theme, 'function trimEdgeDashes(value)')) {
    $failures[] = 'Theme Studio must keep the linear edge-dash cleanup helper.';
}
if (!str_contains($themeCompact, "value.startsWith('-',start)")) {
    $failures[] = 'Theme Studio edge cleanup must scan leading dashes linearly.';
}
if (!str_contains($themeCompact, "value.endsWith('-',end)")) {
    $failures[] = 'Theme Studio edge cleanup must scan trailing dashes linearly.';
}
if (!str_contains($themeCompact, 'safeSlug=value=>trimEdgeDashes(')) {
    $failures[] = 'Theme Studio safeSlug must use the linear edge-dash cleanup helper.';
}

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "Sonar regex reliability contract passed.\n";
