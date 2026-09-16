<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/event_lifecycle.php';
require_once __DIR__ . '/content-validation.php';

function brvtal_event_workflow_text(mixed $value, int $max): string
{
    return mb_substr(trim((string)$value), 0, $max);
}

function brvtal_event_workflow_slug(string $value): string
{
    if (function_exists('slugify')) {
        return slugify($value);
    }
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/i', '-', $value) ?? '';
    return trim($value, '-');
}

function brvtal_event_workflow_url(mixed $value, string $field): ?string
{
    $raw = brvtal_event_workflow_text($value, 700);
    if ($raw === '') return null;
    if (filter_var($raw, FILTER_VALIDATE_URL) === false) {
        throw new InvalidArgumentException('INVALID_' . strtoupper($field));
    }
    $scheme = strtolower((string)parse_url($raw, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true)) {
        throw new InvalidArgumentException('INVALID_' . strtoupper($field));
    }
    return $raw;
}

/** @return array{error:string,field:string}|null */
function brvtal_event_state_error(array $state): ?array
{
    if (trim((string)($state['title'] ?? '')) === '') {
        return ['error' => 'TITLE_REQUIRED', 'field' => 'title'];
    }
    $status = strtolower(trim((string)($state['status'] ?? 'draft')));
    if ($status === 'draft') return null;
    if (trim((string)($state['event_date'] ?? '')) === '') {
        return ['error' => 'EVENT_DATE_REQUIRED', 'field' => 'event_date'];
    }
    if (trim((string)($state['city'] ?? '')) === '') {
        return ['error' => 'EVENT_CITY_REQUIRED', 'field' => 'city'];
    }
    return null;
}

function brvtal_event_workflow_event(array $input): array
{
    $allowedStatuses = ['draft','published','upcoming','tickets_available','last_tickets','sold_out','cancelled','finished','archived'];
    $payload = [];
    $strings = [
        'title'=>180,'slug'=>190,'venue'=>180,'city'=>120,'description'=>65535,
        'skin'=>60,'accent'=>30,'cover_image'=>500,'ticket_instructions'=>65535,'ticket_qr'=>500,
    ];
    foreach ($strings as $field => $max) {
        if (array_key_exists($field, $input)) $payload[$field] = brvtal_event_workflow_text($input[$field], $max);
    }
    if (array_key_exists('ticket_url', $input)) {
        $payload['ticket_url'] = brvtal_event_workflow_url($input['ticket_url'], 'ticket_url');
    }
    if (array_key_exists('event_date', $input)) {
        $payload['event_date'] = $input['event_date'];
    }
    $temporal = brvtal_content_temporal_normalize('events', $payload);
    if ($temporal['error'] !== null) {
        throw new InvalidArgumentException((string)$temporal['error']['error']);
    }
    $payload = $temporal['payload'];

    if (array_key_exists('status', $input)) {
        $status = strtolower(brvtal_event_workflow_text($input['status'], 30));
        if (!in_array($status, $allowedStatuses, true)) throw new InvalidArgumentException('INVALID_STATUS');
        $payload['status'] = $status;
    }
    if (array_key_exists('accent', $payload) && $payload['accent'] !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $payload['accent'])) {
        throw new InvalidArgumentException('INVALID_ACCENT');
    }
    if (array_key_exists('featured', $input)) $payload['featured'] = (int)((bool)$input['featured']);
    if (array_key_exists('sort_order', $input)) $payload['sort_order'] = (int)$input['sort_order'];
    if (array_key_exists('archive_year', $input)) {
        $year = $input['archive_year'];
        if ($year === null || $year === '') {
            $payload['archive_year'] = null;
        } else {
            $year = (int)$year;
            if ($year < 2000 || $year > 2200) throw new InvalidArgumentException('INVALID_ARCHIVE_YEAR');
            $payload['archive_year'] = $year;
        }
    }
    if (array_key_exists('slug', $payload)) {
        $payload['slug'] = brvtal_event_workflow_slug($payload['slug']);
    }
    if (($payload['slug'] ?? '') === '' && isset($payload['title'])) {
        $payload['slug'] = brvtal_event_workflow_slug((string)$payload['title']);
    }
    return $payload;
}

