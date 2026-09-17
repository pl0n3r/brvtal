<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/public_seo.php';

$base = brvtal_public_base_url($config);
$urls = [];
foreach (brvtal_public_static_routes() as $path) {
    $urls[] = [$base . ($path === '/' ? '/' : $path), null];
}

foreach (brvtal_public_content_definitions() as $route => $definition) {
    $table = $definition['table'];
    $where = $definition['where'];
    $parameters = $definition['parameters'];
    try {
        $select = $definition['event_visibility']
            ? 'slug,updated_at,status,event_date,published_at'
            : 'slug,updated_at';
        $statement = db()->prepare("SELECT {$select} FROM `{$table}` WHERE {$where} AND slug<>'' ORDER BY id");
        $statement->execute($parameters);
        $rows = $statement->fetchAll();
    } catch (Throwable $error) {
        if (function_exists('brvtal_log')) {
            brvtal_log('PUBLIC_SITEMAP_ERROR', 'Sitemap content-family query failed', [
                'table' => $table,
                'class' => get_class($error),
                'message' => $error->getMessage(),
            ]);
        }
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        header('Retry-After: 60');
        header('X-Robots-Tag: noindex, follow');
        echo "SITEMAP_UNAVAILABLE\n";
        exit;
    }
    foreach ($rows as $row) {
        if ($definition['event_visibility'] && !brvtal_public_event_is_visible($row)) continue;
        $urls[] = [$base . '/' . $route . '/' . rawurlencode((string)$row['slug']), $row['updated_at'] ?? null];
    }
}

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
foreach ($urls as [$location, $modified]) {
    echo '  <url><loc>' . htmlspecialchars($location, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>';
    if ($modified) echo '<lastmod>' . htmlspecialchars(substr((string)$modified, 0, 10), ENT_XML1, 'UTF-8') . '</lastmod>';
    echo "</url>\n";
}
echo "</urlset>\n";
