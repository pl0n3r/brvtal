<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_activity.php';
require_once __DIR__ . '/../config/seo_defaults.php';
require_once __DIR__ . '/../config/seo_persistence.php';
require_once __DIR__ . '/../config/seo_workspace.php';
require_once __DIR__ . '/../config/indexnow.php';

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

    $resources = brvtalSeoWorkspaceEntityDefinitions();

    $resource = strtolower(trim((string)($_GET['resource'] ?? '')));
    $id = (int)($_GET['id'] ?? 0);
    if (!isset($resources[$resource])) brvtal_seo_json(['ok'=>false,'error'=>'INVALID_RESOURCE'], 422);
    if ($id < 1) brvtal_seo_json(['ok'=>false,'error'=>'ID_REQUIRED'], 422);

    $pdo = db();
    $definition = $resources[$resource];
    $table = $definition['table'];
    $titleField = $definition['title'];
    $descriptionField = $definition['description'];
    if (!brvtal_seo_table_columns_ready($pdo, $table)) {
        brvtal_seo_json(['ok'=>false,'error'=>'SEO_SCHEMA_MISSING'], 503);
    }

    if ($method === 'GET') {
        $st = $pdo->prepare("SELECT id,seo_title,seo_description,`{$titleField}` AS source_title,`{$descriptionField}` AS source_description FROM `{$table}` WHERE id=? LIMIT 1");
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) brvtal_seo_json(['ok'=>false,'error'=>'NOT_FOUND'], 404);
        $row['id'] = (int)$row['id'];
        $row['seo_title'] = trim((string)($row['seo_title'] ?? '')) !== ''
            ? (string)$row['seo_title']
            : brvtal_seo_default_title($row['source_title'] ?? '');
        $sourceDescription = brvtalSeoWorkspaceSourceDescription(
            $resource,
            $row['source_description'] ?? ''
        );
        $row['seo_description'] = trim((string)($row['seo_description'] ?? '')) !== ''
            ? (string)$row['seo_description']
            : brvtal_seo_default_description($sourceDescription, 160);
        unset($row['source_title'], $row['source_description']);
        brvtal_seo_json(['ok'=>true,'data'=>$row]);
    }

    brvtal_admin_require_csrf();
    $body = brvtal_seo_body();
    try {
        $after = brvtalSeoPersistOverrides($pdo, $resource, $id, $definition, $body);
    } catch (RuntimeException $error) {
        if ($error->getMessage() === 'SEO_RESOURCE_NOT_FOUND') {
            brvtal_seo_json(['ok'=>false,'error'=>'NOT_FOUND'], 404);
        }
        throw $error;
    }
    $seoTitle = $after['seo_title'];
    $seoDescription = $after['seo_description'];

    brvtalIndexNowNotifyEntityId($pdo, $resource, $id);

    brvtal_seo_json([
        'ok'=>true,
        'data'=>[
            'id'=>$id,
            'resource'=>$resource,
            'seo_title'=>$seoTitle,
            'seo_description'=>$seoDescription,
        ],
    ]);
} catch (Throwable $e) {
    if ($e instanceof RuntimeException && $e->getMessage() === 'ACTIVITY_SCHEMA_MISSING') {
        brvtal_seo_json(['ok'=>false,'error'=>'ACTIVITY_SCHEMA_MISSING'], 503);
    }
    if (function_exists('brvtal_log')) {
        brvtal_log('SEO_METADATA_ERROR', 'SEO metadata request failed', [
            'class'=>get_class($e),
            'message'=>$e->getMessage(),
        ]);
    }
    brvtal_seo_json(['ok'=>false,'error'=>'INTERNAL_ERROR'], 500);
}
