<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/admin_auth.php';
require_once __DIR__.'/../../config/ticketing_staff_permissions.php';
require_once __DIR__.'/../../config/ticketing_promos.php';
require_once __DIR__.'/../../config/public_visibility.php';
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
brvtal_admin_require();
$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if (!in_array($method,['GET','POST'],true)) {
    json_response(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405,['Allow'=>'GET, POST']);
}
if ($method==='POST') brvtal_admin_require_csrf();
try {
    $db=db();
    $q=$db->prepare('SELECT staff_role FROM admins WHERE id=? AND is_active=1 LIMIT 1');
    $q->execute([(int)($_SESSION['admin_id']??0)]);
    if (!brvtalTicketingCan((string)($q->fetchColumn()?:''),'settings')) {
        json_response(['ok'=>false,'error'=>'FORBIDDEN'],403);
    }
    if ($method==='GET' && ($_GET['catalog']??'')==='1') {
        // #1964: pickers instead of typed IDs. Only published events and their ticket names.
        // #2022: every selling state (tickets_available, last_tickets…), not only 'published'.
        $active=brvtal_public_event_statuses()['active'];
        $eventsQ=$db->prepare('SELECT id,title,event_date FROM events WHERE status IN ('
            .implode(',',array_fill(0,count($active),'?')).')
            ORDER BY event_date IS NULL, event_date DESC, id DESC LIMIT 60');
        $eventsQ->execute($active);
        $events=$eventsQ->fetchAll(PDO::FETCH_ASSOC);
        $types=[];
        if ($events) {
            $ids=array_map(static fn(array $row): int => (int)$row['id'], $events);
            $q=$db->prepare('SELECT id,event_id,name FROM event_ticket_types WHERE event_id IN ('
                .implode(',',array_fill(0,count($ids),'?')).') ORDER BY sort_order,id');
            $q->execute($ids);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $types[(int)$row['event_id']][]=['id'=>(int)$row['id'],'name'=>(string)$row['name']];
            }
        }
        json_response(['ok'=>true,'events'=>array_map(static fn(array $row): array => [
            'id'=>(int)$row['id'],'title'=>(string)$row['title'],'date'=>$row['event_date'],
            'ticket_types'=>$types[(int)$row['id']]??[]],$events)]);
    }
    if ($method==='GET') {
        $event=filter_var($_GET['event_id']??null,FILTER_VALIDATE_INT);
        if (!$event || $event<1) throw new InvalidArgumentException();
        $q=$db->prepare('SELECT id,event_id,ticket_type_id,kind,value_cop,
            starts_at_utc,ends_at_utc,max_uses,buyer_limit,uses_count,active,campaign_key
            FROM ticket_promo_rules WHERE event_id=? ORDER BY id DESC LIMIT 50');
        $q->execute([$event]);
        // Never return a full digest or plaintext coupon to the admin list.
        json_response(['ok'=>true,'rules'=>$q->fetchAll(PDO::FETCH_ASSOC),
            'synthetic_only'=>true,'checkout_activation'=>false]);
    }
    if (!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),
            'application/json') || (int)($_SERVER['CONTENT_LENGTH']??0)>4096) {
        json_response(['ok'=>false,'error'=>'INVALID_CONTENT_TYPE'],415);
    }
    $raw=file_get_contents('php://input',false,null,0,4097);
    if (!is_string($raw)||strlen($raw)>4096) throw new InvalidArgumentException();
    $body=json_decode($raw,true,12,JSON_THROW_ON_ERROR);
    if (!is_array($body)) throw new InvalidArgumentException();
    $action=$body['action']??null; unset($body['action']);
    if ($action==='create') {
        $id=brvtalPromoCreate($db,$body);
        json_response(['ok'=>true,'id'=>$id,'synthetic_only'=>true],201);
    }
    if ($action==='deactivate' && array_keys($body)===['id'] && is_int($body['id'])) {
        brvtalPromoDeactivate($db,$body['id']);
        json_response(['ok'=>true,'synthetic_only'=>true]);
    }
    throw new InvalidArgumentException();
} catch (InvalidArgumentException|JsonException) {
    json_response(['ok'=>false,'error'=>'INVALID_PROMO_INPUT'],422);
} catch (DomainException $e) {
    $code=$e->getMessage();
    $status=$code==='PROMOS_NOT_ENABLED'?423:
        ($code==='PROMO_ALREADY_EXISTS'?409:404);
    json_response(['ok'=>false,'error'=>$code],$status);
} catch (Throwable) {
    json_response(['ok'=>false,'error'=>'PROMOS_UNAVAILABLE'],503);
}
