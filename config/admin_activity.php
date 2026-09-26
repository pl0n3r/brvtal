<?php
declare(strict_types=1);

require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/artist_collective_lifecycle.php';

function brvtal_activity_schema_ready(PDO $pdo): bool
{
    static $ready = [];
    $key = spl_object_id($pdo);
    if (($ready[$key] ?? false) === true) return true;
    $st = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='admin_activity_log'");
    $isReady = (int)$st->fetchColumn() === 1;
    if ($isReady) $ready[$key] = true;
    return $isReady;
}

function brvtal_activity_request_id(): string
{
    static $requestId = null;
    if (is_string($requestId)) return $requestId;

    $provided = strtolower(trim((string)($_SERVER['HTTP_X_REQUEST_ID'] ?? '')));
    if (preg_match('/^[a-f0-9]{32}$/', $provided)) {
        $requestId = $provided;
    } else {
        $requestId = bin2hex(random_bytes(16));
    }
    return $requestId;
}

function brvtal_activity_actor(PDO $pdo): array
{
    static $cache = [];
    brvtal_admin_session_start();
    $adminId = (int)($_SESSION['admin_id'] ?? 0);
    $cacheKey = spl_object_id($pdo) . ':' . $adminId;
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];

    if ($adminId < 1) {
        return $cache[$cacheKey] = ['id' => null, 'name' => null, 'email' => null];
    }

    $st = $pdo->prepare('SELECT id,name,email FROM admins WHERE id=? LIMIT 1');
    $st->execute([$adminId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return $cache[$cacheKey] = ['id' => $adminId, 'name' => 'Admin #' . $adminId, 'email' => null];
    }

    return $cache[$cacheKey] = [
        'id' => (int)$row['id'],
        'name' => mb_substr(trim((string)$row['name']), 0, 120),
        'email' => mb_substr(strtolower(trim((string)$row['email'])), 0, 190),
    ];
}

function brvtal_activity_allowed_fields(string $resource): array
{
    return [
        'events' => ['id','title','slug','event_date','archive_year','venue','city','description','skin','accent','cover_image','ticket_url','ticket_instructions','ticket_qr','featured','published_at','cancelled_at','finished_at','status','sort_order','seo_title','seo_description'],
        'artists' => [
            'id','name','slug','bio','photo','instagram_url','soundcloud_url',
            'website_url','is_collective_member','status','sort_order',
            'seo_title','seo_description',
        ],
        'sets' => ['id','title','slug','artist_id','event_id','platform','external_url','embed_url','cover_image','description','status','sort_order','seo_title','seo_description'],
        'pages' => ['id','title','slug','locale','content_json','seo_title','seo_description','status'],
        'ticket_types' => ['id','event_id','name','description','price','currency','external_url','payment_instructions','qr_image','status','available_from','available_until','sort_order'],
        'releases' => ['id','title','slug','release_type','catalog_number','release_date','description','seo_title','seo_description','artwork','spotify_url','soundcloud_url','bandcamp_url','youtube_url','beatport_url','status','featured','sort_order','published_at','artists'],
        'blog' => ['id','title','slug','excerpt','body','cover_image','seo_title','seo_description','status','featured','published_at','sort_order','tags','relations'],
        'event_lineup' => ['event_id','lineup'],
        'media' => ['id','type','title','file_path','mime_type','file_size','alt_text','status'],
        'settings' => ['setting_key','is_json'],
    ][$resource] ?? [];
}

function brvtalActivitySettingKeyAuditable(string $key): bool
{
    $normalized = strtolower(trim($key));
    if ($normalized === '' || brvtal_activity_is_sensitive_key($normalized)) {
        return false;
    }

    if ($normalized === 'theme.active') {
        return true;
    }
    if (preg_match('/^theme\.[a-z0-9_-]{1,80}$/D', $normalized) === 1) {
        return true;
    }

    return in_array($normalized, [
        'site.name',
        'site.tagline',
        'site.locale',
        'site.timezone',
        'seo.default_title',
        'seo.default_description',
        'public.hero_slider',
        'public.contact_email',
        'public.instagram_url',
        'public.soundcloud_url',
    ], true);
}

function brvtal_activity_is_sensitive_key(string $key): bool
{
    return (bool)preg_match('/(?:password|secret|token|csrf|recovery|session|credential|authorization|api[_-]?key|private[_-]?key)/i', $key);
}

function brvtal_activity_sanitize_value(mixed $value, int $depth = 0): mixed
{
    if ($depth > 6) return '[DEPTH_LIMIT]';
    if ($value === null || is_bool($value) || is_int($value) || is_float($value)) return $value;
    if (is_string($value)) return mb_substr($value, 0, 65535);
    if (!is_array($value)) return (string)$value;

    $out = [];
    $count = 0;
    foreach ($value as $key => $item) {
        if ($count++ >= 300) {
            $out['_truncated'] = true;
            break;
        }
        $keyString = (string)$key;
        if (brvtal_activity_is_sensitive_key($keyString)) continue;
        $out[$key] = brvtal_activity_sanitize_value($item, $depth + 1);
    }
    return $out;
}

function brvtal_activity_snapshot(string $resource, ?array $row): ?array
{
    if ($row === null) return null;
    $allowed = brvtal_activity_allowed_fields($resource);
    if ($allowed === []) return [];

    $snapshot = [];
    foreach ($allowed as $field) {
        if (array_key_exists((string) $field, $row)) {
            $snapshot[$field] = brvtal_activity_sanitize_value($row[$field]);
        }
    }
    return $snapshot;
}

