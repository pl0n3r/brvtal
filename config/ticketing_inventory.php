<?php
declare(strict_types=1);

require_once __DIR__ . '/public_visibility.php';

/**
 * T05 stock is authoritative ONLY in MariaDB. No editorial "active" value
 * implies unlimited inventory. All hold mutations must run in one caller-owned
 * transaction, with an exclusive lock on the Event to serialize ALL types.
 * No payment, ticket emission, PII or external provider occurs here.
 */
function brvtal_ticket_inventory_now(?DateTimeImmutable $now = null): DateTimeImmutable
{
    return ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('UTC'));
}

function brvtal_ticket_inventory_saleable(array $event, array $type, ?DateTimeImmutable $now = null): bool
{
    $clock = brvtal_ticket_inventory_now($now)->setTimezone(new DateTimeZone('America/Bogota'));
    if (!brvtal_public_event_allows_ticketing($event, $clock)) return false;
    // Legacy editorial timestamps are Bogota wall-clock dates, not UTC.
    // Never allow a future publication boundary merely because the web
    // server happens to run in UTC.
    $notBeforeRaw = trim((string)($event['published_at'] ?? ''));
    if ($notBeforeRaw !== '') {
        try {
            $notBefore = new DateTimeImmutable($notBeforeRaw, new DateTimeZone('America/Bogota'));
        } catch (Throwable) {
            return false;
        }
        if ($notBefore > $clock) return false;
    }
    $rawDate = trim((string)($event['event_date'] ?? ''));
    if ($rawDate === '') return false;
    try {
        $date = new DateTimeImmutable($rawDate, new DateTimeZone('America/Bogota'));
    } catch (Throwable) {
        return false;
    }
    if ($date <= $clock) return false;
    if (($type['status'] ?? '') !== 'active' || ($type['currency'] ?? '') !== 'COP') return false;
    foreach (['available_from'=>'from', 'available_until'=>'until'] as $field=>$boundary) {
        $raw = trim((string)($type[$field] ?? ''));
        if ($raw === '') continue;
        try {
            $point = new DateTimeImmutable($raw, new DateTimeZone('America/Bogota'));
        } catch (Throwable) {
            return false;
        }
        if (($boundary === 'from' && $clock < $point) || ($boundary === 'until' && $clock > $point)) return false;
    }
    return true;
}

function brvtal_ticket_inventory_scalar(PDO $db, string $sql, array $args): int
{
    // Checkout may have a REPEATABLE READ snapshot predating our Event lock.
    // Locking reads force current stock while the Event mutex is held.
    $statement = $db->prepare($db->inTransaction() ? $sql . ' FOR UPDATE' : $sql);
    $statement->execute($args);
    return (int)$statement->fetchColumn();
}

