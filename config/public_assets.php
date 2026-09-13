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
