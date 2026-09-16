<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_activity.php';
require_once __DIR__ . '/event-workflow-lib.php';

brvtal_admin_require();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    json_response(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'], 405, ['Allow'=>'POST']);
}

brvtal_admin_require_csrf();

try {
    $pdo = db();
    $request = brvtal_event_workflow_request(input_json());
    $result = brvtal_event_workflow_apply(
        $pdo,
        $request,
        static function (
            string $action,
            string $resource,
            int $id,
            ?array $before,
            ?array $after,
            array $meta,
            ?string $label
        ) use ($pdo): void {
            brvtal_activity_record($pdo, $action, $resource, $id, $before, $after, $meta, $label);
        }
    );
    brvtal_log('ADMIN_EVENT_WORKFLOW', 'Event workflow saved atomically', [
        'event_id'=>(int)$result['event']['id'],
        'ticket_count'=>count($result['ticket_types']),
        'lineup_count'=>count($result['lineup']),
    ]);
    json_response(['ok'=>true,'data'=>$result]);
} catch (InvalidArgumentException $e) {
    json_response(['ok'=>false,'error'=>$e->getMessage()], 422);
} catch (RuntimeException $e) {
    if ($e->getMessage() === 'EVENT_NOT_FOUND') {
        json_response(['ok'=>false,'error'=>'EVENT_NOT_FOUND'], 404);
    }
    if ($e->getMessage() === 'ACTIVITY_SCHEMA_MISSING') {
        json_response(['ok'=>false,'error'=>'ACTIVITY_SCHEMA_MISSING'], 503);
    }
    brvtal_log('API_ERROR', 'Event workflow failed', ['message'=>$e->getMessage()]);
    json_response(['ok'=>false,'error'=>'EVENT_WORKFLOW_FAILED'], 500);
} catch (PDOException $e) {
    $code = (int)($e->errorInfo[1] ?? 0);
    brvtal_log('DB_ERROR', 'Event workflow database failure', ['code'=>$code,'message'=>$e->getMessage()]);
    if ($code === 1062) json_response(['ok'=>false,'error'=>'DUPLICATE_SLUG'], 409);
    json_response(['ok'=>false,'error'=>'DATABASE_ERROR'], 500);
} catch (Throwable $e) {
    brvtal_log('API_ERROR', 'Event workflow failed', ['class'=>get_class($e),'message'=>$e->getMessage()]);
    json_response(['ok'=>false,'error'=>'EVENT_WORKFLOW_FAILED'], 500);
}
