<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/public_routes.php';
require_once __DIR__ . '/../config/public_sitemap.php';

/** Fail when the canonical public sitemap contract drifts. */
function public_sitemap_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "PUBLIC SITEMAP CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$definitions = brvtal_public_content_definitions();
$expectedRoutes = ['events', 'artists', 'sets', 'releases', 'blog', 'pages'];
public_sitemap_expect(
    array_keys($definitions) === $expectedRoutes,
    'The canonical public content registry must cover every routed indexable content family.'
);
public_sitemap_expect(
    brvtal_public_static_routes() === ['/', '/contact'],
    'The sitemap static-route registry must cover the public home and contact pages.'
);
$canonicalStorageTables = $definitions['sets']['table'] === 'sets_media'
    && $definitions['blog']['table'] === 'blog_posts';
public_sitemap_expect(
    $canonicalStorageTables,
    'Route keys must stay decoupled from their canonical storage table names.'
);
$eventDefinitionIsCanonical = $definitions['events']['event_visibility'] === true
    && $definitions['events']['parameters'] === brvtal_public_visible_event_statuses();
public_sitemap_expect(
    $eventDefinitionIsCanonical,
    'Events must keep using the canonical public lifecycle visibility policy.'
);

$staticUrls = brvtal_public_sitemap_static_urls(
    BRVTAL_SITEMAP_CANONICAL_ORIGIN,
    brvtal_public_static_routes()
);
$response = brvtal_public_sitemap_response($staticUrls, BRVTAL_SITEMAP_CANONICAL_ORIGIN);
public_sitemap_expect(
    $response['headers'] === [
        'Content-Type: application/xml; charset=utf-8',
        'Cache-Control: no-cache, must-revalidate',
    ],
    'Healthy sitemap responses must remain XML and revalidate before reuse.'
);
public_sitemap_expect(
    str_contains(
        $response['body'],
        '<loc>https://www.brvtal.com.co/contact</loc>'
    ),
    'The executable sitemap URL boundary must include the canonical Contact route.'
);
public_sitemap_expect(
    BRVTAL_SITEMAP_NAMESPACE === 'http://www.sitemaps.org/schemas/sitemap/0.9',
    'The Sitemap protocol namespace must remain canonical.'
);

$htaccess = (string) file_get_contents(__DIR__ . '/../.htaccess');
$canonicalXmlRewrite = str_contains(
    $htaccess,
    'RewriteCond %{THE_REQUEST} \\s/+sitemap\\.php(?:[?\\s]) [NC]'
) && str_contains(
    $htaccess,
    'RewriteRule ^sitemap\\.php$ /sitemap.xml [R=301,L,NE]'
) && str_contains(
    $htaccess,
    'RewriteRule ^sitemap\\.xml$ sitemap.php [L,QSA]'
);
public_sitemap_expect(
    $canonicalXmlRewrite,
    'Direct PHP sitemap requests must redirect to the canonical XML URL before internal rendering.'
);

$routePattern = '/RewriteRule \\^\\(([^)]+)\\)\\/\\(\\[a-z0-9-\\]\\+\\)\\/\\?\\$/';
public_sitemap_expect(
    preg_match($routePattern, $htaccess, $matches) === 1,
    'The public entity rewrite rule must remain discoverable by the sitemap contract.'
);
$rewriteRoutes = explode('|', $matches[1]);
public_sitemap_expect(
    $rewriteRoutes === $expectedRoutes,
    'The web-server public route list must stay synchronized with the canonical content registry.'
);

$robots = (string) file_get_contents(__DIR__ . '/../robots.txt');
$robotsUsesXml = str_contains(
    $robots,
    'Sitemap: https://www.brvtal.com.co/sitemap.xml'
) && !str_contains(
    $robots,
    'sitemap.php'
);
public_sitemap_expect(
    $robotsUsesXml,
    'robots.txt must advertise only the canonical XML sitemap URL.'
);

echo "Public sitemap contract passed.\n";
