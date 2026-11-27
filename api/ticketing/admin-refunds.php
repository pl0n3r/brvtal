<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/admin_auth.php';
require_once __DIR__.'/../../config/ticketing_refunds.php';
require_once __DIR__.'/../../config/ticketing_admin_orders.php';
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
            e.title AS event_title,d.cause AS event_cause,
            o.id AS order_id,o.status AS order_status,o.total_cop,o.currency
            FROM ticket_refunds r JOIN events e ON e.id=r.event_id
            LEFT JOIN ticket_orders o ON o.id=r.order_id
            LEFT JOIN ticket_refund_event_decisions d ON d.event_id=r.event_id
            ORDER BY r.id DESC LIMIT 100');
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        $schema=brvtalTicketAdminPaymentSchema($db);
        $manualEnabled=getenv('BRVTAL_TICKET_REFUNDS_MANUAL_ENABLED')==='1'
            ||(getenv('BRVTAL_INTEGRATION_TESTS')==='1'
                &&preg_match('/^brvtal_test[a-zA-Z0-9_]*$/D',(string)$db->query('SELECT DATABASE()')->fetchColumn())===1);
        $unknown=['channel'=>'unknown','state'=>'unavailable','reason'=>'payment_evidence_unavailable','anomaly'=>false];
        foreach($rows as &$row) {
            $reference=null;
            $evidence=$unknown;
            try {
                $evidence=brvtalTicketAdminPaymentEvidence($db,[
                    'id'=>$row['order_id'],'status'=>$row['order_status'],
                    'total_cop'=>$row['total_cop'],'currency'=>$row['currency']
                ],$schema);
                if($evidence['channel']==='wompi'&&$evidence['state']==='verified') {
                    // The order label alone is insufficient for the manual Wompi action.
                    $proof=$db->prepare("SELECT DISTINCT a.id,a.provider_transaction_id,a.gross_amount_cop,a.status
                        FROM ticket_payment_attempts a
                        JOIN ticket_payment_webhook_events w ON w.attempt_id=a.id
                          AND w.provider_transaction_id=a.provider_transaction_id
                        WHERE a.order_id=? AND a.provider='wompi'
                          AND a.status IN ('approved','refunded') AND w.signed_status='APPROVED'
                        LIMIT 2");
                    $proof->execute([$row['order_id']]);
                    $matches=$proof->fetchAll(PDO::FETCH_ASSOC);
                    if(count($matches)>1) {
                        $evidence=['channel'=>'conflict','state'=>'discrepancy','reason'=>'duplicate_wompi','anomaly'=>true];
                    } elseif(count($matches)===1&&$matches[0]['status']==='approved'
                        && (int)$matches[0]['gross_amount_cop']===(int)$row['total_cop']
                        && preg_match('/^[A-Za-z0-9._:-]{1,190}$/D',(string)$matches[0]['provider_transaction_id'])) {
                        $reference=(string)$matches[0]['provider_transaction_id'];
                    } else {
                        $evidence=['channel'=>'wompi','state'=>'unavailable','reason'=>'wompi_reference_unverified','anomaly'=>true];
                    }
                }
            } catch(PDOException $e) {
                if(!in_array($e->getCode(),['42S02','42S22'],true))throw $e;
                $evidence=$unknown;
            }
            $row['payment_evidence']=$evidence;
            $row['can_manual_confirm']=$owner&&$manualEnabled&&$reference!==null
                &&in_array($row['status'],['approved','manual_pending'],true);
            $row['wompi_payment_ref']=$row['can_manual_confirm']?$reference:null;
            unset($row['order_id'],$row['order_status'],$row['total_cop'],$row['currency']);
        }
        unset($row);
        $events=$owner?$db->query("SELECT DISTINCT e.id,e.title
            FROM events e JOIN ticket_orders o ON o.event_id=e.id
            WHERE o.status='paid' ORDER BY e.id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC):[];
        json_response(['ok'=>true,'data'=>$rows,
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
