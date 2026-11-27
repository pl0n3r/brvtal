<?php
declare(strict_types=1);
require_once __DIR__ . '/ticketing_staff_permissions.php';

function brvtalCourtesyAdminFilters(array $raw): array
{
    $fields = ['event_id','ticket_type_id','invite_id','category','status','cursor','limit'];
    foreach ($raw as $key => $value) {
        if (!in_array($key, $fields, true) || !is_string($value)) {
            throw new InvalidArgumentException('INVALID_FILTER');
        }
    }
    $out = [];
    foreach (['event_id','ticket_type_id','invite_id','cursor','limit'] as $key) {
        $value = $raw[$key] ?? ($key === 'limit' ? '20' : '');
        if ($value === '' && $key !== 'limit') {
            $out[$key] = null;
            continue;
        }
        if (!ctype_digit($value) || (int)$value < 1 || (string)(int)$value !== $value
            || ($key === 'limit' && (int)$value > 50)) {
            throw new InvalidArgumentException('INVALID_FILTER');
        }
        $out[$key] = (int)$value;
    }
    foreach (['category'=>['','artist','staff','press','partner'],
        'status'=>['','issued','used','revoked','refunded','unknown']] as $key => $allowed) {
        $out[$key] = $raw[$key] ?? '';
        if (!in_array($out[$key], $allowed, true)) {
            throw new InvalidArgumentException('INVALID_FILTER');
        }
    }
    return $out;
}

function brvtalCourtesyAdminHasTable(PDO $db, string $table): bool
{
    $q = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $q->execute([$table]);
    return $q->fetchColumn() !== false;
}