/** Counts committed sales and courtesy consumption without double-counting holds. */
function brvtal_ticket_inventory_usage(PDO $db, int $eventId, int $typeId, string $utc): array
{
    $sold = 'SELECT COALESCE(SUM(i.quantity),0) FROM ticket_order_items i
        JOIN ticket_orders o ON o.id=i.order_id
        WHERE o.event_id=? AND o.status=\'paid\'
          AND NOT EXISTS (SELECT 1 FROM ticket_inventory_holds x
              WHERE x.order_id=o.id AND x.ticket_type_id=i.ticket_type_id
                AND (x.kind=\'courtesy\'
                  OR (x.kind=\'paid\' AND x.status=\'active\' AND x.expires_at_utc>?)))';
    $held = 'SELECT COALESCE(SUM(quantity),0) FROM ticket_inventory_holds
        WHERE event_id=? AND status=\'active\' AND expires_at_utc>?';
    $courtesy = 'SELECT COALESCE(SUM(quantity),0) FROM ticket_inventory_holds
        WHERE event_id=? AND kind=\'courtesy\' AND status=\'consumed\'';
    $typeSuffix = $typeId > 0 ? ' AND ticket_type_id=?' : '';
    $soldType = $typeId > 0 ? ' AND i.ticket_type_id=?' : '';
    $salesArgs = $typeId > 0 ? [$eventId,$utc,$typeId] : [$eventId,$utc];
    $heldArgs = $typeId > 0 ? [$eventId,$utc,$typeId] : [$eventId,$utc];
    $courtesyArgs = $typeId > 0 ? [$eventId,$typeId] : [$eventId];
    return [
        'sold'=>brvtal_ticket_inventory_scalar($db,$sold.$soldType,$salesArgs),
        'held'=>brvtal_ticket_inventory_scalar($db,$held.$typeSuffix,$heldArgs),
        'courtesy'=>brvtal_ticket_inventory_scalar($db,$courtesy.$typeSuffix,$courtesyArgs),
    ];
}

/**
 * Must be called in the owning checkout transaction, before order confirmation.
 * Returns the same reservation for a retry with the same order/type/parameters.
 * Capacity 0 or missing configuration always fails closed.
 */
function brvtal_ticket_inventory_hold(
    PDO $db, int $orderId, int $typeId, int $qty, string $kind = 'paid',
    int $ttlSeconds = 600, ?DateTimeImmutable $now = null
): array {
    if (!$db->inTransaction()) throw new LogicException('INVENTORY_TRANSACTION_REQUIRED');
    if ($orderId < 1 || $typeId < 1 || $qty < 1 || $qty > 20
        || !in_array($kind,['paid','courtesy'],true)
        || $ttlSeconds < 60 || $ttlSeconds > 900) {
        throw new InvalidArgumentException('INVENTORY_INVALID_INPUT');
    }
    // The event row is the global inventory mutex. Read the immutable
    // order->event mapping without a lock, then acquire the Event FIRST.
    // A subsequent locking read revalidates current order status and quantity.
    $lookup = $db->prepare('SELECT event_id FROM ticket_orders WHERE id=?');
    $lookup->execute([$orderId]);
    $eventId = (int)$lookup->fetchColumn();
    if ($eventId < 1) throw new DomainException('INVENTORY_ORDER_NOT_ELIGIBLE');
    $eventQuery = $db->prepare('SELECT e.id,e.status,e.event_date,e.published_at,
        l.capacity AS event_capacity
        FROM events e LEFT JOIN ticket_inventory_events l ON l.event_id=e.id
        WHERE e.id=? FOR UPDATE');
    $eventQuery->execute([$eventId]);
    $event = $eventQuery->fetch(PDO::FETCH_ASSOC);
    if (!$event) throw new DomainException('INVENTORY_NOT_SALEABLE');
    $orderQuery = $db->prepare('SELECT o.id,o.event_id,o.status,i.quantity AS item_quantity
        FROM ticket_orders o JOIN ticket_order_items i ON i.order_id=o.id
        WHERE o.id=? AND i.ticket_type_id=? FOR UPDATE');
    $orderQuery->execute([$orderId,$typeId]);
    $order = $orderQuery->fetch(PDO::FETCH_ASSOC);
    if (!$order || (int)$order['event_id'] !== $eventId
        || !in_array($order['status'],['draft','pending'],true)
        || (int)$order['item_quantity'] !== $qty) {
        throw new DomainException('INVENTORY_ORDER_NOT_ELIGIBLE');
    }
    $typeQuery = $db->prepare('SELECT t.id,t.event_id,t.status,t.currency,
        t.available_from,t.available_until,l.capacity,l.courtesy_capacity
        FROM event_ticket_types t LEFT JOIN ticket_inventory_types l ON l.ticket_type_id=t.id
        WHERE t.id=? AND t.event_id=? FOR UPDATE');
    $typeQuery->execute([$typeId,$eventId]);
    $type = $typeQuery->fetch(PDO::FETCH_ASSOC);
    $existing = $db->prepare('SELECT id,quantity,kind,status,expires_at_utc
        FROM ticket_inventory_holds WHERE order_id=? AND ticket_type_id=? FOR UPDATE');
    $existing->execute([$orderId,$typeId]);
    $old = $existing->fetch(PDO::FETCH_ASSOC);
    // Capture the time AFTER the final potentially blocking read. In production
    // use the live UTC clock; explicit injection keeps synthetic tests reproducible.
    $clock = brvtal_ticket_inventory_now($now);
    $utc = $clock->format('Y-m-d H:i:s.u');
    if (!$event || !$type || !brvtal_ticket_inventory_saleable($event,$type,$clock)) {
        throw new DomainException('INVENTORY_NOT_SALEABLE');
    }
    if ($event['event_capacity'] === null || $type['capacity'] === null) {
        throw new DomainException('INVENTORY_NOT_CONFIGURED');
    }
    $expire = $db->prepare('UPDATE ticket_inventory_holds SET status=\'expired\'
        WHERE event_id=? AND status=\'active\' AND expires_at_utc<=?');
    $expire->execute([$eventId,$utc]);
    if ($old) {
        if ($old['status'] === 'active' && (int)$old['quantity'] === $qty && $old['kind'] === $kind
            && (string)$old['expires_at_utc'] > $utc) {
            return ['id'=>(int)$old['id'],'quantity'=>$qty,'status'=>'active','idempotent'=>true];
        }
        throw new DomainException('INVENTORY_HOLD_CONFLICT');
    }
    $eventUsage = brvtal_ticket_inventory_usage($db,$eventId,0,$utc);
    $typeUsage = brvtal_ticket_inventory_usage($db,$eventId,$typeId,$utc);
    if ($eventUsage['sold']+$eventUsage['held']+$eventUsage['courtesy']+$qty>(int)$event['event_capacity']
        || $typeUsage['sold']+$typeUsage['held']+$typeUsage['courtesy']+$qty>(int)$type['capacity']) {
        throw new DomainException('INVENTORY_SOLD_OUT');
    }
    if ($kind === 'courtesy' && $typeUsage['courtesy']+
        brvtal_ticket_inventory_scalar($db,
            'SELECT COALESCE(SUM(quantity),0) FROM ticket_inventory_holds
             WHERE ticket_type_id=? AND kind=\'courtesy\' AND status=\'active\' AND expires_at_utc>?',
            [$typeId,$utc])+$qty>(int)$type['courtesy_capacity']) {
        throw new DomainException('INVENTORY_COURTESY_LIMIT');
    }
    $expiry = $clock->modify('+' . $ttlSeconds . ' seconds')->format('Y-m-d H:i:s.u');
    $insert = $db->prepare('INSERT INTO ticket_inventory_holds
        (order_id,event_id,ticket_type_id,quantity,kind,status,expires_at_utc)
        VALUES (?,?,?,?,?,\'active\',?)');
    $insert->execute([$orderId,$eventId,$typeId,$qty,$kind,$expiry]);
    return ['id'=>(int)$db->lastInsertId(),'quantity'=>$qty,'status'=>'active','idempotent'=>false];
}

/** No ticket issuance: checkout is responsible for payment and later T07. */
function brvtal_ticket_inventory_transition(PDO $db, int $orderId, int $typeId, string $target): bool
{
    if (!$db->inTransaction()) throw new LogicException('INVENTORY_TRANSACTION_REQUIRED');
    if (!in_array($target,['released','consumed'],true)) throw new InvalidArgumentException('INVALID_HOLD_TRANSITION');
    $q = $db->prepare('SELECT h.id,h.status,h.expires_at_utc,o.status AS order_status
        FROM ticket_inventory_holds h JOIN ticket_orders o ON o.id=h.order_id
        WHERE h.order_id=? AND h.ticket_type_id=? FOR UPDATE');
    $q->execute([$orderId,$typeId]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;
    if ($row['status']===$target) return true;
    if ($row['status']!=='active') throw new DomainException('INVENTORY_HOLD_TERMINAL');
    if ($target==='consumed' && ($row['order_status']!=='paid'
        || (string)$row['expires_at_utc']<=brvtal_ticket_inventory_now()->format('Y-m-d H:i:s.u'))) {
        throw new DomainException('INVENTORY_PAYMENT_NOT_CONFIRMED');
    }
    $upd = $db->prepare('UPDATE ticket_inventory_holds SET status=? WHERE id=? AND status=\'active\'');
    $upd->execute([$target,$row['id']]);
    return $upd->rowCount()===1;
}

/** Bounded, retryable reconciliation with no emails, payments or tickets. */
function brvtal_ticket_inventory_reconcile(PDO $db, int $limit = 200): int
{
    if ($limit<1 || $limit>1000) throw new InvalidArgumentException('INVALID_RECONCILE_LIMIT');
    $stmt=$db->prepare("UPDATE ticket_inventory_holds SET status='expired'
        WHERE status='active' AND expires_at_utc<=UTC_TIMESTAMP(6)
        ORDER BY expires_at_utc,id LIMIT {$limit}");
    $stmt->execute();
    return $stmt->rowCount();
}
