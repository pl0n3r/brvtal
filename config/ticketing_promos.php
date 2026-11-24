<?php
declare(strict_types=1);
require_once __DIR__.'/ticketing_inventory.php';

/** T23 is confined to disposable MariaDB. DDL and UI do not enable real discounts. */
function brvtalPromoSynthetic(PDO $db): void {
    if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1' ||
        !preg_match('/^brvtal_test[a-zA-Z0-9_]*$/D',
            (string)$db->query('SELECT DATABASE()')->fetchColumn())) {
        throw new DomainException('PROMOS_NOT_ENABLED');
    }
}
function brvtalPromoDigest(string $code): string {
    $code = strtoupper(trim($code));
    if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{7,31}$/D', $code)) {
        throw new InvalidArgumentException('INVALID_PROMO_CODE');
    }
    return hash('sha256', 'brvtal:t23:coupon:'.$code);
}
/** A retry without a coupon must not silently discard an existing redemption,
 * even if the coupon's discount was zero. An absent T23 table is legitimate
 * before the migration is applied; other SQL failures do not get ignored.
 */
function brvtalPromoRejectUncodedReplay(PDO $db,int $orderId): void {
    if ($orderId<1) throw new InvalidArgumentException('PROMO_ORDER_REQUIRED');
    try {
        $q=$db->prepare('SELECT 1 FROM ticket_promo_redemptions WHERE order_id=? LIMIT 1');
        $q->execute([$orderId]);
        if ($q->fetchColumn()!==false) throw new DomainException('CHECKOUT_RETRY_CONFLICT');
    } catch (PDOException $error) {
        if ((int)($error->errorInfo[1] ?? 0)!==1146) throw $error;
    }
}
/** Fixed values are COP; percent uses an integer percentage; group3/5 is a bundle total. */
function brvtalPromoInput(array $in): array {
    $expected=['code','event_id','ticket_type_id','kind','value_cop',
        'starts_at_utc','ends_at_utc','max_uses','buyer_limit','campaign_key'];
    $keys=array_keys($in); sort($keys); $sorted=$expected; sort($sorted);
    if ($keys!==$sorted || !is_string($in['code']) ||
        !is_int($in['event_id']) || $in['event_id']<1 ||
        ($in['ticket_type_id']!==null &&
            (!is_int($in['ticket_type_id']) || $in['ticket_type_id']<1)) ||
        !in_array($in['kind'],['fixed','percent','group3','group5'],true) ||
        !is_int($in['value_cop']) || $in['value_cop']<1 ||
        $in['value_cop']>10000000 ||
        ($in['kind']==='percent' && $in['value_cop']>100) ||
        !is_int($in['max_uses']) || $in['max_uses']<1 ||
        !is_int($in['buyer_limit']) || $in['buyer_limit']<1 ||
        $in['buyer_limit']>$in['max_uses'] ||
        !is_string($in['campaign_key']) ||
        !preg_match('/^[a-zA-Z0-9_-]{0,48}$/D',$in['campaign_key'])) {
        throw new InvalidArgumentException('INVALID_PROMO_RULE');
    }
    foreach (['starts_at_utc','ends_at_utc'] as $field) {
        $raw=$in[$field];
        if (!is_string($raw) ||
            !preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/D',$raw) ||
            ($date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$raw,
                new DateTimeZone('UTC')))===false ||
            $date->format('Y-m-d H:i:s')!==$raw) {
            throw new InvalidArgumentException('INVALID_PROMO_WINDOW');
        }
    }
    if ($in['starts_at_utc'] >= $in['ends_at_utc']) {
        throw new InvalidArgumentException('INVALID_PROMO_WINDOW');
    }
    $in['code_digest']=brvtalPromoDigest($in['code']);
    unset($in['code']);
    return $in;
}
/** Immutable creation only: edits never change a paid order's rule or snapshot. */
function brvtalPromoCreate(PDO $db,array $input): int {
    brvtalPromoSynthetic($db);
    if ($db->inTransaction()) throw new LogicException('PROMO_TRANSACTION_ALREADY_ACTIVE');
    $rule=brvtalPromoInput($input);
    $db->beginTransaction();
    try {
        $q=$db->prepare('SELECT id FROM events WHERE id=? FOR UPDATE');
        $q->execute([$rule['event_id']]);
        if (!$q->fetchColumn()) throw new DomainException('PROMO_EVENT_NOT_FOUND');
        if ($rule['ticket_type_id']!==null) {
            $q=$db->prepare('SELECT id FROM event_ticket_types WHERE id=? AND event_id=?');
            $q->execute([$rule['ticket_type_id'],$rule['event_id']]);
            if (!$q->fetchColumn()) throw new DomainException('PROMO_TYPE_NOT_FOUND');
        }
        $q=$db->prepare('SELECT id FROM ticket_promo_rules WHERE code_digest=? FOR UPDATE');
        $q->execute([$rule['code_digest']]);
        if ($q->fetchColumn()) throw new DomainException('PROMO_ALREADY_EXISTS');
        $q=$db->prepare('INSERT INTO ticket_promo_rules
          (code_digest,event_id,ticket_type_id,kind,value_cop,starts_at_utc,
           ends_at_utc,max_uses,buyer_limit,campaign_key,active)
          VALUES (?,?,?,?,?,?,?,?,?,?,1)');
        $q->execute([$rule['code_digest'],$rule['event_id'],$rule['ticket_type_id'],
            $rule['kind'],$rule['value_cop'],$rule['starts_at_utc'],
            $rule['ends_at_utc'],$rule['max_uses'],$rule['buyer_limit'],$rule['campaign_key']]);
        $id=(int)$db->lastInsertId();
        $db->commit(); return $id;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
/** Disable only; never rewrite a code, paid ledger, quota or historic price. */
function brvtalPromoDeactivate(PDO $db,int $id): void {
    brvtalPromoSynthetic($db);
    if ($id<1 || $db->inTransaction()) throw new InvalidArgumentException('PROMO_INVALID_ID');
    $db->beginTransaction();
    try {
        $q=$db->prepare('SELECT id FROM ticket_promo_rules WHERE id=? FOR UPDATE');
        $q->execute([$id]);
        if (!$q->fetchColumn()) throw new DomainException('PROMO_NOT_FOUND');
        $db->prepare('UPDATE ticket_promo_rules SET active=0 WHERE id=?')->execute([$id]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
/**
 * Caller holds the owning checkout transaction. Event row is the T05 mutex;
 * availability is derived from real T05 sold+held+courtesy, never from the UI.
 * No two promotions may stack. No code in T23 calls live payment providers.
 */
function brvtalPromoQuote(PDO $db,int $eventId,int $typeId,int $buyerId,
    int $quantity,int $unitPrice,string $code,array $alreadyApplied=[],
    ?DateTimeImmutable $clock=null): array {
    brvtalPromoSynthetic($db);
    if (!$db->inTransaction()) throw new LogicException('PROMO_TRANSACTION_REQUIRED');
    if ($eventId<1 || $typeId<1 || $buyerId<1 ||
        $quantity<1 || $quantity>20 || $unitPrice<1 ||
        $unitPrice>intdiv(10000000,$quantity) || $alreadyApplied!==[]) {
        throw new DomainException('PROMO_INVALID_QUOTE');
    }
    $q=$db->prepare('SELECT id FROM events WHERE id=? FOR UPDATE');
    $q->execute([$eventId]);
    if (!$q->fetchColumn()) throw new DomainException('PROMO_EVENT_NOT_FOUND');
    $q=$db->prepare('SELECT * FROM ticket_promo_rules WHERE code_digest=? FOR UPDATE');
    $q->execute([brvtalPromoDigest($code)]);
    $rule=$q->fetch(PDO::FETCH_ASSOC);
    $now=($clock??new DateTimeImmutable('now',new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    if (!$rule || (int)$rule['active']!==1 ||
        (int)$rule['event_id']!==$eventId ||
        ($rule['ticket_type_id']!==null && (int)$rule['ticket_type_id']!==$typeId) ||
        $now<$rule['starts_at_utc'] || $now>=$rule['ends_at_utc'] ||
        (int)$rule['uses_count'] >= (int)$rule['max_uses']) {
        throw new DomainException('PROMO_UNAVAILABLE');
    }
    $q=$db->prepare('SELECT COUNT(*) FROM ticket_promo_redemptions
        WHERE promo_id=? AND buyer_id=?');
    $q->execute([(int)$rule['id'],$buyerId]);
    if ((int)$q->fetchColumn()>=(int)$rule['buyer_limit']) {
        throw new DomainException('PROMO_BUYER_LIMIT');
    }
    $cap=$db->prepare('SELECT capacity FROM ticket_inventory_events WHERE event_id=? FOR UPDATE');
    $cap->execute([$eventId]); $eventCap=$cap->fetchColumn();
    $cap=$db->prepare('SELECT capacity FROM ticket_inventory_types
        WHERE event_id=? AND ticket_type_id=? FOR UPDATE');
    $cap->execute([$eventId,$typeId]); $typeCap=$cap->fetchColumn();
    if ($eventCap===false || $typeCap===false) throw new DomainException('PROMO_NO_INVENTORY');
    $utc=($clock??new DateTimeImmutable('now',new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    $eventUsed=array_sum(brvtal_ticket_inventory_usage($db,$eventId,0,$utc));
    $typeUsed=array_sum(brvtal_ticket_inventory_usage($db,$eventId,$typeId,$utc));
    if (min((int)$eventCap-$eventUsed,(int)$typeCap-$typeUsed)<$quantity) {
        throw new DomainException('PROMO_SOLD_OUT');
    }
    $subtotal=$unitPrice*$quantity;
    $kind = $rule['kind'];
    $value = (int)$rule['value_cop'];
    if ($kind==='group3' && $quantity!==3 ||
        $kind==='group5' && $quantity!==5) {
        throw new DomainException('PROMO_GROUP_QUANTITY');
    }
    $discount=match($kind) {
        'fixed'=>min($subtotal,$value),
        'percent'=>intdiv($subtotal*$value,100),
        'group3','group5'=>max(0,$subtotal-$value)
    };
    // Wompi cannot charge zero COP. Roll back the entire checkout and its hold.
    if ($discount >= $subtotal) {
        throw new DomainException('PROMO_ZERO_TOTAL');
    }
    return [
        'promo_id'=>(int)$rule['id'], 'code_digest'=>$rule['code_digest'],
        'event_id'=>$eventId,'ticket_type_id'=>$typeId,'buyer_id'=>$buyerId,
        'quantity'=>$quantity,'subtotal_cop'=>$subtotal,
        'discount_cop'=>$discount,'total_cop'=>$subtotal-$discount,
        'campaign_key'=>$rule['campaign_key']
    ];
}
/** Reserve once against a synthetic draft order with an exact server-authoritative total. */
function brvtalPromoReserve(PDO $db,int $orderId,int $eventId,int $typeId,
    int $buyerId,int $quantity,int $unitPrice,string $code,
    ?DateTimeImmutable $clock=null): array {
    brvtalPromoSynthetic($db);
    if ($orderId<1 || !$db->inTransaction()) throw new LogicException('PROMO_TRANSACTION_REQUIRED');
    // Lock Event before the first snapshot read, even for direct reserve callers.
    $q=$db->prepare('SELECT id FROM events WHERE id=? FOR UPDATE');
    $q->execute([$eventId]);
    if (!$q->fetchColumn()) throw new DomainException('PROMO_EVENT_NOT_FOUND');
    $q=$db->prepare('SELECT * FROM ticket_promo_redemptions WHERE order_id=?');
    $q->execute([$orderId]); $previous=$q->fetch(PDO::FETCH_ASSOC);
    if ($previous) {
        if ($previous['code_digest']!==brvtalPromoDigest($code) ||
            (int)$previous['buyer_id']!==$buyerId) {
            throw new DomainException('PROMO_RETRY_CONFLICT');
        }
        return $previous; // frozen, never quote with today's rule.
    }
    $quote=brvtalPromoQuote($db,$eventId,$typeId,$buyerId,
        $quantity,$unitPrice,$code,[],$clock);
    $q=$db->prepare("SELECT buyer_id,event_id,status,total_cop FROM ticket_orders
        WHERE id=? FOR UPDATE");
    $q->execute([$orderId]); $order=$q->fetch(PDO::FETCH_ASSOC);
    if (!$order || (int)$order['buyer_id']!==$buyerId ||
        (int)$order['event_id']!==$eventId ||
        !in_array($order['status'],['draft','pending'],true) ||
        (int)$order['total_cop']!==$quote['total_cop']) {
        throw new DomainException('PROMO_ORDER_MISMATCH');
    }
    $q=$db->prepare('INSERT INTO ticket_promo_redemptions
      (promo_id,order_id,buyer_id,code_digest,event_id,ticket_type_id,quantity,
       subtotal_cop,discount_cop,total_cop,campaign_key)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $q->execute([
        $quote['promo_id'],$orderId,$buyerId,$quote['code_digest'],$eventId,$typeId,
        $quantity,$quote['subtotal_cop'],$quote['discount_cop'],
        $quote['total_cop'],$quote['campaign_key']
    ]);
    $db->prepare('UPDATE ticket_promo_rules SET uses_count=uses_count+1 WHERE id=?')
        ->execute([$quote['promo_id']]);
    return $quote;
}
