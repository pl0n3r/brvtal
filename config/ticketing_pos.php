<?php
declare(strict_types=1);

require_once __DIR__ . '/ticketing_staff_permissions.php';
require_once __DIR__ . '/ticketing_admin_actions.php';
require_once __DIR__ . '/ticketing_inventory.php';
require_once __DIR__ . '/ticketing_stages.php';
require_once __DIR__ . '/ticketing_issue.php';
require_once __DIR__ . '/ticketing_pos_receipts.php';

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
/** Exact action shapes; only manual orders accept an evidenced received amount. */
function brvtalPosInput(array $raw): array {
    $shape = [
        'open'=>['action','event_id','opening_cash_cop'],
        'shift_status'=>['action','event_id'],
        'manual_intent'=>['action','idempotency_key'],
        'manual_quote'=>['action','event_id','ticket_type_id','quantity','idempotency_key'],
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
    if ($action==='manual_order' && array_key_exists('quote_token',$raw)) $expected[]='quote_token';
    if ($action==='manual_order' && array_key_exists('destination_id', $raw)) {
        $expected[]='destination_id';
    }
    if ($action==='manual_order' && array_key_exists('received_amount_cop',$raw)) {
        $expected=array_merge($expected,['received_amount_cop','received_reason','received_reference']);
    }
    sort($expected);
    if ($keys!==$expected) throw new InvalidArgumentException('POS_INVALID_FIELDS');
    $result=['action'=>$action];
    foreach (['event_id','shift_id','ticket_type_id','quantity','sale_id'] as $name) {
        if (array_key_exists($name,$raw)) $result[$name]=brvtalPosInt($raw[$name],1,$name==='quantity'?10:1000000000);
    }
    foreach (['opening_cash_cop','closing_cash_cop'] as $name) {
        if (isset($raw[$name])) $result[$name]=brvtalPosInt($raw[$name],0,1000000000);
    }
    if ($action==='shift_status') {
        $result['event_id']=brvtalPosInt($raw['event_id']);
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
    if (in_array($action,['manual_intent','manual_quote'],true)) {
        $key=$raw['idempotency_key'];
        if (!is_string($key) || !preg_match('/^[A-Za-z0-9_-]{20,64}$/D', $key)) {
            throw new InvalidArgumentException('POS_INVALID_MANUAL_INTENT');
        }
        $result['idempotency_key']=$key;
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
        if (array_key_exists('destination_id', $raw)) {
            $result['destination_id']=brvtalPosInt($raw['destination_id']);
        }
        if (array_key_exists('received_amount_cop',$raw)) {
            // Zero is a separate courtesy flow. Preserve the existing integer-COP
            // unit × quantity invariant rather than silently rounding a receipt.
            $received=brvtalPosInt($raw['received_amount_cop']);
            $reason=$raw['received_reason'];
            $evidence=$raw['received_reference'];
            if ($received % $result['quantity']!==0
                || !is_string($reason) || strlen(trim($reason))<3 || strlen($reason)>80
                || preg_match('//u',$reason)!==1 || preg_match('/[\x00-\x1f\x7f|]/',$reason)
                || !is_string($evidence)
                || !preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._\/-]{3,63}$/D',$evidence)) {
                throw new InvalidArgumentException('POS_INVALID_RECEIVED_EVIDENCE');
            }
            $result+=['received_amount_cop'=>$received,'received_reason'=>trim($reason),
                'received_reference'=>$evidence];
        }
        if (array_key_exists('quote_token',$raw)) {
            if (!is_string($raw['quote_token']) || !preg_match('/^[0-9]{10}[.][a-f0-9]{64}$/D',$raw['quote_token'])) {
                throw new InvalidArgumentException('POS_INVALID_QUOTE');
            }
            $result['quote_token']=$raw['quote_token'];
        }
    }
    if ($action==='confirm' || $action==='cancel') {
        if (!is_string($raw['confirmation_token'])
            || !preg_match('/^(?:[a-f0-9]{48}|[a-f0-9]{112})$/D',$raw['confirmation_token'])) {
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
function brvtalPosOwner(string $role): bool
{
    return in_array(strtolower(trim($role)), ['superadmin', 'root', 'owner', 'platform_owner'], true);
}

/** Missing or explicitly disabled setting preserves the existing flow. Invalid state fails closed. */
function brvtalPosReceiptEnabled(PDO $db): bool
{
    $q=$db->prepare("SELECT setting_value,is_json FROM settings WHERE setting_key=?");
    $q->execute(['pos.receipt_identity.enabled']);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    $value=(string)$row['setting_value'];
    if ((int)$row['is_json']===1) {
        $value=json_decode($value, true);
        if (is_bool($value)) {
            return $value;
        }
    } elseif (in_array($value, ['0', '1', 'false', 'true'], true)) {
        return in_array($value, ['1', 'true'], true);
    }
    throw new DomainException('POS_RECEIPT_REVIEW_REQUIRED');
}

/** No bank details or reference history leave the server. */
function brvtalPosReceiptCatalog(PDO $db, int $actor, string $role): array
{
    if ($actor<1 || !brvtalPosOwner($role)) {
        throw new DomainException('FORBIDDEN');
    }
    $enabled=brvtalPosReceiptEnabled($db);
    $destinations=[];
    if ($enabled) {
        foreach (['transfer','nequi','daviplata','terminal'] as $method) {
            $destinations[$method]=brvtalPosDestinations($db, $method);
        }
    }
    return ['enabled'=>$enabled,'destinations'=>$destinations];
}

/** Current reads under destination lock prevent cross-actor/event reuse. */
function brvtalPosReceiptScope(PDO $db, array $i): ?string
{
    $destination=$i['destination_id']??null;
    if ($i['method']==='cash' || !brvtalPosReceiptEnabled($db)) {
        if ($destination!==null) {
            throw new DomainException('POS_RECEIPT_REVIEW_REQUIRED');
        }
        return null;
    }
    if ($destination===null || isset($i['received_amount_cop'])) {
        // Additional/adjusted payment evidence may represent a split payment.
        throw new DomainException('POS_RECEIPT_REVIEW_REQUIRED');
    }
    $reference=brvtalPosReceiptNormalize($i['payment_reference']);
    $q=$db->prepare('SELECT id FROM ticket_pos_payment_destinations
        WHERE id=? AND method=? AND is_active=1 FOR UPDATE');
    $q->execute([$destination,$i['method']]);
    if (!$q->fetchColumn()) {
        throw new DomainException('POS_RECEIPT_REVIEW_REQUIRED');
    }
    $q=$db->prepare('SELECT id FROM ticket_pos_payment_receipts
        WHERE destination_id=? AND normalized_reference=? FOR UPDATE');
    $q->execute([$destination,$reference]);
    if ($q->fetchColumn()) {
        throw new DomainException('POS_RECEIPT_DUPLICATE');
    }
    // Historical notes lack a destination. A consistent read sees note and
    // registration atomically, without range-locking unrelated audit history.
    $q=$db->prepare("SELECT n.id FROM ticket_order_notes n
        WHERE n.note_text LIKE CONCAT('manual-payment|',?,'|%')
          AND BINARY UPPER(REGEXP_REPLACE(TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(n.note_text,'|',3),'|',-1)),' +',' '))=?
          AND NOT EXISTS (SELECT 1 FROM ticket_pos_payment_receipts r WHERE r.order_id=n.order_id)
        LIMIT 1");
    $q->execute([$i['method'],$reference]);
    if ($q->fetchColumn()) {
        throw new DomainException('POS_RECEIPT_REVIEW_REQUIRED');
    }
    return $reference;
}

function brvtalPosRun(PDO $db, int $actor, string $role, array $raw, ?string $quoteKey=null): array {
    $input=brvtalPosInput($raw);
    $manual=in_array($input['action'], ['manual_order', 'manual_intent', 'manual_quote'], true);
    $isOwner=brvtalPosOwner($role);
    if ($actor<1 || ($manual ? !$isOwner : !brvtalPosCan($role))) {
        throw new DomainException('FORBIDDEN');
    }
    if ($input['action']==='manual_intent') {
        return brvtalPosManualIntent($db, $actor, $input['idempotency_key']);
    }
    // Legacy drawer remains synthetic-only. Only the tightly validated owner
    // manual-order action is production-capable; it never calls Wompi.
    if (!$manual && !brvtalTicketAdminSyntheticMode($db)) {
        throw new DomainException('POS_SYNTHETIC_ONLY');
    }
    if ($input['action']==='shift_status') {
        return brvtalPosShiftStatus($db, $actor, $input['event_id']);
    }
    if ($db->inTransaction()) throw new LogicException('POS_TRANSACTION_REQUIRED');
    $db->beginTransaction();
    try {
        $result=match($input['action']) {
            'open'=>brvtalPosOpen($db,$actor,$input),
            'manual_quote'=>brvtalPosManualQuote($db,$actor,$input,$quoteKey),
            'manual_order'=>brvtalPosManualOrder($db,$actor,$input,$quoteKey),
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
/**
 * Read-only, synthetic drawer snapshot. The actor comes from the authenticated
 * endpoint. A closed shift is physical only with positive legacy-sale evidence;
 * manual audit parents and empty historical drawers cannot prove that identity.
 */
function brvtalPosShiftStatus(PDO $db, int $actor, int $event): array
{
    $q=$db->prepare("SELECT sh.id,sh.status,
        EXISTS(SELECT 1 FROM ticket_pos_sales p
            LEFT JOIN ticket_orders o ON o.id=p.order_id AND o.event_id=p.event_id
            WHERE p.shift_id=sh.id AND p.operator_admin_id=sh.operator_admin_id
              AND p.event_id=sh.event_id
              AND (p.status IN ('prepared','cancelled') OR
                   (p.status='paid' AND o.checkout_key_hash=SHA2(CONCAT('pos:',p.id),256)))) AS physical,
        EXISTS(SELECT 1 FROM ticket_pos_sales p
            LEFT JOIN ticket_orders o ON o.id=p.order_id AND o.event_id=p.event_id
            WHERE p.shift_id=sh.id AND
              (p.operator_admin_id<>sh.operator_admin_id OR p.event_id<>sh.event_id
               OR (p.status='paid' AND (o.id IS NULL OR
                    o.checkout_key_hash<>SHA2(CONCAT('pos:',p.id),256))))) AS ambiguous,
        (SELECT COUNT(*) FROM ticket_pos_shifts h
            WHERE h.operator_admin_id=sh.operator_admin_id AND h.status='open') AS open_count,
        s.id AS sale_id,s.ticket_type_id,s.quantity,s.amount_cop,
        s.expires_at_utc>UTC_TIMESTAMP(6) AS pending
        FROM (SELECT h.id,h.status,h.operator_admin_id,h.event_id
              FROM ticket_pos_shifts h JOIN admins a ON a.id=h.operator_admin_id AND a.is_active=1
              WHERE h.operator_admin_id=? AND h.event_id=?
              ORDER BY (h.status='open') DESC,h.id DESC LIMIT 1) sh
        LEFT JOIN ticket_pos_sales s ON sh.status='open' AND s.shift_id=sh.id
          AND s.event_id=sh.event_id AND s.operator_admin_id=sh.operator_admin_id
          AND s.status='prepared'
        ORDER BY s.id LIMIT 101");
    $q->execute([$actor,$event]);
    $rows=$q->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return ['state'=>'not_found'];
    }
    $shift=$rows[0];
    if (count($rows)>100 || (bool)$shift['ambiguous'] || (int)$shift['open_count']>1
        || ($shift['status']==='closed' && !(bool)$shift['physical'])) {
        return ['state'=>'unverifiable'];
    }
    $preparations=[];
    foreach ($rows as $row) {
        if ($row['sale_id']===null) {
            continue;
        }
        $preparations[]=['sale_id'=>(int)$row['sale_id'],
            'ticket_type_id'=>(int)$row['ticket_type_id'],'quantity'=>(int)$row['quantity'],
            'amount_cop'=>(int)$row['amount_cop'],'state'=>(bool)$row['pending']?'pending':'expired'];
    }
    return ['state'=>$shift['status'],'shift_id'=>(int)$shift['id'],
        'event_id'=>$event,'preparations'=>$preparations];
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
    // #2022: 'active' is not an event state; accept every canonical selling state.
    require_once __DIR__.'/public_visibility.php';
    $active=brvtal_public_event_statuses()['active'];
    $event=$db->prepare('SELECT id FROM events WHERE id=? AND status IN ('
        .implode(',',array_fill(0,count($active),'?')).')');
    $event->execute(array_merge([$i['event_id']],$active));
    if ($event->fetchColumn()===false) throw new DomainException('POS_EVENT_UNAVAILABLE');
    $insert=$db->prepare('INSERT INTO ticket_pos_shifts(event_id,operator_admin_id,opening_cash_cop) VALUES (?,?,?)');
    $insert->execute([$i['event_id'],$actor,$i['opening_cash_cop']]);
    return ['shift_id'=>(int)$db->lastInsertId(),'status'=>'open','cash_cop'=>$i['opening_cash_cop']];
}
/** Event first: all inventory and stage decisions share the T05 mutex. */
function brvtalPosEventLock(PDO $db,int $event): array {
    $q=$db->prepare('SELECT e.id,e.status,e.event_date,e.published_at,l.capacity
        FROM events e LEFT JOIN ticket_inventory_events l ON l.event_id=e.id
        WHERE e.id=? FOR UPDATE');
    $q->execute([$event]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new DomainException('POS_EVENT_UNAVAILABLE');
    return $row;
}
function brvtalPosChannelQuote(PDO $db,int $event,int $type,int $quantity,string $channel): array {
    if (!$db->inTransaction()) throw new LogicException('POS_TRANSACTION_REQUIRED');
    $e=brvtalPosEventLock($db,$event);
    $q=$db->prepare('SELECT t.name,t.price,t.door_price_cop,t.currency,t.status,t.available_from,
        t.available_until,l.capacity FROM event_ticket_types t
        LEFT JOIN ticket_inventory_types l ON l.ticket_type_id=t.id AND l.event_id=t.event_id
        WHERE t.id=? AND t.event_id=? FOR UPDATE');
    $q->execute([$type,$event]);$t=$q->fetch(PDO::FETCH_ASSOC);
    if (!$t) throw new DomainException('POS_TYPE_UNAVAILABLE');
    $q=$db->prepare('SELECT code,price_cop,cumulative_capacity,opens_at_local,closes_at_local
        FROM ticket_type_stages WHERE event_id=? AND ticket_type_id=? FOR UPDATE');
    $q->execute([$event,$type]);$stages=$q->fetchAll(PDO::FETCH_ASSOC);
    foreach ($stages as &$stage) {
        $stage['price_cop']=(int)$stage['price_cop'];
        $stage['cumulative_capacity']=(int)$stage['cumulative_capacity'];
    }
    unset($stage);
    $clock=brvtal_ticket_inventory_now();
    if (!brvtal_ticket_inventory_saleable($e,$t,$clock)) throw new DomainException('INVENTORY_NOT_SALEABLE');
    if ($e['capacity']===null || $t['capacity']===null) throw new DomainException('INVENTORY_NOT_CONFIGURED');
    if (getenv('BRVTAL_TICKET_REFUNDS_POLICY_ENABLED')==='1') {
        $q=$db->prepare('SELECT 1 FROM ticket_refund_event_decisions WHERE event_id=? LIMIT 1');
        $q->execute([$event]);
        if ($q->fetchColumn()) throw new DomainException('INVENTORY_NOT_SALEABLE');
    }
    $utc=$clock->format('Y-m-d H:i:s.u');
    $used=array_sum(brvtal_ticket_inventory_usage($db,$event,$type,$utc));
    $eventFree=max(0,(int)$e['capacity']-array_sum(brvtal_ticket_inventory_usage($db,$event,0,$utc)));
    $typeFree=max(0,(int)$t['capacity']-$used);
    $free=min($eventFree,$typeFree);
    if ($free<$quantity) throw new DomainException('INVENTORY_SOLD_OUT');
    $base=is_string($t['price'])&&preg_match('/^[0-9]{1,9}[.]00$/D',$t['price'])?(int)$t['price']:null;
    $door=$t['door_price_cop']===null?null:(int)$t['door_price_cop'];
    $quote=brvtalTicketChannelQuote($channel,$stages,$base,$door,$used,$quantity,$free,$clock);
    $stageFree=$typeFree;
    foreach ($stages as $stage) {
        if ($stage['code']===$quote['code']) $stageFree=$stage['cumulative_capacity']-$used;
    }
    return $quote+['event_free'=>$eventFree,'type_free'=>$typeFree,'stage_free'=>$stageFree,
        'name'=>$t['name']];
}
function brvtalPosQuoteFingerprint(int $actor,string $channel,int $event,int $type,int $quantity,
    string $intent,array $q): string {
    return hash('sha256',json_encode([$actor,$channel,$event,$type,$quantity,$intent,
        $q['unit_price_cop'],$q['subtotal_cop'],$q['source'],$q['code'],
        $q['event_free'],$q['type_free'],$q['stage_free']],JSON_THROW_ON_ERROR));
}
function brvtalPosQuoteProof(?string $key,int $expiry,string $fingerprint): string {
    if ($key===null || strlen($key)!==32) throw new DomainException('POS_QUOTE_REQUIRED');
    return $expiry.'.'.hash_hmac('sha256','brvtal:pos:manual:v1:'.$expiry.':'.$fingerprint,$key);
}
function brvtalPosManualQuote(PDO $db,int $actor,array $i,?string $key): array {
    $q=$db->prepare('SELECT id FROM admins WHERE id=? AND is_active=1 FOR UPDATE');
    $q->execute([$actor]);
    if ($q->fetchColumn()===false) throw new DomainException('FORBIDDEN');
    $quote=brvtalPosChannelQuote($db,$i['event_id'],$i['ticket_type_id'],$i['quantity'],'manual');
    $intent=hash('sha256','manual-order:'.$actor.':'.$i['idempotency_key']);
    $fingerprint=brvtalPosQuoteFingerprint($actor,'manual',$i['event_id'],$i['ticket_type_id'],$i['quantity'],$intent,$quote);
    $expiry=time()+120;
    return ['channel'=>'manual','unit_price_cop'=>$quote['unit_price_cop'],'amount_cop'=>$quote['subtotal_cop'],
        'source'=>$quote['source'],'stage_code'=>$quote['code'],'expires_at_utc'=>gmdate('Y-m-d\TH:i:s\Z',$expiry),
        'quote_token'=>brvtalPosQuoteProof($key,$expiry,$fingerprint)];
}
function brvtalPosQuoteRecheck(PDO $db,int $event,int $type,int $quantity,string $channel): array {
    try {return brvtalPosChannelQuote($db,$event,$type,$quantity,$channel);}
    catch (DomainException) {throw new DomainException('POS_QUOTE_CHANGED');}
}

function brvtalPosPrepare(PDO $db,int $actor,array $i): array {
    $lookup=$db->prepare('SELECT event_id FROM ticket_pos_shifts WHERE id=? AND operator_admin_id=?');
    $lookup->execute([$i['shift_id'],$actor]);$event=$lookup->fetchColumn();
    if ($event===false) throw new DomainException('POS_SHIFT_UNAVAILABLE');
    brvtalPosEventLock($db,(int)$event);
    $shift=brvtalPosShift($db,$actor,$i['shift_id']);
    if ($i['method']==='wompi_qr') throw new DomainException('POS_PROVIDER_PROOF_REQUIRED');
    if ($i['method']==='transfer') throw new DomainException('POS_TRANSFER_RULES_REQUIRED');
    $key=hash('sha256',$actor.':'.$i['idempotency_key']);
    $digest=hash('sha256',json_encode([$i['shift_id'],$i['ticket_type_id'],$i['quantity'],$i['method']],JSON_THROW_ON_ERROR));
    $check=$db->prepare('SELECT id,request_hash,status,amount_cop FROM ticket_pos_sales WHERE request_key_hash=? FOR UPDATE');
    $check->execute([$key]); $old=$check->fetch(PDO::FETCH_ASSOC);
    if ($old) {
        if (!hash_equals((string)$old['request_hash'],$digest)) throw new DomainException('POS_IDEMPOTENCY_CONFLICT');
        return ['sale_id'=>(int)$old['id'],'status'=>$old['status'],'amount_cop'=>(int)$old['amount_cop'],
            'replayed'=>true,'confirmation_token'=>null];
    }
    $quote=brvtalPosChannelQuote($db,(int)$shift['event_id'],$i['ticket_type_id'],$i['quantity'],'door');
    $unit=$quote['unit_price_cop'];$amount=$quote['subtotal_cop'];
    $fingerprint=brvtalPosQuoteFingerprint($actor,'door',(int)$shift['event_id'],$i['ticket_type_id'],$i['quantity'],$key,$quote);
    $token=bin2hex(random_bytes(24)).$fingerprint;
    $ins=$db->prepare("INSERT INTO ticket_pos_sales
        (shift_id,event_id,operator_admin_id,ticket_type_id,quantity,unit_price_cop,amount_cop,method,
          request_key_hash,request_hash,confirmation_hash,expires_at_utc,quote_source,quote_stage_code,quote_channel)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 120 SECOND),?,?,'door')");
    $ins->execute([$i['shift_id'],(int)$shift['event_id'],$actor,$i['ticket_type_id'],$i['quantity'],
        $unit,$amount,$i['method'],$key,$digest,hash('sha256',$token),$quote['source'],$quote['code']]);
    return ['sale_id'=>(int)$db->lastInsertId(),'amount_cop'=>$amount,'method'=>$i['method'],
        'status'=>'prepared','confirmation_token'=>$token,'replayed'=>false,
        'unit_price_cop'=>$unit,'source'=>$quote['source'],'stage_code'=>$quote['code']];
}
function brvtalPosSale(PDO $db,int $actor,array $i,bool $allowPaid=false): array {
    $q=$db->prepare('SELECT shift_id,event_id,operator_admin_id FROM ticket_pos_sales WHERE id=?');
    $q->execute([$i['sale_id']]);$lookup=$q->fetch(PDO::FETCH_ASSOC);
    if (!$lookup) throw new DomainException('POS_SALE_UNAVAILABLE');
    if ((int)$lookup['operator_admin_id']!==$actor) throw new DomainException('POS_SHIFT_UNAVAILABLE');
    brvtalPosEventLock($db,(int)$lookup['event_id']);
    $shift=brvtalPosShift($db,$actor,(int)$lookup['shift_id'],!$allowPaid);
    $q=$db->prepare('SELECT * FROM ticket_pos_sales WHERE id=? AND operator_admin_id=? FOR UPDATE');
    $q->execute([$i['sale_id'],$actor]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if (!$row || !hash_equals((string)$row['confirmation_hash'],hash('sha256',$i['confirmation_token']))) {
        throw new DomainException('POS_SALE_UNAVAILABLE');
    }
    if ($allowPaid && $row['status']==='paid') return $row;
    if ($shift['status']!=='open' || $row['status']!=='prepared'
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
    $row=brvtalPosSale($db,$actor,$i,true);
    if ($row['method']!=='cash') throw new DomainException('POS_PROVIDER_PROOF_REQUIRED');
    if ($row['status']==='paid') {
        if (!$row['order_id']) throw new DomainException('POS_SALE_UNAVAILABLE');
        // The receipt is immutable; never mint/reveal QR credentials again on replay.
        return ['sale_id'=>(int)$row['id'],'receipt'=>'POS-'.(int)$row['id'],'status'=>'paid',
            'amount_cop'=>(int)$row['amount_cop'],'channel'=>'door_cash','order_id'=>(int)$row['order_id'],
            'tickets'=>[],'replayed'=>true];
    }
    $quote=brvtalPosQuoteRecheck($db,(int)$row['event_id'],(int)$row['ticket_type_id'],(int)$row['quantity'],'door');
    $fingerprint=brvtalPosQuoteFingerprint($actor,'door',(int)$row['event_id'],(int)$row['ticket_type_id'],
        (int)$row['quantity'],$row['request_key_hash'],$quote);
    if ((string)$row['expires_at_utc']<=gmdate('Y-m-d H:i:s') || strlen($i['confirmation_token'])!==112
        || !hash_equals($fingerprint,substr($i['confirmation_token'],48))
        || $row['quote_channel']!=='door' || $row['quote_source']!==$quote['source']
        || $row['quote_stage_code']!==$quote['code'] || (int)$row['unit_price_cop']!==$quote['unit_price_cop']
        || (int)$row['amount_cop']!==$quote['subtotal_cop']) throw new DomainException('POS_QUOTE_CHANGED');
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
function brvtalPosManualOrder(PDO $db,int $actor,array $i,?string $quoteKey): array {
    // Serialize requests from the same owner, including concurrent retries.
    $lock=$db->prepare('SELECT id FROM admins WHERE id=? AND is_active=1 FOR UPDATE');
    $lock->execute([$actor]);
    if ($lock->fetchColumn()===false) throw new DomainException('FORBIDDEN');

    $key=hash('sha256','manual-order:'.$actor.':'.$i['idempotency_key']);
    $request=[
        $i['event_id'],$i['ticket_type_id'],$i['quantity'],$i['buyer_name'],
        $i['buyer_email'],$i['method'],$i['payment_reference']
    ];
    // Keep pre-existing order digests byte-for-byte compatible.
    if (isset($i['received_amount_cop'])) {
        $request[]=[$i['received_amount_cop'],$i['received_reason'],$i['received_reference']];
    }
    if (isset($i['destination_id'])) {
        $request[]=['destination_id'=>$i['destination_id']];
    }
    $digest=hash('sha256',json_encode($request,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    brvtalPosEventLock($db,$i['event_id']);
    $old=$db->prepare('SELECT id,status,total_cop FROM ticket_orders WHERE checkout_key_hash=? FOR UPDATE');
    $old->execute([$key]);
    $prior=$old->fetch(PDO::FETCH_ASSOC);
    if ($prior) {
        $q=$db->prepare('SELECT s.id,s.amount_cop,n.note_text FROM ticket_pos_sales s
            JOIN ticket_order_notes n ON n.order_id=s.order_id AND n.actor_admin_id=s.operator_admin_id
            JOIN ticket_order_items i ON i.order_id=s.order_id AND i.event_id=s.event_id
                AND i.ticket_type_id=s.ticket_type_id AND i.quantity=s.quantity
                AND i.unit_price_cop=s.unit_price_cop AND i.subtotal_cop=s.amount_cop
            WHERE s.order_id=? AND s.operator_admin_id=? AND s.status=\'paid\'
              AND n.note_text LIKE ? LIMIT 1 FOR UPDATE');
        $q->execute([(int)$prior['id'],$actor,'%|'.$digest]);
        $receipt=$q->fetch(PDO::FETCH_ASSOC);
        if (!$receipt || (int)$receipt['amount_cop']!==(int)$prior['total_cop']
            || !str_ends_with((string)$receipt['note_text'],'|'.$digest)) {
            throw new DomainException('POS_IDEMPOTENCY_CONFLICT');
        }
        return ['order_id'=>(int)$prior['id'],'sale_id'=>(int)$receipt['id'],'status'=>$prior['status'],
            'amount_cop'=>(int)$receipt['amount_cop'],'method'=>$i['method'],'issuance_queued'=>true,
            'replayed'=>true];
    }

    $receiptReference=brvtalPosReceiptScope($db, $i);
    if (!isset($i['quote_token'])) throw new DomainException('POS_QUOTE_REQUIRED');
    $expiry=(int)substr($i['quote_token'],0,10);
    if ($expiry<=time() || $expiry>time()+120) throw new DomainException('POS_QUOTE_CHANGED');
    $quote=brvtalPosQuoteRecheck($db,$i['event_id'],$i['ticket_type_id'],$i['quantity'],'manual');
    $fingerprint=brvtalPosQuoteFingerprint($actor,'manual',$i['event_id'],$i['ticket_type_id'],$i['quantity'],$key,$quote);
    if ($expiry<=time() || !hash_equals(brvtalPosQuoteProof($quoteKey,$expiry,$fingerprint),$i['quote_token'])) {
        throw new DomainException('POS_QUOTE_CHANGED');
    }
    $amount=$i['received_amount_cop']??$quote['subtotal_cop'];
    $unit=isset($i['received_amount_cop'])?intdiv($amount,$i['quantity']):$quote['unit_price_cop'];

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
    if ($receiptReference!==null) {
        brvtalPosReceiptRegister($db, $orderId, $i['method'], $i['destination_id'], $receiptReference, $digest);
    }
    $db->prepare("INSERT INTO ticket_order_items
        (order_id,event_id,ticket_type_id,ticket_name_snapshot,quantity,unit_price_cop,subtotal_cop,currency)
        VALUES (?,?,?,?,?,?,?,'COP')")
        ->execute([$orderId,$i['event_id'],$i['ticket_type_id'],$quote['name'],
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
         request_hash,confirmation_hash,expires_at_utc,completed_at_utc,quote_source,quote_stage_code,quote_channel)
        VALUES (?,?,?,?,?,?,?,?,?,'paid',?,?,?,DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 120 SECOND),UTC_TIMESTAMP(6),?,?,'manual')")
        ->execute([$shiftId,$i['event_id'],$actor,$i['ticket_type_id'],$orderId,
            $i['quantity'],$unit,$amount,$posMethod,hash('sha256','pos:'.$key),
            $digest,hash('sha256',random_bytes(24)),$quote['source'],$quote['code']]);
    $saleId=(int)$db->lastInsertId();
    // Exact method, bank reference and request digest are durable auditable
    // owner evidence, separate from Wompi and without QR secrets.
    $db->prepare('INSERT INTO ticket_order_notes(order_id,actor_admin_id,note_text)
        VALUES (?,?,?)')->execute([$orderId,$actor,
            'manual-payment|'.$i['method'].'|'.$i['payment_reference'].'|'.$digest]);
    if (isset($i['received_amount_cop'])) {
        // Separate immutable evidence preserves legacy payment verification and
        // records both the binding tariff and the actual received amount.
        $db->prepare('INSERT INTO ticket_order_notes(order_id,actor_admin_id,note_text)
            VALUES (?,?,?)')->execute([$orderId,$actor,
                'manual-received|'.$digest.'|'.$quote['subtotal_cop'].'|'.$amount.'|'.
                $i['received_reference'].'|'.$i['received_reason']]);
    }

    brvtal_ticket_pos_enqueue($db,$orderId);
    return ['order_id'=>$orderId,'sale_id'=>$saleId,'status'=>'paid',
        'amount_cop'=>$amount,'method'=>$i['method'],
        'issuance_queued'=>true,'replayed'=>false];
}

/** Read-only recovery of a single actor-bound manual checkout intention.
 * A missing order is not proof that an in-flight request cannot commit.
 */
function brvtalPosManualIntent(PDO $db, int $actor, string $key): array
{
    $hash=hash('sha256', 'manual-order:'.$actor.':'.$key);
    $q=$db->prepare("SELECT o.id,o.status,o.total_cop,s.method,s.request_hash,
        n.note_text,b.state AS issuance_state,b.payment_attempt_id
        FROM ticket_orders o
        JOIN admins a ON a.id=? AND a.is_active=1
        LEFT JOIN ticket_pos_sales s ON s.order_id=o.id AND s.event_id=o.event_id
            AND s.operator_admin_id=a.id AND s.request_key_hash=?
        LEFT JOIN ticket_order_notes n ON n.order_id=o.id AND n.actor_admin_id=a.id
            AND n.note_text LIKE CONCAT('manual-payment|%|',s.request_hash)
        LEFT JOIN ticket_issue_outbox b ON b.order_id=o.id
        WHERE o.checkout_key_hash=? LIMIT 2");
    $q->execute([$actor, hash('sha256', 'pos:'.$hash), $hash]);
    $rows=$q->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return ['state'=>'not_found'];
    }
    if (count($rows)!==1) {
        return ['state'=>'uncertain'];
    }
    $row=$rows[0];
    $parts=explode('|', (string)$row['note_text']);
    $method=$parts[1]??'';
    $mapped=in_array($method, ['nequi', 'daviplata'], true)?'transfer':$method;
    if (count($parts)!==4 || $parts[0]!=='manual-payment'
        || !in_array($method, ['cash', 'transfer', 'nequi', 'daviplata', 'terminal'], true)
        || ($method==='cash' ? $parts[2]!=='' :
            !preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._\\/-]{3,79}$/D', $parts[2]))
        || !preg_match('/^[a-f0-9]{64}$/D', (string)$row['request_hash'])
        || !hash_equals((string)$row['request_hash'], $parts[3])
        || $mapped!==$row['method'] || $row['payment_attempt_id']!==null
        || $row['status']!=='paid'
        || !in_array($row['issuance_state'], ['pending', 'processing', 'done'], true)
        || !brvtal_ticket_pos_verified($db, (int)$row['id'])) {
        return ['state'=>'uncertain'];
    }
    return ['state'=>'found','order_id'=>(int)$row['id'],
        'amount_cop'=>(int)$row['total_cop'],'method'=>$method,'status'=>'paid',
        'issuance'=>$row['issuance_state']==='done'?'issued':'pending'];
}
