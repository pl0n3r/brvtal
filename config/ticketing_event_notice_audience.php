<?php
declare(strict_types=1);
require_once __DIR__.'/ticketing_staff_permissions.php';
require_once __DIR__.'/ticketing_admin_orders.php';

const BRVTAL_NOTICE_ORDER_LIMIT = 200;
const BRVTAL_NOTICE_ROW_LIMIT = 2000;

function brvtalNoticeEventId(array $raw): int
{
    $id=$raw['event_id']??null;
    if (count($raw)!==1 || !is_string($id) || !ctype_digit($id) || (int)$id<1
        || (int)$id>4294967295 || (string)(int)$id!==$id) throw new InvalidArgumentException('INVALID_FILTER');
    return (int)$id;
}

function brvtalNoticeUnknown(): array
{
    return ['state'=>'unknown','cause'=>null,'counts'=>null,'send_authorized'=>false];
}

/** Bound each source before aggregation; no partial count may escape. */
function brvtalNoticeWithinBudget(PDO $db,int $event): bool
{
    $sources=[
        'ticket_orders o',
        'ticket_order_items i JOIN ticket_orders o ON o.id=i.order_id',
        'ticket_entries e JOIN ticket_order_items i ON i.id=e.order_item_id JOIN ticket_orders o ON o.id=i.order_id',
        'ticket_payment_attempts a JOIN ticket_orders o ON o.id=a.order_id',
        'ticket_payment_webhook_events w JOIN ticket_payment_attempts a ON a.id=w.attempt_id JOIN ticket_orders o ON o.id=a.order_id',
        'ticket_courtesy_invites c JOIN ticket_orders o ON o.id=c.order_id',
        'ticket_inventory_holds h JOIN ticket_orders o ON o.id=h.order_id',
        'ticket_pos_sales p JOIN ticket_orders o ON o.id=p.order_id',
        'ticket_order_notes n JOIN ticket_orders o ON o.id=n.order_id',
        'ticket_promo_redemptions p JOIN ticket_orders o ON o.id=p.order_id',
    ];
    foreach ($sources as $source) {
        $limit=$source==='ticket_orders o'?BRVTAL_NOTICE_ORDER_LIMIT:BRVTAL_NOTICE_ROW_LIMIT;
        $q=$db->prepare('SELECT 1 FROM '.$source.' WHERE o.event_id=? LIMIT '.($limit+1));
        $q->execute([$event]);
        if (count($q->fetchAll(PDO::FETCH_COLUMN))>$limit) return false;
    }
    return true;
}

