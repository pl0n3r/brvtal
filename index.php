<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/deployment.php';
require_once __DIR__ . '/config/public_assets.php';
require_once __DIR__ . '/config/public_analytics.php';
require_once __DIR__ . '/config/public_seo.php';
require_once __DIR__ . '/config/public_page.php';
require_once __DIR__ . '/config/public_artist.php';
require_once __DIR__ . '/config/public_not_found.php';
require_once __DIR__ . '/config/public_unavailable.php';
require_once __DIR__ . '/config/public_home.php';
require_once __DIR__ . '/config/public_contact_page.php';

$pageRoute = trim((string)($_GET['page'] ?? ''));
$type = trim((string)($_GET['type'] ?? ''));
$slug = trim((string)($_GET['slug'] ?? ''));
$entity = null;
$baseUrl = brvtal_public_base_url($config);

if ($pageRoute === 'contact') {
    $seo = brvtal_public_contact_seo($baseUrl);
    $analytics = brvtal_public_analytics_markup(brvtal_public_gtm_id(db()), brvtalDeploymentCacheKey());
    $contactHtml = brvtal_public_contact_page($seo, $analytics);
    $contactHtml = brvtal_public_optimize_font_stylesheet($contactHtml);
    $contactHtml = brvtal_public_version_assets($contactHtml, brvtalDeploymentCacheKey());
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: public, max-age=60, stale-while-revalidate=300');
    echo $contactHtml;
    exit;
}

if ($type !== '' || $slug !== '') {
    $entity = brvtal_public_seo_entity(db(), $type, $slug);
    if (!$entity) {
        $seo = brvtal_public_not_found_seo($baseUrl, $type, $slug);
        $analytics = brvtal_public_analytics_markup(brvtal_public_gtm_id(db()), brvtalDeploymentCacheKey());
        http_response_code(404);
        header('X-Robots-Tag: noindex, follow');
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: public, max-age=60, stale-while-revalidate=300');
        echo brvtal_public_not_found_page($seo, $analytics);
        exit;
    }
}

