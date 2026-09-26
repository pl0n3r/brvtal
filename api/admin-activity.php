<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_activity.php';

brvtal_admin_require();

function brvtal_activity_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function brvtal_activity_decode(?string $value): mixed
{
    if ($value === null || $value === '') return null;
    $decoded = json_decode($value, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
}

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        header('Allow: GET');
        brvtal_activity_json(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'], 405);
    }

    $pdo = db();
    if (!brvtal_activity_schema_ready($pdo)) {
        brvtal_activity_json(['ok'=>false,'error'=>'ACTIVITY_SCHEMA_MISSING'], 503);
    }

    $detailId = (int)($_GET['id'] ?? 0);
    if ($detailId > 0) {
        $st = $pdo->prepare(
            'SELECT id,admin_id,admin_name,admin_email,action,resource,resource_id,resource_label,changed_fields,before_json,after_json,meta_json,request_id,created_at
             FROM admin_activity_log WHERE id=? LIMIT 1'
        );
        $st->execute([$detailId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) brvtal_activity_json(['ok'=>false,'error'=>'ACTIVITY_NOT_FOUND'], 404);
        $row['id'] = (int)$row['id'];
        $row['admin_id'] = $row['admin_id'] !== null ? (int)$row['admin_id'] : null;
        $row['resource_id'] = $row['resource_id'] !== null ? (int)$row['resource_id'] : null;
        $row['changed_fields'] = brvtal_activity_decode($row['changed_fields']);
        $row['before'] = brvtal_activity_decode($row['before_json']);
        $row['after'] = brvtal_activity_decode($row['after_json']);
        $row['meta'] = brvtal_activity_decode($row['meta_json']);
        unset($row['before_json'], $row['after_json'], $row['meta_json']);
        brvtal_activity_json(['ok'=>true,'data'=>$row]);
    }

    $where = [];
    $params = [];
    $resource = strtolower(trim((string)($_GET['resource'] ?? '')));
    if ($resource !== '') {
        if (!preg_match('/^[a-z0-9_-]{1,40}$/', $resource)) brvtal_activity_json(['ok'=>false,'error'=>'INVALID_RESOURCE'], 422);
        $where[] = 'resource=?';
        $params[] = $resource;
    }
    $resourceId = (int)($_GET['resource_id'] ?? 0);
    if ($resourceId > 0) {
        $where[] = 'resource_id=?';
        $params[] = $resourceId;
    }
    $action = strtolower(trim((string)($_GET['action'] ?? '')));
    if ($action !== '') {
        if (!preg_match('/^[a-z0-9_-]{1,40}$/', $action)) brvtal_activity_json(['ok'=>false,'error'=>'INVALID_ACTION'], 422);
        $where[] = 'action=?';
        $params[] = $action;
    }
    $adminId = (int)($_GET['admin_id'] ?? 0);
    if ($adminId > 0) {
        $where[] = 'admin_id=?';
        $params[] = $adminId;
    }

    $history = (string)($_GET['history'] ?? '') === '1';
    if ($history && ($resource === '' || $resourceId < 1)) {
        brvtal_activity_json(['ok'=>false,'error'=>'HISTORY_RESOURCE_REQUIRED'], 422);
    }

    $limit = max(1, min(100, (int)($_GET['limit'] ?? ($history ? 50 : 20))));
    $cursorRaw = trim((string)($_GET['cursor'] ?? ''));
    $cursor = null;
    if ($cursorRaw !== '') {
        if (!ctype_digit($cursorRaw) || (int)$cursorRaw < 1) {
            brvtal_activity_json(['ok'=>false,'error'=>'INVALID_CURSOR'], 422);
        }
        $cursor = (int)$cursorRaw;
    }

    $filters = [];
    if ($resource !== '') {
        $filters['resource'] = $resource;
    }
    if ($resourceId > 0) {
        $filters['resource_id'] = $resourceId;
    }
    if ($action !== '') {
        $filters['action'] = $action;
    }
    if ($adminId > 0) {
        $filters['admin_id'] = $adminId;
    }
    $page = brvtalActivityPage($pdo, $filters, $limit, $cursor, $history);
    $items = $page['items'];
    foreach ($items as &$row) {
        $row['id'] = (int)$row['id'];
        $row['admin_id'] = $row['admin_id'] !== null ? (int)$row['admin_id'] : null;
        $row['resource_id'] = $row['resource_id'] !== null ? (int)$row['resource_id'] : null;
        $row['changed_fields'] = brvtal_activity_decode($row['changed_fields']) ?? [];
        if ($history) {
            $row['before'] = brvtal_activity_decode($row['before_json']);
            $row['after'] = brvtal_activity_decode($row['after_json']);
            unset($row['before_json'], $row['after_json']);
        }
    }
    unset($row);

    brvtal_activity_json([
        'ok'=>true,
        'data'=>[
            'items'=>$items,
            'total'=>$page['total'],
            'limit'=>$page['limit'],
            'returned'=>$page['returned'],
            'has_more'=>$page['has_more'],
            'next_cursor'=>$page['next_cursor'],
            'read_only'=>true,
            'mode'=>$history ? 'content_history' : 'activity',
        ],
    ]);
} catch (Throwable $e) {
    if (function_exists('brvtal_log')) {
        brvtal_log('ADMIN_ACTIVITY_ERROR', 'Admin activity request failed', [
            'class'=>get_class($e),
            'message'=>$e->getMessage(),
        ]);
    }
    brvtal_activity_json(['ok'=>false,'error'=>'INTERNAL_ERROR'], 500);
}
