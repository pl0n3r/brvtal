<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/public_routes.php';

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
public_sitemap_expect(
    $definitions['sets']['table'] === 'sets_media' && $definitions['blog']['table'] === 'blog_posts',
    'Route keys must stay decoupled from their canonical storage table names.'
);
public_sitemap_expect(
    $definitions['events']['event_visibility'] === true
        && $definitions['events']['parameters'] === brvtal_public_visible_event_statuses(),
    'Events must keep using the canonical public lifecycle visibility policy.'
);

$sitemap = (string) file_get_contents(__DIR__ . '/../sitemap.php');
public_sitemap_expect(
    str_contains($sitemap, 'brvtal_public_content_definitions()')
        && str_contains($sitemap, 'brvtal_public_static_routes()'),
    'The sitemap renderer must consume the canonical dynamic public registries.'
);
public_sitemap_expect(
    !str_contains($sitemap, "Cache-Control: public, max-age=900")
        && str_contains($sitemap, 'Cache-Control: no-cache, must-revalidate'),
    'The public sitemap must revalidate instead of serving the old 15-minute snapshot.'
);
public_sitemap_expect(
    str_contains($sitemap, "Content-Type: application/xml; charset=utf-8")
        && str_contains($sitemap, '<?xml version='),
    'The canonical sitemap response must remain XML.'
);

$seo = (string) file_get_contents(__DIR__ . '/../config/public_seo.php');
public_sitemap_expect(
    str_contains($seo, "require_once __DIR__ . '/public_routes.php';")
        && str_contains($seo, '$definitions = brvtal_public_content_definitions();'),
    'Public entity SEO must use the same content-family registry as the sitemap.'
);

$htaccess = (string) file_get_contents(__DIR__ . '/../.htaccess');
public_sitemap_expect(
    str_contains($htaccess, 'RewriteCond %{THE_REQUEST} \\s/+sitemap\\.php(?:[?\\s]) [NC]')
        && str_contains($htaccess, 'RewriteRule ^sitemap\\.php$ /sitemap.xml [R=301,L,NE]')
        && str_contains($htaccess, 'RewriteRule ^sitemap\\.xml$ sitemap.php [L,QSA]'),
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
public_sitemap_expect(
    str_contains($robots, 'Sitemap: https://www.brvtal.com.co/sitemap.xml')
        && !str_contains($robots, 'sitemap.php'),
    'robots.txt must advertise only the canonical XML sitemap URL.'
);

echo "Public sitemap contract passed.\n";
