<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_activity.php';
require_once __DIR__ . '/../config/indexnow.php';
require_once __DIR__ . '/blog-relations.php';

brvtal_admin_require();

function brvtal_blog_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function brvtal_blog_body(): array
{
    $raw = (string)file_get_contents('php://input');
    if ($raw === '') return $_POST;
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function brvtal_blog_schema_ready(PDO $pdo): bool
{
    $st = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('blog_posts','blog_tags','blog_post_tags','blog_post_relations')");
    return (int)$st->fetchColumn() === 4;
}

function brvtal_blog_slug(string $value): string
{
    $value = trim($value);
    if ($value === '') return '';
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($converted) && $converted !== '') $value = $converted;
    }
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    return trim($value, '-');
}

function brvtal_blog_image(mixed $value): string
{
    $path = trim((string)$value);
    if ($path === '') return '';
    if (preg_match('/^https?:\/\//i', $path)) {
        if (!filter_var($path, FILTER_VALIDATE_URL)) throw new InvalidArgumentException('INVALID_COVER_IMAGE');
        return $path;
    }
    if (!str_starts_with($path, '/')) $path = '/' . ltrim($path, '/');
    if (!str_starts_with($path, '/uploads/')) throw new InvalidArgumentException('INVALID_COVER_IMAGE');
    return $path;
}

/**
 * Normalize a Blog mutation payload and reject malformed related-content IDs
 * before they can be coerced, locked, or persisted.
 */
function brvtal_blog_payload(array $input): array
{
    $title = trim((string)($input['title'] ?? ''));
    if ($title === '') throw new InvalidArgumentException('TITLE_REQUIRED');

    $slug = brvtal_blog_slug((string)($input['slug'] ?? $title));
    if ($slug === '') throw new InvalidArgumentException('SLUG_REQUIRED');

    $status = strtolower(trim((string)($input['status'] ?? 'draft')));
    if (!in_array($status, ['draft','published','archived'], true)) throw new InvalidArgumentException('INVALID_STATUS');

    $tags = [];
    foreach ((array)($input['tags'] ?? []) as $tag) {
        $name = trim(is_array($tag) ? (string)($tag['name'] ?? '') : (string)$tag);
        if ($name === '') continue;
        $tagSlug = brvtal_blog_slug($name);
        if ($tagSlug === '') continue;
        $tags[$tagSlug] = ['name' => mb_substr($name, 0, 100), 'slug' => mb_substr($tagSlug, 0, 120)];
    }

    $relations = [];
    foreach ((array)($input['relations'] ?? []) as $index => $relation) {
        if (!is_array($relation)) continue;
        $type = strtolower(trim((string)($relation['related_type'] ?? '')));
        if (!in_array($type, ['event','artist','set','release'], true)) continue;
        $id = brvtal_blog_relation_id($relation['related_id'] ?? null);
        $key = $type . ':' . $id;
        $relations[$key] = [
            'related_type' => $type,
            'related_id' => $id,
            'sort_order' => (int)($relation['sort_order'] ?? $index),
        ];
    }

    return [
        'title' => mb_substr($title, 0, 220),
        'slug' => mb_substr($slug, 0, 190),
        'excerpt' => mb_substr(trim((string)($input['excerpt'] ?? '')), 0, 700),
        'body' => trim((string)($input['body'] ?? '')),
        'cover_image' => brvtal_blog_image($input['cover_image'] ?? ''),
        'seo_title' => mb_substr(trim((string)($input['seo_title'] ?? '')), 0, 190),
        'seo_description' => mb_substr(trim((string)($input['seo_description'] ?? '')), 0, 320),
        'status' => $status,
        'featured' => !empty($input['featured']) ? 1 : 0,
        'sort_order' => (int)($input['sort_order'] ?? 0),
        'tags' => array_values($tags),
        'relations' => array_values($relations),
    ];
}

