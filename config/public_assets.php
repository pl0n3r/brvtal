<?php
declare(strict_types=1);

function brvtal_public_version_assets(string $html, string $version): string
{
    if ($version === '') return $html;

    return preg_replace_callback(
        '~(?<prefix>(?:href|src)="/?(?:css|js|assets|uploads)/[^"?]+)(?:\?[^"#]*)?(?<suffix>")~',
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

function brvtal_public_defer_stylesheets(string $html, array $paths): string
{
    $targets = [];
    foreach ($paths as $path) {
        $path = ltrim(trim((string)$path), '/');
        if ($path !== '') $targets[$path] = true;
    }
    if (!$targets) return $html;

    return preg_replace_callback(
        '~<link\b(?=[^>]*\brel="stylesheet")(?=[^>]*\bhref="([^"]+)")[^>]*>~i',
        static function (array $match) use ($targets): string {
            $tag = $match[0];
            $href = (string)$match[1];
            $path = parse_url($href, PHP_URL_PATH);
            $path = is_string($path) ? ltrim($path, '/') : '';
            if (!isset($targets[$path])) return $tag;

            return '<link rel="stylesheet" href="' . $href . '" media="print" onload="this.media=\'all\'">'
                . '<noscript><link rel="stylesheet" href="' . $href . '"></noscript>';
        },
        $html
    ) ?? $html;
}

function brvtal_public_local_image_dimensions(string $src): ?array
{
    static $cache = [];

    $src = trim(html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($src === '' || str_contains($src, "\0")) return null;
    if (array_key_exists($src, $cache)) return $cache[$src];

    $parts = parse_url($src);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
        return $cache[$src] = null;
    }

    $path = ltrim((string)($parts['path'] ?? ''), '/');
    if ($path === '' || str_contains($path, '..')) {
        return $cache[$src] = null;
    }
    if (!str_starts_with($path, 'assets/') && !str_starts_with($path, 'uploads/')) {
        return $cache[$src] = null;
    }

    $root = realpath(dirname(__DIR__));
    $absolute = realpath(dirname(__DIR__) . '/' . $path);
    if ($root === false || $absolute === false) {
        return $cache[$src] = null;
    }

    $root = rtrim(str_replace('\\', '/', $root), '/');
    $absolute = str_replace('\\', '/', $absolute);
    if ($absolute !== $root && !str_starts_with($absolute, $root . '/')) {
        return $cache[$src] = null;
    }

    $info = @getimagesize($absolute);
    if (!is_array($info) || (int)($info[0] ?? 0) < 1 || (int)($info[1] ?? 0) < 1) {
        return $cache[$src] = null;
    }

    return $cache[$src] = [
        'width' => (int)$info[0],
        'height' => (int)$info[1],
    ];
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

            $hasWidth = preg_match('~\bwidth\s*=~i', $tag) === 1;
            $hasHeight = preg_match('~\bheight\s*=~i', $tag) === 1;
            if ((!$hasWidth || !$hasHeight) && preg_match('~\bsrc\s*=\s*(["\'])(.*?)\1~i', $tag, $srcMatch) === 1) {
                $dimensions = brvtal_public_local_image_dimensions((string)$srcMatch[2]);
                if (is_array($dimensions)) {
                    if (!$hasWidth) $attributes[] = 'width="' . $dimensions['width'] . '"';
                    if (!$hasHeight) $attributes[] = 'height="' . $dimensions['height'] . '"';
                }
            }

            if (!$attributes) return $tag;

            return preg_replace('~^<img\b~i', '<img ' . implode(' ', $attributes), $tag, 1) ?? $tag;
        },
        $html
    ) ?? $html;
}
