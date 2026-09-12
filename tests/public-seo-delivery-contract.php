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
$sitemap = (string)file_get_contents(__DIR__ . '/../sitemap.php');
$robots = (string)file_get_contents(__DIR__ . '/../robots.txt');
$html = (string)file_get_contents(__DIR__ . '/../index.html');

foreach (['events','artists','sets','releases','blog','pages'] as $route) {
    public_seo_expect(str_contains($router, $route), "pretty URL routing must support {$route}");
}
foreach (['canonical','og:title','og:description','og:image','twitter:card','application/ld+json'] as $marker) {
    public_seo_expect(str_contains($seo, $marker), "server-rendered metadata must include {$marker}");
}
foreach (['MusicEvent','MusicGroup','MusicRecording','MusicAlbum','BlogPosting','WebPage'] as $type) {
    public_seo_expect(str_contains($seo, $type), "structured data must support {$type}");
}
public_seo_expect(str_contains($renderer, "http_response_code(404)"), 'unknown or private entities must return HTTP 404');
public_seo_expect(str_contains($renderer, 'X-Robots-Tag: noindex, follow'), 'not-found entity routes must be excluded from indexing');
public_seo_expect(str_contains($router, 'sitemap.php'), 'sitemap.xml must be served dynamically');
public_seo_expect(str_contains($sitemap, '<urlset'), 'sitemap must use the standard XML URL set');
public_seo_expect(str_contains($robots, 'Sitemap: https://www.brvtal.com.co/sitemap.xml'), 'robots must advertise the canonical sitemap');
public_seo_expect(str_contains($html, '<base href="/">'), 'nested public routes must resolve assets from the site root');

echo "BRVTAL public SEO delivery contract tests passed.\n";
