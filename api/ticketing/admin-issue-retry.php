<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/admin_auth.php';
require_once __DIR__ . '/../../config/ticketing_staff_permissions.php';
require_once __DIR__ . '/../../config/ticketing_issue_delivery.php';

/** #1976: bring forward the next issuance attempt of one paid order. Never issues here. */
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
brvtal_admin_require();
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    json_response(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'], 405, ['Allow'=>'POST']);
}
brvtal_admin_require_csrf();
try {
    $db = db();
    $q = $db->prepare('SELECT staff_role FROM admins WHERE id=? AND is_active=1 LIMIT 1');
    $q->execute([(int)($_SESSION['admin_id'] ?? 0)]);
    if (!brvtalTicketingCan((string)($q->fetchColumn() ?: ''), 'mail')) {
        json_response(['ok'=>false,'error'=>'FORBIDDEN'], 403);
    }
    if (!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')
        || (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 512) {
        json_response(['ok'=>false,'error'=>'INVALID_CONTENT_TYPE'], 415);
    }
    $raw = file_get_contents('php://input', false, null, 0, 513);
    $body = is_string($raw) && strlen($raw) <= 512 ? json_decode($raw, true, 4, JSON_THROW_ON_ERROR) : null;
    $orderId = is_array($body) ? filter_var($body['order_id'] ?? null, FILTER_VALIDATE_INT) : false;
    if (!is_int($orderId) || $orderId < 1) throw new InvalidArgumentException();
    if (!brvtal_ticket_issue_retry_now($db, $orderId)) {
        json_response(['ok'=>false,'error'=>'NOTHING_TO_RETRY'], 409);
    }
    json_response(['ok'=>true,'data'=>['scheduled'=>true]]);
} catch (InvalidArgumentException|JsonException) {
    json_response(['ok'=>false,'error'=>'INVALID_REQUEST'], 422);
} catch (Throwable) {
    json_response(['ok'=>false,'error'=>'RETRY_UNAVAILABLE'], 503);
}