$seoDefaults = brvtal_public_global_seo(db());
$seo = brvtal_public_seo_document($entity, $baseUrl, $seoDefaults);
$analytics = brvtal_public_analytics_markup(brvtal_public_gtm_id(db()), brvtalDeploymentCacheKey());
if ($entity) {
    try {
        $page = brvtal_public_page_data(db(), $entity);
        if (($entity['route_type'] ?? '') === 'artists') {
            $page = brvtal_public_artist_enhance_page(db(), $page);
        }
    } catch (Throwable $e) {
        if (function_exists('brvtal_log')) {
            brvtal_log('PUBLIC_ENTITY_DATA_ERROR', 'Essential canonical entity data failed to load', [
                'type' => (string)($entity['route_type'] ?? ''),
                'id' => (int)($entity['id'] ?? 0),
                'class' => get_class($e),
                'message' => $e->getMessage(),
            ]);
        }
        $unavailableSeo = brvtal_public_unavailable_seo($seo);
        http_response_code(503);
        header('Retry-After: 60');
        header('X-Robots-Tag: noindex, follow');
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo brvtal_public_unavailable_page($unavailableSeo, $analytics);
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    if (!empty($page['degraded'])) {
        header('X-Robots-Tag: noindex, follow');
        header('X-BRVTAL-Data-State: degraded');
        header('Cache-Control: no-store');
    } else {
        header('Cache-Control: public, max-age=60, stale-while-revalidate=300');
    }
    $entityHtml = brvtal_public_entity_page($page, $seo, $analytics);
    $entityHtml = str_replace(
        '</head>',
        "  <link rel=\"stylesheet\" href=\"/css/public-controls.css\">\n  <link rel=\"stylesheet\" href=\"/css/public-legibility.css\">\n</head>",
        $entityHtml
    );
    if (($entity['route_type'] ?? '') === 'artists') {
        $entityHtml = brvtal_public_artist_decorate_html($entityHtml, $page);
    }
    $entityHtml = brvtal_public_version_assets($entityHtml, brvtalDeploymentCacheKey());
    echo $entityHtml;
    exit;
}

$html = (string)file_get_contents(__DIR__ . '/index.html');
try {
    $nextExperience = brvtal_public_next_experience(db());
} catch (Throwable $e) {
    $nextExperience = null;
    if (function_exists('brvtal_log')) {
        brvtal_log('PUBLIC_HOME_ERROR', 'Next Experience lookup failed', [
            'class' => get_class($e),
            'message' => $e->getMessage(),
        ]);
    }
}
$html = brvtal_public_render_next_experience($html, $nextExperience);
$html = str_replace('<a href="#contact"><span>07</span>CONTACT</a>', '<a href="/contact"><span>07</span>CONTACT</a>', $html);
$html = str_replace('<footer class="footer scene" id="contact" data-scene="CORE">', '<footer class="footer scene" id="site-footer" data-scene="CORE">', $html);
$html = str_replace('href="mailto:contact@brvtal.com.co" data-cursor="CONTACT"', 'href="/contact" data-cursor="CONTACT"', $html);
$html = brvtal_public_preload_home_lcp($html);
$html = brvtal_public_keep_home_lcp_visible($html);
$html = str_replace('<body data-scene="CORE">', '<body data-scene="CORE">' . "\n  <a class=\"skip-link mono\" href=\"#top\">SKIP TO CONTENT</a>", $html);
$html = str_replace('<main id="top">', '<main id="top" tabindex="-1">', $html);
$html = str_replace('class="artist" href="#"', 'class="artist" aria-disabled="true"', $html);
$artistHeadSource = '<div class="section-head"><span class="mono">ROSTER / 04</span>'
    . '<h2>ARTISTS</h2><span class="mono">MOVE CURSOR</span></div>';
$artistHeadTarget = '<div class="section-head"><span class="mono">BRVTAL ARTISTS / 03</span>'
    . '<h2>ARTISTS</h2><span class="mono">CORE / ALUMNI / COLLABORATORS</span></div>';
$html = str_replace($artistHeadSource, $artistHeadTarget, $html);

$nightHeadSource = '<div class="section-head"><span class="mono">EVENTS / 03</span>'
    . '<h2>EVENTS</h2><span class="mono">DRAG / SCROLL →</span></div>';
$nightHeadTarget = '<div class="section-head"><span class="mono">BRVTAL NIGHTS / 02</span>'
    . '<h2>NIGHTS</h2><span class="mono">DRAG / SWIPE →</span></div>';
$html = str_replace($nightHeadSource, $nightHeadTarget, $html);
$html = str_replace(
    ['RAW / HARD TECHNO','HARDCORE / INDUSTRIAL','HARD TECHNO / RAW','TECHNO / HARD DANCE','PSY / HARDCORE'],
    'ARTIST / PROFILE',
    $html
);
$soundHeadSource = '<div class="section-head"><span class="mono">AUDIO ARCHIVE / 05</span>'
    . '<h2>SETS</h2><span class="mono">SOUNDCLOUD / BRVTAL</span></div>';
$soundHeadTarget = '<div class="section-head"><span class="mono">BRVTAL SOUND / 04</span>'
    . '<h2>SOUND</h2><span class="mono">SETS / LISTEN / ARCHIVE</span></div>';
$html = str_replace($soundHeadSource, $soundHeadTarget, $html);
$html = str_replace(
    '<span class="mono">VISUAL ARCHIVE / 06</span>',
    '<span class="mono">BRVTAL MEMORIES / 05</span>',
    $html
);
$html = preg_replace('/(<div class="set-main">.*?<p>).*?(<\/p><\/div>)/sU', '$1SET / ARCHIVE$2', $html) ?? $html;
$html = str_replace(
    'href="https://soundcloud.com/" target="_blank" rel="noopener" class="set-action magnetic"',
    'class="set-action magnetic" aria-disabled="true" aria-hidden="true" tabindex="-1"',
    $html
);
$homeStyles = [
    'css/public-header-alignment.css',
    'css/input-accessibility.css',
    'css/mobile-events.css',
    'css/hero-slider.css',
    'css/hero-slider-v2.css',
    'css/public-roster.css',
    'css/public-sets-library.css',
    'css/public-memories.css',
    'css/public-transmissions.css',
    'css/public-visual-identity.css',
];
$homeStyleLinks = implode(
    "\n",
    array_map(
        static fn(string $href): string => '  <link rel="stylesheet" href="' . $href . '">',
        $homeStyles
    )
);
$html = str_replace('</head>', $homeStyleLinks . "\n</head>", $html);
$html = str_replace(
    'href="css/hero-slider-v2.css">',
    'href="css/hero-slider-v2.css" data-hero-v2-public="1">',
    $html
);
$html = str_replace('</body>', "  <script src=\"js/public-quick-wins.js\"></script>\n  <script src=\"js/public-home-visual.js\"></script>\n</body>", $html);
$html = brvtal_public_dedupe_decorative_assets($html);
$html = brvtal_public_optimize_font_stylesheet($html);
$html = brvtal_public_inline_stylesheets($html, [
    'css/hero-slider.css',
    'css/hero-slider-v2.css',
]);
$html = brvtal_public_defer_stylesheets($html, [
    'css/archive.css',
    'css/public-media.css',
    'css/public-memories.css',
    'css/input-accessibility.css',
    'css/mobile-events.css',
    'css/public-roster.css',
    'css/public-sets-library.css',
    'css/public-transmissions.css',
]);
$html = brvtal_public_optimize_home_images($html);
$html = brvtal_public_version_assets($html, brvtalDeploymentCacheKey());
$html = str_replace('</body>', $analytics . "\n</body>", $html);
$html = preg_replace('/<title>.*?<\/title>/s', '<title>' . htmlspecialchars($seo['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</title>', $html, 1) ?? $html;
$html = preg_replace('/<meta name="description" content="[^"]*">/', '<meta name="description" content="' . htmlspecialchars($seo['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">', $html, 1) ?? $html;
$html = str_replace('</head>', '  ' . brvtal_public_seo_tags($seo) . "\n</head>", $html);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=60, stale-while-revalidate=300');
echo $html;