/** Read a coherent snapshot. No production enable switch, writes or delivery. */
function brvtalNoticePreview(PDO $db,int $event,string $role): array
{
    if (!brvtalTicketingCan($role,'refund')) throw new DomainException('FORBIDDEN');
    if (getenv('BRVTAL_INTEGRATION_TESTS')!=='1' || !preg_match('/^brvtal_test[a-zA-Z0-9_]*$/D',
        (string)$db->query('SELECT DATABASE()')->fetchColumn())) throw new DomainException('NOTICE_SANDBOX_ONLY');
    if ($event<1 || $db->inTransaction()) return brvtalNoticeUnknown();
    try {
        $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        $db->beginTransaction();
        $q=$db->prepare('SELECT d.cause FROM events e JOIN ticket_refund_event_decisions d ON d.event_id=e.id WHERE e.id=?');
        $q->execute([$event]); $cause=$q->fetchColumn();
        if (!in_array($cause,['cancelled','postponed'],true) || in_array(false,brvtalTicketAdminPaymentSchema($db),true)
            || !brvtalNoticeWithinBudget($db,$event)) return brvtalNoticeUnknown();
        $q=$db->prepare(<<<'SQL'
WITH target AS (
 SELECT o.id,o.event_id,o.buyer_id,o.status,o.currency,o.total_cop,b.email,b.status AS buyer_status
 FROM ticket_orders o LEFT JOIN ticket_buyers b ON b.id=o.buyer_id
 WHERE o.event_id=? ORDER BY o.id LIMIT 201
), items AS (
 SELECT i.order_id,COUNT(*) AS item_count,SUM(i.quantity) AS units,SUM(i.subtotal_cop) AS amount,
 MAX(i.ticket_type_id) AS ticket_type_id,
 SUM(CASE WHEN i.event_id<>t.event_id OR ty.id IS NULL OR i.quantity<1 OR i.currency<>'COP'
 OR i.subtotal_cop<>i.quantity*i.unit_price_cop THEN 1 ELSE 0 END) AS bad_items
 FROM target t JOIN ticket_order_items i ON i.order_id=t.id
 LEFT JOIN event_ticket_types ty ON ty.id=i.ticket_type_id AND ty.event_id=t.event_id GROUP BY i.order_id
), promos AS (
 SELECT p.order_id,COUNT(*) AS promo_count,
 SUM(CASE WHEN p.buyer_id=t.buyer_id AND p.event_id=t.event_id AND i.item_count=1
 AND p.ticket_type_id=i.ticket_type_id AND p.quantity=i.units AND p.subtotal_cop=i.amount
 AND p.discount_cop BETWEEN 0 AND p.subtotal_cop AND p.total_cop+p.discount_cop=p.subtotal_cop
 AND p.total_cop=t.total_cop THEN 1 ELSE 0 END) AS promo_verified
 FROM target t JOIN ticket_promo_redemptions p ON p.order_id=t.id LEFT JOIN items i ON i.order_id=t.id GROUP BY p.order_id
), entries AS (
 SELECT i.order_id,COUNT(*) AS entry_count,
 SUM(CASE WHEN e.status='issued' THEN 1 ELSE 0 END) AS issued,
 SUM(CASE WHEN e.status='used' THEN 1 ELSE 0 END) AS used,
 SUM(CASE WHEN e.status IN ('revoked','refunded') THEN 1 ELSE 0 END) AS terminal_entries,
 SUM(CASE WHEN e.event_id<>t.event_id OR e.event_id<>i.event_id OR e.ticket_type_id<>i.ticket_type_id
 OR e.unit_number<1 OR e.unit_number>i.quantity THEN 1 ELSE 0 END) AS bad_entries
 FROM target t JOIN ticket_order_items i ON i.order_id=t.id JOIN ticket_entries e ON e.order_item_id=i.id
 GROUP BY i.order_id
), attempts AS (
 SELECT a.order_id,COUNT(*) AS attempt_count,
 SUM(CASE WHEN a.status='approved' THEN 1 ELSE 0 END) AS approval_count,
 SUM(CASE WHEN a.status='approved' AND a.provider='wompi' AND a.gross_amount_cop=t.total_cop
 AND a.provider_reference<>'' AND a.provider_transaction_id<>'' AND EXISTS(
 SELECT 1 FROM ticket_payment_webhook_events w WHERE w.attempt_id=a.id
 AND w.provider_transaction_id=a.provider_transaction_id AND w.signed_status='APPROVED') AND NOT EXISTS(
 SELECT 1 FROM ticket_payment_webhook_events w WHERE w.attempt_id=a.id
 AND w.provider_transaction_id=a.provider_transaction_id AND w.signed_status IN ('VOIDED','DECLINED','ERROR'))
 THEN 1 ELSE 0 END) AS verified_approvals,
 SUM(CASE WHEN a.status IN ('created','pending') AND a.provider='wompi' THEN 1 ELSE 0 END) AS pending_attempts
 FROM target t JOIN ticket_payment_attempts a ON a.order_id=t.id GROUP BY a.order_id
), courtesy AS (
 SELECT c.order_id,COUNT(*) AS courtesy_count,
 SUM(CASE WHEN c.event_id=t.event_id AND c.origin='courtesy' AND c.recipient_digest=SHA2(LOWER(TRIM(t.email)),256)
 AND e.id IS NOT NULL AND i.id IS NOT NULL AND h.id IS NOT NULL AND c.status IN ('issued','used')
 AND e.status=c.status THEN 1 ELSE 0 END) AS courtesy_verified
 FROM target t JOIN ticket_courtesy_invites c ON c.order_id=t.id
 LEFT JOIN ticket_entries e ON e.id=c.entry_id AND e.event_id=t.event_id AND e.ticket_type_id=c.ticket_type_id
 LEFT JOIN ticket_order_items i ON i.id=e.order_item_id AND i.order_id=t.id AND i.event_id=t.event_id
 AND i.ticket_type_id=c.ticket_type_id AND i.quantity=1 AND i.subtotal_cop=0 AND i.unit_price_cop=0
 LEFT JOIN ticket_inventory_holds h ON h.order_id=t.id AND h.event_id=t.event_id AND h.ticket_type_id=c.ticket_type_id
 AND h.kind='courtesy' AND h.status='consumed' AND h.quantity=1 GROUP BY c.order_id
)
SELECT t.*,COALESCE(i.item_count,0) AS item_count,COALESCE(i.units,0) AS units,COALESCE(i.amount,0) AS amount,
 COALESCE(i.bad_items,0) AS bad_items,COALESCE(e.entry_count,0) AS entry_count,
 COALESCE(p.promo_count,0) AS promo_count,COALESCE(p.promo_verified,0) AS promo_verified,
 COALESCE(e.issued,0) AS issued,COALESCE(e.used,0) AS used,COALESCE(e.terminal_entries,0) AS terminal_entries,
 COALESCE(e.bad_entries,0) AS bad_entries,COALESCE(a.attempt_count,0) AS attempt_count,
 COALESCE(a.approval_count,0) AS approval_count,COALESCE(a.verified_approvals,0) AS verified_approvals,
 COALESCE(a.pending_attempts,0) AS pending_attempts,COALESCE(c.courtesy_count,0) AS courtesy_count,
 COALESCE(c.courtesy_verified,0) AS courtesy_verified,
 (SELECT COUNT(*) FROM ticket_pos_sales p WHERE p.order_id=t.id) AS pos_count,
 (SELECT COUNT(*) FROM ticket_inventory_holds h WHERE h.order_id=t.id AND h.event_id=t.event_id
 AND h.kind='paid' AND h.status='active' AND h.expires_at_utc>UTC_TIMESTAMP(6)) AS pending_holds
 FROM target t LEFT JOIN items i ON i.order_id=t.id LEFT JOIN promos p ON p.order_id=t.id LEFT JOIN entries e ON e.order_id=t.id
 LEFT JOIN attempts a ON a.order_id=t.id LEFT JOIN courtesy c ON c.order_id=t.id ORDER BY t.id
SQL);
        $q->execute([$event]); $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows)>BRVTAL_NOTICE_ORDER_LIMIT) return brvtalNoticeUnknown();
        $units=0;
        foreach ($rows as &$r) {
            foreach (['item_count','units','amount','bad_items','entry_count','issued','used','terminal_entries','bad_entries',
                'attempt_count','approval_count','verified_approvals','pending_attempts','courtesy_count','courtesy_verified',
                'pos_count','pending_holds','promo_count','promo_verified'] as $key) $r[$key]=(int)$r[$key];
            $units+=$r['units'];
            if ($units>BRVTAL_NOTICE_ROW_LIMIT) return brvtalNoticeUnknown();
            // Discounted orders require one exact redemption and verified Wompi evidence.
            $amountConsistent=($r['promo_count']===0 && $r['amount']===(int)$r['total_cop'])
                || ($r['promo_count']===1 && $r['promo_verified']===1 && $r['verified_approvals']===1
                    && !$r['pos_count'] && !$r['courtesy_count']);
            $r['consistent']=$r['item_count']>0 && $r['units']>0 && $amountConsistent
                && $r['currency']==='COP' && !$r['bad_items'] && !$r['bad_entries'] && $r['entry_count']<=$r['units']
                && $r['entry_count']===$r['issued']+$r['used']+$r['terminal_entries']
                && $r['approval_count']<=1 && $r['courtesy_count']<=1 && $r['pos_count']<=1
                && !(($r['courtesy_count']||$r['pos_count'])&&$r['attempt_count']) && !($r['courtesy_count']&&$r['pos_count'])
                && !($r['pending_attempts']>0 && $r['verified_approvals']>0);
            $r['channel']=$r['courtesy_count']?'courtesy':($r['pos_count']?'pos':($r['approval_count']?'wompi':'none'));
            $r['verified']=match($r['channel']) {
                'courtesy'=>$r['courtesy_verified']===1 && (int)$r['total_cop']===0 && $r['units']===1,
                'pos'=>$r['consistent'] && (int)$r['total_cop']>0 && brvtal_ticket_pos_verified($db,(int)$r['id']),
                'wompi'=>$r['verified_approvals']===1 && (int)$r['total_cop']>0,
                default=>false,
            };
            $r['pending_evidence']=$r['pending_attempts']>0 || $r['pending_holds']>0;
        }
        unset($r);
        return brvtalNoticeCounts($rows,$event,$cause);
    } catch (Throwable) {
        return brvtalNoticeUnknown();
    } finally {
        if ($db->inTransaction()) $db->rollBack();
    }
}

