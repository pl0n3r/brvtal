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

/** Current capacity and usage per ticket type of one event. */
function brvtal_ticket_inventory_admin_read(PDO $db, int $eventId): array
{
    if ($eventId < 1) throw new InvalidArgumentException('INVENTORY_INVALID_EVENT');
    $q = $db->prepare('SELECT e.id,l.capacity FROM events e
        LEFT JOIN ticket_inventory_events l ON l.event_id=e.id WHERE e.id=?');
    $q->execute([$eventId]);
    $event = $q->fetch(PDO::FETCH_ASSOC);
    if (!$event) throw new DomainException('INVENTORY_EVENT_NOT_FOUND');
    $utc = brvtal_ticket_inventory_now()->format('Y-m-d H:i:s.u');
    $q = $db->prepare('SELECT t.id,t.name,t.status,l.capacity,COALESCE(l.courtesy_capacity,0) AS courtesy_capacity
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
            'sold'=>$usage['sold'], 'held'=>$usage['held'], 'courtesy_used'=>$usage['courtesy'],
        ];
    }
    $eventUsage = brvtal_ticket_inventory_usage($db, $eventId, 0, $utc);
    return [
        'event_id'=>$eventId,
        'capacity'=>$event['capacity'] === null ? null : (int)$event['capacity'],
        'used'=>array_sum($eventUsage),
        'types'=>$types,
    ];
}

/** Strict input: {capacity:int, types:[{ticket_type_id:int, capacity:int}]}. */
function brvtal_ticket_inventory_admin_input(mixed $input): array
{
    $valid = static fn(mixed $value): bool => is_int($value) && $value >= 0 && $value <= BRVTAL_TICKET_INVENTORY_MAX;
    if (!is_array($input) || array_is_list($input) || array_diff(array_keys($input), ['capacity','types'])
        || !$valid($input['capacity'] ?? null) || !is_array($input['types'] ?? null)
        || !array_is_list($input['types']) || count($input['types']) > 50) {
        throw new InvalidArgumentException('INVENTORY_INVALID_INPUT');
    }
    $types = [];
    foreach ($input['types'] as $row) {
        if (!is_array($row) || array_is_list($row) || count($row) !== 2
            || !is_int($row['ticket_type_id'] ?? null) || $row['ticket_type_id'] < 1
            || !$valid($row['capacity'] ?? null) || isset($types[$row['ticket_type_id']])) {
            throw new InvalidArgumentException('INVENTORY_INVALID_INPUT');
        }
        $types[$row['ticket_type_id']] = $row['capacity'];
    }
    return ['capacity'=>$input['capacity'], 'types'=>$types];
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
        $q = $db->prepare('SELECT e.id,l.capacity FROM events e
            LEFT JOIN ticket_inventory_events l ON l.event_id=e.id WHERE e.id=? FOR UPDATE');
        $q->execute([$eventId]);
        $event = $q->fetch(PDO::FETCH_ASSOC);
        if (!$event) throw new DomainException('INVENTORY_EVENT_NOT_FOUND');
        $utc = brvtal_ticket_inventory_now()->format('Y-m-d H:i:s.u');
        $typeQuery = $db->prepare('SELECT t.id,l.capacity,COALESCE(l.courtesy_capacity,0) AS courtesy_capacity
            FROM event_ticket_types t LEFT JOIN ticket_inventory_types l ON l.ticket_type_id=t.id
            WHERE t.id=? AND t.event_id=? FOR UPDATE');
        $before = ['capacity'=>$event['capacity'] === null ? null : (int)$event['capacity'], 'types'=>[]];
        foreach ($data['types'] as $typeId => $capacity) {
            $typeQuery->execute([$typeId, $eventId]);
            $type = $typeQuery->fetch(PDO::FETCH_ASSOC);
            if (!$type) throw new DomainException('INVENTORY_TYPE_NOT_IN_EVENT');
            if ($capacity < (int)$type['courtesy_capacity']) throw new DomainException('INVENTORY_BELOW_COURTESY');
            if ($capacity < array_sum(brvtal_ticket_inventory_usage($db, $eventId, $typeId, $utc))) {
                throw new DomainException('INVENTORY_BELOW_USAGE');
            }
            $before['types'][$typeId] = $type['capacity'] === null ? null : (int)$type['capacity'];
        }
        if ($data['capacity'] < array_sum(brvtal_ticket_inventory_usage($db, $eventId, 0, $utc))) {
            throw new DomainException('INVENTORY_BELOW_USAGE');
        }
        $db->prepare('INSERT INTO ticket_inventory_events(event_id,capacity) VALUES(?,?)
            ON DUPLICATE KEY UPDATE capacity=VALUES(capacity)')->execute([$eventId, $data['capacity']]);
        $upsert = $db->prepare('INSERT INTO ticket_inventory_types(ticket_type_id,event_id,capacity,courtesy_capacity)
            VALUES(?,?,?,0) ON DUPLICATE KEY UPDATE capacity=VALUES(capacity)');
        foreach ($data['types'] as $typeId => $capacity) $upsert->execute([$typeId, $eventId, $capacity]);
        brvtal_activity_record($db, 'inventory_update', 'ticket_inventory', $eventId, null, null,
            ['before'=>$before, 'after'=>['capacity'=>$data['capacity'], 'types'=>$data['types']]], 'Cupo de boletas');
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    return brvtal_ticket_inventory_admin_read($db, $eventId);
}
