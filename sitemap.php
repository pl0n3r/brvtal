<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/public_seo.php';

$base = brvtal_public_base_url($config);
$urls = [[$base . '/', null]];
$eventStatuses = brvtal_public_visible_event_statuses();
$eventWhere = 'status IN (' . brvtal_public_sql_placeholders($eventStatuses) . ')';
$definitions = [
    ['events', $eventWhere, $eventStatuses],
    ['artists', "status='published'", []],
    ['sets_media', "status='published'", []],
    ['releases', "status='published'", []],
    ['blog_posts', "status='published'", []],
    ['pages', "status='published' AND locale='en'", []],
];
$routes = ['events'=>'events','artists'=>'artists','sets_media'=>'sets','releases'=>'releases','blog_posts'=>'blog','pages'=>'pages'];
foreach ($definitions as [$table, $where, $parameters]) {
    try {
        $statement = db()->prepare("SELECT slug,updated_at FROM `{$table}` WHERE {$where} AND slug<>'' ORDER BY id");
        $statement->execute($parameters);
        $rows = $statement->fetchAll();
    } catch (Throwable) {
        continue;
    }
    foreach ($rows as $row) {
        $urls[] = [$base . '/' . $routes[$table] . '/' . rawurlencode((string)$row['slug']), $row['updated_at'] ?? null];
    }
}

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=900');
echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
foreach ($urls as [$location, $modified]) {
    echo '  <url><loc>' . htmlspecialchars($location, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>';
    if ($modified) echo '<lastmod>' . htmlspecialchars(substr((string)$modified, 0, 10), ENT_XML1, 'UTF-8') . '</lastmod>';
    echo "</url>\n";
}
echo "</urlset>\n";
