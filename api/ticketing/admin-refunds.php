<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/admin_auth.php';
require_once __DIR__.'/../../config/ticketing_refunds.php';
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
brvtal_admin_require();
try {
    $db=db(); brvtalRefundGuard($db); $actor=(int)($_SESSION['admin_id']??0);
    $q=$db->prepare('SELECT staff_role FROM admins WHERE id=? AND is_active=1 LIMIT 1');
    $q->execute([$actor]); $role=(string)($q->fetchColumn()?:'');
    $owner=brvtalTicketingCan($role,'refund');
    if(!$owner&&!brvtalTicketingCan($role,'finance'))
        json_response(['ok'=>false,'error'=>'FORBIDDEN'],403);
    if(($_SERVER['REQUEST_METHOD']??'GET')==='GET') {
        $q=$db->query('SELECT r.id,r.amount_cop,r.status,r.created_at_utc,
            e.title AS event_title,d.cause AS event_cause
            FROM ticket_refunds r JOIN events e ON e.id=r.event_id
            LEFT JOIN ticket_refund_event_decisions d ON d.event_id=r.event_id
            ORDER BY r.id DESC LIMIT 100');
        $events=$owner?$db->query("SELECT DISTINCT e.id,e.title
            FROM events e JOIN ticket_orders o ON o.event_id=e.id
            WHERE o.status='paid' ORDER BY e.id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC):[];
        json_response(['ok'=>true,'data'=>$q->fetchAll(PDO::FETCH_ASSOC),
            'events'=>$events,'can_decide'=>$owner,
            'csrf'=>(string)($_SESSION['csrf']??'')]);
    }
    if(!$owner)json_response(['ok'=>false,'error'=>'FORBIDDEN'],403);
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST') json_response(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405);
    brvtal_admin_require_csrf();
    if(!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/json'))
        json_response(['ok'=>false,'error'=>'INVALID_CONTENT_TYPE'],415);
    $body=input_json();
    if(!is_array($body)||array_is_list($body))
        throw new InvalidArgumentException('REFUND_INVALID');
    if(($body['action']??null)==='declare_incident') {
        if(array_diff(array_keys($body),['action','event_id','cause'])
            ||!is_int($body['event_id']??null)||!is_string($body['cause']??null))
            throw new InvalidArgumentException('REFUND_INVALID');
        json_response(['ok'=>true,'data'=>brvtalRefundDeclareIncident(
            $db,$actor,$role,$body['event_id'],$body['cause'])]);
    }
    if(array_diff(array_keys($body),['id','action','note','receipt']))
        throw new InvalidArgumentException('REFUND_INVALID');
    $id=$body['id']??null; $action=$body['action']??null; $note=$body['note']??'';
    if(!is_int($id)||!is_string($action)||!is_string($note)||!is_string($body['receipt']??''))
        throw new InvalidArgumentException('REFUND_INVALID');
    json_response(['ok'=>true,'data'=>brvtalRefundStaff(
        $db,$actor,$role,$id,$action,$note,(string)($body['receipt']??''))]);
} catch(InvalidArgumentException) {
    json_response(['ok'=>false,'error'=>'REFUND_INVALID'],422);
} catch(DomainException $e) {
    $code=$e->getMessage();
    json_response(['ok'=>false,'error'=>$code],$code==='REFUND_SANDBOX_ONLY'?423:403);
} catch(Throwable) {
    json_response(['ok'=>false,'error'=>'REFUNDS_UNAVAILABLE'],503);
}
