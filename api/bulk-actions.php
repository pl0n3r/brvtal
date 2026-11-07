<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_activity.php';
require_once __DIR__ . '/../config/indexnow.php';
require_once __DIR__ . '/bulk-actions-lib.php';

brvtal_admin_require();

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    json_response(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'], 405, ['Allow'=>'POST']);
}

brvtal_admin_require_csrf();

try {
    $pdo = db();
    $request = brvtal_bulk_normalize_request(input_json());
    $specs = brvtal_bulk_resource_specs();
    $spec = $specs[(string)$request['resource']];
    $ids = array_values((array)$request['ids']);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $beforeIndexStatement = $pdo->prepare(
        "SELECT * FROM {$spec['table']} WHERE id IN ({$placeholders})"
    );
    $beforeIndexStatement->execute($ids);
    $beforeIndexRows = [];
    foreach ($beforeIndexStatement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $beforeIndexRows[(int)$row['id']] = $row;
    }

    $deleting = $request['action'] === 'delete_selected';
    $deletedBefore = [];
    $audit = static function (string $resource, int $id, array $before, array $after, string $label) use ($pdo, $request, $deleting, &$deletedBefore): void {
        if ($deleting) $deletedBefore[$id] = $before; // Locked row, not the pre-lock snapshot.
        brvtal_activity_record(
            $pdo,
            $deleting ? 'bulk_delete' : 'bulk_status',
            $resource,
            $id,
            $before,
            $after,
            [
                'source'=>'bulk_actions',
                'batch_size'=>count((array)$request['ids']),
                'target_status'=>$deleting ? null : (string)$request['status'],
            ],
            $label
        );
    };
    $result = $deleting ? brvtal_bulk_delete_sets($pdo,$request,$audit) : brvtal_bulk_apply($pdo, $request, $audit);
    try {
        foreach ((array)$result['ids'] as $changedId) {
            $changedId = (int)$changedId;
            $beforeIndex = $deleting ? ($deletedBefore[$changedId] ?? null) : ($beforeIndexRows[$changedId] ?? null);
            $afterIndex = brvtalIndexNowFetchEntity(
                $pdo,
                (string)$result['resource'],
                $changedId
            );
            $beforeStatus = is_array($beforeIndex) ? (string)($beforeIndex['status'] ?? '') : '';
            $afterStatus = is_array($afterIndex) ? (string)($afterIndex['status'] ?? '') : '';
            if ($deleting || $beforeStatus !== $afterStatus) {
                brvtalIndexNowNotifyChange(
                    $pdo,
                    (string)$result['resource'],
                    $beforeIndex,
                    $afterIndex
                );
            }
        }
    } catch (Throwable $indexNowError) {
        brvtal_log('INDEXNOW_BULK_NOTIFY_FAILED', 'Bulk IndexNow notification failed', [
            'resource' => (string)$result['resource'],
            'class' => $indexNowError::class,
        ]);
    }

    brvtal_log($deleting ? 'ADMIN_BULK_DELETE' : 'ADMIN_BULK_STATUS',
        $deleting ? 'Bulk content deleted' : 'Bulk content status updated', [
        'resource' => $result['resource'],
        'action' => $request['action'],
        'status' => $result['status'] ?? null,
        'ids' => $result['ids'],
        'matched' => $result['matched'],
        'changed' => $result['changed'] ?? null,
        'deleted' => $result['deleted'] ?? null,
    ]);
    json_response(['ok'=>true,'data'=>$result]);
} catch (InvalidArgumentException $e) {
    json_response(['ok'=>false,'error'=>$e->getMessage()], 422);
} catch (RuntimeException $e) {
    if ($e->getMessage() === 'BULK_ITEMS_NOT_FOUND') {
        json_response(['ok'=>false,'error'=>'BULK_ITEMS_NOT_FOUND'], 409);
    }
    if ($e->getMessage() === 'BULK_SET_REFERENCED') {
        json_response(['ok'=>false,'error'=>'BULK_SET_REFERENCED'], 409);
    }
    if ($e->getMessage() === 'ACTIVITY_SCHEMA_MISSING') {
        json_response(['ok'=>false,'error'=>'ACTIVITY_SCHEMA_MISSING'], 503);
    }
    brvtal_log('API_ERROR', 'Bulk action failed', ['message'=>$e->getMessage()]);
    json_response(['ok'=>false,'error'=>'BULK_ACTION_FAILED'], 500);
} catch (Throwable $e) {
    brvtal_log('API_ERROR', 'Bulk action failed', ['class'=>$e::class,'message'=>$e->getMessage()]);
    json_response(['ok'=>false,'error'=>'BULK_ACTION_FAILED'], 500);
}