function brvtal_blog_sync_tags(PDO $pdo, int $postId, array $tags): void
{
    $pdo->prepare('DELETE FROM blog_post_tags WHERE post_id=?')->execute([$postId]);
    if (!$tags) return;
    $upsert = $pdo->prepare('INSERT INTO blog_tags(name,slug) VALUES(?,?) ON DUPLICATE KEY UPDATE name=VALUES(name)');
    $find = $pdo->prepare('SELECT id FROM blog_tags WHERE slug=? LIMIT 1');
    $link = $pdo->prepare('INSERT IGNORE INTO blog_post_tags(post_id,tag_id) VALUES(?,?)');
    foreach ($tags as $tag) {
        $upsert->execute([$tag['name'],$tag['slug']]);
        $find->execute([$tag['slug']]);
        $tagId = (int)$find->fetchColumn();
        if ($tagId > 0) $link->execute([$postId,$tagId]);
    }
}

function brvtal_blog_sync_relations(PDO $pdo, int $postId, array $relations): void
{
    $pdo->prepare('DELETE FROM blog_post_relations WHERE post_id=?')->execute([$postId]);
    if (!$relations) return;
    $st = $pdo->prepare('INSERT INTO blog_post_relations(post_id,related_type,related_id,sort_order) VALUES(?,?,?,?)');
    foreach ($relations as $relation) {
        $st->execute([$postId,$relation['related_type'],$relation['related_id'],$relation['sort_order']]);
    }
}

function brvtal_blog_fetch(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM blog_posts WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $post = $st->fetch();
    if (!$post) return null;

    $tags = $pdo->prepare('SELECT t.id,t.name,t.slug FROM blog_post_tags pt JOIN blog_tags t ON t.id=pt.tag_id WHERE pt.post_id=? ORDER BY t.name');
    $tags->execute([$id]);
    $relations = $pdo->prepare('SELECT related_type,related_id,sort_order FROM blog_post_relations WHERE post_id=? ORDER BY sort_order,related_type,related_id');
    $relations->execute([$id]);

    $post['id'] = (int)$post['id'];
    $post['featured'] = (int)$post['featured'];
    $post['sort_order'] = (int)$post['sort_order'];
    $post['tags'] = $tags->fetchAll();
    $post['relations'] = $relations->fetchAll();
    return $post;
}

function brvtal_blog_list(PDO $pdo): array
{
    $rows = $pdo->query('SELECT * FROM blog_posts ORDER BY featured DESC, COALESCE(published_at,updated_at) DESC, sort_order ASC, id DESC')->fetchAll();
    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['featured'] = (int)$row['featured'];
        $row['sort_order'] = (int)$row['sort_order'];
    }
    unset($row);
    return $rows;
}