function brvtal_event_workflow_ticket(array $input): array
{
    $payload = [];
    if (array_key_exists('id', $input)) {
        $id = filter_var($input['id'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if ($id === false) throw new InvalidArgumentException('INVALID_TICKET_ID');
        $payload['id'] = (int)$id;
    }
    foreach (['name'=>120,'description'=>500,'payment_instructions'=>4000,'qr_image'=>500] as $field=>$max) {
        if (array_key_exists($field, $input)) $payload[$field] = brvtal_event_workflow_text($input[$field], $max);
    }
    if (array_key_exists('external_url', $input)) {
        $payload['external_url'] = brvtal_event_workflow_url($input['external_url'], 'external_url');
    }
    if (array_key_exists('price', $input)) {
        if ($input['price'] === null || trim((string)$input['price']) === '') {
            $payload['price'] = null;
        } elseif (!is_numeric($input['price']) || (float)$input['price'] < 0) {
            throw new InvalidArgumentException('INVALID_PRICE');
        } else {
            $payload['price'] = number_format((float)$input['price'], 2, '.', '');
        }
    }
    if (array_key_exists('currency', $input)) {
        $currency = strtoupper(brvtal_event_workflow_text($input['currency'], 3));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) throw new InvalidArgumentException('INVALID_CURRENCY');
        $payload['currency'] = $currency;
    }
    if (array_key_exists('status', $input)) {
        $status = strtolower(brvtal_event_workflow_text($input['status'], 20));
        if (!in_array($status, ['draft','active','inactive','sold_out'], true)) throw new InvalidArgumentException('INVALID_TICKET_STATUS');
        $payload['status'] = $status;
    }
    foreach (['available_from','available_until'] as $field) {
        if (array_key_exists($field, $input)) $payload[$field] = $input[$field];
    }
    $temporal = brvtal_content_temporal_normalize('ticket_types', $payload);
    if ($temporal['error'] !== null) throw new InvalidArgumentException((string)$temporal['error']['error']);
    $payload = $temporal['payload'];
    if (array_key_exists('sort_order', $input)) $payload['sort_order'] = (int)$input['sort_order'];
    return $payload;
}

function brvtal_event_workflow_lineup(array $input): array
{
    if (count($input) > 200) throw new InvalidArgumentException('INVALID_LINEUP');
    $lineup = [];
    $seen = [];
    foreach ($input as $index => $item) {
        if (!is_array($item)) throw new InvalidArgumentException('INVALID_LINEUP');
        $artistId = filter_var($item['artist_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if ($artistId === false || isset($seen[(int)$artistId])) throw new InvalidArgumentException('INVALID_LINEUP');
        $seen[(int)$artistId] = true;
        $lineup[] = [
            'artist_id'=>(int)$artistId,
            'lineup_order'=>array_key_exists('lineup_order', $item) ? (int)$item['lineup_order'] : (int)$index,
            'role'=>brvtal_event_workflow_text($item['role'] ?? '', 80),
        ];
    }
    usort($lineup, static fn(array $a,array $b): int => $a['lineup_order'] <=> $b['lineup_order']);
    foreach ($lineup as $index=>&$item) $item['lineup_order'] = $index;
    unset($item);
    return $lineup;
}

function brvtal_event_workflow_request(array $input): array
{
    $event = $input['event'] ?? null;
    $tickets = $input['ticket_types'] ?? [];
    $lineup = $input['lineup'] ?? [];
    if (!is_array($event) || !is_array($tickets) || !is_array($lineup)) throw new InvalidArgumentException('INVALID_EVENT_WORKFLOW');
    if (count($tickets) > 200) throw new InvalidArgumentException('TOO_MANY_TICKET_TYPES');
    $eventId = null;
    if (array_key_exists('id', $event) && $event['id'] !== null && $event['id'] !== '') {
        $parsed = filter_var($event['id'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if ($parsed === false) throw new InvalidArgumentException('INVALID_EVENT_ID');
        $eventId = (int)$parsed;
        unset($event['id']);
    }
    $normalizedTickets = [];
    $ticketIds = [];
    foreach ($tickets as $ticket) {
        if (!is_array($ticket)) throw new InvalidArgumentException('INVALID_TICKET_TYPE');
        $normalized = brvtal_event_workflow_ticket($ticket);
        if (isset($normalized['id'])) {
            if (isset($ticketIds[$normalized['id']])) throw new InvalidArgumentException('DUPLICATE_TICKET_ID');
            $ticketIds[$normalized['id']] = true;
        }
        $normalizedTickets[] = $normalized;
    }
    return [
        'event_id'=>$eventId,
        'event'=>brvtal_event_workflow_event($event),
        'ticket_types'=>$normalizedTickets,
        'lineup'=>brvtal_event_workflow_lineup($lineup),
    ];
}

function brvtal_event_workflow_fetch_event(PDO $pdo, int $id, bool $lock = false): ?array
{
    $sql = 'SELECT * FROM events WHERE id=? LIMIT 1' . ($lock ? ' FOR UPDATE' : '');
    $st = $pdo->prepare($sql); $st->execute([$id]); $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function brvtal_event_workflow_fetch_tickets(PDO $pdo, int $eventId, bool $lock = false): array
{
    $sql = 'SELECT * FROM event_ticket_types WHERE event_id=? ORDER BY sort_order,id' . ($lock ? ' FOR UPDATE' : '');
    $st = $pdo->prepare($sql); $st->execute([$eventId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function brvtal_event_workflow_fetch_lineup(PDO $pdo, int $eventId, bool $lock = false): array
{
    $sql = 'SELECT artist_id,lineup_order,role FROM event_artists WHERE event_id=? ORDER BY lineup_order,artist_id' . ($lock ? ' FOR UPDATE' : '');
    $st = $pdo->prepare($sql); $st->execute([$eventId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function brvtal_event_workflow_dynamic_update(PDO $pdo, string $table, int $id, array $patch): void
{
    if ($patch === []) return;
    $fields = array_keys($patch);
    $set = implode(',', array_map(static fn(string $field): string => "`{$field}`=?", $fields));
    $st = $pdo->prepare("UPDATE {$table} SET {$set} WHERE id=?");
    $st->execute([...array_values($patch), $id]);
}

/**
 * Persist the Event, Ticket Types and lineup as one database transaction.
 * The optional audit callback executes inside the same transaction.
 */
function brvtal_event_workflow_apply(PDO $pdo, array $request, ?callable $audit = null): array
{
    if ($pdo->inTransaction()) throw new RuntimeException('EVENT_WORKFLOW_TRANSACTION_ALREADY_ACTIVE');
    $pdo->beginTransaction();
    try {
        $eventId = $request['event_id'];
        $eventPatch = $request['event'];
        $beforeEvent = null;
        if ($eventId !== null) {
            $beforeEvent = brvtal_event_workflow_fetch_event($pdo, (int)$eventId, true);
            if ($beforeEvent === null) throw new RuntimeException('EVENT_NOT_FOUND');
            $eventPatch = brvtal_event_lifecycle_patch($beforeEvent, $eventPatch);
            $finalEvent = array_replace($beforeEvent, $eventPatch);
            $stateError = brvtal_event_state_error($finalEvent);
            if ($stateError !== null) throw new InvalidArgumentException($stateError['error']);
            brvtal_event_workflow_dynamic_update($pdo, 'events', (int)$eventId, $eventPatch);
        } else {
            $eventPatch = brvtal_event_lifecycle_patch([], $eventPatch);
            $finalEvent = array_replace(['status'=>'draft'], $eventPatch);
            $stateError = brvtal_event_state_error($finalEvent);
            if ($stateError !== null) throw new InvalidArgumentException($stateError['error']);
            if (($eventPatch['slug'] ?? '') === '') $eventPatch['slug'] = brvtal_event_workflow_slug((string)$eventPatch['title']);
            $fields = array_keys($eventPatch);
            $columns = implode(',', array_map(static fn(string $field): string => "`{$field}`", $fields));
            $marks = implode(',', array_fill(0, count($fields), '?'));
            $st = $pdo->prepare("INSERT INTO events ({$columns}) VALUES ({$marks})");
            $st->execute(array_values($eventPatch));
            $eventId = (int)$pdo->lastInsertId();
        }
        $afterEvent = brvtal_event_workflow_fetch_event($pdo, (int)$eventId, false);
        if ($afterEvent === null) throw new RuntimeException('EVENT_WORKFLOW_EVENT_MISSING');
        if ($audit !== null) {
            $audit($beforeEvent === null ? 'create' : 'update', 'events', (int)$eventId, $beforeEvent, $afterEvent, ['source'=>'event_workflow'], (string)$afterEvent['title']);
        }

        $beforeTickets = brvtal_event_workflow_fetch_tickets($pdo, (int)$eventId, true);
        $ticketMap = [];
        foreach ($beforeTickets as $row) $ticketMap[(int)$row['id']] = $row;
        $keep = [];
        foreach ($request['ticket_types'] as $index=>$ticket) {
            $ticketId = isset($ticket['id']) ? (int)$ticket['id'] : null;
            $patch = $ticket;
            unset($patch['id']);
            $patch['sort_order'] = $index;
            if ($ticketId !== null) {
                if (!isset($ticketMap[$ticketId])) throw new InvalidArgumentException('TICKET_NOT_FOUND');
                $before = $ticketMap[$ticketId];
                $state = array_replace($before, $patch);
                if (trim((string)($state['name'] ?? '')) === '') throw new InvalidArgumentException('TICKET_NAME_REQUIRED');
                $windowError = brvtal_ticket_window_error($state);
                if ($windowError !== null) throw new InvalidArgumentException($windowError['error']);
                brvtal_event_workflow_dynamic_update($pdo, 'event_ticket_types', $ticketId, $patch);
                $after = $pdo->prepare('SELECT * FROM event_ticket_types WHERE id=? LIMIT 1');
                $after->execute([$ticketId]); $afterRow = $after->fetch(PDO::FETCH_ASSOC) ?: $state;
                if ($audit !== null) $audit('update','ticket_types',$ticketId,$before,$afterRow,['source'=>'event_workflow'],(string)$afterRow['name']);
                $keep[$ticketId] = true;
            } else {
                if (trim((string)($patch['name'] ?? '')) === '') throw new InvalidArgumentException('TICKET_NAME_REQUIRED');
                $state = array_replace(['status'=>'active','currency'=>'COP'], $patch);
                $windowError = brvtal_ticket_window_error($state);
                if ($windowError !== null) throw new InvalidArgumentException($windowError['error']);
                $insert = ['event_id'=>(int)$eventId] + $patch;
                $fields = array_keys($insert);
                $columns = implode(',', array_map(static fn(string $field): string => "`{$field}`", $fields));
                $marks = implode(',', array_fill(0, count($fields), '?'));
                $st = $pdo->prepare("INSERT INTO event_ticket_types ({$columns}) VALUES ({$marks})");
                $st->execute(array_values($insert));
                $ticketId = (int)$pdo->lastInsertId();
                $after = $pdo->prepare('SELECT * FROM event_ticket_types WHERE id=? LIMIT 1');
                $after->execute([$ticketId]); $afterRow = $after->fetch(PDO::FETCH_ASSOC) ?: $insert;
                if ($audit !== null) $audit('create','ticket_types',$ticketId,null,$afterRow,['source'=>'event_workflow'],(string)$afterRow['name']);
                $keep[$ticketId] = true;
            }
        }
        foreach ($ticketMap as $ticketId=>$before) {
            if (isset($keep[$ticketId])) continue;
            $pdo->prepare('DELETE FROM event_ticket_types WHERE id=? AND event_id=?')->execute([$ticketId,(int)$eventId]);
            if ($audit !== null) $audit('delete','ticket_types',$ticketId,$before,null,['source'=>'event_workflow'],(string)$before['name']);
        }

        $beforeLineup = brvtal_event_workflow_fetch_lineup($pdo, (int)$eventId, true);
        $lineup = $request['lineup'];
        if ($lineup !== []) {
            $artistIds = array_column($lineup, 'artist_id');
            $marks = implode(',', array_fill(0, count($artistIds), '?'));
            $st = $pdo->prepare("SELECT id FROM artists WHERE id IN ({$marks}) FOR UPDATE");
            $st->execute($artistIds);
            $found = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            sort($found); $expected = array_map('intval', $artistIds); sort($expected);
            if ($found !== $expected) throw new InvalidArgumentException('LINEUP_ARTIST_NOT_FOUND');
        }
        $pdo->prepare('DELETE FROM event_artists WHERE event_id=?')->execute([(int)$eventId]);
        if ($lineup !== []) {
            $st = $pdo->prepare('INSERT INTO event_artists(event_id,artist_id,lineup_order,role) VALUES(?,?,?,?)');
            foreach ($lineup as $item) $st->execute([(int)$eventId,(int)$item['artist_id'],(int)$item['lineup_order'],(string)$item['role']]);
        }
        $afterLineup = brvtal_event_workflow_fetch_lineup($pdo, (int)$eventId, false);
        if ($audit !== null && $beforeLineup !== $afterLineup) {
            $audit('lineup_update','event_lineup',(int)$eventId,['event_id'=>(int)$eventId,'lineup'=>$beforeLineup],['event_id'=>(int)$eventId,'lineup'=>$afterLineup],['source'=>'event_workflow'],(string)$afterEvent['title']);
        }

        $afterTickets = brvtal_event_workflow_fetch_tickets($pdo, (int)$eventId, false);
        $pdo->commit();
        return ['event'=>$afterEvent,'ticket_types'=>$afterTickets,'lineup'=>$afterLineup];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
