<?php
declare(strict_types=1);

const BRVTAL_SITEMAP_NAMESPACE = 'http://www.sitemaps.org/schemas/sitemap/0.9';

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

/** Keep only absolute canonical-site URLs that are safe to publish in the sitemap. */
function brvtal_public_sitemap_location(string $location, string $base): ?string
{
    $location = trim($location);
    $locationParts = parse_url($location);
    $baseParts = parse_url($base);
    $hasValidShape = $location !== ''
        && strlen($location) < 2048
        && is_array($locationParts)
        && is_array($baseParts);
    if (!$hasValidShape) {
        return null;
    }

    $locationScheme = strtolower((string) ($locationParts['scheme'] ?? ''));
    $baseScheme = strtolower((string) ($baseParts['scheme'] ?? ''));
    $locationHost = strtolower((string) ($locationParts['host'] ?? ''));
    $baseHost = strtolower((string) ($baseParts['host'] ?? ''));
    $isCanonical = $baseScheme === 'https'
        && $locationScheme === $baseScheme
        && $locationHost !== ''
        && $locationHost === $baseHost;

    return $isCanonical ? $location : null;
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