/** Groups can overlap for valid orders; candidate_recipients is the unique total.
 * No group is a final legal inclusion or consent decision. */
function brvtalNoticeCounts(array $rows, int $eventId, ?string $cause, int $limit=BRVTAL_NOTICE_ORDER_LIMIT): array
{
    $unknown = brvtalNoticeUnknown();
    if ($eventId < 1 || !in_array($cause,['cancelled','postponed'],true) || count($rows)>$limit) return $unknown;
    $counts = array_fill_keys(['issued','used','courtesy','approved_without_issuance','pending_review','terminal_review','blocked_review','ambiguous_review','mixed_review','unverified_review'],0);
    $recipients=[]; $missing=0; $seen=[];
    foreach ($rows as $row) {
        if ((int)$row['event_id']!==$eventId) return $unknown;
        $order=(int)$row['id'];
        if ($order<1 || isset($seen[$order])) return $unknown;
        $seen[$order]=true;
        $email=strtolower(trim((string)($row['email']??'')));
        if (strlen($email)>190 || !filter_var($email,FILTER_VALIDATE_EMAIL) || (int)($row['buyer_id']??0)<1) {
            ++$missing; continue;
        }
        $groups=brvtalNoticeOrderGroups($row);
        $recipients[$email]??=['buyer_ids'=>[],'groups'=>[]];
        $recipients[$email]['buyer_ids'][(int)$row['buyer_id']]=true;
        foreach ($groups as $group) $recipients[$email]['groups'][$group]=true;
    }
    $candidates=0; $review=0;
    foreach ($recipients as $recipient) {
        $groups=array_keys($recipient['groups']);
        $candidateGroups=array_intersect($groups,['issued','used','courtesy','approved_without_issuance']);
        $reviewGroups=array_diff($groups,$candidateGroups);
        if (in_array('blocked_review',$groups,true)) $groups=['blocked_review'];
        elseif (count($recipient['buyer_ids'])!==1) $groups=['ambiguous_review'];
        elseif ($candidateGroups && $reviewGroups) $groups=['mixed_review'];
        elseif ($reviewGroups) $groups=count($reviewGroups)===1?array_values($reviewGroups):['mixed_review'];
        if (!array_intersect($groups,['issued','used','courtesy','approved_without_issuance'])) ++$review;
        else ++$candidates;
        foreach ($groups as $group) ++$counts[$group];
    }
    return ['state'=>'known','cause'=>$cause,'counts'=>['candidate_recipients'=>$candidates,'review_recipients'=>$review,
        'orders_without_identity'=>$missing,'orders_examined'=>count($rows),'groups'=>$counts], 'send_authorized'=>false];
}

