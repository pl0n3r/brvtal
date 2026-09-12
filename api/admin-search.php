<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_auth.php';

brvtal_admin_require();

function brvtal_admin_search_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function brvtal_admin_search_table_exists(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
}

function brvtal_admin_search_like(string $value): string
{
    $value = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    return '%' . $value . '%';
}

function brvtal_admin_search_rows(PDO $pdo, string $sql, array $params, string $type, string $module): array
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['type'] = $type;
        $row['module'] = $module;
        $row['subtitle'] = trim((string)($row['subtitle'] ?? ''));
        $row['status'] = trim((string)($row['status'] ?? ''));
    }
    unset($row);
    return $rows;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        header('Allow: GET');
        brvtal_admin_search_json(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED'], 405);
    }

    $query = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 120);
    if ($query === '' || mb_strlen($query) < 2) {
        brvtal_admin_search_json(['ok' => true, 'data' => ['query' => $query, 'groups' => [], 'total' => 0]]);
    }

    $pdo = db();
    $like = brvtal_admin_search_like($query);
    $limit = 6;
    $groups = [];

    $definitions = [
        'events' => [
            'table' => 'events', 'module' => 'events', 'label' => 'EVENTS',
            'sql' => "SELECT id,title,CONCAT_WS(' · ',NULLIF(city,''),NULLIF(venue,'')) subtitle,status FROM events WHERE title LIKE ? ESCAPE '\\\\' OR slug LIKE ? ESCAPE '\\\\' OR city LIKE ? ESCAPE '\\\\' OR venue LIKE ? ESCAPE '\\\\' ORDER BY id DESC LIMIT {$limit}",
            'params' => [$like,$like,$like,$like],
        ],
        'artists' => [
            'table' => 'artists', 'module' => 'artists', 'label' => 'ARTISTS',
            'sql' => "SELECT id,name title,slug subtitle,status FROM artists WHERE name LIKE ? ESCAPE '\\\\' OR slug LIKE ? ESCAPE '\\\\' OR bio LIKE ? ESCAPE '\\\\' ORDER BY id DESC LIMIT {$limit}",
            'params' => [$like,$like,$like],
        ],
        'sets' => [
            'table' => 'sets_media', 'module' => 'sets', 'label' => 'SETS',
            'sql' => "SELECT s.id,s.title,CONCAT_WS(' · ',NULLIF(a.name,''),NULLIF(s.platform,'')) subtitle,s.status FROM sets_media s LEFT JOIN artists a ON a.id=s.artist_id WHERE s.title LIKE ? ESCAPE '\\\\' OR s.slug LIKE ? ESCAPE '\\\\' OR s.description LIKE ? ESCAPE '\\\\' OR s.external_url LIKE ? ESCAPE '\\\\' ORDER BY s.id DESC LIMIT {$limit}",
            'params' => [$like,$like,$like,$like],
        ],
        'media' => [
            'table' => 'media', 'module' => 'media', 'label' => 'MEDIA',
            'sql' => "SELECT id,COALESCE(NULLIF(title,''),file_path) title,CONCAT_WS(' · ',NULLIF(type,''),NULLIF(mime_type,'')) subtitle,status FROM media WHERE title LIKE ? ESCAPE '\\\\' OR alt_text LIKE ? ESCAPE '\\\\' OR file_path LIKE ? ESCAPE '\\\\' ORDER BY id DESC LIMIT {$limit}",
            'params' => [$like,$like,$like],
        ],
        'pages' => [
            'table' => 'pages', 'module' => 'pages', 'label' => 'PAGES',
            'sql' => "SELECT id,title,CONCAT_WS(' · ',NULLIF(locale,''),NULLIF(slug,'')) subtitle,status FROM pages WHERE title LIKE ? ESCAPE '\\\\' OR slug LIKE ? ESCAPE '\\\\' OR seo_title LIKE ? ESCAPE '\\\\' OR seo_description LIKE ? ESCAPE '\\\\' ORDER BY id DESC LIMIT {$limit}",
            'params' => [$like,$like,$like,$like],
        ],
        'releases' => [
            'table' => 'releases', 'module' => 'releases', 'label' => 'RELEASES',
            'sql' => "SELECT id,title,CONCAT_WS(' · ',NULLIF(catalog_number,''),NULLIF(release_type,'')) subtitle,status FROM releases WHERE title LIKE ? ESCAPE '\\\\' OR slug LIKE ? ESCAPE '\\\\' OR catalog_number LIKE ? ESCAPE '\\\\' OR description LIKE ? ESCAPE '\\\\' ORDER BY id DESC LIMIT {$limit}",
            'params' => [$like,$like,$like,$like],
        ],
        'blog' => [
            'table' => 'blog_posts', 'module' => 'blog', 'label' => 'BLOG',
            'sql' => "SELECT id,title,slug subtitle,status FROM blog_posts WHERE title LIKE ? ESCAPE '\\\\' OR slug LIKE ? ESCAPE '\\\\' OR excerpt LIKE ? ESCAPE '\\\\' OR body LIKE ? ESCAPE '\\\\' ORDER BY id DESC LIMIT {$limit}",
            'params' => [$like,$like,$like,$like],
        ],
    ];

    $total = 0;
    foreach ($definitions as $type => $definition) {
        if (!brvtal_admin_search_table_exists($pdo, $definition['table'])) continue;
        $items = brvtal_admin_search_rows($pdo, $definition['sql'], $definition['params'], $type, $definition['module']);
        if (!$items) continue;
        $groups[] = ['type' => $type, 'label' => $definition['label'], 'items' => $items];
        $total += count($items);
    }

    brvtal_admin_search_json(['ok' => true, 'data' => ['query' => $query, 'groups' => $groups, 'total' => $total]]);
} catch (Throwable $e) {
    if (function_exists('brvtal_log')) {
        brvtal_log('ADMIN_SEARCH_ERROR', 'Global admin search failed', ['class' => get_class($e), 'message' => $e->getMessage()]);
    }
    brvtal_admin_search_json(['ok' => false, 'error' => 'INTERNAL_ERROR'], 500);
}
