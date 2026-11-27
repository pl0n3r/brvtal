<?php
declare(strict_types=1);

require_once __DIR__ . '/ticketing_inventory.php';

/**
 * #1931 · DISCADMIN capacity for online sale. Online checkout only offers a ticket
 * type with configured capacity (event and type), so this is the switch that makes
 * a type sellable. Capacity can never drop below what is already sold, held or
 * consumed as courtesy, nor below the courtesy allocation of a type.
 */
const BRVTAL_TICKET_INVENTORY_MAX = 100000;
const BRVTAL_TICKET_INVENTORY_DOOR_PRICE_MAX = 10000000;

/** Reads event capacity with the same event-row mutex used by checkout. */
function brvtal_ticket_inventory_admin_event(PDO $db, int $eventId, bool $lock = false): array
{
    if ($eventId < 1) {
        throw new InvalidArgumentException('INVENTORY_INVALID_EVENT');
    }
    $sql = 'SELECT e.id,l.capacity FROM events e
        LEFT JOIN ticket_inventory_events l ON l.event_id=e.id WHERE e.id=?';
    $statement = $db->prepare($lock ? $sql . ' FOR UPDATE' : $sql);
    $statement->execute([$eventId]);
    $event = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$event) {
        throw new DomainException('INVENTORY_EVENT_NOT_FOUND');
    }
    return $event;
}

/** Current capacity and usage per ticket type of one event. */
function brvtal_ticket_inventory_admin_read(PDO $db, int $eventId): array
{
    $event = brvtal_ticket_inventory_admin_event($db, $eventId);
    $utc = brvtal_ticket_inventory_now()->format('Y-m-d H:i:s.u');
    $q = $db->prepare('SELECT t.id,t.name,t.status,t.door_price_cop,l.capacity,COALESCE(l.courtesy_capacity,0) AS courtesy_capacity
        FROM event_ticket_types t LEFT JOIN ticket_inventory_types l ON l.ticket_type_id=t.id
        WHERE t.event_id=? ORDER BY t.sort_order,t.id');
    $q->execute([$eventId]);
    $types = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $usage = brvtal_ticket_inventory_usage($db, $eventId, (int)$row['id'], $utc);
        $types[] = [
            'ticket_type_id'=>(int)$row['id'], 'name'=>(string)$row['name'], 'status'=>(string)$row['status'],
            'capacity'=>$row['capacity'] === null ? null : (int)$row['capacity'],
            'courtesy_capacity'=>(int)$row['courtesy_capacity'],
            'door_price_cop'=>$row['door_price_cop'] === null ? null : (int)$row['door_price_cop'],
            'sold'=>$usage['sold'], 'held'=>$usage['held'], 'courtesy_used'=>$usage['courtesy'],
        ];
    }
    $eventUsage = brvtal_ticket_inventory_usage($db, $eventId, 0, $utc);
    return [
        'event_id'=>$eventId,
        'capacity'=>$event['capacity'] === null ? null : (int)$event['capacity'],
        'used'=>array_sum($eventUsage),
        'types'=>$types,
        'sale'=>brvtal_ticket_inventory_sale_readiness($db, $eventId),
    ];
}

/**
 * #1939 · Why an event does or does not sell online. Walks the same conditions as
 * brvtal_public_ticket_checkout_offers() and reports reason codes; the verdict is the
 * checkout itself, so this can never claim "ready" while the public page sells nothing.
 * Exceptions are reported by class only (no messages, no secrets).
 */
