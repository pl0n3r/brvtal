<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_auth.php';
require_once __DIR__ . '/../config/media_relations.php';

brvtal_admin_require();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

$pdo = db();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$mediaIdRaw = $_GET['media_id'] ?? '';
$mediaId = is_string($mediaIdRaw) && ctype_digit($mediaIdRaw) ? (int)$mediaIdRaw : 0;

function brvtal_media_relations_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function brvtal_media_relations_media_exists(PDO $pdo, int $mediaId, bool $lock = false): bool
{
    $sql = 'SELECT id FROM media WHERE id=?';
    if ($lock) $sql .= ' FOR UPDATE';
    $st = $pdo->prepare($sql);
    $st->execute([$mediaId]);
    return $st->fetchColumn() !== false;
}

try {
    if ($mediaId < 1) {
        brvtal_media_relations_json(['ok'=>false, 'error'=>'MEDIA_ID_REQUIRED'], 422);
    }

    if ($method === 'GET') {
        if (!brvtal_media_relations_media_exists($pdo, $mediaId)) {
            brvtal_media_relations_json(['ok'=>false, 'error'=>'MEDIA_NOT_FOUND'], 404);
        }
        $available = brvtal_media_relations_table_exists($pdo);
        brvtal_media_relations_json([
            'ok'=>true,
            'data'=>[
                'media_id'=>$mediaId,
                'available'=>$available,
                'relations'=>$available ? brvtal_media_relations_for_media($pdo, $mediaId) : [],
                'options'=>$available ? brvtal_media_relation_options($pdo) : [],
            ],
        ]);
    }

    if ($method === 'PUT') {
        brvtal_admin_require_csrf();
        $raw = (string)file_get_contents('php://input');
        $data = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($data) || !array_key_exists('relations', $data) || !is_array($data['relations'])) {
            brvtal_media_relations_json(['ok'=>false, 'error'=>'INVALID_MEDIA_RELATIONS'], 422);
        }
        if (!brvtal_media_relations_table_exists($pdo)) {
            brvtal_media_relations_json(['ok'=>false, 'error'=>'MEDIA_RELATIONS_MIGRATION_REQUIRED'], 409);
        }

        $pdo->beginTransaction();
        try {
            if (!brvtal_media_relations_media_exists($pdo, $mediaId, true)) {
                $pdo->rollBack();
                brvtal_media_relations_json(['ok'=>false, 'error'=>'MEDIA_NOT_FOUND'], 404);
            }
            $relations = brvtal_media_replace_relations($pdo, $mediaId, $data['relations']);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        brvtal_media_relations_json([
            'ok'=>true,
            'data'=>[
                'media_id'=>$mediaId,
                'available'=>true,
                'relations'=>$relations,
                'options'=>brvtal_media_relation_options($pdo),
            ],
        ]);
    }

    header('Allow: GET, PUT');
    brvtal_media_relations_json(['ok'=>false, 'error'=>'METHOD_NOT_ALLOWED'], 405);
} catch (InvalidArgumentException $e) {
    brvtal_media_relations_json(['ok'=>false, 'error'=>$e->getMessage()], 422);
} catch (Throwable $e) {
    if (function_exists('brvtal_log')) {
        brvtal_log('MEDIA_RELATIONS_ERROR', 'Media relation endpoint failed', [
            'media_id'=>$mediaId,
            'method'=>$method,
            'class'=>get_class($e),
            'message'=>$e->getMessage(),
        ]);
    }
    brvtal_media_relations_json(['ok'=>false, 'error'=>'MEDIA_RELATIONS_ERROR'], 500);
}
