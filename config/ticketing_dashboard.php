<?php
declare(strict_types=1);
require_once __DIR__.'/ticketing_finance.php';
require_once __DIR__.'/ticketing_staff_permissions.php';

function brvtalDashboardCan(string $role): bool
{
    return brvtalTicketingCan($role,'orders') && brvtalTicketingCan($role,'finance');
}

function brvtalDashboardFilters(array $raw): array
{
    foreach ($raw as $key=>$value) {
        if (!in_array($key,['event_id','from','to','channel'],true) || !is_string($value)) {
            throw new InvalidArgumentException('INVALID_FILTER');
        }
    }
    $f=brvtalTicketAdminFilters(array_intersect_key($raw,array_flip(['event_id','from','to'])));
    $channel=$raw['channel']??'all';
    if (!in_array($channel,['all','wompi','pos'],true)) throw new InvalidArgumentException('INVALID_CHANNEL');
    return [$f['event_id'],$f['from']?:null,$f['to']?:null,$channel];
}

/** Missing optional schemas are unknown; other failures must not become zeroes. */
function brvtalDashboardQuery(PDO $db,string $sql,array $params=[]): ?array
{
    try {
        $q=$db->prepare($sql);
        $q->execute($params);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        if (!in_array($e->getCode(),['42S02','42S22'],true)) throw $e;
        return null;
    }
}

/** Return integer counts keyed by event/order, without exposing source records. */
function brvtalDashboardCounts(PDO $db,string $sql,array $ids): ?array
{
    if (!$ids) return [];
    $in=implode(',',array_fill(0,count($ids),'?'));
    $rows=brvtalDashboardQuery($db,str_replace('{ids}',$in,$sql),$ids);
    return $rows===null?null:array_map('intval',array_column($rows,'n','k'));
}

function brvtalDashboardCount(?array $counts,int $id): ?int
{
    return $counts===null?null:($counts[$id]??0);
}

