<?php
declare(strict_types=1);

function public_seo_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "PUBLIC SEO DELIVERY CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$router = (string)file_get_contents(__DIR__ . '/../.htaccess');
$renderer = (string)file_get_contents(__DIR__ . '/../index.php');
$seo = (string)file_get_contents(__DIR__ . '/../config/public_seo.php');
$routes = (string)file_get_contents(__DIR__ . '/../config/public_routes.php');
$sitemap = (string)file_get_contents(__DIR__ . '/../sitemap.php');
$pages = (string)file_get_contents(__DIR__ . '/../config/public_page.php');
$notFound = (string)file_get_contents(__DIR__ . '/../config/public_not_found.php');
$robots = (string)file_get_contents(__DIR__ . '/../robots.txt');
$html = (string)file_get_contents(__DIR__ . '/../index.html');

foreach (['events','artists','sets','releases','blog','pages'] as $route) {
    public_seo_expect(str_contains($router, $route), "pretty URL routing must support {$route}");
}
foreach (['canonical','og:title','og:description','og:image','twitter:card','application/ld+json'] as $marker) {
    public_seo_expect(str_contains($seo, $marker), "server-rendered metadata must include {$marker}");
}
foreach (['MusicEvent','MusicGroup','MusicRecording','MusicAlbum','BlogPosting','WebPage'] as $type) {
    public_seo_expect(str_contains($routes, $type), "canonical public route registry must support {$type} structured data");
}
public_seo_expect(str_contains($renderer, "http_response_code(404)"), 'unknown or private entities must return HTTP 404');
public_seo_expect(str_contains($renderer, 'X-Robots-Tag: noindex, follow'), 'not-found entity routes must be excluded from indexing');
public_seo_expect(str_contains($renderer, 'brvtal_public_not_found_seo($baseUrl, $type, $slug)'), 'invalid entity routes must receive dedicated not-found SEO');
public_seo_expect(str_contains($renderer, 'brvtal_public_not_found_page($seo, $analytics)'), 'invalid entity routes must render the dedicated not-found page');
$notFoundRenderPos = strpos($renderer, 'echo brvtal_public_not_found_page($seo, $analytics)');
$homeLoadPos = strpos($renderer, "file_get_contents(__DIR__ . '/index.html')");
public_seo_expect($notFoundRenderPos !== false && $homeLoadPos !== false && $notFoundRenderPos < $homeLoadPos, 'not-found response must terminate before Home HTML is loaded');
public_seo_expect(str_contains($notFound, '<meta name="robots" content="noindex, follow">'), 'not-found HTML must carry an explicit noindex directive');
public_seo_expect(str_contains($notFound, 'RESOURCE NOT FOUND'), 'not-found HTML must clearly identify the missing resource');
public_seo_expect(str_contains($notFound, 'BACK HOME'), 'not-found HTML must provide a clear recovery route');
public_seo_expect(str_contains($router, 'sitemap.php'), 'sitemap.xml must be served dynamically');
public_seo_expect(str_contains($sitemap, '<urlset'), 'sitemap must use the standard XML URL set');
public_seo_expect(str_contains($seo, 'brvtal_public_event_is_visible($row)'), 'canonical Event resolver must apply publication-proof visibility');
public_seo_expect(str_contains($seo, 'status,event_date,published_at'), 'canonical Event resolver must hydrate lifecycle evidence used by visibility policy');
public_seo_expect(str_contains($sitemap, 'brvtal_public_event_is_visible($row)'), 'sitemap must apply the same canonical Event visibility predicate');
public_seo_expect(str_contains($sitemap, 'status,event_date,published_at'), 'sitemap Event rows must include publication-proof lifecycle fields');
public_seo_expect(str_contains($pages, 'brvtal_page_public_event_rows'), 'entity relationships must filter Event rows through canonical visibility');
public_seo_expect(str_contains($pages, "$route !== 'events' || brvtal_public_event_is_visible($item)"), 'Blog Event relationships must apply canonical visibility');
public_seo_expect(str_contains($robots, 'Sitemap: https://www.brvtal.com.co/sitemap.xml'), 'robots must advertise the canonical sitemap');
public_seo_expect(str_contains($html, '<base href="/">'), 'nested public routes must resolve assets from the site root');

require_once __DIR__ . '/../config/public_seo.php';
require_once __DIR__ . '/../config/public_not_found.php';
$auto = brvtal_public_seo_document([
    'route_type'=>'events',
    'slug'=>'genesis',
    'title'=>'Genesis',
    'seo_title'=>'',
    'seo_description'=>'',
    'description'=>str_repeat('Underground techno in Pereira with BRVTAL. ', 8),
    'image'=>'',
    'schema_type'=>'MusicEvent',
], 'https://www.brvtal.com.co');
public_seo_expect(str_starts_with($auto['title'], 'Genesis'), 'public SEO title must default to the entity title');
public_seo_expect(mb_strlen($auto['description']) <= 160, 'automatic public SEO description must not exceed 160 characters');

$manual = brvtal_public_seo_document([
    'route_type'=>'artists',
    'slug'=>'pl0n3r',
    'title'=>'PL0N3R',
    'seo_title'=>'Custom PL0N3R search title',
    'seo_description'=>'Custom description that must remain authoritative.',
    'description'=>'Fallback bio that should not replace manual SEO.',
    'image'=>'',
    'schema_type'=>'MusicGroup',
], 'https://www.brvtal.com.co');
public_seo_expect(str_starts_with($manual['title'], 'Custom PL0N3R search title'), 'manual SEO title must override the editorial default');
public_seo_expect($manual['description'] === 'Custom description that must remain authoritative.', 'manual SEO description must override the editorial default');

$missing = brvtal_public_not_found_seo('https://www.brvtal.com.co', 'events', 'does-not-exist');
public_seo_expect($missing['canonical'] === 'https://www.brvtal.com.co/events/does-not-exist', 'not-found canonical must describe the requested canonical route, not Home');
public_seo_expect($missing['schema']['@type'] === 'WebPage', 'not-found SEO must use a neutral WebPage schema');
public_seo_expect(str_contains($missing['title'], 'Not Found'), 'not-found SEO title must be explicit');
$missingHtml = brvtal_public_not_found_page($missing);
public_seo_expect(str_contains($missingHtml, 'RESOURCE NOT FOUND'), 'dedicated not-found renderer must produce explicit 404 content');
public_seo_expect(!str_contains($missingHtml, 'NEXT EXPERIENCE'), 'dedicated not-found renderer must not reuse Home content');

echo "BRVTAL public SEO delivery contract tests passed.\n";