function brvtalNoticeOrderGroups(array $r): array
{
    if ($r['buyer_status']==='blocked') return ['blocked_review'];
    if (!in_array($r['buyer_status'],['guest','active'],true)) return ['unverified_review'];
    if (!$r['consistent']) return ['ambiguous_review'];
    if (in_array($r['status'],['refunded','cancelled','expired','failed'],true)) return ['terminal_review'];
    if ($r['terminal_entries']>0) return ['terminal_review'];
    if ($r['channel']==='courtesy') return $r['verified']&&$r['status']==='draft'&&$r['issued']+$r['used']===1
        ? ['courtesy'] : ['unverified_review'];
    if ($r['verified']&&in_array($r['channel'],['wompi','pos'],true)&&$r['status']==='paid') {
        if ($r['issued']+$r['used']===0) return ['approved_without_issuance'];
        if ($r['issued']+$r['used']!==$r['units']) return ['mixed_review'];
        $groups=[];
        if ($r['issued']>0) $groups[]='issued';
        if ($r['used']>0) $groups[]='used';
        return $groups?:['unverified_review'];
    }
    if ($r['status']==='pending'&&$r['pending_evidence']&&$r['channel']==='none'&&!$r['issued']&&!$r['used']) return ['pending_review'];
    return ['unverified_review'];
}
