<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_auth.php';
require_once __DIR__ . '/../config/event_analytics_signals.php';

brvtal_admin_require();

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    json_response(
        ['ok'=>false, 'error'=>'METHOD_NOT_ALLOWED'],
        405,
        ['Allow'=>'GET', 'Cache-Control'=>'no-store']
    );
}

foreach (array_keys($_GET) as $parameter) {
    if (!is_string($parameter) || !in_array($parameter, ['id', 'window'], true)) {
        json_response(['ok'=>false, 'error'=>'INVALID_PARAMETER'], 422, ['Cache-Control'=>'no-store']);
    }
}

$eventId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
if ($eventId === false) {
    json_response(['ok'=>false, 'error'=>'INVALID_EVENT_ID'], 422, ['Cache-Control'=>'no-store']);
}

$windowRaw = $_GET['window'] ?? '7d';
if (!is_string($windowRaw)) {
    json_response(['ok'=>false, 'error'=>'INVALID_WINDOW'], 422, ['Cache-Control'=>'no-store']);
}
$window = strtolower(trim($windowRaw));
if ($window !== '7d') {
    json_response(['ok'=>false, 'error'=>'INVALID_WINDOW'], 422, ['Cache-Control'=>'no-store']);
}

try {
    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT id, slug, status, event_date, published_at FROM events WHERE id = ? LIMIT 1'
    );
    $stmt->execute([(int)$eventId]);
    $event = $stmt->fetch();

    if (!is_array($event)) {
        json_response(['ok'=>false, 'error'=>'EVENT_NOT_FOUND'], 404, ['Cache-Control'=>'no-store']);
    }

    $data = brvtalEventAnalyticsSignals($event);
    json_response(
        ['ok'=>true, 'data'=>$data],
        200,
        ['Cache-Control'=>'no-store']
    );
} catch (Throwable $e) {
    if (function_exists('brvtal_log')) {
        brvtal_log('ADMIN_EVENT_ANALYTICS_ERROR', 'Event analytics endpoint failed', [
            'class'=>$e::class,
            'event_id'=>(int)$eventId,
        ]);
    }
    json_response(['ok'=>false, 'error'=>'EVENT_ANALYTICS_UNAVAILABLE'], 503, ['Cache-Control'=>'no-store']);
}