function brvtal_ticket_inventory_sale_readiness(PDO $db, int $eventId): array
{
    require_once __DIR__ . '/public_ticket_checkout.php';
    require_once __DIR__ . '/public_visibility.php';
    $reasons = [];
    $typeReasons = [];
    $wompi = brvtal_ticket_wompi_status();
    if (($wompi['ready'] ?? false) !== true) $reasons[] = 'WOMPI_' . preg_replace('/^WOMPI_/', '', (string)($wompi['reason'] ?? 'DISABLED'));
    try {
        $q = $db->prepare('SELECT e.id,e.status,e.event_date,e.published_at,c.capacity AS event_capacity
            FROM events e LEFT JOIN ticket_inventory_events c ON c.event_id=e.id WHERE e.id=?');
        $q->execute([$eventId]);
        $event = $q->fetch(PDO::FETCH_ASSOC);
        if (!$event) return ['ready'=>false, 'reasons'=>['EVENT_NOT_FOUND'], 'types'=>[]];
        $now = brvtal_ticket_inventory_now();
        $bogota = $now->setTimezone(new DateTimeZone('America/Bogota'));
        $status = strtolower(trim((string)$event['status']));
        if ((int)($event['event_capacity'] ?? 0) < 1) $reasons[] = 'EVENT_CAPACITY_MISSING';
        if ($status === 'sold_out') $reasons[] = 'EVENT_SOLD_OUT';
        elseif (!in_array($status, brvtal_public_event_statuses()['active'], true)) $reasons[] = 'EVENT_STATUS';
        elseif (!brvtal_public_event_is_visible($event, $now)) $reasons[] = 'EVENT_NOT_PUBLISHED';
        $rawDate = trim((string)($event['event_date'] ?? ''));
        if ($rawDate === '') {
            $reasons[] = 'EVENT_DATE_MISSING';
        } else {
            try {
                if (new DateTimeImmutable($rawDate, new DateTimeZone('America/Bogota')) <= $bogota) $reasons[] = 'EVENT_DATE_PAST';
            } catch (Throwable) {
                $reasons[] = 'EVENT_DATE_INVALID';
            }
        }
        $utc = $now->format('Y-m-d H:i:s.u');
        $eventFree = (int)($event['event_capacity'] ?? 0) - array_sum(brvtal_ticket_inventory_usage($db, $eventId, 0, $utc));
        if ((int)($event['event_capacity'] ?? 0) >= 1 && $eventFree < 1) $reasons[] = 'EVENT_FULL';
        $q = $db->prepare('SELECT t.id,t.price,t.currency,t.status,t.available_from,t.available_until,c.capacity
            FROM event_ticket_types t LEFT JOIN ticket_inventory_types c ON c.ticket_type_id=t.id
            WHERE t.event_id=? ORDER BY t.sort_order,t.id');
        $q->execute([$eventId]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $type) {
            $codes = [];
            if ((int)($type['capacity'] ?? 0) < 1) $codes[] = 'TYPE_CAPACITY_MISSING';
            if (($type['status'] ?? '') !== 'active') $codes[] = 'TYPE_STATUS';
            if (($type['currency'] ?? '') !== 'COP') $codes[] = 'TYPE_CURRENCY';
            foreach (['available_from'=>'TYPE_NOT_STARTED', 'available_until'=>'TYPE_ENDED'] as $field => $code) {
                $raw = trim((string)($type[$field] ?? ''));
                if ($raw === '') continue;
                try {
                    $point = new DateTimeImmutable($raw, new DateTimeZone('America/Bogota'));
                    if (($code === 'TYPE_NOT_STARTED' && $bogota < $point) || ($code === 'TYPE_ENDED' && $bogota > $point)) $codes[] = $code;
                } catch (Throwable) {
                    $codes[] = 'TYPE_WINDOW_INVALID';
                }
            }
            try {
                brvtal_ticket_checkout_price($type['price'], 1);
            } catch (DomainException) {
                $codes[] = 'TYPE_PRICE';
            }
            if ($codes === [] && $eventFree >= 1
                && (int)$type['capacity'] - array_sum(brvtal_ticket_inventory_usage($db, $eventId, (int)$type['id'], $utc)) < 1) {
                $codes[] = 'TYPE_SOLD_OUT';
            }
            $typeReasons[(int)$type['id']] = $codes;
        }
        brvtal_waitlist_offers_enabled();
    } catch (Throwable $error) {
        $reasons[] = 'ERROR_' . strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $error::class));
    }
    // The verdict comes from the checkout itself.
    $offered = array_map(static fn(array $offer): int => (int)$offer['id'],
        (array)(brvtal_public_ticket_checkout_offers($db, $eventId)['offers'] ?? []));
    $types = [];
    foreach ($typeReasons as $typeId => $codes) {
        $isOffered = in_array($typeId, $offered, true);
        if (!$isOffered && $codes === [] && $reasons === []) $codes[] = 'TYPE_NOT_OFFERED';
        $types[] = ['ticket_type_id'=>$typeId, 'offered'=>$isOffered, 'reasons'=>$isOffered ? [] : $codes];
    }
    $ready = $offered !== [];
    if (!$ready && $reasons === [] && array_merge(...array_column($types, 'reasons') ?: [[]]) === []) $reasons[] = 'UNKNOWN';
    return ['ready'=>$ready, 'reasons'=>$ready ? [] : array_values(array_unique($reasons)), 'types'=>$types];
}

