<?php
declare(strict_types=1);

require_once __DIR__ . '/ticketing_staff_permissions.php';
require_once __DIR__ . '/ticketing_admin_actions.php';
require_once __DIR__ . '/ticketing_inventory.php';
require_once __DIR__ . '/ticketing_issue.php';

/** T24: only authorized financial staff in a disposable integration database. */
function brvtalPosCan(string $role): bool {
    return brvtalTicketingCan($role, 'orders') && brvtalTicketingCan($role, 'finance');
}
function brvtalPosInt(mixed $value, int $min = 1, int $max = 1000000000): int {
    if (!is_string($value) || !preg_match('/^[0-9]{1,10}$/D', $value)
        || (int)$value < $min || (int)$value > $max
        || (string)(int)$value !== $value) {
        throw new InvalidArgumentException('POS_INVALID_NUMBER');
    }
    return (int)$value;
}
/** Exact action shapes, no client-provided amount, buyer, provider proof or event status. */
function brvtalPosInput(array $raw): array {
    $shape = [
        'open'=>['action','event_id','opening_cash_cop'],
        'manual_order'=>['action','event_id','ticket_type_id','quantity','buyer_name',
            'buyer_email','method','payment_reference','idempotency_key','confirm'],
        'prepare'=>['action','shift_id','ticket_type_id','quantity','method','idempotency_key'],
        'confirm'=>['action','sale_id','confirmation_token','confirm'],
        'cancel'=>['action','sale_id','confirmation_token'],
        'close'=>['action','shift_id','closing_cash_cop'],
    ];
    $action = $raw['action'] ?? null;
    if (!is_string($action) || !isset($shape[$action])) throw new InvalidArgumentException('POS_INVALID_ACTION');
    $keys=array_keys($raw); sort($keys);
    $expected=$shape[$action];
    if ($action==='confirm' && array_key_exists('buyer_email',$raw)) $expected[]='buyer_email';
    sort($expected);
    if ($keys!==$expected) throw new InvalidArgumentException('POS_INVALID_FIELDS');
    $result=['action'=>$action];
    foreach (['event_id','shift_id','ticket_type_id','quantity','sale_id'] as $name) {
        if (isset($raw[$name])) $result[$name]=brvtalPosInt($raw[$name],1,$name==='quantity'?10:1000000000);
    }
    foreach (['opening_cash_cop','closing_cash_cop'] as $name) {
        if (isset($raw[$name])) $result[$name]=brvtalPosInt($raw[$name],0,1000000000);
    }
    if ($action==='prepare') {
        if (!is_string($raw['method']) || !in_array($raw['method'],['cash','transfer','wompi_qr'],true)
            || !is_string($raw['idempotency_key'])
            || !preg_match('/^[A-Za-z0-9_-]{20,64}$/D',$raw['idempotency_key'])) {
            throw new InvalidArgumentException('POS_INVALID_PREPARE');
        }
        $result['method']=$raw['method'];
        $result['idempotency_key']=$raw['idempotency_key'];
    }
    if ($action==='manual_order') {
        $name=trim((string)($raw['buyer_name']??''));
        $email=trim((string)($raw['buyer_email']??''));
        $method=$raw['method']??null;
        $reference=trim((string)($raw['payment_reference']??''));
        $key=$raw['idempotency_key']??null;
        if (!is_string($raw['buyer_name']) || strlen($name)<3 || strlen($name)>120
            || preg_match('/[\x00-\x1f\x7f]/', $name)
            || !is_string($raw['buyer_email']) || strlen($email)>190
            || filter_var($email,FILTER_VALIDATE_EMAIL)===false
            || !is_string($method)
            || !in_array($method,['cash','transfer','nequi','daviplata','terminal'],true)
            || !is_string($raw['payment_reference'])
            || strlen($reference)>80
            || ($method!=='cash' && (strlen($reference)<4
                || !preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._\/-]*$/D',$reference)))
            || ($method==='cash' && $reference!=='')
            || !is_string($key) || !preg_match('/^[A-Za-z0-9_-]{20,64}$/D',$key)
            || ($raw['confirm']??false)!==true) {
            throw new InvalidArgumentException('POS_INVALID_MANUAL_ORDER');
        }
        $result+=['buyer_name'=>$name,'buyer_email'=>strtolower($email),
            'method'=>$method,'payment_reference'=>$reference,
            'idempotency_key'=>$key,'confirm'=>true];
    }
    if ($action==='confirm' || $action==='cancel') {
        if (!is_string($raw['confirmation_token'])
            || !preg_match('/^[a-f0-9]{48}$/D',$raw['confirmation_token'])) {
            throw new InvalidArgumentException('POS_INVALID_CONFIRMATION');
        }
        $result['confirmation_token']=$raw['confirmation_token'];
    }
    if ($action==='confirm') {
        if ($raw['confirm']!==true) throw new InvalidArgumentException('POS_CONFIRMATION_REQUIRED');
        $result['confirm']=true;
        if (array_key_exists('buyer_email',$raw)) {
            $email=$raw['buyer_email'];
            if (!is_string($email) || strlen($email)>190
                || ($email!=='' && filter_var($email,FILTER_VALIDATE_EMAIL)===false)) {
                throw new InvalidArgumentException('POS_INVALID_EMAIL');
            }
            $result['buyer_email']=$email===''?null:strtolower($email);
        }
    }
    return $result;
}
function brvtalPosRun(PDO $db, int $actor, string $role, array $raw): array {
    $input=brvtalPosInput($raw);
    $manual=$input['action']==='manual_order';
    $isOwner=in_array(strtolower(trim($role)),
        ['superadmin','root','owner','platform_owner'],true);
    if ($actor<1 || ($manual ? !$isOwner : !brvtalPosCan($role))) {
        throw new DomainException('FORBIDDEN');
    }
    // Legacy drawer remains synthetic-only. Only the tightly validated owner
    // manual-order action is production-capable; it never calls Wompi.
    if (!$manual && !brvtalTicketAdminSyntheticMode($db)) {
        throw new DomainException('POS_SYNTHETIC_ONLY');
    }
    if ($db->inTransaction()) throw new LogicException('POS_TRANSACTION_REQUIRED');
    $db->beginTransaction();
    try {
        $result=match($input['action']) {
            'open'=>brvtalPosOpen($db,$actor,$input),
            'manual_order'=>brvtalPosManualOrder($db,$actor,$input),
            'prepare'=>brvtalPosPrepare($db,$actor,$input),
            'confirm'=>brvtalPosConfirm($db,$actor,$input),
            'cancel'=>brvtalPosCancel($db,$actor,$input),
            'close'=>brvtalPosClose($db,$actor,$input),
        };
        $db->commit();
        return $result;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
function brvtalPosShift(PDO $db,int $actor,int $id,bool $open=true): array {
    $q=$db->prepare('SELECT * FROM ticket_pos_shifts WHERE id=? AND operator_admin_id=? FOR UPDATE');
    $q->execute([$id,$actor]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    if (!$row || ($open && $row['status']!=='open')) throw new DomainException('POS_SHIFT_UNAVAILABLE');
    return $row;
}
function brvtalPosOpen(PDO $db,int $actor,array $i): array {
    // Lock this actor before querying open shifts: no second concurrent drawer.
    $actorRow=$db->prepare('SELECT id FROM admins WHERE id=? AND is_active=1 FOR UPDATE');
    $actorRow->execute([$actor]);
    if ($actorRow->fetchColumn()===false) throw new DomainException('FORBIDDEN');
    $q=$db->prepare("SELECT id FROM ticket_pos_shifts WHERE operator_admin_id=? AND status='open' LIMIT 1");
    $q->execute([$actor]);
    if ($q->fetchColumn()!==false) throw new DomainException('POS_SHIFT_ALREADY_OPEN');
    $event=$db->prepare('SELECT id FROM events WHERE id=? AND status IN (\'active\',\'published\')');
    $event->execute([$i['event_id']]);
    if ($event->fetchColumn()===false) throw new DomainException('POS_EVENT_UNAVAILABLE');
    $insert=$db->prepare('INSERT INTO ticket_pos_shifts(event_id,operator_admin_id,opening_cash_cop) VALUES (?,?,?)');
    $insert->execute([$i['event_id'],$actor,$i['opening_cash_cop']]);
    return ['shift_id'=>(int)$db->lastInsertId(),'status'=>'open','cash_cop'=>$i['opening_cash_cop']];
}
function brvtalPosPrepare(PDO $db,int $actor,array $i): array {
    $shift=brvtalPosShift($db,$actor,$i['shift_id']);
    if ($i['method']==='wompi_qr') throw new DomainException('POS_PROVIDER_PROOF_REQUIRED');
    if ($i['method']==='transfer') throw new DomainException('POS_TRANSFER_RULES_REQUIRED');
    $type=$db->prepare('SELECT id,event_id,name,price,currency,status FROM event_ticket_types WHERE id=? AND event_id=?');
    $type->execute([$i['ticket_type_id'],(int)$shift['event_id']]);
    $row=$type->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['status']!=='active' || $row['currency']!=='COP'
        || !is_string($row['price']) || !preg_match('/^[0-9]{1,9}\.00$/D',$row['price'])) {
        throw new DomainException('POS_TYPE_UNAVAILABLE');
    }
    $unit=(int)$row['price']; $amount=$unit*$i['quantity'];
    if ($unit<1 || $amount>1000000000) throw new DomainException('POS_AMOUNT_INVALID');
    $key=hash('sha256',$actor.':'.$i['idempotency_key']);
    $digest=hash('sha256',json_encode([$i['shift_id'],$i['ticket_type_id'],$i['quantity'],$i['method']],JSON_THROW_ON_ERROR));
    $check=$db->prepare('SELECT id,request_hash,status FROM ticket_pos_sales WHERE request_key_hash=? FOR UPDATE');
    $check->execute([$key]); $old=$check->fetch(PDO::FETCH_ASSOC);
    if ($old) {
        if (!hash_equals((string)$old['request_hash'],$digest)) throw new DomainException('POS_IDEMPOTENCY_CONFLICT');
        return ['sale_id'=>(int)$old['id'],'status'=>$old['status'],'replayed'=>true,'confirmation_token'=>null];
    }
    $token=bin2hex(random_bytes(24));
    $ins=$db->prepare("INSERT INTO ticket_pos_sales
        (shift_id,event_id,operator_admin_id,ticket_type_id,quantity,unit_price_cop,amount_cop,method,
          request_key_hash,request_hash,confirmation_hash,expires_at_utc)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 120 SECOND))");
    $ins->execute([$i['shift_id'],(int)$shift['event_id'],$actor,$i['ticket_type_id'],$i['quantity'],
        $unit,$amount,$i['method'],$key,$digest,hash('sha256',$token)]);
    return ['sale_id'=>(int)$db->lastInsertId(),'amount_cop'=>$amount,'method'=>$i['method'],
        'status'=>'prepared','confirmation_token'=>$token,'replayed'=>false];
}
function brvtalPosSale(PDO $db,int $actor,array $i): array {
    $q=$db->prepare('SELECT shift_id FROM ticket_pos_sales WHERE id=?');
    $q->execute([$i['sale_id']]); $shift=$q->fetchColumn();
    if ($shift===false) throw new DomainException('POS_SALE_UNAVAILABLE');
    brvtalPosShift($db,$actor,(int)$shift);
    $q=$db->prepare('SELECT * FROM ticket_pos_sales WHERE id=? AND operator_admin_id=? FOR UPDATE');
    $q->execute([$i['sale_id'],$actor]); $row=$q->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['status']!=='prepared'
        || !hash_equals((string)$row['confirmation_hash'],hash('sha256',$i['confirmation_token']))
        || (string)$row['expires_at_utc']<=gmdate('Y-m-d H:i:s')) {
        throw new DomainException('POS_SALE_UNAVAILABLE');
    }
    return $row;
}
function brvtalPosCancel(PDO $db,int $actor,array $i): array {
    $row=brvtalPosSale($db,$actor,$i);
    $db->prepare("UPDATE ticket_pos_sales SET status='cancelled',completed_at_utc=UTC_TIMESTAMP(6)
        WHERE id=? AND status='prepared'")->execute([(int)$row['id']]);
    return ['sale_id'=>(int)$row['id'],'status'=>'cancelled','tickets'=>[]];
}
function brvtalPosConfirm(PDO $db,int $actor,array $i): array {
    $row=brvtalPosSale($db,$actor,$i);
    if ($row['method']!=='cash') throw new DomainException('POS_PROVIDER_PROOF_REQUIRED');
    // A separate POS evidence row, never a forged Wompi approval or payout.
    // No marketing consent is inferred. The address is used only for the
    // transactional QR delivery after a trusted cash/terminal confirmation.
    $email=$i['buyer_email']??null;
    if ($email!==null) {
        $read=$db->prepare('SELECT id FROM ticket_buyers WHERE email=? FOR UPDATE');
        $read->execute([$email]);
        $existing=$read->fetchColumn();
        if ($existing!==false) {
            $buyer=(int)$existing;
        } else {
            $db->prepare("INSERT INTO ticket_buyers(public_id,email,status)
                VALUES (?,?,'guest') ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)")
                ->execute([random_bytes(16),$email]);
            $buyer=(int)$db->lastInsertId();
        }
    } else {
        $db->prepare("INSERT INTO ticket_buyers(public_id,status) VALUES (?,'guest')")
            ->execute([random_bytes(16)]);
        $buyer=(int)$db->lastInsertId();
    }
    $db->prepare("INSERT INTO ticket_orders
        (public_id,buyer_id,event_id,checkout_key_hash,status,currency,total_cop)
        VALUES (?,?,?,?,'draft','COP',?)")
        ->execute([random_bytes(16),$buyer,(int)$row['event_id'],
            hash('sha256','pos:'.$row['id']),(int)$row['amount_cop']]);
    $order=(int)$db->lastInsertId();
    $type=$db->prepare("SELECT name FROM event_ticket_types WHERE id=? AND event_id=?");
    $type->execute([(int)$row['ticket_type_id'],(int)$row['event_id']]);
    $name=$type->fetchColumn();
    if (!is_string($name) || $name==='') throw new DomainException('POS_TYPE_UNAVAILABLE');
    $db->prepare("INSERT INTO ticket_order_items
        (order_id,event_id,ticket_type_id,ticket_name_snapshot,quantity,unit_price_cop,subtotal_cop,currency)
        VALUES (?,?,?,?,?,?,?,'COP')")
        ->execute([$order,(int)$row['event_id'],(int)$row['ticket_type_id'],$name,
            (int)$row['quantity'],(int)$row['unit_price_cop'],(int)$row['amount_cop']]);
    $item=(int)$db->lastInsertId();
    brvtal_ticket_inventory_hold($db,$order,(int)$row['ticket_type_id'],(int)$row['quantity'],'paid');
    $db->prepare("UPDATE ticket_orders SET status='paid' WHERE id=? AND status='draft'")->execute([$order]);
    if (!brvtal_ticket_inventory_transition($db,$order,(int)$row['ticket_type_id'],'consumed')) {
        throw new DomainException('POS_INVENTORY_UNAVAILABLE');
    }
    $entry=$db->prepare('INSERT INTO ticket_entries
        (public_id,order_item_id,event_id,ticket_type_id,unit_number,issued_at_utc)
        VALUES (?,?,?,?,?,UTC_TIMESTAMP(6))');
    $credential=$db->prepare('INSERT INTO ticket_entry_credentials(entry_id,version,token_hash,created_at_utc)
        VALUES (?,1,?,UTC_TIMESTAMP(6))');
    $tickets=[];
    for($n=1;$n<=(int)$row['quantity'];$n++) {
        $public=random_bytes(16); $secret=random_bytes(32);
        $entry->execute([$public,$item,(int)$row['event_id'],(int)$row['ticket_type_id'],$n]);
        $credential->execute([(int)$db->lastInsertId(),hash('sha256',$secret,true)]);
        $qr=rtrim(strtr(base64_encode($secret),'+/','-_'),'=');
        $tickets[]='BRVTAL:T1:'.bin2hex($public).':'.$qr;
    }
    $db->prepare("UPDATE ticket_pos_sales SET status='paid',order_id=?,completed_at_utc=UTC_TIMESTAMP(6)
        WHERE id=? AND status='prepared'")->execute([$order,(int)$row['id']]);
    return ['sale_id'=>(int)$row['id'],'receipt'=>'POS-'.(int)$row['id'],
        'status'=>'paid','amount_cop'=>(int)$row['amount_cop'],'channel'=>'door_cash',
        'order_id'=>$order,'tickets'=>$tickets,'replayed'=>false];
}
function brvtalPosClose(PDO $db,int $actor,array $i): array {
    $row=brvtalPosShift($db,$actor,$i['shift_id']);
    $q=$db->prepare("SELECT COALESCE(SUM(amount_cop),0) FROM ticket_pos_sales
        WHERE shift_id=? AND status='paid' AND method='cash'");
    $q->execute([$i['shift_id']]); $sold=(int)$q->fetchColumn();
    $expected=(int)$row['opening_cash_cop']+$sold;
    $difference=$i['closing_cash_cop']-$expected;
    $db->prepare("UPDATE ticket_pos_shifts SET status='closed',expected_cash_cop=?,
        closing_cash_cop=?,cash_difference_cop=?,closed_at_utc=UTC_TIMESTAMP(6)
        WHERE id=? AND status='open'")->execute([$expected,$i['closing_cash_cop'],$difference,$i['shift_id']]);
    return ['shift_id'=>$i['shift_id'],'status'=>'closed','opening_cash_cop'=>(int)$row['opening_cash_cop'],
        'cash_sales_cop'=>$sold,'expected_cash_cop'=>$expected,
        'closing_cash_cop'=>$i['closing_cash_cop'],'cash_difference_cop'=>$difference,
        'wompi_settlement_included'=>false];
}

/**
 * Owner-confirmed offline payment. One transaction covers the buyer, order,
 * inventory, POS audit and issuance outbox. The worker alone generates QR
 * credentials and queues the transactional buyer email after commit.
 * Never fabricate a Wompi attempt or call an external payment provider.
 */
function brvtalPosManualOrder(PDO $db,int $actor,array $i): array {
    // Serialize requests from the same owner, including concurrent retries.
    $lock=$db->prepare('SELECT id FROM admins WHERE id=? AND is_active=1 FOR UPDATE');
    $lock->execute([$actor]);
    if ($lock->fetchColumn()===false) throw new DomainException('FORBIDDEN');

    $key=hash('sha256','manual-order:'.$actor.':'.$i['idempotency_key']);
    $digest=hash('sha256',json_encode([
        $i['event_id'],$i['ticket_type_id'],$i['quantity'],$i['buyer_name'],
        $i['buyer_email'],$i['method'],$i['payment_reference']
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    $old=$db->prepare('SELECT id,status FROM ticket_orders WHERE checkout_key_hash=? FOR UPDATE');
    $old->execute([$key]);
    $prior=$old->fetch(PDO::FETCH_ASSOC);
    if ($prior) {
        $q=$db->prepare('SELECT s.id,s.amount_cop,n.note_text FROM ticket_pos_sales s
            JOIN ticket_order_notes n ON n.order_id=s.order_id AND n.actor_admin_id=s.operator_admin_id
            WHERE s.order_id=? AND s.operator_admin_id=? LIMIT 1 FOR UPDATE');
        $q->execute([(int)$prior['id'],$actor]);
        $receipt=$q->fetch(PDO::FETCH_ASSOC);
        if (!$receipt || !str_ends_with((string)$receipt['note_text'],'|'.$digest)) {
            throw new DomainException('POS_IDEMPOTENCY_CONFLICT');
        }
        return ['order_id'=>(int)$prior['id'],'status'=>$prior['status'],
            'amount_cop'=>(int)$receipt['amount_cop'],'issuance_queued'=>true,
            'replayed'=>true];
    }

    $type=$db->prepare('SELECT id,event_id,name,price,currency,status
        FROM event_ticket_types WHERE id=? AND event_id=?');
    $type->execute([$i['ticket_type_id'],$i['event_id']]);
    $ticket=$type->fetch(PDO::FETCH_ASSOC);
    if (!$ticket || $ticket['status']!=='active' || $ticket['currency']!=='COP'
        || !is_string($ticket['price'])
        || !preg_match('/^[0-9]{1,9}\.00$/D',$ticket['price'])) {
        throw new DomainException('POS_TYPE_UNAVAILABLE');
    }
    $unit=(int)$ticket['price'];
    $amount=$unit*$i['quantity'];
    if ($unit<1 || $amount>1000000000) throw new DomainException('POS_AMOUNT_INVALID');

    // Never infer marketing consent from an offline ticket sale.
    $buyer=$db->prepare('INSERT INTO ticket_buyers(public_id,email,full_name,status)
        VALUES (?,?,?,\'guest\')
        ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)');
    $buyer->execute([random_bytes(16),$i['buyer_email'],$i['buyer_name']]);
    $buyerId=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO ticket_orders
        (public_id,buyer_id,event_id,checkout_key_hash,status,currency,total_cop)
        VALUES (?,?,?,?,'draft','COP',?)")
        ->execute([random_bytes(16),$buyerId,$i['event_id'],$key,$amount]);
    $orderId=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO ticket_order_items
        (order_id,event_id,ticket_type_id,ticket_name_snapshot,quantity,unit_price_cop,subtotal_cop,currency)
        VALUES (?,?,?,?,?,?,?,'COP')")
        ->execute([$orderId,$i['event_id'],$i['ticket_type_id'],$ticket['name'],
            $i['quantity'],$unit,$amount]);

    // Inventory holds lock the Event row and revalidate saleability/aforo.
    brvtal_ticket_inventory_hold($db,$orderId,$i['ticket_type_id'],$i['quantity'],'paid');
    $db->prepare("UPDATE ticket_orders SET status='paid',updated_at_utc=UTC_TIMESTAMP(6)
        WHERE id=? AND status='draft'")->execute([$orderId]);
    if (!brvtal_ticket_inventory_transition($db,$orderId,$i['ticket_type_id'],'consumed')) {
        throw new DomainException('POS_INVENTORY_UNAVAILABLE');
    }

    // Closed zero-cash shift is an audit parent, NOT a live drawer.
    $db->prepare("INSERT INTO ticket_pos_shifts
        (event_id,operator_admin_id,status,opening_cash_cop,expected_cash_cop,
            closing_cash_cop,cash_difference_cop,closed_at_utc)
        VALUES (?,?,'closed',0,0,0,0,UTC_TIMESTAMP(6))")
        ->execute([$i['event_id'],$actor]);
    $shiftId=(int)$db->lastInsertId();
    $posMethod=in_array($i['method'],['nequi','daviplata'],true) ? 'transfer' : $i['method'];
    $db->prepare("INSERT INTO ticket_pos_sales
        (shift_id,event_id,operator_admin_id,ticket_type_id,order_id,
         quantity,unit_price_cop,amount_cop,method,status,request_key_hash,
         request_hash,confirmation_hash,expires_at_utc,completed_at_utc)
        VALUES (?,?,?,?,?,?,?,?,?,'paid',?,?,?,DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 120 SECOND),UTC_TIMESTAMP(6))")
        ->execute([$shiftId,$i['event_id'],$actor,$i['ticket_type_id'],$orderId,
            $i['quantity'],$unit,$amount,$posMethod,hash('sha256','pos:'.$key),
            $digest,hash('sha256',random_bytes(24))]);
    $saleId=(int)$db->lastInsertId();
    // Exact method, bank reference and request digest are durable auditable
    // owner evidence, separate from Wompi and without QR secrets.
    $db->prepare('INSERT INTO ticket_order_notes(order_id,actor_admin_id,note_text)
        VALUES (?,?,?)')->execute([$orderId,$actor,
            'manual-payment|'.$i['method'].'|'.$i['payment_reference'].'|'.$digest]);

    brvtal_ticket_pos_enqueue($db,$orderId);
    return ['order_id'=>$orderId,'sale_id'=>$saleId,'status'=>'paid',
        'amount_cop'=>$amount,'method'=>$i['method'],
        'issuance_queued'=>true,'replayed'=>false];
}
