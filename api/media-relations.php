<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_auth.php';
require_once __DIR__ . '/../config/media_relations.php';

brvtal_admin_require();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

$pdo = db();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$mediaId = filter_var($_GET['media_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);

function brvtal_media_relations_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($mediaId === false || $mediaId === null) {
    brvtal_media_relations_response(['ok'=>false,'error'=>'MEDIA_ID_REQUIRED'], 422);
}

try {
    $st = $pdo->prepare('SELECT id,title FROM media WHERE id=? LIMIT 1');
    $st->execute([(int)$mediaId]);
    $media = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($media)) brvtal_media_relations_response(['ok'=>false,'error'=>'MEDIA_NOT_FOUND'], 404);

    if ($method === 'GET') {
        $available = brvtal_media_relations_table_exists($pdo);
        brvtal_media_relations_response([
            'ok'=>true,
            'data'=>[
                'media_id'=>(int)$mediaId,
                'available'=>$available,
                'relations'=>$available ? brvtal_media_relations_for($pdo, (int)$mediaId) : [],
                'options'=>brvtal_media_relation_options($pdo),
            ],
        ]);
    }

    if ($method === 'PUT') {
        brvtal_admin_require_csrf();
        if (!brvtal_media_relations_table_exists($pdo)) {
            brvtal_media_relations_response(['ok'=>false,'error'=>'MEDIA_RELATIONS_MIGRATION_REQUIRED'], 409);
        }
        $raw = (string)file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!is_array($data) || !is_array($data['relations'] ?? null)) {
            brvtal_media_relations_response(['ok'=>false,'error'=>'INVALID_RELATIONS_PAYLOAD'], 422);
        }
        try {
            $relations = brvtal_media_relations_replace($pdo, (int)$mediaId, $data['relations']);
        } catch (InvalidArgumentException $e) {
            brvtal_media_relations_response(['ok'=>false,'error'=>$e->getMessage()], 422);
        }
        brvtal_media_relations_response(['ok'=>true,'data'=>['media_id'=>(int)$mediaId,'relations'=>$relations]]);
    }

    header('Allow: GET, PUT');
    brvtal_media_relations_response(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'], 405);
} catch (Throwable $e) {
    if (function_exists('brvtal_log')) brvtal_log('MEDIA_RELATIONS_ERROR', 'Media relation endpoint failed', ['class'=>get_class($e)]);
    brvtal_media_relations_response(['ok'=>false,'error'=>'MEDIA_RELATIONS_ERROR'], 500);
}
