<?php
declare(strict_types=1);

require_once __DIR__ . '/public_assets.php';
require_once __DIR__ . '/public_page.php';
require_once __DIR__ . '/public_artist.php';

/**
 * Render one canonical public entity document.
 *
 * Production routes and authenticated draft previews must use this exact
 * pipeline so layout, artist enrichment, public controls and asset versioning
 * cannot drift between preview and live delivery.
 */
function brvtalPublicEntityDocument(
    PDO $pdo,
    array $page,
    array $seo,
    string $analytics,
    string $cacheKey
): string {
    $routeType = (string)($page['entity']['route_type'] ?? '');
    if ($routeType === 'artists') {
        $page = brvtal_public_artist_enhance_page($pdo, $page);
    }

    $html = brvtal_public_entity_page($page, $seo, $analytics);
    $html = str_replace(
        '</head>',
        "  <link rel=\"stylesheet\" href=\"/css/public-controls.css\">\n"
            . "  <link rel=\"stylesheet\" href=\"/css/public-legibility.css\">\n</head>",
        $html
    );

    if ($routeType === 'artists') {
        $html = brvtal_public_artist_decorate_html($html, $page);
    }

    return brvtal_public_version_assets($html, $cacheKey);
}
