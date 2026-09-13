<?php
declare(strict_types=1);

function brvtal_public_version_assets(string $html, string $version): string
{
    if ($version === '') return $html;

    return preg_replace_callback(
        '~(?<prefix>(?:href|src)="(?:css|js)/[^"?]+)(?:\?[^"#]*)?(?<suffix>")~',
        static fn(array $match): string => $match['prefix'] . '?v=' . rawurlencode($version) . $match['suffix'],
        $html
    ) ?? $html;
}

function brvtal_public_optimize_font_stylesheet(string $html): string
{
    $href = 'https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;500;600;700;800;900&family=Space+Mono:wght@400;700&display=swap';
    $blocking = '<link href="' . $href . '" rel="stylesheet">';
    if (!str_contains($html, $blocking)) return $html;

    $preload = '<link rel="preload" href="' . $href . '" as="style" onload="this.onload=null;this.rel=\'stylesheet\'">';
    $fallback = '<noscript><link href="' . $href . '" rel="stylesheet"></noscript>';

    return str_replace($blocking, $preload . "\n  " . $fallback, $html);
}

function brvtal_public_optimize_home_images(string $html): string
{
    return preg_replace_callback(
        '~<img\b[^>]*>~i',
        static function (array $match): string {
            $tag = $match[0];
            $isHeroLogo = preg_match('~class="[^"]*\bhero-logo\b[^"]*"~i', $tag) === 1;
            $attributes = [];

            if (preg_match('~\bloading\s*=~i', $tag) !== 1) {
                $attributes[] = 'loading="' . ($isHeroLogo ? 'eager' : 'lazy') . '"';
            }
            if ($isHeroLogo && preg_match('~\bfetchpriority\s*=~i', $tag) !== 1) {
                $attributes[] = 'fetchpriority="high"';
            }
            if (preg_match('~\bdecoding\s*=~i', $tag) !== 1) {
                $attributes[] = 'decoding="async"';
            }
            if (!$attributes) return $tag;

            return preg_replace('~^<img\b~i', '<img ' . implode(' ', $attributes), $tag, 1) ?? $tag;
        },
        $html
    ) ?? $html;
}
