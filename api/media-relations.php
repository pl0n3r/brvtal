<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_auth.php';
require_once __DIR__ . '/../config/media.php';
require_once __DIR__ . '/../config/media_integrity.php';
require_once __DIR__ . '/../config/media_relations.php';

brvtal_admin_require();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$pdo = db();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$id = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;

function brvtal_media_relations_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function brvtal_media_relations_find(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id,type,title,file_path,mime_type,file_size,alt_text,status,created_at FROM media WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch();
    return is_array($row) ? $row : null;
}

function brvtal_media_relations_payload(PDO $pdo, array $row): array
{
    $asset = brvtal_media_asset_payload($row);
    $ready = brvtal_media_relations_ready($pdo);
    $asset['relations_available'] = $ready;
    $asset['relations'] = $ready ? brvtal_media_relations_list($pdo, (int)$row['id']) : [];
    $asset['relation_options'] = $ready ? brvtal_media_relation_options($pdo) : [];
    $asset['usage'] = brvtal_media_integrity_usage($pdo, $row);
    $asset['usage_count'] = count($asset['usage']);
    return $asset;
}

try {
    if ($method !== 'GET') {
        header('Allow: GET');
        brvtal_media_relations_response(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'], 405);
    }
    if ($id < 1) brvtal_media_relations_response(['ok'=>false,'error'=>'MEDIA_ID_REQUIRED'], 422);

    $row = brvtal_media_relations_find($pdo, $id);
    if (!$row) brvtal_media_relations_response(['ok'=>false,'error'=>'MEDIA_NOT_FOUND'], 404);
    brvtal_media_relations_response(['ok'=>true,'data'=>brvtal_media_relations_payload($pdo, $row)]);
} catch (Throwable $e) {
    if (function_exists('brvtal_log')) {
        brvtal_log('MEDIA_RELATIONS_ERROR', 'Media relations request failed.', ['class'=>get_class($e),'message'=>$e->getMessage()]);
    }
    brvtal_media_relations_response(['ok'=>false,'error'=>'INTERNAL_ERROR'], 500);
}