/** Optional fields preserve stored values when absent; explicit null clears the door tariff. */
function brvtal_ticket_inventory_admin_input(mixed $input): array
{
    $valid = static fn(mixed $value): bool => is_int($value) && $value >= 0 && $value <= BRVTAL_TICKET_INVENTORY_MAX;
    if (!is_array($input) || array_is_list($input) || array_diff(array_keys($input), ['capacity','types'])
        || !$valid($input['capacity'] ?? null) || !is_array($input['types'] ?? null)
        || !array_is_list($input['types']) || count($input['types']) > 50) {
        throw new InvalidArgumentException('INVENTORY_INVALID_INPUT');
    }
    $types = [];
    $courtesies = [];
    $doorPrices = [];
    foreach ($input['types'] as $row) {
        if (!is_array($row) || array_is_list($row)
            || array_diff(array_keys($row), ['ticket_type_id','capacity','courtesy_capacity','door_price_cop'])
            || (array_key_exists('courtesy_capacity', $row) && !$valid($row['courtesy_capacity']))
            || !is_int($row['ticket_type_id'] ?? null) || $row['ticket_type_id'] < 1
            || !$valid($row['capacity'] ?? null) || isset($types[$row['ticket_type_id']])) {
            throw new InvalidArgumentException('INVENTORY_INVALID_INPUT');
        }
        if (array_key_exists('door_price_cop', $row)) {
            $price = $row['door_price_cop'];
            if ($price !== null && (!is_int($price) || $price < 1 || $price > BRVTAL_TICKET_INVENTORY_DOOR_PRICE_MAX)) {
                throw new InvalidArgumentException('INVENTORY_INVALID_DOOR_PRICE');
            }
            $doorPrices[$row['ticket_type_id']] = $price;
        }
        $types[$row['ticket_type_id']] = $row['capacity'];
        if (array_key_exists('courtesy_capacity', $row)) {
            $courtesies[$row['ticket_type_id']] = $row['courtesy_capacity'];
        }
    }
    return ['capacity'=>$input['capacity'], 'types'=>$types, 'courtesies'=>$courtesies, 'door_prices'=>$doorPrices];
}

