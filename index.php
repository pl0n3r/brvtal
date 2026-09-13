<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/deployment.php';
require_once __DIR__ . '/config/public_assets.php';
require_once __DIR__ . '/config/public_analytics.php';
require_once __DIR__ . '/config/public_seo.php';
require_once __DIR__ . '/config/public_page.php';

$type = trim((string)($_GET['type'] ?? ''));
$slug = trim((string)($_GET['slug'] ?? ''));
$entity = null;
if ($type !== '' || $slug !== '') {
    $entity = brvtal_public_seo_entity(db(), $type, $slug);
    if (!$entity) {
        http_response_code(404);
        header('X-Robots-Tag: noindex, follow');
    }
}

$seo = brvtal_public_seo_document($entity, brvtal_public_base_url($config));
$analytics = brvtal_public_analytics_markup(brvtal_public_ga_id(db()), brvtal_deployment_short_sha());
if ($entity) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: public, max-age=60, stale-while-revalidate=300');
    echo brvtal_public_entity_page(brvtal_public_page_data(db(), $entity), $seo, $analytics);
    exit;
}
$html = (string)file_get_contents(__DIR__ . '/index.html');
$html = str_replace('<body data-scene="CORE">', '<body data-scene="CORE">' . "\n  <a class=\"skip-link mono\" href=\"#top\">SKIP TO CONTENT</a>", $html);
$html = str_replace('<main id="top">', '<main id="top" tabindex="-1">', $html);
$html = str_replace('</head>', "  <link rel=\"stylesheet\" href=\"css/input-accessibility.css\">\n  <link rel=\"stylesheet\" href=\"css/mobile-events.css\">\n</head>", $html);
$html = str_replace('<script src="js/app.js"></script>', "<script src=\"js/mobile-performance.js\"></script>\n  <script src=\"js/app.js\"></script>", $html);
$html = str_replace('</body>', "  <script src=\"js/menu-accessibility.js\"></script>\n  <script src=\"js/input-accessibility.js\"></script>\n  <script src=\"js/mobile-events.js\"></script>\n</body>", $html);
$html = brvtal_public_optimize_home_images($html);
$html = brvtal_public_version_assets($html, brvtal_deployment_short_sha());
$html = str_replace('</body>', $analytics . "\n</body>", $html);
$html = preg_replace('/<title>.*?<\/title>/s', '<title>' . htmlspecialchars($seo['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</title>', $html, 1) ?? $html;
$html = preg_replace('/<meta name="description" content="[^"]*">/', '<meta name="description" content="' . htmlspecialchars($seo['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">', $html, 1) ?? $html;
$html = str_replace('</head>', '  ' . brvtal_public_seo_tags($seo) . "\n</head>", $html);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=60, stale-while-revalidate=300');
echo $html;