try {
    $pdo = db();
    if (!brvtal_blog_schema_ready($pdo)) brvtal_blog_json(['ok'=>false,'error'=>'BLOG_SCHEMA_MISSING'], 503);

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($method === 'GET') {
        if ($id > 0) {
            $post = brvtal_blog_fetch($pdo, $id);
            if (!$post) brvtal_blog_json(['ok'=>false,'error'=>'BLOG_POST_NOT_FOUND'], 404);
            brvtal_blog_json(['ok'=>true,'data'=>$post]);
        }
        brvtal_blog_json(['ok'=>true,'data'=>brvtal_blog_list($pdo)]);
    }

    if (!in_array($method, ['POST','PUT','DELETE'], true)) {
        header('Allow: GET, POST, PUT, DELETE');
        brvtal_blog_json(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'], 405);
    }

    brvtal_admin_require_csrf();

    if ($method === 'DELETE') {
        if ($id <= 0) brvtal_blog_json(['ok'=>false,'error'=>'BLOG_POST_ID_REQUIRED'], 422);
        $before = brvtal_blog_fetch($pdo, $id);
        if (!$before) brvtal_blog_json(['ok'=>false,'error'=>'BLOG_POST_NOT_FOUND'], 404);
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('DELETE FROM blog_posts WHERE id=?');
            $st->execute([$id]);
            if ($st->rowCount() < 1) throw new RuntimeException('BLOG_POST_NOT_FOUND',404);
            brvtal_activity_record($pdo, 'delete', 'blog', $id, $before, null, ['source'=>'blog_api'], (string)$before['title']);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        brvtal_indexnow_notify_change($pdo, 'blog', $before, null);
        brvtal_blog_json(['ok'=>true,'deleted'=>$id]);
    }

    $data = brvtal_blog_payload(brvtal_blog_body());
    $before = null;
    $pdo->beginTransaction();
    try {
        brvtal_blog_lock_relation_targets($pdo, $data['relations']);
        if ($method === 'POST') {
            $st = $pdo->prepare("INSERT INTO blog_posts(title,slug,excerpt,body,cover_image,seo_title,seo_description,status,featured,published_at,sort_order) VALUES(?,?,?,?,?,?,?,?,?,?,?)");
            $st->execute([
                $data['title'],$data['slug'],$data['excerpt'] ?: null,$data['body'] ?: null,$data['cover_image'] ?: null,
                $data['seo_title'] ?: null,$data['seo_description'] ?: null,$data['status'],$data['featured'],
                $data['status']==='published' ? date('Y-m-d H:i:s') : null,$data['sort_order'],
            ]);
            $id = (int)$pdo->lastInsertId();
        } else {
            if ($id <= 0) throw new RuntimeException('BLOG_POST_NOT_FOUND',404);
            $before = brvtal_blog_fetch($pdo,$id);
            if (!$before) throw new RuntimeException('BLOG_POST_NOT_FOUND',404);
            $st = $pdo->prepare("UPDATE blog_posts SET title=?,slug=?,excerpt=?,body=?,cover_image=?,seo_title=?,seo_description=?,status=?,featured=?,published_at=CASE WHEN ?='published' THEN COALESCE(published_at,CURRENT_TIMESTAMP) ELSE published_at END,sort_order=? WHERE id=?");
            $st->execute([
                $data['title'],$data['slug'],$data['excerpt'] ?: null,$data['body'] ?: null,$data['cover_image'] ?: null,
                $data['seo_title'] ?: null,$data['seo_description'] ?: null,$data['status'],$data['featured'],$data['status'],$data['sort_order'],$id,
            ]);
        }
        brvtal_blog_sync_tags($pdo,$id,$data['tags']);
        brvtal_blog_sync_relations($pdo,$id,$data['relations']);
        $after = brvtal_blog_fetch($pdo,$id);
        if (!$after) throw new RuntimeException('BLOG_POST_NOT_FOUND',404);
        brvtal_activity_record(
            $pdo,
            $method === 'POST' ? 'create' : 'update',
            'blog',
            $id,
            $before,
            $after,
            ['source'=>'blog_api'],
            (string)$after['title']
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    brvtal_indexnow_notify_change($pdo, 'blog', $before, $after);
    brvtal_blog_json(['ok'=>true,'data'=>$after], $method==='POST' ? 201 : 200);
} catch (InvalidArgumentException $e) {
    brvtal_blog_json(['ok'=>false,'error'=>$e->getMessage()],422);
} catch (PDOException $e) {
    if ((string)$e->getCode()==='23000') brvtal_blog_json(['ok'=>false,'error'=>'BLOG_POST_CONFLICT'],409);
    if (function_exists('brvtal_log')) brvtal_log('BLOG_API_ERROR','Blog database failure',['message'=>$e->getMessage()]);
    brvtal_blog_json(['ok'=>false,'error'=>'INTERNAL_ERROR'],500);
} catch (RuntimeException $e) {
    if ($e->getMessage() === 'ACTIVITY_SCHEMA_MISSING') {
        brvtal_blog_json(['ok'=>false,'error'=>'ACTIVITY_SCHEMA_MISSING'],503);
    }
    $status = $e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : 500;
    brvtal_blog_json(['ok'=>false,'error'=>$e->getMessage()],$status);
} catch (Throwable $e) {
    if (function_exists('brvtal_log')) brvtal_log('BLOG_API_ERROR','Blog API failure',['class'=>get_class($e),'message'=>$e->getMessage()]);
    brvtal_blog_json(['ok'=>false,'error'=>'INTERNAL_ERROR'],500);
}
