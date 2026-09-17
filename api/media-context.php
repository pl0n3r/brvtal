<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_auth.php';
require_once __DIR__ . '/media-relations.php';

brvtal_admin_require();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$pdo = db();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$id = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;

function brvtal_media_context_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function brvtal_media_context_row(PDO $pdo, int $id, bool $lock = false): ?array
{
    $sql = 'SELECT id,title,alt_text,status FROM media WHERE id=? LIMIT 1' . ($lock ? ' FOR UPDATE' : '');
    $statement = $pdo->prepare($sql);
    $statement->execute([$id]);
    $row = $statement->fetch();
    return is_array($row) ? $row : null;
}

function brvtal_media_context_text(mixed $value, int $max): string
{
    return mb_substr(trim((string)$value), 0, $max);
}

function brvtal_media_context_fail(Throwable $e, int $id, string $method): never
{
    if (function_exists('brvtal_log')) {
        brvtal_log('MEDIA_CONTEXT_ERROR', 'Media cultural context request failed.', [
            'media_id' => $id,
            'method' => $method,
            'class' => get_class($e),
        ]);
    }
    brvtal_media_context_response(['ok' => false, 'error' => 'MEDIA_CONTEXT_ERROR'], 500);
}

try {
    if ($id < 1) {
        brvtal_media_context_response(['ok' => false, 'error' => 'MEDIA_ID_REQUIRED'], 422);
    }

    if ($method === 'GET') {
        $row = brvtal_media_context_row($pdo, $id);
        if (!$row) brvtal_media_context_response(['ok' => false, 'error' => 'MEDIA_NOT_FOUND'], 404);

        $ready = brvtal_media_relations_ready($pdo);
        brvtal_media_context_response([
            'ok' => true,
            'data' => [
                'media_id' => $id,
                'relations_ready' => $ready,
                'relations' => $ready ? brvtal_media_load_relations($pdo, $id) : [],
                'relation_catalog' => $ready ? brvtal_media_relation_catalog($pdo) : [],
            ],
        ]);
    }

    if ($method === 'PUT') {
        brvtal_admin_require_csrf();
        if (!brvtal_media_relations_ready($pdo)) {
            brvtal_media_context_response(['ok' => false, 'error' => 'MEDIA_RELATIONS_NOT_READY'], 409);
        }

        $raw = (string)file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            brvtal_media_context_response(['ok' => false, 'error' => 'INVALID_JSON'], 400);
        }
        if (!array_key_exists('relations', $data)) {
            brvtal_media_context_response(['ok' => false, 'error' => 'RELATIONS_REQUIRED'], 422);
        }
        $relations = brvtal_media_normalize_relations($data['relations']);

        $pdo->beginTransaction();
        try {
            $row = brvtal_media_context_row($pdo, $id, true);
            if (!$row) {
                $pdo->rollBack();
                brvtal_media_context_response(['ok' => false, 'error' => 'MEDIA_NOT_FOUND'], 404);
            }

            $title = brvtal_media_context_text($data['title'] ?? $row['title'], 180);
            if ($title === '') throw new InvalidArgumentException('TITLE_REQUIRED');
            $alt = brvtal_media_context_text($data['alt_text'] ?? $row['alt_text'], 255);
            $status = ($data['status'] ?? $row['status']) === 'draft' ? 'draft' : 'published';

            brvtal_media_replace_relations($pdo, $id, $relations);
            $statement = $pdo->prepare('UPDATE media SET title=?,alt_text=?,status=? WHERE id=?');
            $statement->execute([$title, $alt, $status, $id]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        brvtal_media_context_response([
            'ok' => true,
            'data' => [
                'media_id' => $id,
                'relations_ready' => true,
                'relations' => brvtal_media_load_relations($pdo, $id),
            ],
        ]);
    }

    brvtal_media_context_response(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED'], 405);
} catch (InvalidArgumentException $e) {
    brvtal_media_context_response(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (RuntimeException $e) {
    if ($e->getMessage() === 'MEDIA_RELATIONS_NOT_READY') {
        brvtal_media_context_response(['ok' => false, 'error' => 'MEDIA_RELATIONS_NOT_READY'], 409);
    }
    brvtal_media_context_fail($e, $id, $method);
} catch (Throwable $e) {
    brvtal_media_context_fail($e, $id, $method);
}