/** Delivery is evidence of the current revision, never of an earlier resend. */
function brvtalCourtesyAdminMailSql(PDO $db): array
{
    if (!brvtalCourtesyAdminHasTable($db, 'ticket_mail_outbox')) {
        return ['', "'unknown'"];
    }
    $joins = " LEFT JOIN ticket_mail_outbox m ON m.message_key=CONCAT('courtesy:',i.id,':',i.resend_requests)
        AND m.related_ref=CONCAT('courtesy:',i.id)";
    $delivered = '';
    if (brvtalCourtesyAdminHasTable($db, 'ticket_mail_history')) {
        $joins .= ' LEFT JOIN ticket_mail_history h ON h.outbox_id=m.id AND h.message_key=m.message_key
            AND h.resend_email_id=m.provider_message_id';
        $delivered = "WHEN m.state='accepted' AND m.accepted_at IS NOT NULL AND m.provider_message_id<>''
            AND h.delivery_state='delivered' AND h.state_at IS NOT NULL THEN 'delivered' ";
    }
    return [$joins, "CASE $delivered
        WHEN m.state='accepted' AND m.accepted_at IS NOT NULL AND m.provider_message_id<>'' THEN 'accepted'
        WHEN m.state IN ('queued','retry','sending') AND m.accepted_at IS NULL
            AND m.provider_message_id IS NULL THEN 'queued' ELSE 'unknown' END"];
}

/** No writes, no buyer join, no bearer or recipient columns. Recheck the current staff role. */
function brvtalCourtesyAdminRead(PDO $db, int $actor, array $raw): array
{
    if ($actor < 1) {
        throw new DomainException('FORBIDDEN');
    }
    $q = $db->prepare('SELECT staff_role FROM admins WHERE id=? AND is_active=1');
    $q->execute([$actor]);
    if (!brvtalTicketingCan((string)$q->fetchColumn(), 'courtesy')) {
        throw new DomainException('FORBIDDEN');
    }
    $f = brvtalCourtesyAdminFilters($raw);
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) {
        // Do not depend on the session's default isolation level.
        $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        $db->beginTransaction();
    }
    try {
        $result = brvtalCourtesyAdminSnapshot($db, $f);
        if ($ownsTransaction) {
            $db->commit();
        }
        return $result;
    } catch (Throwable $e) {
        if ($ownsTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function brvtalCourtesyAdminSnapshot(PDO $db, array $f): array
{
    $from = ' FROM ticket_courtesy_invites i JOIN events ev ON ev.id=i.event_id
        JOIN event_ticket_types t ON t.id=i.ticket_type_id AND t.event_id=i.event_id
        LEFT JOIN ticket_entries e ON e.id=i.entry_id
        LEFT JOIN ticket_order_items oi ON oi.id=e.order_item_id';
    $state = "CASE WHEN e.id IS NULL OR e.event_id<>i.event_id OR e.ticket_type_id<>i.ticket_type_id
        OR oi.id IS NULL OR oi.order_id<>i.order_id OR oi.event_id<>i.event_id
        OR oi.ticket_type_id<>i.ticket_type_id THEN 'unknown' ELSE e.status END";
    $where = [];
    $params = [];
    foreach (['event_id','ticket_type_id','category','invite_id'] as $key) {
        if ($f[$key] === null || $f[$key] === '') {
            continue;
        }
        $where[] = 'i.' . ($key === 'invite_id' ? 'id' : $key) . '=?';
        $params[] = $f[$key];
    }
    // Totals describe the filtered cohort, excluding the cursor and page size.
    if ($f['status'] !== '') {
        $where[] = "($state)=?";
        $params[] = $f['status'];
    }
    $filter = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $q = $db->prepare(
        "SELECT $state AS effective_status,COUNT(*) AS total" . $from . $filter . ' GROUP BY effective_status'
    );
    $q->execute($params);
    $totals = array_fill_keys(['issued','used','revoked','refunded','unknown'], 0);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $totals[$row['effective_status']] = (int)$row['total'];
    }
    if ($f['cursor'] !== null) {
        $where[] = 'i.id<?';
        $params[] = $f['cursor'];
    }
    $filter = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    [$mailJoins, $mailState] = brvtalCourtesyAdminMailSql($db);
    $limit = $f['limit'] + 1;
    $q = $db->prepare("SELECT i.id,i.event_id,ev.title AS event_title,i.ticket_type_id,t.name AS ticket_type_name,
        i.category,i.created_at_utc,i.status AS invite_status,$state AS status,
        i.resend_requests,$mailState AS mail_state" . $from . $mailJoins . $filter
        . " ORDER BY i.id DESC LIMIT $limit");
    $q->execute($params);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    $more = count($rows) > $f['limit'];
    if ($more) {
        array_pop($rows);
    }
    foreach ($rows as &$row) {
        foreach (['id','event_id','ticket_type_id','resend_requests'] as $key) {
            $row[$key] = (int)$row[$key];
        }
        $row['incident'] = $row['status'] !== $row['invite_status'];
    }
    unset($row);
    // Configured types without invitations and historical types; not a promise of availability.
    $catalog = $db->query("SELECT ev.id AS event_id,ev.title AS event_title,
        t.id AS ticket_type_id,t.name AS ticket_type_name
        FROM event_ticket_types t JOIN events ev ON ev.id=t.event_id
        WHERE EXISTS (SELECT 1 FROM ticket_courtesy_invites i WHERE i.ticket_type_id=t.id AND i.event_id=t.event_id)
        OR EXISTS (SELECT 1 FROM ticket_inventory_types l WHERE l.ticket_type_id=t.id AND l.event_id=t.event_id
            AND l.courtesy_capacity>0) ORDER BY ev.id DESC,t.id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($catalog as &$item) {
        $item['event_id'] = (int)$item['event_id'];
        $item['ticket_type_id'] = (int)$item['ticket_type_id'];
    }
    unset($item);
    return ['rows'=>$rows,'totals'=>$totals,'catalog'=>$catalog,'has_more'=>$more,
        'next_cursor'=>$more ? array_last($rows)['id'] : null];
}
