<?php
declare(strict_types=1);

const BRVTAL_SITEMAP_NAMESPACE = 'http://www.sitemaps.org/schemas/sitemap/0.9';
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
 * Render a Google-compatible Sitemap XML document.
 *
 * @param list<array{0:string,1:mixed}> $urls
 */
function brvtal_public_sitemap_xml(array $urls, string $base): string
{
    $lines = [
        '<?xml version="1.0" encoding="UTF-8"?>',
        '<urlset xmlns="' . BRVTAL_SITEMAP_NAMESPACE . '">',
    ];

    foreach ($urls as [$location, $modified]) {
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
