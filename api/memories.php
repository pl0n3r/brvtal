<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_auth.php';
require_once __DIR__ . '/../config/memory_relations.php';

brvtal_admin_require();
header('X-Content-Type-Options: nosniff');

function brvtal_memories_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function brvtal_memories_schema_ready(PDO $pdo): bool
{
    $st = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='memories'");
    return (int)$st->fetchColumn() === 1;
}

function brvtal_memories_body(): array
{
    $raw = (string)file_get_contents('php://input');
    if ($raw === '') return $_POST;
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) brvtal_memories_json(['ok'=>false,'error'=>'INVALID_JSON'], 400);
    return $decoded;
}

function brvtal_memories_media(PDO $pdo, int $mediaId): ?array
{
    $st = $pdo->prepare('SELECT id,type,title,file_path,mime_type,file_size,alt_text,status,created_at FROM media WHERE id=? LIMIT 1');
    $st->execute([$mediaId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function brvtal_memories_validate(PDO $pdo, array $input, ?array $existing = null): array
{
    $mediaId = (int)($input['media_id'] ?? $existing['media_id'] ?? 0);
    if ($mediaId < 1) throw new InvalidArgumentException('MEDIA_REQUIRED');

    $media = brvtal_memories_media($pdo, $mediaId);
    if (!$media) throw new InvalidArgumentException('MEDIA_NOT_FOUND');
    if (!in_array((string)$media['type'], ['image','video','audio'], true)) {
        throw new InvalidArgumentException('MEDIA_TYPE_NOT_ALLOWED');
    }

    $status = strtolower(trim((string)($input['status'] ?? $existing['status'] ?? 'draft')));
    if (!in_array($status, ['draft','published'], true)) throw new InvalidArgumentException('INVALID_STATUS');
    if ($status === 'published' && (string)$media['status'] !== 'published') {
        throw new InvalidArgumentException('MEDIA_NOT_PUBLIC');
    }

    $title = mb_substr(trim((string)($input['title'] ?? $existing['title'] ?? $media['title'] ?? '')), 0, 180);
    if ($title === '') $title = mb_substr((string)($media['title'] ?? 'Memory'), 0, 180);
    $context = mb_substr(trim((string)($input['context'] ?? $existing['context'] ?? '')), 0, 320);
    $sortOrder = max(-100000, min(100000, (int)($input['sort_order'] ?? $existing['sort_order'] ?? 0)));

    return [
        'media_id'=>$mediaId,
        'title'=>$title,
        'context'=>$context !== '' ? $context : null,
        'status'=>$status,
        'sort_order'=>$sortOrder,
    ];
}

function brvtal_memories_validation_error(InvalidArgumentException $error): string
{
    return match ($error->getMessage()) {
        'MEDIA_REQUIRED','MEDIA_NOT_FOUND','MEDIA_TYPE_NOT_ALLOWED','INVALID_STATUS','MEDIA_NOT_PUBLIC',
        'INVALID_MEMORY_RELATION','INVALID_MEMORY_RELATIONS','TOO_MANY_MEMORY_RELATIONS',
        'MEMORY_RELATION_NOT_FOUND','INVALID_MEMORY_ID' => $error->getMessage(),
        default => 'INVALID_REQUEST',
    };
}

function brvtal_memories_fetch(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare(
        "SELECT m.id,m.media_id,m.title,m.context,m.status,m.sort_order,m.created_at,m.updated_at,
                media.type AS media_type,media.title AS media_title,media.file_path,media.mime_type,media.file_size,
                media.alt_text,media.status AS media_status
         FROM memories m
         JOIN media ON media.id=m.media_id
         WHERE m.id=? LIMIT 1"
    );
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) return null;
    $row['id'] = (int)$row['id'];
    $row['media_id'] = (int)$row['media_id'];
    $row['sort_order'] = (int)$row['sort_order'];
    $row['file_size'] = (int)$row['file_size'];
    $row['relations'] = brvtal_memory_load_relations($pdo, $id);
    return $row;
}

function brvtal_memories_list(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT m.id,m.media_id,m.title,m.context,m.status,m.sort_order,m.created_at,m.updated_at,
                media.type AS media_type,media.title AS media_title,media.file_path,media.mime_type,media.file_size,
                media.alt_text,media.status AS media_status
         FROM memories m
         JOIN media ON media.id=m.media_id
         ORDER BY m.sort_order ASC,m.id ASC"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $relationsByMemory = brvtal_memory_load_relations_batch(
        $pdo,
        array_column($rows, 'id')
    );

    return array_map(static function (array $row) use ($relationsByMemory): array {
        $row['id'] = (int)$row['id'];
        $row['media_id'] = (int)$row['media_id'];
        $row['sort_order'] = (int)$row['sort_order'];
        $row['file_size'] = (int)$row['file_size'];
        $row['relations'] = $relationsByMemory[$row['id']] ?? [];
        return $row;
    }, $rows);
}

function brvtal_memories_available(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT media.id,media.type,media.title,media.file_path,media.mime_type,media.file_size,media.alt_text,media.status,media.created_at,
                memories.id AS memory_id
         FROM media
         LEFT JOIN memories ON memories.media_id=media.id
         WHERE media.type IN ('image','video','audio')
         ORDER BY media.created_at DESC,media.id DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
    return array_map(static function (array $row): array {
        $row['id'] = (int)$row['id'];
        $row['memory_id'] = $row['memory_id'] === null ? null : (int)$row['memory_id'];
        $row['file_size'] = (int)$row['file_size'];
        return $row;
    }, $rows ?: []);
}

$pdo = db();
if (!brvtal_memories_schema_ready($pdo)) {
    brvtal_memories_json(['ok'=>false,'error'=>'MEMORIES_SCHEMA_MISSING'], 503);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = strtolower(trim((string)($_GET['action'] ?? 'list')));
$id = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;

try {
    if ($method === 'GET' && $action === 'list') {
        brvtal_memories_json([
            'ok'=>true,
            'data'=>brvtal_memories_list($pdo),
            'relations_ready'=>brvtal_memory_relations_ready($pdo),
        ]);
    }
    if ($method === 'GET' && $action === 'available') {
        brvtal_memories_json(['ok'=>true,'data'=>brvtal_memories_available($pdo)]);
    }
    if ($method === 'GET' && $action === 'catalog') {
        brvtal_memories_json([
            'ok'=>true,
            'data'=>brvtal_memory_relation_catalog($pdo),
            'relations_ready'=>brvtal_memory_relations_ready($pdo),
        ]);
    }

    if ($method === 'POST' && $action === 'create') {
        brvtal_admin_require_csrf();
        $input = brvtal_memories_body();
        $relationsProvided = array_key_exists('relations', $input);
        if ($relationsProvided && !brvtal_memory_relations_ready($pdo)) {
            brvtal_memories_json(['ok'=>false,'error'=>'MEMORY_RELATIONS_SCHEMA_MISSING'], 409);
        }
        $data = brvtal_memories_validate($pdo, $input);
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare('INSERT INTO memories(media_id,title,context,status,sort_order) VALUES(?,?,?,?,?)');
            $st->execute([$data['media_id'],$data['title'],$data['context'],$data['status'],$data['sort_order']]);
            $createdId = (int)$pdo->lastInsertId();
            if ($relationsProvided) {
                brvtal_memory_replace_relations($pdo, $createdId, $input['relations']);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof PDOException && (int)($e->errorInfo[1] ?? 0) === 1062) {
                brvtal_memories_json(['ok'=>false,'error'=>'MEDIA_ALREADY_CURATED'], 409);
            }
            throw $e;
        }
        brvtal_memories_json(['ok'=>true,'data'=>brvtal_memories_fetch($pdo, $createdId)], 201);
    }

    if ($method === 'PUT' && $action === 'update') {
        brvtal_admin_require_csrf();
        if ($id < 1) {
            brvtal_memories_json(['ok'=>false,'error'=>'MEMORY_ID_REQUIRED'], 422);
        }
        $existing = brvtal_memories_fetch($pdo, $id);
        if (!$existing) {
            brvtal_memories_json(['ok'=>false,'error'=>'MEMORY_NOT_FOUND'], 404);
        }
        $input = brvtal_memories_body();
        $relationsProvided = array_key_exists('relations', $input);
        if ($relationsProvided && !brvtal_memory_relations_ready($pdo)) {
            brvtal_memories_json(['ok'=>false,'error'=>'MEMORY_RELATIONS_SCHEMA_MISSING'], 409);
        }
        $data = brvtal_memories_validate($pdo, $input, $existing);
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare('UPDATE memories SET media_id=?,title=?,context=?,status=?,sort_order=? WHERE id=?');
            $st->execute([$data['media_id'],$data['title'],$data['context'],$data['status'],$data['sort_order'],$id]);
            if ($relationsProvided) {
                brvtal_memory_replace_relations($pdo, $id, $input['relations']);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof PDOException && (int)($e->errorInfo[1] ?? 0) === 1062) {
                brvtal_memories_json(['ok'=>false,'error'=>'MEDIA_ALREADY_CURATED'], 409);
            }
            throw $e;
        }
        brvtal_memories_json(['ok'=>true,'data'=>brvtal_memories_fetch($pdo, $id)]);
    }

    if ($method === 'DELETE') {
        brvtal_admin_require_csrf();
        if ($id < 1) {
            brvtal_memories_json(['ok'=>false,'error'=>'MEMORY_ID_REQUIRED'], 422);
        }
        $existing = brvtal_memories_fetch($pdo, $id);
        if (!$existing) {
            brvtal_memories_json(['ok'=>false,'error'=>'MEMORY_NOT_FOUND'], 404);
        }
        $pdo->prepare('DELETE FROM memories WHERE id=?')->execute([$id]);
        brvtal_memories_json(['ok'=>true,'deleted_id'=>$id]);
    }

    header('Allow: GET, POST, PUT, DELETE');
    brvtal_memories_json(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'], 405);
} catch (InvalidArgumentException $e) {
    brvtal_memories_json(['ok'=>false,'error'=>brvtal_memories_validation_error($e)], 422);
} catch (Throwable $e) {
    if ($e instanceof RuntimeException && $e->getMessage() === 'MEMORY_RELATIONS_SCHEMA_MISSING') {
        brvtal_memories_json(['ok'=>false,'error'=>'MEMORY_RELATIONS_SCHEMA_MISSING'], 409);
    }
    if (function_exists('brvtal_log')) {
        brvtal_log('MEMORIES_ADMIN_ERROR', 'Memories admin API failure', ['class'=>get_class($e)]);
    }
    brvtal_memories_json(['ok'=>false,'error'=>'INTERNAL_ERROR'], 500);
}
