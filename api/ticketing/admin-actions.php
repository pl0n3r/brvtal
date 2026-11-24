<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/admin_auth.php';
require_once __DIR__ . '/../../config/ticketing_admin_actions.php';

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
brvtal_admin_require();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405,['Allow'=>'POST']);
}
brvtal_admin_require_csrf();
if (!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')
    || (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 2048) {
    json_response(['ok'=>false,'error'=>'INVALID_CONTENT_TYPE'],415);
}
try {
    $body = file_get_contents('php://input', false, null, 0, 2049);
    if (!is_string($body) || strlen($body) > 2048) {
        json_response(['ok'=>false,'error'=>'INVALID_PAYLOAD'],413);
    }
    $decoded = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || array_is_list($decoded)) {
        json_response(['ok'=>false,'error'=>'INVALID_PAYLOAD'],422);
    }
    $input = brvtalTicketAdminActionInput($decoded);
    $pdo = db();
    $roleQuery = $pdo->prepare('SELECT staff_role FROM admins WHERE id=? AND is_active=1 LIMIT 1');
    $actorId = (int)($_SESSION['admin_id'] ?? 0);
    $roleQuery->execute([$actorId]);
    $role = (string)($roleQuery->fetchColumn() ?: '');
    $receipt = brvtalTicketAdminRecordAction($pdo,$actorId,$role,$input);
    json_response(['ok'=>$receipt['status'] !== 'blocked', 'data'=>$receipt,
        'error'=>$receipt['status'] === 'blocked' ? 'CONSENT_REQUIRED' : null],
        $receipt['status'] === 'blocked' ? 409 : 200);
} catch (InvalidArgumentException | JsonException) {
    json_response(['ok'=>false,'error'=>'INVALID_PAYLOAD'],422);
} catch (DomainException $e) {
    $code = $e->getMessage();
    $status = match ($code) {
        'FORBIDDEN' => 403, 'ORDER_NOT_FOUND' => 404,
        'IDEMPOTENCY_CONFLICT' => 409, 'SYNTHETIC_ONLY' => 423,
        'ORDER_NOT_PENDING' => 409, 'ORDER_NOT_PAID' => 409,
        'PAYMENT_RECONCILIATION_REQUIRED' => 409,
        'ORDER_ALREADY_ISSUED' => 409, 'TICKETS_NOT_ISSUED' => 409,
        'MAIL_PAYLOAD_NOT_RECOVERABLE' => 409, 'MAIL_NOT_ENABLED' => 423,
        'MAIL_RATE_LIMITED' => 429,
        default => 403,
    };
    json_response(['ok'=>false,'error'=>$code],$status);
} catch (Throwable) {
    // No exception text, request body, email or note is logged or returned.
    json_response(['ok'=>false,'error'=>'ACTION_UNAVAILABLE'],503);
}
