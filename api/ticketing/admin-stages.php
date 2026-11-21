<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/admin_auth.php';
require_once __DIR__.'/../../config/ticketing_staff_permissions.php';
require_once __DIR__.'/../../config/ticketing_stages.php';
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
brvtal_admin_require();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method,['GET','POST'],true)) {
    json_response(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405,['Allow'=>'GET, POST']);
}
if ($method === 'POST') brvtal_admin_require_csrf();
try {
    $pdo=db();
    $q=$pdo->prepare('SELECT staff_role FROM admins WHERE id=? AND is_active=1 LIMIT 1');
    $q->execute([(int)($_SESSION['admin_id'] ?? 0)]);
    if (!brvtalTicketingCan((string)($q->fetchColumn() ?: ''),'settings')) {
        json_response(['ok'=>false,'error'=>'FORBIDDEN'],403);
    }
    if ($method === 'GET') {
        $eventId=filter_var($_GET['event_id'] ?? null,FILTER_VALIDATE_INT);
        $typeId=filter_var($_GET['ticket_type_id'] ?? null,FILTER_VALIDATE_INT);
        if (!$eventId || !$typeId || $eventId < 1 || $typeId < 1) {
            throw new InvalidArgumentException('INVALID_TARGET');
        }
        $q=$pdo->prepare('SELECT code,price_cop,cumulative_capacity,opens_at_local,closes_at_local
          FROM ticket_type_stages WHERE event_id=? AND ticket_type_id=?
          ORDER BY FIELD(code, "early_bird","preventa","general","puerta")');
        $q->execute([$eventId,$typeId]);
        json_response(['ok'=>true,'stages'=>$q->fetchAll(PDO::FETCH_ASSOC),
          'draft_only'=>true,'checkout_activation'=>false]);
    }
    if (!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')),
        'application/json') || (int)($_SERVER['CONTENT_LENGTH'] ?? 0)>4096) {
        json_response(['ok'=>false,'error'=>'INVALID_CONTENT_TYPE'],415);
    }
    $raw=file_get_contents('php://input',false,null,0,4097);
    if (!is_string($raw) || strlen($raw)>4096) throw new InvalidArgumentException();
    $data=json_decode($raw,true,12,JSON_THROW_ON_ERROR);
    if (!is_array($data) || array_diff(array_keys($data),
      ['event_id','ticket_type_id','stages'])
      || !is_int($data['event_id'] ?? null) || !is_int($data['ticket_type_id'] ?? null)
      || !is_array($data['stages'] ?? null)) throw new InvalidArgumentException();
    brvtalTicketStageSave($pdo,$data['event_id'],$data['ticket_type_id'],$data['stages']);
    json_response(['ok'=>true,'draft_only'=>true,'checkout_activation'=>false]);
} catch (InvalidArgumentException | JsonException) {
    json_response(['ok'=>false,'error'=>'INVALID_STAGE_INPUT'],422);
} catch (DomainException $e) {
    $error=$e->getMessage();
    json_response(['ok'=>false,'error'=>$error],$error==='ACTIVE_RESERVATIONS'?409:404);
} catch (Throwable) {
    json_response(['ok'=>false,'error'=>'STAGES_UNAVAILABLE'],503);
}
