<?php
declare(strict_types=1);
require_once __DIR__ . '/ticketing_staff_permissions.php';
require_once __DIR__ . '/ticketing_wompi_client.php';

/** T21 is deliberately disabled outside disposable synthetic databases. */
function brvtalRefundGuard(PDO $db): void {
    if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1'
        || !preg_match('/^brvtal_test[a-zA-Z0-9_]*$/D',(string)$db->query('SELECT DATABASE()')->fetchColumn())) {
        throw new DomainException('REFUND_SANDBOX_ONLY');
    }
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
        $q=$db->prepare("SELECT id,event_id,total_cop,status,currency FROM ticket_orders
            WHERE public_id=UNHEX(?) AND buyer_id=? FOR UPDATE");
        $q->execute([$ref,$buyer]); $order=$q->fetch(PDO::FETCH_ASSOC);
        if (!$order||$order['currency']!=='COP') {
            throw new DomainException('ORDER_NOT_REFUNDABLE');
        }
        $prior=$db->prepare('SELECT id,status,selection_hash FROM ticket_refunds WHERE order_id=? AND request_hash=?');
        $prior->execute([(int)$order['id'],$request]); $existing=$prior->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if (!hash_equals($existing['selection_hash'],$selection)) throw new DomainException('IDEMPOTENCY_CONFLICT');
            $db->commit(); return ['id'=>(int)$existing['id'],'status'=>$existing['status'],'replayed'=>true];
        }
        // A replay after the full refund must remain idempotent, but a new request is forbidden.
        if ($order['status']!=='paid') throw new DomainException('ORDER_NOT_REFUNDABLE');
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
        if ($before===$after) { $db->commit();return ['id'=>$id,'status'=>$after,'replayed'=>true]; }
        if (($action==='manual_confirm'&&(!in_array($before,['approved','manual_pending'],true)
              ||(int)$r['approver_admin_id']===$actor
              ||!preg_match('/^[a-f0-9]{64}$/D',$receipt)))
            ||($action!=='manual_confirm'&&$before!=='requested')) throw new DomainException('REFUND_INVALID_STATE');
        if ($action==='manual_confirm') {
            brvtalRefundFinalize($db,$id,(int)$r['order_id'],(int)$r['event_id'],(int)$r['amount_cop']);
        }
        $db->prepare('UPDATE ticket_refunds SET status=?,approver_admin_id=COALESCE(approver_admin_id,?),
            reviewer_note=?,manual_receipt_sha256=?,updated_at_utc=UTC_TIMESTAMP(6) WHERE id=?')
            ->execute([$after,$actor,$note,$action==='manual_confirm'?$receipt:null,$id]);
        brvtalRefundAudit($db,$id,'staff',$actor,$before,$after);
        $db->commit();return ['id'=>$id,'status'=>$after,'replayed'=>false];
    } catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function brvtalRefundBuyerList(PDO $db,int $buyer):array {
    brvtalRefundGuard($db);
    if ($buyer<1) throw new DomainException('AUTH_REQUIRED');
    $q=$db->prepare('SELECT r.id,LOWER(HEX(o.public_id)) AS order_id,r.status,r.amount_cop,r.created_at_utc
        FROM ticket_refunds r JOIN ticket_orders o ON o.id=r.order_id WHERE r.buyer_id=?
        ORDER BY r.id DESC LIMIT 100');
    $q->execute([$buyer]); return $q->fetchAll(PDO::FETCH_ASSOC);
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