function brvtal_activity_changed_fields(?array $before, ?array $after): array
{
    $before ??= [];
    $after ??= [];
    $keys = array_values(array_unique(array_merge(array_keys($before), array_keys($after))));
    $ignore = ['id','created_at','updated_at'];
    $changed = [];

    foreach ($keys as $key) {
        if (in_array($key, $ignore, true)) continue;
        $left = $before[$key] ?? null;
        $right = $after[$key] ?? null;
        if (json_encode($left, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !== json_encode($right, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) {
            $changed[] = (string)$key;
        }
    }
    sort($changed);
    return $changed;
}

function brvtal_activity_label(string $resource, ?array $snapshot, ?string $fallback = null): ?string
{
    foreach (['title','name','slug'] as $key) {
        $value = trim((string)($snapshot[$key] ?? ''));
        if ($value !== '') return mb_substr($value, 0, 220);
    }
    if ($fallback !== null && trim($fallback) !== '') return mb_substr(trim($fallback), 0, 220);
    return null;
}

function brvtal_activity_record(
    PDO $pdo,
    string $action,
    string $resource,
    ?int $resourceId,
    ?array $before = null,
    ?array $after = null,
    array $meta = [],
    ?string $label = null
): ?int {
    if (!brvtal_activity_schema_ready($pdo)) {
        throw new RuntimeException('ACTIVITY_SCHEMA_MISSING');
    }

    $action = strtolower(trim($action));
    $resource = strtolower(trim($resource));
    if (!preg_match('/^[a-z0-9_-]{1,40}$/', $action) || !preg_match('/^[a-z0-9_-]{1,40}$/', $resource)) {
        throw new InvalidArgumentException('INVALID_ACTIVITY_EVENT');
    }

    if ($resource === 'artists') {
        $before = is_array($before) ? brvtalArtistCollectiveMembershipEnrichRow($before) : null;
        $after = is_array($after) ? brvtalArtistCollectiveMembershipEnrichRow($after) : null;
    }
    $safeBefore = brvtal_activity_snapshot($resource, $before);
    $safeAfter = brvtal_activity_snapshot($resource, $after);
    $changed = brvtal_activity_changed_fields($safeBefore, $safeAfter);
    if ($action === 'update' && $changed === []) return null;

    $actor = brvtal_activity_actor($pdo);
    if ($resource === 'artists' && $resourceId !== null && in_array($action, ['create', 'update'], true)) {
        brvtalArtistCollectiveSyncHistory(
            $pdo,
            $action,
            $resourceId,
            $before,
            $after,
            isset($actor['id']) ? (int)$actor['id'] : null
        );
    }

    $label = brvtal_activity_label($resource, $safeAfter ?? $safeBefore, $label);
    $safeMeta = brvtal_activity_sanitize_value($meta);

    $encode = static function (mixed $value): ?string {
        if ($value === null) return null;
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        return is_string($json) ? $json : null;
    };

    $st = $pdo->prepare(
        'INSERT INTO admin_activity_log(admin_id,admin_name,admin_email,action,resource,resource_id,resource_label,changed_fields,before_json,after_json,meta_json,request_id)
         VALUES(?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $st->execute([
        $actor['id'],
        $actor['name'],
        $actor['email'],
        $action,
        $resource,
        $resourceId,
        $label,
        $encode($changed),
        $encode($safeBefore),
        $encode($safeAfter),
        $encode($safeMeta),
        brvtal_activity_request_id(),
    ]);

    return (int)$pdo->lastInsertId();
}


/**
 * @param array{resource?:string,resource_id?:int,action?:string,admin_id?:int} $filters
 * @return array{items:array<int,array<string,mixed>>,total:int,limit:int,returned:int,has_more:bool,next_cursor:?int}
 */
function brvtalActivityPage(
    PDO $pdo,
    array $filters,
    int $limit,
    ?int $cursor = null,
    bool $history = false
): array {
    $limit = max(1, min(100, $limit));
    if ($cursor !== null && $cursor < 1) {
        throw new InvalidArgumentException('INVALID_CURSOR');
    }

    $where = [];
    $params = [];
    foreach ([
        'resource' => 'resource',
        'resource_id' => 'resource_id',
        'action' => 'action',
        'admin_id' => 'admin_id',
    ] as $filter => $column) {
        if (!array_key_exists($filter, $filters)) {
            continue;
        }
        $value = $filters[$filter];
        if ($value === '' || $value === null || $value === 0) {
            continue;
        }
        $where[] = $column . '=?';
        $params[] = $value;
    }

    $baseWhereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $count = $pdo->prepare('SELECT COUNT(*) FROM admin_activity_log' . $baseWhereSql);
    $count->execute($params);
    $total = (int)$count->fetchColumn();

    if ($cursor !== null) {
        $where[] = 'id < ?';
        $params[] = $cursor;
    }
    $pageWhereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $snapshotColumns = $history ? ',before_json,after_json' : '';
    $fetchLimit = $limit + 1;
    $statement = $pdo->prepare(
        'SELECT id,admin_id,admin_name,admin_email,action,resource,resource_id,'
        . 'resource_label,changed_fields,request_id,created_at'
        . $snapshotColumns
        . ' FROM admin_activity_log'
        . $pageWhereSql
        . ' ORDER BY id DESC LIMIT '
        . $fetchLimit
    );
    $statement->execute($params);
    $items = $statement->fetchAll(PDO::FETCH_ASSOC);
    $hasMore = count($items) > $limit;
    if ($hasMore) {
        array_pop($items);
    }
    $nextCursor = $hasMore && $items !== []
        ? (int)array_last($items)['id']
        : null;

    return [
        'items' => $items,
        'total' => $total,
        'limit' => $limit,
        'returned' => count($items),
        'has_more' => $hasMore,
        'next_cursor' => $nextCursor,
    ];
}
