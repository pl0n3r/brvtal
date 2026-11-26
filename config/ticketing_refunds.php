<?php
declare(strict_types=1);
require_once __DIR__ . '/ticketing_staff_permissions.php';
require_once __DIR__ . '/ticketing_wompi_client.php';
require_once __DIR__ . '/buyer_account.php';

/** Opt-in for the manual dashboard policy; default is no production refunds. */
function brvtalRefundGuard(PDO $db): void {
    $name=(string)$db->query('SELECT DATABASE()')->fetchColumn();
    $synthetic=getenv('BRVTAL_INTEGRATION_TESTS')==='1'
        && preg_match('/^brvtal_test[a-zA-Z0-9_]*$/D',$name)===1;
    if (!$synthetic && getenv('BRVTAL_TICKET_REFUNDS_POLICY_ENABLED')!=='1')
        throw new DomainException('REFUND_SANDBOX_ONLY');
}
/** The old Wompi transport stays test-only; a feature flag cannot enable it. */
function brvtalRefundSandboxGuard(PDO $db):void {
    if (getenv('BRVTAL_INTEGRATION_TESTS')!=='1'
        || preg_match('/^brvtal_test[a-zA-Z0-9_]*$/D',
            (string)$db->query('SELECT DATABASE()')->fetchColumn())!==1)
        throw new DomainException('REFUND_SANDBOX_ONLY');
}
/** Authoritative incident is persisted by the owner, never asserted by the buyer. */
function brvtalRefundIncident(PDO $db,int $eventId):string {
    $q=$db->prepare('SELECT cause FROM ticket_refund_event_decisions WHERE event_id=? LIMIT 1');
    $q->execute([$eventId]);$cause=(string)($q->fetchColumn()?:'');
    return in_array($cause,['cancelled','postponed'],true)?$cause:'';
}
/** Finalized refund entries release exactly one sold unit per ticket type.
 * A read-only count; inventory owns capacity, and this service owns entry state.
 */
