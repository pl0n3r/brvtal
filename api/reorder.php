<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_activity.php';
require_once __DIR__ . '/../config/content_ordering.php';

brvtal_admin_require();

function brvtal_order_json(array $payload, int $status = 200): never
{
    json_response($payload, $status, ['Cache-Control'=>'no-store']);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'POST') {
    brvtal_order_json(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'], 405);
}
brvtal_admin_require_csrf();

try {
    $input = input_json();
    $resource = strtolower(trim((string)($input['resource'] ?? '')));
    $definition = brvtal_content_order_resource($resource);
    if ($definition === null) {
        brvtal_order_json(['ok'=>false,'error'=>'ORDER_RESOURCE_NOT_ALLOWED'], 422);
    }
    $ids = brvtal_content_order_ids($input['ids'] ?? null);
    $table = $definition['table'];
    $labelColumn = $definition['label'];
    $activityResource = $definition['activity'];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $rows = $pdo->query(
            "SELECT id,sort_order,{$labelColumn} AS label FROM {$table} ORDER BY sort_order ASC,id ASC FOR UPDATE"
        )->fetchAll(PDO::FETCH_ASSOC);
        $currentIds = array_map(static fn(array $row): int => (int)$row['id'], $rows);
        if (!brvtal_content_order_matches($ids, $currentIds)) {
            throw new RuntimeException('ORDER_STALE', 409);
        }

        $byId = [];
        foreach ($rows as $row) $byId[(int)$row['id']] = $row;
        $update = $pdo->prepare("UPDATE {$table} SET sort_order=? WHERE id=?");
        $changed = 0;

        foreach ($ids as $position => $id) {
            $row = $byId[$id];
            if ((int)$row['sort_order'] === $position) continue;
            $before = ['id'=>$id,$labelColumn=>(string)$row['label'],'sort_order'=>(int)$row['sort_order']];
            $update->execute([$position,$id]);
            if ($update->rowCount() < 1) throw new RuntimeException('ORDER_STALE', 409);
            $after = $before;
            $after['sort_order'] = $position;
            brvtal_activity_record(
                $pdo, 'update', $activityResource, $id, $before, $after,
                ['source'=>'content_ordering','position'=>$position], (string)$row['label']
            );
            $changed++;
        }

        $pdo->commit();
        brvtal_order_json(['ok'=>true,'resource'=>$resource,'ids'=>$ids,'changed'=>$changed]);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
} catch (InvalidArgumentException $error) {
    brvtal_order_json(['ok'=>false,'error'=>$error->getMessage()], 422);
} catch (RuntimeException $error) {
    if ($error->getMessage() === 'ACTIVITY_SCHEMA_MISSING') {
        brvtal_order_json(['ok'=>false,'error'=>'ACTIVITY_SCHEMA_MISSING'], 503);
    }
    $status = $error->getCode() >= 400 && $error->getCode() <= 599 ? $error->getCode() : 500;
    brvtal_order_json(['ok'=>false,'error'=>$error->getMessage()], $status);
} catch (Throwable $error) {
    if (function_exists('brvtal_log')) {
        brvtal_log('CONTENT_ORDER_ERROR','Content reorder failed',[
            'class'=>get_class($error),'message'=>$error->getMessage()
        ]);
    }
    brvtal_order_json(['ok'=>false,'error'=>'INTERNAL_ERROR'], 500);
}
