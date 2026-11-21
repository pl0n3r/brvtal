<?php
declare(strict_types=1);

/** T22 stage policy is a draft contract. Checkout must not activate it without
 * enforcing this exact server-authoritative price and stock in its own lock.
 * Existing event_ticket_types and paid order_item snapshots remain unchanged.
 */
function brvtalTicketStageInput(array $rows): array
{
    if (!array_is_list($rows) || $rows === [] || count($rows) > 4) {
        throw new InvalidArgumentException('INVALID_STAGES');
    }
    $order = array_flip(['early_bird','preventa','general','puerta']);
    $seen = []; $normalized = [];
    foreach ($rows as $row) {
        if (!is_array($row) || array_diff(array_keys($row),
            ['code','price_cop','cumulative_capacity','opens_at_local','closes_at_local'])) {
            throw new InvalidArgumentException('INVALID_STAGE_FIELDS');
        }
        $code = $row['code'] ?? null;
        if (!is_string($code) || !array_key_exists($code,$order) || isset($seen[$code])
            || !is_int($row['price_cop'] ?? null) || $row['price_cop'] < 1
            || $row['price_cop'] > 10000000
            || !is_int($row['cumulative_capacity'] ?? null)
            || $row['cumulative_capacity'] < 1) {
            throw new InvalidArgumentException('INVALID_STAGE_VALUE');
        }
        $zone = new DateTimeZone('America/Bogota');
        foreach (['opens_at_local','closes_at_local'] as $field) {
            $raw = $row[$field] ?? null;
            if (!is_string($raw) || !preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/D',$raw)
                || ($parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$raw,$zone)) === false
                || $parsed->format('Y-m-d H:i:s') !== $raw) {
                throw new InvalidArgumentException('INVALID_STAGE_WINDOW');
            }
        }
        if ($row['opens_at_local'] >= $row['closes_at_local']) {
            throw new InvalidArgumentException('INVALID_STAGE_WINDOW');
        }
        $seen[$code] = true; $normalized[] = $row;
    }
    usort($normalized,static fn(array $a,array $b): int => $order[$a['code']] <=> $order[$b['code']]);
    $lastStart = ''; $lastCap = 0;
    foreach ($normalized as $stage) {
        if ($stage['opens_at_local'] <= $lastStart
            || $stage['cumulative_capacity'] <= $lastCap) {
            throw new InvalidArgumentException('STAGE_ORDER_OR_CAPACITY');
        }
        $lastStart = $stage['opens_at_local'];
        $lastCap = $stage['cumulative_capacity'];
    }
    return $normalized;
}

/** No invented urgency: most recent open stage with real remaining allocation. */
function brvtalTicketStageCurrent(array $stages, int $used,
    ?DateTimeImmutable $clock = null): ?array
{
    if ($used < 0) throw new InvalidArgumentException('INVALID_USAGE');
    $clock = ($clock ?? new DateTimeImmutable('now',new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('America/Bogota'));
    $now = $clock->format('Y-m-d H:i:s');
    $selected = null;
    foreach (brvtalTicketStageInput($stages) as $stage) {
        if ($stage['opens_at_local'] <= $now && $now < $stage['closes_at_local']
            && $used < $stage['cumulative_capacity']) $selected = $stage;
    }
    return $selected;
}

/**
 * Quote-only T22 policy. Caller must obtain T05 Event mutex and compute
 * inventoryFree from sold + held + courtesy before calling. This pure helper
 * does not activate stage pricing for the public checkout.
 */
function brvtalTicketStageQuote(array $stages, int $used, int $quantity,
    int $inventoryFree, ?DateTimeImmutable $clock = null): array
{
    if ($used < 0 || $inventoryFree < 0 || $quantity < 1 || $quantity > 20) {
        throw new InvalidArgumentException('INVALID_STAGE_QUOTE');
    }
    $stage = brvtalTicketStageCurrent($stages, $used, $clock);
    if ($stage === null) throw new DomainException('STAGE_NOT_SALEABLE');
    $free = min($inventoryFree, $stage['cumulative_capacity'] - $used);
    if ($free < $quantity) throw new DomainException('STAGE_SOLD_OUT');
    $price = $stage['price_cop'];
    if ($price > intdiv(10000000, $quantity)) {
        throw new DomainException('STAGE_PRICE_LIMIT');
    }
    return [
        'code' => $stage['code'], 'unit_price_cop' => $price,
        'subtotal_cop' => $price * $quantity,
        'available' => min(20, $free),
    ];
}

/** Serialize with T05 holds on the Event row. Never mutate paid order snapshots. */
function brvtalTicketStageSave(PDO $pdo,int $eventId,int $typeId,array $rows): void
{
    if ($eventId < 1 || $typeId < 1 || $pdo->inTransaction()) {
        throw new InvalidArgumentException('INVALID_STAGE_TARGET');
    }
    // Explicitly synthetic until checkout also binds stage price + stock
    // under the existing T05 lock; never change a real tenant's prices.
    if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1'
        || !preg_match('/^brvtal_test[a-zA-Z0-9_]*$/D',
            (string)$pdo->query('SELECT DATABASE()')->fetchColumn())) {
        throw new DomainException('STAGES_DRAFT_ONLY');
    }
    $stages = brvtalTicketStageInput($rows);
    $pdo->beginTransaction();
    try {
        $q=$pdo->prepare('SELECT id FROM events WHERE id=? FOR UPDATE');
        $q->execute([$eventId]);
        if (!$q->fetchColumn()) throw new DomainException('EVENT_NOT_FOUND');
        $q=$pdo->prepare('SELECT id FROM event_ticket_types WHERE id=? AND event_id=? FOR UPDATE');
        $q->execute([$typeId,$eventId]);
        if (!$q->fetchColumn()) throw new DomainException('TICKET_TYPE_NOT_FOUND');
        $q=$pdo->prepare("SELECT id FROM ticket_inventory_holds WHERE event_id=? AND ticket_type_id=?
          AND status='active' AND expires_at_utc > UTC_TIMESTAMP(6) LIMIT 1 FOR UPDATE");
        $q->execute([$eventId,$typeId]);
        if ($q->fetchColumn()) throw new DomainException('ACTIVE_RESERVATIONS');
        // Validate existing + proposed stage rows as one coherent policy.
        $existing=$pdo->prepare('SELECT code,price_cop,cumulative_capacity,
          opens_at_local,closes_at_local FROM ticket_type_stages
          WHERE ticket_type_id=? AND event_id=? FOR UPDATE');
        $existing->execute([$typeId,$eventId]);
        $combined=[];
        foreach ($existing->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['price_cop']=(int)$row['price_cop'];
            $row['cumulative_capacity']=(int)$row['cumulative_capacity'];
            $combined[$row['code']]=$row;
        }
        foreach ($stages as $stage) $combined[$stage['code']]=$stage;
        brvtalTicketStageInput(array_values($combined));
        $write=$pdo->prepare('INSERT INTO ticket_type_stages
          (ticket_type_id,event_id,code,price_cop,cumulative_capacity,opens_at_local,closes_at_local)
          VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE price_cop=VALUES(price_cop),
          cumulative_capacity=VALUES(cumulative_capacity),
          opens_at_local=VALUES(opens_at_local),closes_at_local=VALUES(closes_at_local)');
        foreach ($stages as $stage) $write->execute([$typeId,$eventId,$stage['code'],
          $stage['price_cop'],$stage['cumulative_capacity'],
          $stage['opens_at_local'],$stage['closes_at_local']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
