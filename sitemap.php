<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/public_seo.php';
require_once __DIR__ . '/config/public_sitemap.php';

$base = BRVTAL_SITEMAP_CANONICAL_ORIGIN;
$urls = brvtalPublicSitemapLocalizedStaticUrls($base, brvtal_public_static_routes());

foreach (brvtal_public_content_definitions() as $route => $definition) {
    $table = $definition['table'];
    $where = $definition['where'];
    $parameters = $definition['parameters'];
    try {
        $select = $definition['event_visibility']
            ? 'id,slug,updated_at,status,event_date,published_at'
            : 'id,slug,updated_at,status';
        $sql = "SELECT {$select} FROM `{$table}` "
            . "WHERE {$where} AND slug<>'' ORDER BY id";
        $statement = db()->prepare($sql);
        $statement->execute($parameters);
        $rows = $statement->fetchAll();
    } catch (Throwable $error) {
        if (function_exists('brvtal_log')) {
            brvtal_log('PUBLIC_SITEMAP_ERROR', 'Sitemap content-family query failed', [
                'table' => $table,
                'class' => $error::class,
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

    foreach (
        brvtalPublicSitemapLocalizedContentUrls(
            $base,
            $route,
            $rows,
            $definition['event_visibility']
        ) as $localizedRow
    ) {
        $urls[] = $localizedRow;
    }
}

$response = brvtal_public_sitemap_response($urls, $base);
foreach ($response['headers'] as $headerLine) {
    header($headerLine);
}
echo $response['body'];
