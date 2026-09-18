<?php
declare(strict_types=1);

require_once __DIR__ . '/public_sitemap.php';

const BRVTAL_ROBOTS_CANONICAL_HOST = 'www.brvtal.com.co';

/** Accept only the canonical HTTPS production host representation used by BRVTAL. */
function brvtal_public_robots_host_is_canonical(string $host): bool
{
    return preg_match(
        '/\Awww\.brvtal\.com\.co(?::443)?\z/i',
        trim($host)
    ) === 1;
}

/** Build the canonical production robots policy without depending on the database. */
function brvtal_public_robots_body(string $host): string
{
    if (!brvtal_public_robots_host_is_canonical($host)) {
        return "User-agent: *\nDisallow: /\n";
    }

    return implode("\n", [
        'User-agent: *',
        'Allow: /',
        'Allow: /api/public.php',
        'Disallow: /discadmin/',
        'Disallow: /api/',
        'Disallow: /config/',
        'Disallow: /database/',
        'Disallow: /storage/',
        'Disallow: /.private/',
        'Disallow: /tests/',
        'Disallow: /scripts/',
        '',
        'Sitemap: ' . BRVTAL_SITEMAP_CANONICAL_ORIGIN . '/sitemap.xml',
        '',
    ]);
}

/**
 * Build headers and body for the dynamic robots endpoint.
 *
 * @return array{headers:list<string>,body:string}
 */
function brvtal_public_robots_response(string $host): array
{
    $canonical = brvtal_public_robots_host_is_canonical($host);

    return [
        'headers' => [
            'Content-Type: text/plain; charset=utf-8',
            $canonical
                ? 'Cache-Control: public, max-age=300, stale-while-revalidate=3600'
                : 'Cache-Control: no-store',
            'Vary: Host',
        ],
        'body' => brvtal_public_robots_body($host),
    ];
}
