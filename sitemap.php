<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/public_seo.php';
require_once __DIR__ . '/config/public_sitemap.php';

$base = brvtal_public_base_url($config);
$urls = brvtal_public_sitemap_static_urls($base, brvtal_public_static_routes());

foreach (brvtal_public_content_definitions() as $route => $definition) {
    $table = $definition['table'];
    $where = $definition['where'];
    $parameters = $definition['parameters'];
    try {
        $select = $definition['event_visibility']
            ? 'slug,updated_at,status,event_date,published_at'
            : 'slug,updated_at';
        $sql = "SELECT {$select} FROM `{$table}` "
            . "WHERE {$where} AND slug<>'' ORDER BY id";
        $statement = db()->prepare($sql);
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
        $requiresEventVisibility = $definition['event_visibility'];
        if ($requiresEventVisibility && !brvtal_public_event_is_visible($row)) {
            continue;
        }
        $location = $base . '/' . $route . '/' . rawurlencode((string) $row['slug']);
        $urls[] = [$location, $row['updated_at'] ?? null];
    }
}

$response = brvtal_public_sitemap_response($urls, $base);
foreach ($response['headers'] as $headerLine) {
    header($headerLine);
}
echo $response['body'];
