<?php
declare(strict_types=1);

/** Read-only DISCADMIN T18 contracts. No checkout, payment, or mail mutations. */
function brvtalTicketAdminFilters(array $raw): array
{
    foreach ($raw as $key => $value) {
        if (!in_array($key, ['kind','event_id','status','from','to','email','q','cursor','limit','order_id','format'], true)
            || !is_string($value)) throw new InvalidArgumentException('INVALID_FILTER');
    }
    $kind = $raw['kind'] ?? 'orders';
    if (!in_array($kind, ['orders','buyers'], true)) throw new InvalidArgumentException('INVALID_KIND');
    $status = $raw['status'] ?? '';
    $statuses = $kind === 'orders'
        ? ['draft','pending','paid','failed','cancelled','expired','refunded']
        : ['guest','active','blocked'];
    if ($status !== '' && !in_array($status, $statuses, true)) throw new InvalidArgumentException('INVALID_STATUS');
    $out = ['kind'=>$kind,'status'=>$status];
    foreach (['event_id','cursor','order_id'] as $key) {
        $value = $raw[$key] ?? '';
        if ($value !== '' && (!ctype_digit($value) || (int)$value < 1 || (string)(int)$value !== $value)) {
            throw new InvalidArgumentException('INVALID_' . strtoupper($key));
        }
        $out[$key] = $value === '' ? null : (int)$value;
    }
    if ($kind === 'buyers' && $out['order_id'] !== null) throw new InvalidArgumentException('INVALID_KIND');
    $out['limit'] = $raw['limit'] ?? '';
    if ($out['limit'] !== '' && (!ctype_digit($out['limit']) || (int)$out['limit'] < 1)) {
        throw new InvalidArgumentException('INVALID_LIMIT');
    }
    $out['limit'] = min(50, (int)($out['limit'] ?: 20));
    foreach (['q','email'] as $key) {
        $value = trim($raw[$key] ?? '');
        if (strlen($value) > 120) throw new InvalidArgumentException('INVALID_' . strtoupper($key));
        $out[$key] = $value;
    }
    foreach (['from','to'] as $key) {
        $value = $raw[$key] ?? '';
        if ($value !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
            if (!$date || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException('INVALID_DATE');
        }
        $out[$key] = $value;
    }
    if ($out['from'] !== '' && $out['to'] !== '' && $out['from'] > $out['to']) {
        throw new InvalidArgumentException('INVALID_RANGE');
    }
    $out['format'] = $raw['format'] ?? 'json';
    if (!in_array($out['format'], ['json','csv'], true)) throw new InvalidArgumentException('INVALID_FORMAT');
    return $out;
}

function brvtalTicketAdminHasTable(PDO $pdo, string $table): bool
{
    $s = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1');
    $s->execute([$table]);
    return $s->fetchColumn() !== false;
}

/** One row per buyer/order; correlated aggregates avoid multiplying monetary totals. */
function brvtalTicketAdminPage(PDO $pdo, array $f): array
{
    $isOrder = $f['kind'] === 'orders';
    $params = []; $where = [];
    $alias = $isOrder ? 'o' : 'b';
    if ($f['cursor'] !== null) { $where[] = "$alias.id < ?"; $params[] = $f['cursor']; }
    if ($f['status'] !== '') { $where[] = "$alias.status = ?"; $params[] = $f['status']; }
    if ($f['event_id'] !== null) {
        $where[] = $isOrder ? 'o.event_id = ?' : 'EXISTS (SELECT 1 FROM ticket_orders x WHERE x.buyer_id=b.id AND x.event_id=?)';
        $params[] = $f['event_id'];
    }
    if ($f['q'] !== '') {
        if ($isOrder && ctype_digit($f['q'])) { $where[] = 'o.id = ?'; $params[] = (int)$f['q']; }
        else { $where[] = 'b.email LIKE ?'; $params[] = '%' . addcslashes($f['q'], '%_\\') . '%'; }
    }
    if ($f['email'] !== '') { $where[] = 'b.email = ?'; $params[] = $f['email']; }
    foreach (['from','to'] as $key) {
        if ($f[$key] === '') continue;
        $column = $isOrder ? 'o.created_at_utc' : 'b.created_at';
        $where[] = $column . ($key === 'from' ? ' >= ?' : ' < ?');
        $date = new DateTimeImmutable($f[$key], new DateTimeZone('UTC'));
        $params[] = ($key === 'to' ? $date->modify('+1 day') : $date)->format('Y-m-d 00:00:00');
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    if ($isOrder) {
        $entries = brvtalTicketAdminHasTable($pdo, 'ticket_entries')
            ? '(SELECT COUNT(*) FROM ticket_entries en JOIN ticket_order_items it ON it.id=en.order_item_id WHERE it.order_id=o.id)'
            : 'NULL';
        $mail = brvtalTicketAdminHasTable($pdo, 'ticket_mail_history')
            ? '(SELECT h.delivery_state FROM ticket_mail_history h WHERE h.order_id=o.id ORDER BY h.id DESC LIMIT 1)'
            : 'NULL';
        $sql = "SELECT o.id,o.buyer_id,o.event_id,o.status,o.currency,o.total_cop,o.created_at_utc,
            b.email, (SELECT COALESCE(SUM(i.quantity),0) FROM ticket_order_items i WHERE i.order_id=o.id) AS units,
            (SELECT COUNT(*) FROM ticket_payment_attempts pa WHERE pa.order_id=o.id AND pa.status='approved') AS approvals,
            (SELECT pa.status FROM ticket_payment_attempts pa WHERE pa.order_id=o.id ORDER BY pa.id DESC LIMIT 1) AS last_payment_state,
            $entries AS entries, $mail AS mail_state
            FROM ticket_orders o JOIN ticket_buyers b ON b.id=o.buyer_id" . $whereSql;
    } else {
        $sql = "SELECT b.id,b.email,b.status,b.created_at,
            (SELECT COUNT(*) FROM ticket_orders o WHERE o.buyer_id=b.id) AS order_count,
            (SELECT COALESCE(SUM(o.total_cop),0) FROM ticket_orders o WHERE o.buyer_id=b.id AND o.status='paid') AS paid_total_cop
            FROM ticket_buyers b" . $whereSql;
    }
    $sql .= " ORDER BY $alias.id DESC LIMIT ?";
    $stmt = $pdo->prepare($sql);
    $params[] = $f['limit'] + 1;
    foreach ($params as $i => $value) $stmt->bindValue($i+1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $more = count($rows) > $f['limit'];
    if ($more) array_pop($rows);
    return ['rows'=>$rows,'kind'=>$f['kind'],'has_more'=>$more,
        'next_cursor'=>$more && $rows ? (int)end($rows)['id'] : null];
}

function brvtalTicketAdminDetail(PDO $pdo, int $orderId): array
{
    $items = $pdo->prepare('SELECT ticket_name_snapshot,quantity,unit_price_cop,subtotal_cop FROM ticket_order_items WHERE order_id=? ORDER BY id');
    $items->execute([$orderId]);
    $payments = $pdo->prepare('SELECT provider,status,gross_amount_cop,provider_reference,provider_transaction_id,created_at_utc FROM ticket_payment_attempts WHERE order_id=? ORDER BY id DESC LIMIT 30');
    $payments->execute([$orderId]);
    $mails = [];
    if (brvtalTicketAdminHasTable($pdo, 'ticket_mail_history')) {
        $history = $pdo->prepare('SELECT delivery_state,created_at FROM ticket_mail_history WHERE order_id=? ORDER BY id DESC LIMIT 30');
        $history->execute([$orderId]);
        $mails = $history->fetchAll(PDO::FETCH_ASSOC);
    }
    // #1976: issuance state, so the admin can see a stuck order and retry it.
    $issue = null;
    if (brvtalTicketAdminHasTable($pdo, 'ticket_issue_outbox')) {
        $job = $pdo->prepare('SELECT state,attempts,next_attempt_at_utc FROM ticket_issue_outbox WHERE order_id=? LIMIT 1');
        $job->execute([$orderId]);
        $row = $job->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $issue = ['state'=>(string)$row['state'], 'attempts'=>(int)$row['attempts'],
                'next_attempt_at_utc'=>$row['next_attempt_at_utc']];
        }
    }
    return ['items'=>$items->fetchAll(PDO::FETCH_ASSOC), 'payments'=>$payments->fetchAll(PDO::FETCH_ASSOC),
        'mail'=>$mails, 'issue'=>$issue];
}