function brvtalDashboardReport(PDO $db,array $raw,string $role): array
{
    if (!brvtalDashboardCan($role)) throw new DomainException('FORBIDDEN');
    [$eventId,$from,$to,$channel]=brvtalDashboardFilters($raw);
    $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
    $db->beginTransaction();
    try {
        $result=brvtalDashboardSnapshot($db,$eventId,$from,$to,$channel);
        $db->commit();
        return $result;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function brvtalDashboardSnapshot(PDO $db,?int $eventId,?string $from,?string $to,string $channel): array
{
    $events=brvtalDashboardQuery($db,'SELECT id FROM events'.($eventId===null?'':' WHERE id=?')
        .' ORDER BY id LIMIT 5001',$eventId===null?[]:[$eventId]);
    if ($events===null) throw new RuntimeException('EVENTS_UNAVAILABLE');
    if (count($events)>5000) throw new LengthException('RANGE_TOO_LARGE');
    $ids=array_map('intval',array_column($events,'id'));
    $where=["o.currency='COP'"]; $params=[];
    if ($eventId!==null) { $where[]='o.event_id=?'; $params[]=$eventId; }
    if ($from!==null) { $where[]='o.created_at_utc>=?'; $params[]=brvtalFinanceCalendarBound($from,false); }
    if ($to!==null) { $where[]='o.created_at_utc<?'; $params[]=brvtalFinanceCalendarBound($to,true); }
    $orders=brvtalDashboardQuery($db,'SELECT o.id,o.event_id,o.total_cop,o.currency,o.status FROM ticket_orders o WHERE '
        .implode(' AND ',$where).' ORDER BY o.id DESC LIMIT 5001',$params);
    if ($orders!==null && count($orders)>5000) throw new LengthException('RANGE_TOO_LARGE');
    $proof=null; $finance=null;
    if ($orders!==null) {
        try {
            $proof=brvtalFinanceChannelEvidence($db,$orders);
            $finance=array_column(brvtalFinanceReport($db,$eventId,$from,$to,$channel),null,'event_id');
        } catch (PDOException $e) {
            if (!in_array($e->getCode(),['42S02','42S22'],true)) throw $e;
        }
    }
    $selected=$proof===null?($channel==='all'?$orders:null)
        :brvtalFinanceFilterOrders($orders,$proof['by_order'],$channel);
    $orderIds=array_map('intval',array_column($selected??[],'id'));
    $units=brvtalDashboardCounts($db,'SELECT i.order_id k, SUM(i.quantity) n FROM ticket_order_items i
        WHERE i.order_id IN ({ids}) GROUP BY i.order_id',$orderIds);
    $issued=brvtalDashboardCounts($db,'SELECT i.order_id k, COUNT(e.id) n FROM ticket_order_items i
        JOIN ticket_entries e ON e.order_item_id=i.id WHERE i.order_id IN ({ids}) GROUP BY i.order_id',$orderIds);
    $failed=brvtalDashboardCounts($db,"SELECT order_id k,COUNT(*) n FROM ticket_issue_outbox
        WHERE order_id IN ({ids}) AND state='failed' GROUP BY order_id",$orderIds);
    $mail=brvtalDashboardQuery($db,"SELECT o.id AS order_id,m.state FROM ticket_orders o JOIN ticket_mail_outbox m
        ON m.message_key=CONCAT('order:',o.id,':payment_approved')
        WHERE o.id IN (".($orderIds?implode(',',array_fill(0,count($orderIds),'?')):'NULL').")",$orderIds);
    $delivery=brvtalDashboardQuery($db,"SELECT o.id AS order_id,h.delivery_state FROM ticket_orders o
        JOIN ticket_mail_outbox m ON m.message_key=CONCAT('order:',o.id,':payment_approved')
        JOIN ticket_mail_history h ON h.outbox_id=m.id WHERE o.id IN ("
        .($orderIds?implode(',',array_fill(0,count($orderIds),'?')):'NULL').")",$orderIds);
    $mail=$mail===null?null:array_column($mail,'state','order_id');
    $delivery=$delivery===null?null:array_column($delivery,'delivery_state','order_id');
    $capacity=brvtalDashboardCounts($db,'SELECT event_id k,capacity n FROM ticket_inventory_events
        WHERE event_id IN ({ids})',$ids);
    $access=brvtalDashboardCounts($db,"SELECT event_id k,COUNT(DISTINCT entry_id) n FROM ticket_checkins
        WHERE outcome='accepted' AND event_id IN ({ids}) GROUP BY event_id",$ids);
    $refundSchema=brvtalTicketAdminHasTable($db,'ticket_refunds')
        && brvtalTicketAdminHasTable($db,'ticket_refund_adjustments');
    $schema=brvtalTicketAdminPaymentSchema($db);
    $complete=$schema['courtesy'] && $schema['pos_sales'] && $schema['pos_shifts'] && $schema['wompi_events'];
    $byEvent=[];
    foreach ($selected??[] as $order) $byEvent[(int)$order['event_id']][]=$order;
    $rows=[];
    foreach ($ids as $id) {
        $f=$finance[$id]??brvtalFinanceEmptyEvent($id);
        $known=$finance!==null && $complete;
        $period=[];
        foreach (['paid_orders','gross_cop','approved_cop','pos_cop','refund_cop','refund_in_period_cop',
            'net_after_refunds_cop','unverified_orders'] as $key) {
            $period[$key]=$known?$f[$key]:null;
        }
        if ($known && !isset($finance[$id]) && $refundSchema) $period['net_after_refunds_cop']=0;
        if (!$refundSchema) foreach (['refund_cop','refund_in_period_cop','net_after_refunds_cop'] as $key) $period[$key]=null;
        $period['payment_discrepancies']=$finance===null?null:count(array_filter($f['discrepancies'],
            static fn(array $d): bool=>$d['state']==='discrepancy'));
        $period['payment_evidence_unavailable']=$finance===null?null:count(array_filter($f['discrepancies'],
            static fn(array $d): bool=>$d['state']==='unavailable'));
        $period['pending_orders']=$selected!==null && $proof!==null && $schema['courtesy']?0:null;
        $period['issued_units']=$selected!==null && $issued!==null
            && brvtalTicketAdminHasTable($db,'ticket_entries') && brvtalTicketAdminHasTable($db,'ticket_order_items')?0:null;
        $alerts=['approved_without_full_issuance'=>$known && $issued!==null && $units!==null
            && $period['issued_units']!==null?0:null,
            'issue_failed'=>$selected!==null && $failed!==null && brvtalTicketAdminHasTable($db,'ticket_issue_outbox')?0:null,
            'mail_pending'=>$selected!==null && $mail!==null?0:null,
            'mail_accepted'=>$selected!==null && $mail!==null?0:null,
            'mail_delivered'=>$selected!==null && $delivery!==null?0:null,
            'mail_uncertain'=>$selected!==null && $mail!==null?0:null,
            'mail_failed'=>$selected!==null && $mail!==null?0:null,
            'delivery_failed'=>$selected!==null && $delivery!==null?0:null];
        $verified=array_fill_keys(array_column($f['transactions'],'order_id'),true);
        foreach ($byEvent[$id]??[] as $order) {
            $oid=(int)$order['id'];
            $classification=$proof['by_order'][$oid]['classification']??[];
            if ($period['pending_orders']!==null && in_array($order['status'],['draft','pending'],true)
                && ($classification['channel']??'')!=='courtesy') $period['pending_orders']++;
            if ($period['issued_units']!==null) $period['issued_units']+=brvtalDashboardCount($issued,$oid);
            if ($alerts['approved_without_full_issuance']!==null && $order['status']==='paid'
                && isset($verified[$oid]) && (($units[$oid]??0)===0 || ($issued[$oid]??0)<$units[$oid])) {
                $alerts['approved_without_full_issuance']++;
            }
            if ($alerts['issue_failed']!==null && ($failed[$oid]??0)>0) $alerts['issue_failed']++;
            $state=$mail[$oid]??null;
            if (in_array($state,['queued','retry','sending'],true)) $alerts['mail_pending']++;
            if ($state==='accepted') $alerts['mail_accepted']++;
            if ($state==='uncertain') $alerts['mail_uncertain']++;
            if ($state==='failed') $alerts['mail_failed']++;
            $state=$delivery[$oid]??null;
            if ($state==='delivered') $alerts['mail_delivered']++;
            if (in_array($state,['failed','bounced'],true)) $alerts['delivery_failed']++;
        }
        $rows[]=['event_id'=>$id,'period'=>$period,'alerts'=>$alerts,
            'current'=>['capacity'=>$capacity[$id]??null,'accepted_accesses'=>brvtalDashboardCount($access,$id)]];
    }
    return ['timezone'=>'America/Bogota','from'=>$from,'to'=>$to,'channel'=>$channel,
        'period_basis'=>'order_created','current_basis'=>'all_channels_all_dates','rows'=>$rows];
}
