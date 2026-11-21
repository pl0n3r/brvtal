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
    if(!brvtalTicketingCan($role,'refund')) json_response(['ok'=>false,'error'=>'FORBIDDEN'],403);
    if(($_SERVER['REQUEST_METHOD']??'GET')==='GET') {
        $q=$db->query('SELECT id,order_id,event_id,amount_cop,status,created_at_utc
            FROM ticket_refunds ORDER BY id DESC LIMIT 100');
        // Same-origin, authenticated superadmin only; no-store and server-enforced CSRF.
        json_response(['ok'=>true,'data'=>$q->fetchAll(PDO::FETCH_ASSOC),
            'csrf'=>(string)($_SESSION['csrf']??'')]);
    }
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST') json_response(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405);
    brvtal_admin_require_csrf();
    if(!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/json'))
        json_response(['ok'=>false,'error'=>'INVALID_CONTENT_TYPE'],415);
    $body=input_json();
    if(!is_array($body)||array_is_list($body)||array_diff(array_keys($body),['id','action','note','receipt']))
        throw new InvalidArgumentException('REFUND_INVALID');
    $id=$body['id']??null; $action=$body['action']??null; $note=$body['note']??'';
    if(!is_int($id)||!is_string($action)||!is_string($note)||!is_string($body['receipt']??''))
        throw new InvalidArgumentException('REFUND_INVALID');
    if ($action==='sandbox_dispatch') {
        $config=['mode'=>(string)(getenv('BRVTAL_TICKET_WOMPI_MODE')?:''),
            'private'=>(string)(getenv('BRVTAL_TICKET_WOMPI_PRIVATE_KEY')?:'')];
        json_response(['ok'=>true,'data'=>brvtalRefundSandboxDispatch($db,$actor,$role,$id,$config)]);
    }
    json_response(['ok'=>true,'data'=>brvtalRefundStaff($db,$actor,$role,$id,$action,$note,(string)($body['receipt']??''))]);
} catch(InvalidArgumentException) {
    json_response(['ok'=>false,'error'=>'REFUND_INVALID'],422);
} catch(DomainException $e) {
    $code=$e->getMessage();
    json_response(['ok'=>false,'error'=>$code],$code==='REFUND_SANDBOX_ONLY'?423:403);
} catch(Throwable) {
    json_response(['ok'=>false,'error'=>'REFUNDS_UNAVAILABLE'],503);
}
