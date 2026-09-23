<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_auth.php';
require_once __DIR__ . '/../config/schema_catalog.php';

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
    return brvtalSchemaTableExists($pdo, $table);
}

function brvtal_admin_search_like(string $value): string
{
    $value = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    return '%' . $value . '%';
}

function brvtal_admin_search_rows(PDO $pdo, array $definition, int $limit, int $offset, string $type): array
{
    $sql = 'SELECT ' . $definition['select'] . ' ' . $definition['from'] . ' WHERE ' . $definition['where']
        . ' ORDER BY ' . $definition['order'] . " LIMIT {$limit} OFFSET {$offset}";
    $st = $pdo->prepare($sql);
    $st->execute($definition['params']);
    $rows = $st->fetchAll();
    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['type'] = $type;
        $row['module'] = $definition['module'];
        $row['subtitle'] = trim((string)($row['subtitle'] ?? ''));
        $row['status'] = trim((string)($row['status'] ?? ''));
    }
    unset($row);
    return $rows;
}

function brvtal_admin_search_count(PDO $pdo, array $definition): int
{
    $st = $pdo->prepare('SELECT COUNT(*) ' . $definition['from'] . ' WHERE ' . $definition['where']);
    $st->execute($definition['params']);
    return (int)$st->fetchColumn();
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        header('Allow: GET');
        brvtal_admin_search_json(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED'], 405);
    }

    $query = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 120);
    if ($query === '' || mb_strlen($query) < 2) {
        brvtal_admin_search_json(['ok' => true, 'data' => ['query' => $query, 'groups' => [], 'total' => 0, 'page_size' => 6]]);
    }

    $pdo = db();
    $like = brvtal_admin_search_like($query);
    $limit = 6;
    $requestedType = strtolower(trim((string)($_GET['type'] ?? '')));
    $offset = max(0, min(1000000, (int)($_GET['offset'] ?? 0)));
    $groups = [];

    $definitions = [
        'events' => [
            'table' => 'events', 'module' => 'events', 'label' => 'EVENTS',
            'select' => "id,title,CONCAT_WS(' · ',NULLIF(city,''),NULLIF(venue,'')) subtitle,status",
            'from' => 'FROM events',
            'where' => "title LIKE ? ESCAPE '\\\\' OR slug LIKE ? ESCAPE '\\\\' OR city LIKE ? ESCAPE '\\\\' OR venue LIKE ? ESCAPE '\\\\'",
            'order' => 'id DESC',
            'params' => [$like,$like,$like,$like],
        ],
        'artists' => [
            'table' => 'artists', 'module' => 'artists', 'label' => 'ARTISTS',
            'select' => 'id,name title,slug subtitle,status',
            'from' => 'FROM artists',
            'where' => "name LIKE ? ESCAPE '\\\\' OR slug LIKE ? ESCAPE '\\\\' OR bio LIKE ? ESCAPE '\\\\'",
            'order' => 'id DESC',
            'params' => [$like,$like,$like],
        ],
        'sets' => [
            'table' => 'sets_media', 'module' => 'sets', 'label' => 'SETS',
            'select' => "s.id,s.title,CONCAT_WS(' · ',NULLIF(a.name,''),NULLIF(s.platform,'')) subtitle,s.status",
            'from' => 'FROM sets_media s LEFT JOIN artists a ON a.id=s.artist_id',
            'where' => "s.title LIKE ? ESCAPE '\\\\' OR s.slug LIKE ? ESCAPE '\\\\' OR s.description LIKE ? ESCAPE '\\\\' OR s.external_url LIKE ? ESCAPE '\\\\'",
            'order' => 's.id DESC',
            'params' => [$like,$like,$like,$like],
        ],
        'media' => [
            'table' => 'media', 'module' => 'media', 'label' => 'MEDIA',
            'select' => "id,COALESCE(NULLIF(title,''),file_path) title,CONCAT_WS(' · ',NULLIF(type,''),NULLIF(mime_type,'')) subtitle,status",
            'from' => 'FROM media',
            'where' => "title LIKE ? ESCAPE '\\\\' OR alt_text LIKE ? ESCAPE '\\\\' OR file_path LIKE ? ESCAPE '\\\\'",
            'order' => 'id DESC',
            'params' => [$like,$like,$like],
        ],
        'pages' => [
            'table' => 'pages', 'module' => 'pages', 'label' => 'PAGES',
            'select' => "id,title,CONCAT_WS(' · ',NULLIF(locale,''),NULLIF(slug,'')) subtitle,status",
            'from' => 'FROM pages',
            'where' => "title LIKE ? ESCAPE '\\\\' OR slug LIKE ? ESCAPE '\\\\' OR seo_title LIKE ? ESCAPE '\\\\' OR seo_description LIKE ? ESCAPE '\\\\'",
            'order' => 'id DESC',
            'params' => [$like,$like,$like,$like],
        ],
        'releases' => [
            'table' => 'releases', 'module' => 'releases', 'label' => 'RELEASES',
            'select' => "id,title,CONCAT_WS(' · ',NULLIF(catalog_number,''),NULLIF(release_type,'')) subtitle,status",
            'from' => 'FROM releases',
            'where' => "title LIKE ? ESCAPE '\\\\' OR slug LIKE ? ESCAPE '\\\\' OR catalog_number LIKE ? ESCAPE '\\\\' OR description LIKE ? ESCAPE '\\\\'",
            'order' => 'id DESC',
            'params' => [$like,$like,$like,$like],
        ],
        'blog' => [
            'table' => 'blog_posts', 'module' => 'blog', 'label' => 'BLOG',
            'select' => 'id,title,slug subtitle,status',
            'from' => 'FROM blog_posts',
            'where' => "title LIKE ? ESCAPE '\\\\' OR slug LIKE ? ESCAPE '\\\\' OR excerpt LIKE ? ESCAPE '\\\\' OR body LIKE ? ESCAPE '\\\\'",
            'order' => 'id DESC',
            'params' => [$like,$like,$like,$like],
        ],
    ];

    if ($requestedType !== '' && !isset($definitions[$requestedType])) {
        brvtal_admin_search_json(['ok' => false, 'error' => 'INVALID_TYPE'], 400);
    }
    if ($requestedType === '' && $offset !== 0) {
        brvtal_admin_search_json(['ok' => false, 'error' => 'OFFSET_REQUIRES_TYPE'], 400);
    }

    $activeDefinitions = $requestedType === '' ? $definitions : [$requestedType => $definitions[$requestedType]];
    $total = 0;
    foreach ($activeDefinitions as $type => $definition) {
        if (!brvtal_admin_search_table_exists($pdo, $definition['table'])) continue;
        $items = brvtal_admin_search_rows($pdo, $definition, $limit, $offset, $type);
        if (!$items) {
            if ($offset > 0) $total += brvtal_admin_search_count($pdo, $definition);
            continue;
        }

        $visibleThrough = $offset + count($items);
        $matchTotal = count($items) < $limit ? $visibleThrough : brvtal_admin_search_count($pdo, $definition);
        $groups[] = [
            'type' => $type,
            'label' => $definition['label'],
            'items' => $items,
            'total' => $matchTotal,
            'offset' => $offset,
            'has_more' => $visibleThrough < $matchTotal,
        ];
        $total += $matchTotal;
    }

    brvtal_admin_search_json(['ok' => true, 'data' => ['query' => $query, 'groups' => $groups, 'total' => $total, 'page_size' => $limit]]);
} catch (Throwable $e) {
    if (function_exists('brvtal_log')) {
        brvtal_log('ADMIN_SEARCH_ERROR', 'Global admin search failed', ['class' => get_class($e), 'message' => $e->getMessage()]);
    }
    brvtal_admin_search_json(['ok' => false, 'error' => 'INTERNAL_ERROR'], 500);
}
