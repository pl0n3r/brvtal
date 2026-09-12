<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_auth.php';
require_once __DIR__ . '/bulk-actions-lib.php';

brvtal_admin_require();

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    json_response(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'], 405, ['Allow'=>'POST']);
}

brvtal_admin_require_csrf();

try {
    $request = brvtal_bulk_normalize_request(input_json());
    $result = brvtal_bulk_apply(db(), $request);
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
    brvtal_log('API_ERROR', 'Bulk action failed', ['message'=>$e->getMessage()]);
    json_response(['ok'=>false,'error'=>'BULK_ACTION_FAILED'], 500);
} catch (Throwable $e) {
    brvtal_log('API_ERROR', 'Bulk action failed', ['class'=>get_class($e),'message'=>$e->getMessage()]);
    json_response(['ok'=>false,'error'=>'BULK_ACTION_FAILED'], 500);
}
