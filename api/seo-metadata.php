<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_activity.php';

brvtal_admin_require();

function brvtal_seo_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function brvtal_seo_body(): array
{
    $raw = (string)file_get_contents('php://input');
    if ($raw === '') return $_POST;
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function brvtal_seo_table_columns_ready(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE()
           AND TABLE_NAME=?
           AND COLUMN_NAME IN ('seo_title','seo_description')"
    );
    $st->execute([$table]);
    return (int)$st->fetchColumn() === 2;
}

try {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET','PUT'], true)) {
        header('Allow: GET, PUT');
        brvtal_seo_json(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'], 405);
    }

    $resources = [
        'events' => 'events',
        'artists' => 'artists',
        'sets' => 'sets_media',
        'releases' => 'releases',
    ];

    $resource = strtolower(trim((string)($_GET['resource'] ?? '')));
    $id = (int)($_GET['id'] ?? 0);
    if (!isset($resources[$resource])) brvtal_seo_json(['ok'=>false,'error'=>'INVALID_RESOURCE'], 422);
    if ($id < 1) brvtal_seo_json(['ok'=>false,'error'=>'ID_REQUIRED'], 422);

    $pdo = db();
    $table = $resources[$resource];
    if (!brvtal_seo_table_columns_ready($pdo, $table)) {
        brvtal_seo_json(['ok'=>false,'error'=>'SEO_SCHEMA_MISSING'], 503);
    }

    if ($method === 'GET') {
        $st = $pdo->prepare("SELECT id,seo_title,seo_description FROM `{$table}` WHERE id=? LIMIT 1");
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) brvtal_seo_json(['ok'=>false,'error'=>'NOT_FOUND'], 404);
        $row['id'] = (int)$row['id'];
        brvtal_seo_json(['ok'=>true,'data'=>$row]);
    }

    brvtal_admin_require_csrf();
    $body = brvtal_seo_body();
    $seoTitle = mb_substr(trim((string)($body['seo_title'] ?? '')), 0, 190);
    $seoDescription = mb_substr(trim((string)($body['seo_description'] ?? '')), 0, 320);

    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare("SELECT id,seo_title,seo_description FROM `{$table}` WHERE id=? LIMIT 1 FOR UPDATE");
        $lock->execute([$id]);
        $before = $lock->fetch(PDO::FETCH_ASSOC);
        if (!$before) {
            $pdo->rollBack();
            brvtal_seo_json(['ok'=>false,'error'=>'NOT_FOUND'], 404);
        }

        $st = $pdo->prepare("UPDATE `{$table}` SET seo_title=?,seo_description=? WHERE id=?");
        $st->execute([
            $seoTitle !== '' ? $seoTitle : null,
            $seoDescription !== '' ? $seoDescription : null,
            $id,
        ]);
        $after = [
            'id'=>$id,
            'seo_title'=>$seoTitle !== '' ? $seoTitle : null,
            'seo_description'=>$seoDescription !== '' ? $seoDescription : null,
        ];
        if (brvtal_activity_changed_fields(
            brvtal_activity_snapshot($resource, $before),
            brvtal_activity_snapshot($resource, $after)
        ) !== []) {
            brvtal_activity_record($pdo, 'seo_update', $resource, $id, $before, $after, ['source'=>'seo_metadata']);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    brvtal_seo_json([
        'ok'=>true,
        'data'=>[
            'id'=>$id,
            'resource'=>$resource,
            'seo_title'=>$seoTitle,
            'seo_description'=>$seoDescription,
        ],
    ]);
} catch (RuntimeException $e) {
    if ($e->getMessage() === 'ACTIVITY_SCHEMA_MISSING') {
        brvtal_seo_json(['ok'=>false,'error'=>'ACTIVITY_SCHEMA_MISSING'], 503);
    }
    throw $e;
} catch (Throwable $e) {
    if (function_exists('brvtal_log')) {
        brvtal_log('SEO_METADATA_ERROR', 'SEO metadata request failed', [
            'class'=>get_class($e),
            'message'=>$e->getMessage(),
        ]);
    }
    brvtal_seo_json(['ok'=>false,'error'=>'INTERNAL_ERROR'], 500);
}
