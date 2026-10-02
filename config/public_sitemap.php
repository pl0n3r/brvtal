<?php
declare(strict_types=1);

require_once __DIR__ . '/public_i18n_routing.php';
require_once __DIR__ . '/public_translation_overlay.php';

const BRVTAL_SITEMAP_NAMESPACE = 'http://www.sitemaps.org/schemas/sitemap/0.9';
const BRVTAL_SITEMAP_XHTML_NAMESPACE = 'http://www.w3.org/1999/xhtml';
const BRVTAL_SITEMAP_CANONICAL_ORIGIN = 'https://www.brvtal.com.co';

/** Normalize a sitemap last-modified value to the W3C date form Google accepts. */
function brvtal_public_sitemap_lastmod(mixed $modified): ?string
{
    $value = trim((string) $modified);
    $date = substr($value, 0, 10);
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $errors = DateTimeImmutable::getLastErrors();
    $hasDateErrors = $errors !== false
        && ($errors['warning_count'] > 0 || $errors['error_count'] > 0);
    $isValid = $value !== ''
        && $parsed !== false
        && !$hasDateErrors
        && $parsed->format('Y-m-d') === $date;

    return $isValid ? $date : null;
}

/** Require the one public BRVTAL origin, without alternate hosts or ports. */
function brvtal_public_sitemap_origin_is_canonical(string $origin): bool
{
    $parts = parse_url(trim($origin));
    if (!is_array($parts)) {
        return false;
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    $port = (int) ($parts['port'] ?? 443);
    $path = (string) ($parts['path'] ?? '');
    $hasAuthorityExtras = isset($parts['user']) || isset($parts['pass']);
    $hasSuffix = isset($parts['query']) || isset($parts['fragment']);

    return $scheme === 'https'
        && $host === 'www.brvtal.com.co'
        && $port === 443
        && ($path === '' || $path === '/')
        && !$hasAuthorityExtras
        && !$hasSuffix;
}

/** Keep only absolute canonical-site URLs that are safe to publish in the sitemap. */
function brvtal_public_sitemap_location(string $location, string $base): ?string
{
    $location = trim($location);
    $locationParts = parse_url($location);
    $hasValidShape = $location !== ''
        && strlen($location) < 2048
        && is_array($locationParts)
        && brvtal_public_sitemap_origin_is_canonical($base);
    if (!$hasValidShape) {
        return null;
    }

    $locationScheme = strtolower((string) ($locationParts['scheme'] ?? ''));
    $locationHost = strtolower((string) ($locationParts['host'] ?? ''));
    $locationPort = (int) ($locationParts['port'] ?? 443);
    $hasAuthorityExtras = isset($locationParts['user']) || isset($locationParts['pass']);
    $isCanonical = $locationScheme === 'https'
        && $locationHost === 'www.brvtal.com.co'
        && $locationPort === 443
        && !$hasAuthorityExtras
        && !isset($locationParts['fragment']);

    return $isCanonical ? $location : null;
}

/**
 * Build sitemap rows for public static routes without coupling tests to the endpoint script.
 *
 * @param list<string> $paths
 * @return list<array{0:string,1:null}>
 */
function brvtal_public_sitemap_static_urls(string $base, array $paths): array
{
    $origin = rtrim($base, '/');
    $urls = [];
    foreach ($paths as $path) {
        $normalizedPath = $path === '/' ? '/' : '/' . ltrim($path, '/');
        $urls[] = [$origin . $normalizedPath, null];
    }
    return $urls;
}

/**
 * Build localized static sitemap entries while preserving Spanish canonicals.
 *
 * @param list<string> $paths
 * @param list<string> $locales
 * @return list<array{0:string,1:null,2:array{es:string,en:string,x-default:string}}>
 */
function brvtalPublicSitemapLocalizedStaticUrls(
    string $base,
    array $paths,
    array $locales = ['es', 'en']
): array {
    if (!brvtal_public_sitemap_origin_is_canonical($base)) {
        return [];
    }

    $rows = [];
    foreach ($paths as $path) {
        $page = $path === '/contact' ? 'contact' : '';
        if ($path !== '/' && $page === '') {
            continue;
        }

        foreach ($locales as $localeInput) {
            $locale = brvtalPublicI18nNormalizeLocale($localeInput);
            if ($locale === null) {
                continue;
            }
            $route = brvtalPublicLocalizedRouteUrls($base, $locale, '', '', $page);
            if ($route === null) {
                continue;
            }
            $rows[] = [
                $route['canonical'],
                null,
                $route['alternates'],
            ];
        }
    }
    return $rows;
}

/**
 * Build localized sitemap rows from one canonical published editorial source.
 * Translation resolution is intentionally not performed here.
 *
 * @param array<string,mixed> $source
 * @param list<string> $locales
 * @return list<array{0:string,1:mixed,2:array{es:string,en:string,x-default:string}}>
 */
function brvtalPublicSitemapLocalizedEntityUrls(
    string $base,
    array $source,
    mixed $modified = null,
    array $locales = ['es', 'en']
): array {
    if (!brvtal_public_sitemap_origin_is_canonical($base)) {
        return [];
    }
    if (!array_key_exists('status', $source)) {
        return [];
    }
    if (!brvtalPublicEditorialOverlayEligible($source, true, true)) {
        return [];
    }

    $routeType = trim((string)($source['route_type'] ?? ''));
    $slug = trim((string)($source['slug'] ?? ''));
    $rows = [];
    $seen = [];

    foreach ($locales as $localeInput) {
        $locale = brvtalPublicI18nNormalizeLocale($localeInput);
        if ($locale === null) {
            continue;
        }

        $route = brvtalPublicLocalizedRouteUrls(
            $base,
            $locale,
            $routeType,
            $slug
        );
        if ($route === null) {
            continue;
        }

        $canonical = brvtal_public_sitemap_location(
            (string)$route['canonical'],
            $base
        );
        if ($canonical === null || isset($seen[$canonical])) {
            continue;
        }

        $seen[$canonical] = true;
        $rows[] = [
            $canonical,
            $modified,
            $route['alternates'],
        ];
    }

    return $rows;
}

/**
 * Build localized sitemap rows from already-fetched canonical content rows.
 *
 * @param list<array<string,mixed>> $rows
 * @return list<array{0:string,1:mixed,2:array{es:string,en:string,x-default:string}}>
 */
function brvtalPublicSitemapLocalizedContentUrls(
    string $base,
    string $route,
    array $rows,
    bool $requiresEventVisibility
): array {
    $urls = [];
    foreach ($rows as $row) {
        if ($requiresEventVisibility && !brvtal_public_event_is_visible($row)) {
            continue;
        }

        $source = $row;
        $source['route_type'] = $route;
        foreach (
            brvtalPublicSitemapLocalizedEntityUrls(
                $base,
                $source,
                $row['updated_at'] ?? null
            ) as $localizedRow
        ) {
            $urls[] = $localizedRow;
        }
    }
    return $urls;
}


/**
 * Render a Google-compatible Sitemap XML document.
 *
 * @param list<array{0:string,1:mixed}> $urls
 */
function brvtal_public_sitemap_xml(array $urls, string $base): string
{
    $lines = [
        '<?xml version="1.0" encoding="UTF-8"?>',
        '<urlset xmlns="' . BRVTAL_SITEMAP_NAMESPACE . '" xmlns:xhtml="' . BRVTAL_SITEMAP_XHTML_NAMESPACE . '">',
    ];

    foreach ($urls as $row) {
        $location = $row[0] ?? '';
        $modified = $row[1] ?? null;
        $alternates = is_array($row[2] ?? null) ? $row[2] : [];
        $canonicalLocation = brvtal_public_sitemap_location((string) $location, $base);
        if ($canonicalLocation === null) {
            continue;
        }

        $escapedLocation = htmlspecialchars(
            $canonicalLocation,
            ENT_XML1 | ENT_QUOTES,
            'UTF-8'
        );
        $entry = '  <url><loc>' . $escapedLocation . '</loc>';
        $lastmod = brvtal_public_sitemap_lastmod($modified);
        if ($lastmod !== null) {
            $entry .= '<lastmod>' . $lastmod . '</lastmod>';
        }

        foreach (['es', 'en', 'x-default'] as $hreflang) {
            $alternate = brvtal_public_sitemap_location(
                (string)($alternates[$hreflang] ?? ''),
                $base
            );
            if ($alternate === null) {
                continue;
            }
            $entry .= '<xhtml:link rel="alternate" hreflang="'
                . $hreflang
                . '" href="'
                . htmlspecialchars($alternate, ENT_XML1 | ENT_QUOTES, 'UTF-8')
                . '"/>';
        }
        $lines[] = $entry . '</url>';
    }

    $lines[] = '</urlset>';
    return implode("\n", $lines) . "\n";
}

/**
 * Build the healthy sitemap response policy and body as one executable boundary.
 *
 * @param list<array{0:string,1:mixed}> $urls
 * @return array{headers:list<string>,body:string}
 */
function brvtal_public_sitemap_response(array $urls, string $base): array
{
    return [
        'headers' => [
            'Content-Type: application/xml; charset=utf-8',
            'Cache-Control: no-cache, must-revalidate',
        ],
        'body' => brvtal_public_sitemap_xml($urls, $base),
    ];
}