function brvtalRefundCreditedInventory(PDO $db,int $eventId,int $typeId):int {
    brvtalRefundGuard($db);
    if($eventId<1||$typeId<0)throw new InvalidArgumentException('REFUND_INVALID');
    $sql="SELECT COUNT(*) FROM ticket_refund_entries x
        JOIN ticket_refunds r ON r.id=x.refund_id AND r.status='refunded'
        JOIN ticket_entries e ON e.id=x.entry_id AND e.status='refunded'
        JOIN ticket_orders o ON o.id=r.order_id AND o.status='paid'
        WHERE r.event_id=?";
    $args=[$eventId];
    if($typeId>0){$sql.=' AND e.ticket_type_id=?';$args[]=$typeId;}
    $q=$db->prepare($sql);$q->execute($args);
    return max(0,(int)$q->fetchColumn());
}
/** A confirmed owner-declared event incident cannot be silently reclassified. */
function brvtalRefundDeclareIncident(PDO $db,int $actor,string $role,int $eventId,string $cause):array {
    brvtalRefundGuard($db);
    if($actor<1||!brvtalTicketingCan($role,'refund'))throw new DomainException('FORBIDDEN');
    if($eventId<1||!in_array($cause,['cancelled','postponed'],true))
        throw new InvalidArgumentException('REFUND_INVALID');
    if($db->inTransaction())throw new DomainException('REFUND_BUSY');
    $db->beginTransaction();
    try {
        $q=$db->prepare('SELECT id FROM events WHERE id=? FOR UPDATE');
        $q->execute([$eventId]);if(!$q->fetchColumn())throw new DomainException('EVENT_NOT_FOUND');
        $previous=brvtalRefundIncident($db,$eventId);
        if($previous!==''&&$previous!==$cause)throw new DomainException('REFUND_INCIDENT_CONFLICT');
        if($previous==='') {
            $q=$db->prepare('INSERT INTO ticket_refund_event_decisions(event_id,cause,owner_admin_id) VALUES(?,?,?)');
            $q->execute([$eventId,$cause,$actor]);
        }
        $db->commit();return ['event_id'=>$eventId,'cause'=>$cause,'replayed'=>$previous!==''];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function brvtalRefundAudit(PDO $db,int $id,string $kind,?int $actor,?string $before,string $after):void {
    $db->prepare('INSERT INTO ticket_refund_audit(refund_id,actor_kind,actor_id,prior_status,next_status)
        VALUES(?,?,?,?,?)')->execute([$id,$kind,$actor,$before,$after]);
}
/** Exact floor(total × selected / gross) without overflowing 64-bit PHP integers. */
function brvtalRefundProrate(int $total, int $selected, int $gross): int {
    $limit=1000000000000;
    if ($total<1||$total>$limit||$gross<1||$gross>$limit
        ||$selected<1||$selected>$gross) throw new DomainException('AMOUNT_UNVERIFIED');
    // Binary long division: partial remainder < gross, so each step < 3 × limit.
    $quotient=0; $remainder=0;
    foreach (str_split(decbin($selected)) as $bit) {
        $partial=2*$remainder+($bit==='1'?$total:0);
        $quotient=2*$quotient+intdiv($partial,$gross);
        $remainder=$partial%$gross;
    }
    if ($quotient<1||$quotient>$total) throw new DomainException('AMOUNT_UNVERIFIED');
    return $quotient;
}
/**
 * Guest refund scope under the current buyer row lock.
 * The Event and Order are already locked by the caller; this is a current
 * locking read rather than the REPEATABLE READ snapshot established by
 * the preliminary order lookup. A newly blocked/downgraded buyer must fail
 * closed even when that snapshot still contains the old status.
 *
 * The server-only guest grant is compared to the locked, owned order ID.
 * No new order grant is minted here; unpaid orders cannot create new refunds
 * (the caller's existing paid-status guard still enforces that).
 */
function brvtalRefundRequestScope(PDO $db, int $buyerId, int $orderId): ?int
{
    if (!$db->inTransaction() || $buyerId < 1 || $orderId < 1) {
        throw new DomainException('TICKET_UNAVAILABLE');
    }
    $stmt = $db->prepare('SELECT status,email_verified_at FROM ticket_buyers WHERE id=? FOR UPDATE');
    $stmt->execute([$buyerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) throw new DomainException('TICKET_UNAVAILABLE');
    if ($row['status'] === 'active' && $row['email_verified_at'] !== null) return null;
    if ($row['status'] !== 'guest' || $row['email_verified_at'] !== null
        || (int)($_SESSION['buyer_id'] ?? 0) !== $buyerId) {
        throw new DomainException('TICKET_UNAVAILABLE');
    }
    $grant = (int)($_SESSION['buyer_checkout_new_order'] ?? 0);
    if ($grant < 1) throw new DomainException('TICKET_UNAVAILABLE');
    if ($grant !== $orderId) throw new DomainException('ORDER_NOT_REFUNDABLE');
    return $grant;
}

/** Buyer identity is always session-derived; input supplies only opaque IDs. */
function brvtalRefundRequest(PDO $db,int $buyer,array $input):array {
    brvtalRefundGuard($db);
    if ($buyer<1 || array_diff(array_keys($input),['order_id','entry_ids','reason','idempotency_key'])) {
        throw new InvalidArgumentException('REFUND_INVALID');
    }
    $ref=$input['order_id']??null; $ids=$input['entry_ids']??[];
    $reason=trim((string)($input['reason']??'')); $key=$input['idempotency_key']??null;
    if (!is_string($ref)||!preg_match('/^[a-f0-9]{32}$/D',$ref)
        || !is_array($ids)||!array_is_list($ids)||count($ids)>100
        || !is_string($key)||!preg_match('/^[A-Za-z0-9._:-]{16,96}$/D',$key)
        || strlen($reason)<4||strlen($reason)>220) throw new InvalidArgumentException('REFUND_INVALID');
    foreach ($ids as $id) if (!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id)) {
        throw new InvalidArgumentException('REFUND_INVALID');
    }
    if (count(array_unique($ids))!==count($ids)) throw new InvalidArgumentException('REFUND_INVALID');
    sort($ids,SORT_STRING);
    $selection=hash('sha256',json_encode([$ids,$reason],JSON_THROW_ON_ERROR));
    $request=hash('sha256',$buyer.'|'.$ref.'|'.$key);
    if ($db->inTransaction()) throw new DomainException('REFUND_BUSY');
    $db->beginTransaction();
    try {
        // Global mutex order matches checkout: Event, then Order, then Refund and Entries.
        $pre=$db->prepare('SELECT event_id FROM ticket_orders WHERE public_id=UNHEX(?) AND buyer_id=?');
        $pre->execute([$ref,$buyer]);$eventId=(int)$pre->fetchColumn();
        if($eventId<1)throw new DomainException('ORDER_NOT_REFUNDABLE');
        $lock=$db->prepare('SELECT id FROM events WHERE id=? FOR UPDATE');
        $lock->execute([$eventId]);if(!$lock->fetchColumn())throw new DomainException('ORDER_NOT_REFUNDABLE');
        $q=$db->prepare("SELECT id,event_id,total_cop,status,currency FROM ticket_orders
            WHERE public_id=UNHEX(?) AND buyer_id=? FOR UPDATE");
        $q->execute([$ref,$buyer]); $order=$q->fetch(PDO::FETCH_ASSOC);
        if($order&&(int)$order['event_id']!==$eventId)throw new DomainException('ORDER_NOT_REFUNDABLE');
        if (!$order||$order['currency']!=='COP') {
            throw new DomainException('ORDER_NOT_REFUNDABLE');
        }
        // Recheck the server-issued grant and current buyer status under
        // a locking read, after Event and Order locks, before replay/audit.
        $scope=brvtalRefundRequestScope($db,$buyer,(int)$order['id']);
        if ($scope!==null && $scope!==(int)$order['id']) {
            throw new DomainException('ORDER_NOT_REFUNDABLE');
        }
        $prior=$db->prepare('SELECT id,status,selection_hash FROM ticket_refunds WHERE order_id=? AND request_hash=?');
        $prior->execute([(int)$order['id'],$request]); $existing=$prior->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if (!hash_equals($existing['selection_hash'],$selection)) throw new DomainException('IDEMPOTENCY_CONFLICT');
            $db->commit(); return ['id'=>(int)$existing['id'],'status'=>$existing['status'],'replayed'=>true];
        }
        // A replay after the full refund must remain idempotent, but a new request is forbidden.
        if ($order['status']!=='paid'||brvtalRefundIncident($db,$eventId)==='') throw new DomainException('ORDER_NOT_REFUNDABLE');
        $q=$db->prepare('SELECT e.id,LOWER(HEX(e.public_id)) AS ref,e.status,i.unit_price_cop
            FROM ticket_entries e JOIN ticket_order_items i ON i.id=e.order_item_id
            WHERE i.order_id=? ORDER BY e.id FOR UPDATE');
        $q->execute([(int)$order['id']]); $all=$q->fetchAll(PDO::FETCH_ASSOC);
        if (!$all) throw new DomainException('ORDER_NOT_REFUNDABLE');
        $chosen=[]; $gross=0; $selected=0;
        foreach ($all as $row) {
            $unit=(int)$row['unit_price_cop'];
            if ($unit<1||$unit>1000000000000||$gross>1000000000000-$unit) {
                throw new DomainException('AMOUNT_UNVERIFIED');
            }
            $gross+=$unit;
            if (!$ids||in_array($row['ref'],$ids,true)) {
                if ($row['status']!=='issued') throw new DomainException('ENTRY_NOT_REFUNDABLE');
                if ($selected>1000000000000-$unit) throw new DomainException('AMOUNT_UNVERIFIED');
                $chosen[]=$row; $selected+=$unit;
            }
        }
        if ($ids&&count($chosen)!==count($ids)) throw new DomainException('ENTRY_NOT_REFUNDABLE');
        $total=(int)$order['total_cop'];
        if (!$chosen||$gross<1||$total<1||$total>1000000000000||$gross>1000000000000) {
            throw new DomainException('AMOUNT_UNVERIFIED');
        }
        $amount=count($chosen)===count($all)?$total:brvtalRefundProrate($total,$selected,$gross);
        if ($amount<1||$amount>$total) throw new DomainException('AMOUNT_UNVERIFIED');
        $db->prepare('INSERT INTO ticket_refunds(order_id,buyer_id,event_id,request_hash,selection_hash,reason,amount_cop)
            VALUES(?,?,?,?,?,?,?)')->execute([(int)$order['id'],$buyer,(int)$order['event_id'],$request,$selection,$reason,$amount]);
        $id=(int)$db->lastInsertId();
        $insert=$db->prepare('INSERT INTO ticket_refund_entries(refund_id,entry_id,amount_cop) VALUES(?,?,?)');
        foreach ($chosen as $row) $insert->execute([$id,(int)$row['id'],(int)$row['unit_price_cop']]);
        brvtalRefundAudit($db,$id,'buyer',$buyer,null,'requested');
        $db->commit(); return ['id'=>$id,'status'=>'requested','amount_cop'=>$amount,'replayed'=>false];
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}
/** Finishes a refund ONLY after authentic provider approval or a second staff audit receipt. */
function brvtalRefundFinalize(PDO $db,int $id,int $orderId,int $eventId,int $amount):void {
    $q=$db->prepare("SELECT e.id FROM ticket_refund_entries r JOIN ticket_entries e ON e.id=r.entry_id
        WHERE r.refund_id=? AND e.status='issued' FOR UPDATE");
    $q->execute([$id]); $entries=$q->fetchAll(PDO::FETCH_COLUMN);
    $count=(int)$db->query('SELECT COUNT(*) FROM ticket_refund_entries WHERE refund_id='.(int)$id)->fetchColumn();
    if ($count<1||count($entries)!==$count) throw new DomainException('ENTRY_NOT_REFUNDABLE');
    $revoke=$db->prepare("UPDATE ticket_entry_credentials SET status='revoked',revoked_at_utc=UTC_TIMESTAMP(6)
        WHERE entry_id=? AND status='active'");
    $change=$db->prepare("UPDATE ticket_entries SET status='refunded',revoked_at_utc=UTC_TIMESTAMP(6)
        WHERE id=? AND status='issued'");
    foreach ($entries as $entry) { $revoke->execute([(int)$entry]); $change->execute([(int)$entry]); }
    $db->prepare('INSERT INTO ticket_refund_adjustments(refund_id,order_id,event_id,amount_cop)
        VALUES(?,?,?,?)')->execute([$id,$orderId,$eventId,$amount]);
    $pending=$db->prepare("SELECT COUNT(*) FROM ticket_entries e JOIN ticket_order_items i ON i.id=e.order_item_id
        WHERE i.order_id=? AND e.status IN ('issued','used')");
    $pending->execute([$orderId]);
    if ((int)$pending->fetchColumn()===0) {
        $db->prepare("UPDATE ticket_orders SET status='refunded',updated_at_utc=UTC_TIMESTAMP(6)
            WHERE id=? AND status='paid'")->execute([$orderId]);
    }
}
/** One private notification per refund; outbox persists atomically, never sends here. */
function brvtalRefundQueueMail(PDO $db,int $id,int $orderId):void {
    require_once __DIR__.'/ticketing_mail_templates.php';
    $q=$db->prepare('SELECT b.email,e.title FROM ticket_orders o
        JOIN ticket_buyers b ON b.id=o.buyer_id JOIN events e ON e.id=o.event_id
        WHERE o.id=?');
    $q->execute([$orderId]);$row=$q->fetch(PDO::FETCH_NUM);
    if(!is_array($row)||!filter_var($row[0],FILTER_VALIDATE_EMAIL))
        throw new DomainException('REFUND_MAIL_UNAVAILABLE');
    $mail=brvtal_ticket_mail_render('refund',[
        'event'=>(string)$row[1],'link'=>'https://www.brvtal.com.co/mi-cuenta']);
    brvtal_ticket_mail_enqueue($db,"order:{$orderId}:refund:{$id}",
        "order:{$orderId}",(string)$row[0],$mail['subject'],$mail['text'],
        $mail['html'],'refund');
}
function brvtalRefundStaff(PDO $db,int $actor,string $role,int $id,string $action,string $note='',string $receipt=''):array {
    brvtalRefundGuard($db);
    if ($actor<1||$id<1||!brvtalTicketingCan($role,'refund')) throw new DomainException('FORBIDDEN');
    if (!in_array($action,['approve','reject','manual_confirm'],true)
        || strlen($note)>220||($action==='reject'&&strlen(trim($note))<4)) {
        throw new InvalidArgumentException('REFUND_INVALID');
    }
    if ($db->inTransaction()) throw new DomainException('REFUND_BUSY');
    $lookup=$db->prepare('SELECT order_id,event_id FROM ticket_refunds WHERE id=?');
    $lookup->execute([$id]); $link=$lookup->fetch(PDO::FETCH_ASSOC);
    if (!$link) throw new DomainException('REFUND_NOT_FOUND');
    $db->beginTransaction();
    try {
        $db->prepare('SELECT id FROM events WHERE id=? FOR UPDATE')->execute([(int)$link['event_id']]);
        $db->prepare('SELECT id FROM ticket_orders WHERE id=? FOR UPDATE')->execute([(int)$link['order_id']]);
        $q=$db->prepare('SELECT * FROM ticket_refunds WHERE id=? FOR UPDATE');
        $q->execute([$id]); $r=$q->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new DomainException('REFUND_NOT_FOUND');
        $before=$r['status']; $after=match($action) {
            'approve'=>'approved','reject'=>'rejected','manual_confirm'=>'refunded',
        };
        $manualReference=trim($receipt);
        if($before===$after){
            if($action==='manual_confirm' && !hash_equals(
                (string)$r['provider_refund_id'],'manual:'.$manualReference))
                throw new DomainException('REFUND_RECEIPT_CONFLICT');
            $db->commit();return ['id'=>$id,'status'=>$after,'replayed'=>true];
        }
        if(($action==='manual_confirm'&&(!in_array($before,['approved','manual_pending'],true)
            ||!preg_match('/^[A-Za-z0-9._:-]{6,120}$/D',$manualReference)
            ||(getenv('BRVTAL_TICKET_REFUNDS_MANUAL_ENABLED')!=='1'
              &&!(getenv('BRVTAL_INTEGRATION_TESTS')==='1'
                &&preg_match('/^brvtal_test[a-zA-Z0-9_]*$/D',
                  (string)$db->query('SELECT DATABASE()')->fetchColumn())===1))))
            ||($action!=='manual_confirm'&&$before!=='requested'))
            throw new DomainException('REFUND_INVALID_STATE');
        if($action==='manual_confirm'){
            // Never certify a payment that was not originally approved by a signed
            // Wompi webhook: POS and synthetic/unverified sales are not Wompi refunds.
            $payment=$db->prepare("SELECT 1 FROM ticket_payment_attempts a
                JOIN ticket_payment_webhook_events w ON w.attempt_id=a.id
                  AND w.provider_transaction_id=a.provider_transaction_id
                WHERE a.order_id=? AND a.provider='wompi'
                  AND a.status IN ('approved','refunded')
                  AND a.provider_transaction_id IS NOT NULL
                  AND w.signed_status='APPROVED' LIMIT 1");
            $payment->execute([(int)$r['order_id']]);
            if(!$payment->fetchColumn())throw new DomainException('REFUND_PAYMENT_UNVERIFIED');
            // Owner attests to an already completed Wompi-panel refund.
            // No provider API call or bank transfer is initiated here.
            brvtalRefundFinalize($db,$id,(int)$r['order_id'],(int)$r['event_id'],(int)$r['amount_cop']);
            brvtalRefundQueueMail($db,$id,(int)$r['order_id']);
        }
        $db->prepare('UPDATE ticket_refunds SET status=?,approver_admin_id=COALESCE(approver_admin_id,?),
            reviewer_note=?,provider_refund_id=?,manual_receipt_sha256=?,updated_at_utc=UTC_TIMESTAMP(6) WHERE id=?')
            ->execute([$after,$actor,$note,
                $action==='manual_confirm'?'manual:'.$manualReference:$r['provider_refund_id'],
                $action==='manual_confirm'?hash('sha256',$manualReference):$r['manual_receipt_sha256'],$id]);
        brvtalRefundAudit($db,$id,'staff',$actor,$before,$after);
        $db->commit();return ['id'=>$id,'status'=>$after,'replayed'=>false];
    } catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function brvtalRefundBuyerList(PDO $db,int $buyer):array {
    brvtalRefundGuard($db);
    if ($buyer<1) throw new DomainException('AUTH_REQUIRED');
    $scope=brvtalBuyerAccountCheckoutScope($db,$buyer);
    $sql='SELECT r.id,LOWER(HEX(o.public_id)) AS order_id,r.status,r.amount_cop,r.created_at_utc
        FROM ticket_refunds r JOIN ticket_orders o ON o.id=r.order_id
        WHERE r.buyer_id=? AND o.buyer_id=?';
    $args=[$buyer,$buyer];
    if ($scope!==null) { $sql.=' AND o.id=?'; $args[]=$scope; }
    $q=$db->prepare($sql.' ORDER BY r.id DESC LIMIT 100');
    $q->execute($args); return $q->fetchAll(PDO::FETCH_ASSOC);
}


/**
 * Wompi Refunds V2 sandbox-only request contract.
 * Pure construction: no HTTP, DB changes, payment movement or auto-finalization.
 * The authorized server transport, never the browser, must supply the private key.
 */
function brvtalRefundSandboxV2Payload(
    array $wompi, string $transactionId, int $amountCop, string $reason, string $reference
): array {
    if (($wompi['mode'] ?? '') !== 'sandbox'
        || !is_string($wompi['private'] ?? null)
        || !str_starts_with($wompi['private'], 'prv_test_')
        || strlen($wompi['private']) < 16) {
        throw new DomainException('REFUND_SANDBOX_ONLY');
    }
    if (!preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $transactionId)
        || !preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $reference)
        || $amountCop < 1 || $amountCop > 10000000
        || strlen(trim($reason)) < 4 || strlen($reason) > 220) {
        throw new DomainException('REFUND_INVALID');
    }
    return [
        'endpoint' => 'https://sandbox.wompi.co/v1/refunds',
        'body' => [
            'transaction_id' => $transactionId,
            'amount_in_cents' => $amountCop * 100,
            'reason' => trim($reason),
            'reference' => $reference,
        ],
    ];
}

/** Strictly correlate a trusted sandbox HTTP response before any ledger transition. */
function brvtalRefundSandboxV2Receipt(
    int $httpStatus, string $response, string $transactionId, int $amountCop, string $reference
): array {
    if ($httpStatus !== 201 || strlen($response) > 65536) {
        throw new DomainException('REFUND_PROVIDER_UNVERIFIED');
    }
    $decoded = json_decode($response, true);
    $data = is_array($decoded) ? ($decoded['data'] ?? null) : null;
    if (!is_array($data)
        || ($data['transaction_id'] ?? null) !== $transactionId
        || ($data['amount_in_cents'] ?? null) !== $amountCop * 100
        || ($data['reference'] ?? null) !== $reference) {
        throw new DomainException('REFUND_PROVIDER_UNVERIFIED');
    }
    $status = $data['status'] ?? null;
    if (!in_array($status, ['APPROVED','PENDING','DECLINED','ERROR','CANCELLED'], true)) {
        throw new DomainException('REFUND_PROVIDER_UNVERIFIED');
    }
    $providerId = $data['v2_refund_id'] ?? null;
    if ($status === 'APPROVED' && (!is_string($providerId)
        || !preg_match('/^[A-Za-z0-9._:-]{1,190}$/D', $providerId))) {
        throw new DomainException('REFUND_PROVIDER_UNVERIFIED');
    }
    return [
        'state' => $status === 'APPROVED' ? 'confirmed'
            : ($status === 'PENDING' ? 'processing' : 'failed'),
        'provider_status' => $status,
        'provider_refund_id' => is_string($providerId) ? $providerId : null,
    ];
}


/** Terminal outcomes are monotonic: late contradictory replies never revoke tickets. */
function brvtalRefundProviderNextStatus(string $current,string $providerState):string {
    if (!in_array($providerState,['confirmed','processing','failed'],true)) {
        throw new DomainException('REFUND_PROVIDER_UNVERIFIED');
    }
    if (in_array($current,['refunded','failed','rejected'],true)) return $current;
    if (!in_array($current,['approved','processing'],true)) {
        throw new DomainException('REFUND_INVALID_STATE');
    }
    return match($providerState) {
        'confirmed'=>'refunded','processing'=>'processing','failed'=>'failed',
    };
}

/**
 * Apply only a Wompi HTTP response received by a trusted server-side transport.
 * The buyer/staff HTTP endpoint never accepts arbitrary confirmation JSON.
 */
function brvtalRefundSandboxApply(PDO $db,int $id,int $httpStatus,string $response):array {
    brvtalRefundSandboxGuard($db);
    if ($id<1||$db->inTransaction()) throw new DomainException('REFUND_INVALID_STATE');
    $linkStmt=$db->prepare('SELECT order_id,event_id FROM ticket_refunds WHERE id=?');
    $linkStmt->execute([$id]);$link=$linkStmt->fetch(PDO::FETCH_ASSOC);
    if (!$link) throw new DomainException('REFUND_NOT_FOUND');
    $db->beginTransaction();
    try {
        // Canonical mutex order: Event, Order, Refund, then Entries.
        $db->prepare('SELECT id FROM events WHERE id=? FOR UPDATE')->execute([(int)$link['event_id']]);
        $db->prepare('SELECT id FROM ticket_orders WHERE id=? FOR UPDATE')->execute([(int)$link['order_id']]);
        $q=$db->prepare('SELECT * FROM ticket_refunds WHERE id=? FOR UPDATE');
        $q->execute([$id]);$refund=$q->fetch(PDO::FETCH_ASSOC);
        if (!$refund) throw new DomainException('REFUND_NOT_FOUND');
        $tx=$db->prepare("SELECT a.provider_transaction_id FROM ticket_payment_attempts a
            WHERE a.order_id=? AND a.provider='wompi' AND a.status IN ('approved','refunded')
              AND a.provider_transaction_id IS NOT NULL
              AND EXISTS(SELECT 1 FROM ticket_payment_webhook_events w
                WHERE w.attempt_id=a.id AND w.provider_transaction_id=a.provider_transaction_id
                  AND w.signed_status='APPROVED')
            ORDER BY a.id DESC LIMIT 1");
        $tx->execute([(int)$refund['order_id']]);$transactionId=(string)$tx->fetchColumn();
        if ($transactionId==='') throw new DomainException('REFUND_PAYMENT_UNVERIFIED');
        $receipt=brvtalRefundSandboxV2Receipt(
            $httpStatus,$response,$transactionId,(int)$refund['amount_cop'],'BRVTAL-T21-'.$id);
        $before=(string)$refund['status'];
        $next=brvtalRefundProviderNextStatus($before,$receipt['state']);
        $providerId=$receipt['provider_refund_id'];
        if ($next===$before) {
            if ($before==='refunded' && $providerId!==$refund['provider_refund_id']) {
                throw new DomainException('REFUND_PROVIDER_CONFLICT');
            }
            $db->commit();return ['id'=>$id,'status'=>$before,'replayed'=>true];
        }
        if ($next==='refunded') {
            if (!is_string($providerId)||$providerId===''
                ||($refund['provider_refund_id']!==null
                    && !hash_equals((string)$refund['provider_refund_id'],$providerId))) {
                throw new DomainException('REFUND_PROVIDER_CONFLICT');
            }
            brvtalRefundFinalize($db,$id,(int)$refund['order_id'],
                (int)$refund['event_id'],(int)$refund['amount_cop']);
        }
        $db->prepare('UPDATE ticket_refunds SET status=?,provider_refund_id=?,
            updated_at_utc=UTC_TIMESTAMP(6) WHERE id=?')
            ->execute([$next,$providerId??$refund['provider_refund_id'],$id]);
        brvtalRefundAudit($db,$id,'provider',null,$before,$next);
        $db->commit();return ['id'=>$id,'status'=>$next,'replayed'=>false];
    } catch(Throwable $e) {if ($db->inTransaction())$db->rollBack();throw $e;}
}


/**
 * POST only to Colombia Wompi V2 sandbox, from the server. A mocked transport
 * can be injected for deterministic tests; neither path logs nor returns keys.
 */
function brvtalRefundSandboxExchange(
    array $config,string $transactionId,int $amountCop,string $reason,string $reference,
    ?callable $transport=null
):array {
    $payload=brvtalRefundSandboxV2Payload($config,$transactionId,$amountCop,$reason,$reference);
    $headers=['Authorization: Bearer '.$config['private'],'Content-Type: application/json'];
    if ($transport!==null) {
        $reply=$transport($payload['endpoint'],$headers,$payload['body']);
    } else {
        if (!function_exists('curl_init')) throw new DomainException('REFUND_TRANSPORT_UNAVAILABLE');
        $curl=curl_init($payload['endpoint']);
        if ($curl===false) throw new DomainException('REFUND_TRANSPORT_UNAVAILABLE');
        curl_setopt_array($curl,[
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>json_encode($payload['body'],JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT=>3,
            CURLOPT_TIMEOUT=>8,
            CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2
        ]);
        $body=curl_exec($curl);
        if (!is_string($body)) throw new DomainException('REFUND_PROVIDER_UNVERIFIED');
        $reply=['status'=>(int)curl_getinfo($curl,CURLINFO_HTTP_CODE),'body'=>$body];

    }
    if (!is_array($reply)||!is_int($reply['status']??null)
        ||!is_string($reply['body']??null)||strlen($reply['body'])>65536) {
        throw new DomainException('REFUND_PROVIDER_UNVERIFIED');
    }
    // Do not accept a status or amount from a caller; verify Wompi's reply
    // against our already-persisted original transaction before committing.
    brvtalRefundSandboxV2Receipt(
        $reply['status'],$reply['body'],$transactionId,$amountCop,$reference);
    return ['status'=>$reply['status'],'body'=>$reply['body']];
}

/**
 * Persistent processing barrier is set BEFORE the provider request. Network
 * ambiguity must not trigger a second refund; the operator reconciles it.
 */
function brvtalRefundSandboxDispatch(
    PDO $db,int $actor,string $role,int $id,array $config,?callable $transport=null
):array {
    brvtalRefundSandboxGuard($db);
    if ($actor<1||$id<1||!brvtalTicketingCan($role,'refund')) throw new DomainException('FORBIDDEN');
    if (getenv('BRVTAL_TICKET_WOMPI_SANDBOX_ENABLED')!=='1'
        ||getenv('BRVTAL_TICKET_WOMPI_MODE')!=='sandbox') {
        throw new DomainException('REFUND_SANDBOX_ONLY');
    }
    if ($db->inTransaction()) throw new DomainException('REFUND_BUSY');
    $lookup=$db->prepare('SELECT order_id,event_id FROM ticket_refunds WHERE id=?');
    $lookup->execute([$id]);$link=$lookup->fetch(PDO::FETCH_ASSOC);
    if (!$link) throw new DomainException('REFUND_NOT_FOUND');
    $db->beginTransaction();
    try {
        $db->prepare('SELECT id FROM events WHERE id=? FOR UPDATE')->execute([(int)$link['event_id']]);
        $db->prepare('SELECT id FROM ticket_orders WHERE id=? FOR UPDATE')->execute([(int)$link['order_id']]);
        $q=$db->prepare('SELECT * FROM ticket_refunds WHERE id=? FOR UPDATE');
        $q->execute([$id]);$r=$q->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new DomainException('REFUND_NOT_FOUND');
        if (in_array($r['status'],['processing','refunded','failed'],true)) {
            $db->commit();return ['id'=>$id,'status'=>$r['status'],'replayed'=>true];
        }
        if ($r['status']!=='approved') throw new DomainException('REFUND_INVALID_STATE');
        $tx=$db->prepare("SELECT a.provider_transaction_id,a.gross_amount_cop
            FROM ticket_payment_attempts a WHERE a.order_id=? AND a.provider='wompi'
                AND a.status='approved' AND a.provider_transaction_id IS NOT NULL
                AND EXISTS(SELECT 1 FROM ticket_payment_webhook_events w
                    WHERE w.attempt_id=a.id AND w.provider_transaction_id=a.provider_transaction_id
                      AND w.signed_status='APPROVED')
            ORDER BY a.id DESC LIMIT 1");
        $tx->execute([(int)$r['order_id']]);$payment=$tx->fetch(PDO::FETCH_ASSOC);
        if (!$payment||(int)$r['amount_cop']<1
            ||(int)$r['amount_cop']>(int)$payment['gross_amount_cop']) {
            throw new DomainException('REFUND_PAYMENT_UNVERIFIED');
        }
        $transactionId=(string)$payment['provider_transaction_id'];
        $reference='BRVTAL-T21-'.$id;
        brvtalRefundSandboxV2Payload($config,$transactionId,(int)$r['amount_cop'],
            (string)$r['reason'],$reference);
        $db->prepare("UPDATE ticket_refunds SET status='processing',
            updated_at_utc=UTC_TIMESTAMP(6) WHERE id=?")->execute([$id]);
        brvtalRefundAudit($db,$id,'staff',$actor,'approved','processing');
        $db->commit();
    } catch(Throwable $e) {if ($db->inTransaction())$db->rollBack();throw $e;}
    try {
        $reply=brvtalRefundSandboxExchange($config,$transactionId,(int)$r['amount_cop'],
            (string)$r['reason'],$reference,$transport);
        return brvtalRefundSandboxApply($db,$id,$reply['status'],$reply['body']);
    } catch(Throwable) {
        // HTTP timeout/5xx or DB failure after provider acceptance is UNKNOWN.
        // Never retry POST implicitly; keep the durable processing witness.
        return ['id'=>$id,'status'=>'processing','unverified'=>true];
    }
}
