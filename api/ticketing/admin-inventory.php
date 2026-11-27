<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/admin_auth.php';
require_once __DIR__ . '/../../config/ticketing_staff_permissions.php';
require_once __DIR__ . '/../../config/ticketing_inventory_admin.php';

/** #1931 · Capacity for online sale, edited from the DISCADMIN event editor. */
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
brvtal_admin_require();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$writingInventory = $method === 'POST';
if (!$writingInventory && $method !== 'GET') {
    json_response(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'], 405, ['Allow'=>'GET, POST']);
}
if ($writingInventory) {
    brvtal_admin_require_csrf();
}
try {
    $pdo = db();
    $q = $pdo->prepare('SELECT staff_role FROM admins WHERE id=? AND is_active=1 LIMIT 1');
    $q->execute([(int)($_SESSION['admin_id'] ?? 0)]);
    if (!brvtalTicketingCan((string)($q->fetchColumn() ?: ''), 'settings')) {
        json_response(['ok'=>false,'error'=>'FORBIDDEN'], 403);
    }
    $eventId = filter_var($_GET['event_id'] ?? null, FILTER_VALIDATE_INT);
    if (!is_int($eventId) || $eventId < 1) throw new InvalidArgumentException('INVENTORY_INVALID_EVENT');
    if ($method === 'GET') {
        json_response(['ok'=>true] + brvtal_ticket_inventory_admin_read($pdo, $eventId));
    }
    $typeHeader = (string)($_SERVER['CONTENT_TYPE'] ?? '');
    $mimeType = strtolower(trim(explode(';', $typeHeader, 2)[0]));
    $maximumBytes = 8192;
    if ($mimeType !== 'application/json'
        || (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $maximumBytes) {
        json_response(['ok'=>false,'error'=>'INVALID_CONTENT_TYPE'], 415);
    }
    $raw = file_get_contents('php://input', false, null, 0, $maximumBytes + 1);
    if (!is_string($raw) || strlen($raw) > $maximumBytes) {
        throw new InvalidArgumentException('INVENTORY_INVALID_INPUT');
    }
    $input = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    json_response(['ok'=>true] + brvtal_ticket_inventory_admin_save($pdo, $eventId, $input));
} catch (InvalidArgumentException $e) {
    $code = $e->getMessage() === 'INVENTORY_INVALID_DOOR_PRICE' ? 'INVENTORY_INVALID_DOOR_PRICE' : 'INVENTORY_INVALID_INPUT';
    json_response(['ok'=>false,'error'=>$code], 422);
} catch (JsonException) {
    json_response(['ok'=>false,'error'=>'INVENTORY_INVALID_INPUT'], 422);
} catch (DomainException $e) {
    $code = $e->getMessage();
    json_response(['ok'=>false,'error'=>$code], $code === 'INVENTORY_EVENT_NOT_FOUND' ? 404 : 409);
} catch (Throwable $e) {
    brvtal_log('TICKET_INVENTORY_ADMIN_ERROR', 'Ticket inventory admin operation failed', ['class'=>$e::class]);
    json_response(['ok'=>false,'error'=>'INVENTORY_UNAVAILABLE'], 503);
}