/** Saves event and type capacity atomically, with the event row as mutex. */
function brvtal_ticket_inventory_admin_save(PDO $db, int $eventId, mixed $input): array
{
    $data = brvtal_ticket_inventory_admin_input($input);
    if ($eventId < 1) throw new InvalidArgumentException('INVENTORY_INVALID_EVENT');
    if ($db->inTransaction()) throw new LogicException('INVENTORY_TRANSACTION_ALREADY_ACTIVE');
    require_once __DIR__ . '/admin_activity.php';
    $db->beginTransaction();
    try {
        // Same mutex as checkout holds: no hold can slip in between check and write.
        $event = brvtal_ticket_inventory_admin_event($db, $eventId, true);
        $utc = brvtal_ticket_inventory_now()->format('Y-m-d H:i:s.u');
        $typeQuery = $db->prepare('SELECT t.id,t.door_price_cop,l.capacity,COALESCE(l.courtesy_capacity,0) AS courtesy_capacity
            FROM event_ticket_types t LEFT JOIN ticket_inventory_types l ON l.ticket_type_id=t.id
            WHERE t.id=? AND t.event_id=? FOR UPDATE');
        $before = [
            'capacity'=>$event['capacity'] === null ? null : (int)$event['capacity'],
            'types'=>[],
            'courtesy_capacity'=>[],
            'door_price_cop'=>[],
        ];
        $courtesies = [];
        $doorPrices = [];
        foreach ($data['types'] as $typeId => $capacity) {
            $typeQuery->execute([$typeId, $eventId]);
            $type = $typeQuery->fetch(PDO::FETCH_ASSOC);
            if (!$type) throw new DomainException('INVENTORY_TYPE_NOT_IN_EVENT');
            $courtesy = $data['courtesies'][$typeId] ?? (int)$type['courtesy_capacity'];
            $usage = brvtal_ticket_inventory_usage($db, $eventId, $typeId, $utc);
            if ($capacity < $courtesy) {
                throw new DomainException('INVENTORY_BELOW_COURTESY');
            }
            if ($courtesy < $usage['courtesy']) {
                throw new DomainException('INVENTORY_COURTESY_BELOW_USAGE');
            }
            if ($capacity < array_sum($usage)) {
                throw new DomainException('INVENTORY_BELOW_USAGE');
            }
            $before['types'][$typeId] = $type['capacity'] === null ? null : (int)$type['capacity'];
            $before['courtesy_capacity'][$typeId] = (int)$type['courtesy_capacity'];
            $courtesies[$typeId] = $courtesy;
            $storedPrice = $type['door_price_cop'] === null ? null : (int)$type['door_price_cop'];
            $before['door_price_cop'][$typeId] = $storedPrice;
            $doorPrices[$typeId] = array_key_exists($typeId, $data['door_prices'])
                ? $data['door_prices'][$typeId] : $storedPrice;
        }
        if ($data['capacity'] < array_sum(brvtal_ticket_inventory_usage($db, $eventId, 0, $utc))) {
            throw new DomainException('INVENTORY_BELOW_USAGE');
        }
        $db->prepare('INSERT INTO ticket_inventory_events(event_id,capacity) VALUES(?,?)
            ON DUPLICATE KEY UPDATE capacity=VALUES(capacity)')->execute([$eventId, $data['capacity']]);
        $upsert = $db->prepare('INSERT INTO ticket_inventory_types(ticket_type_id,event_id,capacity,courtesy_capacity)
            VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE
            capacity=VALUES(capacity),courtesy_capacity=VALUES(courtesy_capacity)');
        foreach ($data['types'] as $typeId => $capacity) {
            $upsert->execute([$typeId, $eventId, $capacity, $courtesies[$typeId]]);
        }
        $updateDoorPrice = $db->prepare('UPDATE event_ticket_types SET door_price_cop=? WHERE id=? AND event_id=?');
        foreach ($data['door_prices'] as $typeId => $price) {
            $updateDoorPrice->bindValue(1, $price, $price === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $updateDoorPrice->bindValue(2, $typeId, PDO::PARAM_INT);
            $updateDoorPrice->bindValue(3, $eventId, PDO::PARAM_INT);
            $updateDoorPrice->execute();
        }
        brvtal_activity_record($db, 'inventory_update', 'ticket_inventory', $eventId, null, null,
            ['before'=>$before, 'after'=>['capacity'=>$data['capacity'], 'types'=>$data['types'],
                'courtesy_capacity'=>$courtesies, 'door_price_cop'=>$doorPrices]], 'Cupo de boletas'
        );
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    return brvtal_ticket_inventory_admin_read($db, $eventId);
}
