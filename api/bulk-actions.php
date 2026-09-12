<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_activity.php';
require_once __DIR__ . '/bulk-actions-lib.php';

brvtal_admin_require();

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    json_response(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'], 405, ['Allow'=>'POST']);
}

brvtal_admin_require_csrf();

try {
    $pdo = db();
    $request = brvtal_bulk_normalize_request(input_json());
    $result = brvtal_bulk_apply($pdo, $request, static function (string $resource, int $id, array $before, array $after, string $label) use ($pdo, $request): void {
        brvtal_activity_record(
            $pdo,
            'bulk_status',
            $resource,
            $id,
            $before,
            $after,
            [
                'source'=>'bulk_actions',
                'batch_size'=>count((array)$request['ids']),
                'target_status'=>(string)$request['status'],
            ],
            $label
        );
    });
    brvtal_log('ADMIN_BULK_STATUS', 'Bulk content status updated', [
        'resource' => $result['resource'],
        'status' => $result['status'],
        'ids' => $result['ids'],
        'matched' => $result['matched'],
        'changed' => $result['changed'],
    ]);
    json_response(['ok'=>true,'data'=>$result]);
} catch (InvalidArgumentException $e) {
    json_response(['ok'=>false,'error'=>$e->getMessage()], 422);
} catch (RuntimeException $e) {
    if ($e->getMessage() === 'BULK_ITEMS_NOT_FOUND') {
        json_response(['ok'=>false,'error'=>'BULK_ITEMS_NOT_FOUND'], 409);
    }
    if ($e->getMessage() === 'ACTIVITY_SCHEMA_MISSING') {
        json_response(['ok'=>false,'error'=>'ACTIVITY_SCHEMA_MISSING'], 503);
    }
    brvtal_log('API_ERROR', 'Bulk action failed', ['message'=>$e->getMessage()]);
    json_response(['ok'=>false,'error'=>'BULK_ACTION_FAILED'], 500);
} catch (Throwable $e) {
    brvtal_log('API_ERROR', 'Bulk action failed', ['class'=>get_class($e),'message'=>$e->getMessage()]);
    json_response(['ok'=>false,'error'=>'BULK_ACTION_FAILED'], 500);
}
