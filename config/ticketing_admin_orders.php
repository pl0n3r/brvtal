<?php
declare(strict_types=1);
require_once __DIR__ . '/ticketing_issue.php';

/** Read-only DISCADMIN T18 contracts. No checkout, payment, or mail mutations. */
function brvtalTicketAdminFilters(array $raw): array
{
    foreach ($raw as $key => $value) {
        if (!in_array($key, ['kind','event_id','status','from','to','email','q','cursor','limit','order_id','format','export_scope'], true)
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
    $out['export_scope'] = $raw['export_scope'] ?? 'page';
    if (!in_array($out['export_scope'], ['page','all'], true)
        || ($out['export_scope'] === 'all' && ($out['format'] !== 'csv' || $out['cursor'] !== null))
        || ($out['format'] === 'csv' && $out['order_id'] !== null)) {
        throw new InvalidArgumentException('INVALID_EXPORT_SCOPE');
    }
    return $out;
}

function brvtalTicketAdminHasTable(PDO $pdo, string $table): bool
{
    $s = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1');
    $s->execute([$table]);
    return $s->fetchColumn() !== false;
}


/** Optional proof tables are checked once per list page; no financial writes. */
function brvtalTicketAdminPaymentSchema(PDO $pdo): array
{
    return [
        'pos_sales'=>brvtalTicketAdminHasTable($pdo,'ticket_pos_sales'),
        'pos_shifts'=>brvtalTicketAdminHasTable($pdo,'ticket_pos_shifts'),
        'pos_notes'=>brvtalTicketAdminHasTable($pdo,'ticket_order_notes'),
        'courtesy'=>brvtalTicketAdminHasTable($pdo,'ticket_courtesy_invites'),
        'wompi_events'=>brvtalTicketAdminHasTable($pdo,'ticket_payment_webhook_events'),
    ];
}

/** Reuse T09's signed-event and trusted POS boundaries for read-only labels. */
function brvtalTicketAdminPaymentEvidence(PDO $pdo, array $order, ?array $schema = null): array
{
    $schema ??= brvtalTicketAdminPaymentSchema($pdo);
    $id = (int)($order['id'] ?? 0);
    $status = (string)($order['status'] ?? '');
    $total = (int)($order['total_cop'] ?? 0);
    $currency = (string)($order['currency'] ?? '');
    $paidBefore = in_array($status,['paid','refunded'],true);
    $evidence = static fn(string $channel,string $state,string $reason='',bool $anomaly=false): array =>
        ['channel'=>$channel,'state'=>$state,'reason'=>$reason,'anomaly'=>$anomaly];

    // An approved attempt alone is not proof: T09 requires the matching
    // durable signed/API-verification event, never a synthetic approval.
    $verifiedEvent = $schema['wompi_events']
        ? "EXISTS (SELECT 1 FROM ticket_payment_webhook_events w
            WHERE w.attempt_id=a.id AND w.provider_transaction_id=a.provider_transaction_id)"
        : 'FALSE';
    $q = $pdo->prepare("SELECT COUNT(*) AS total,
        COALESCE(SUM(a.status='approved'),0) AS approvals,
        COALESCE(SUM(a.status='approved' AND a.provider='wompi' AND a.gross_amount_cop=?
            AND a.provider_reference IS NOT NULL AND a.provider_reference<>''
            AND a.provider_transaction_id IS NOT NULL AND a.provider_transaction_id<>''
            AND $verifiedEvent),0) AS confirmed
        FROM ticket_payment_attempts a WHERE a.order_id=?");
    $q->execute([$total,$id]);
    $attempt = $q->fetch(PDO::FETCH_ASSOC) ?: [];
    $attempts = (int)($attempt['total'] ?? 0);
    $approvals = (int)($attempt['approvals'] ?? 0);
    $confirmed = (int)($attempt['confirmed'] ?? 0);

    $pos = 0; $posMethod = '';
    if ($schema['pos_sales']) {
        $q = $pdo->prepare('SELECT COUNT(*) AS total,MAX(method) AS method FROM ticket_pos_sales WHERE order_id=?');
        $q->execute([$id]);
        $row = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        $pos = (int)($row['total'] ?? 0);
        $posMethod = (string)($row['method'] ?? '');
    }
    $courtesy = 0;
    if ($schema['courtesy']) {
        $q = $pdo->prepare('SELECT COUNT(*) FROM ticket_courtesy_invites WHERE order_id=?');
        $q->execute([$id]);
        $courtesy = (int)$q->fetchColumn();
    }
    if ($approvals > 1) return $evidence('conflict','discrepancy','duplicate_wompi',true);
    if (($pos && $attempts) || ($courtesy && ($pos || $attempts))) {
        return $evidence('conflict','discrepancy','channel_conflict',true);
    }
    if ($courtesy) {
        if ($courtesy !== 1 || $total !== 0 || $status !== 'draft') {
            return $evidence('conflict','discrepancy','invalid_courtesy',true);
        }
        return $evidence('courtesy','courtesy');
    }
    if ($pos) {
        if (!$schema['pos_shifts'] || ($posMethod==='transfer' && !$schema['pos_notes'])) {
            return $evidence('unknown','unavailable','pos_schema_missing');
        }
        if ($pos===1 && $status==='paid' && brvtal_ticket_pos_verified($pdo,$id)) {
            return $evidence('pos','registered_offline');
        }
        // A refunded order no longer passes T09's paid-state predicate.
        if ($status==='refunded') return $evidence('unknown','unavailable','pos_refunded');
        return $evidence('conflict','discrepancy','invalid_pos',true);
    }
    if ($approvals) {
        if (!$schema['wompi_events']) return $evidence('unknown','unavailable','wompi_ledger_missing');
        if ($paidBefore && $currency==='COP' && $confirmed===1) {
            return $evidence('wompi','verified');
        }
        return $evidence('conflict','discrepancy','invalid_wompi',true);
    }
    if ($paidBefore) {
        return $schema['pos_sales']
            ? $evidence('conflict','discrepancy','paid_without_proof',true)
            : $evidence('unknown','unavailable','pos_schema_missing');
    }
    if ($status==='draft' && $total===0 && !$schema['courtesy']) {
        return $evidence('unknown','unavailable','courtesy_schema_missing');
    }
    return $evidence('none','no_payment');
}

/** One row per buyer/order; correlated aggregates avoid multiplying monetary totals. */
function brvtalTicketAdminPage(PDO $pdo, array $f, bool $includeEvidence = true): array
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
        // Order timestamps are explicitly UTC; buyer creation has no verified UTC contract.
        $date = new DateTimeImmutable($f[$key], new DateTimeZone($isOrder ? 'America/Bogota' : 'UTC'));
        if ($key === 'to') {
            $date = $date->modify('+1 day');
        }
        $params[] = $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
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
    if ($includeEvidence && $isOrder && $rows) {
        $schema = brvtalTicketAdminPaymentSchema($pdo);
        foreach ($rows as &$row) {
            $row['payment_evidence'] = brvtalTicketAdminPaymentEvidence($pdo,$row,$schema);
        }
        unset($row);
    }
    return ['rows'=>$rows,'kind'=>$f['kind'],'has_more'=>$more,
        'next_cursor'=>$more && $rows ? (int)end($rows)['id'] : null];
}

// New full export is OFF until OWNER's privacy activation decision (#1671).
const BRVTAL_TICKET_EXPORT_MAX = 1000;

function brvtalTicketAdminFullExportEnabled(): bool
{
    return ($GLOBALS['config']['ticketing']['filtered_export_enabled'] ?? false) === true;
}

function brvtalTicketAdminCsvCell(mixed $value): string
{
    $text = (string)($value ?? '');
    if (preg_match('//u', $text) !== 1) {
        throw new RuntimeException('EXPORT_ENCODING');
    }
    // Neutralize spreadsheet formulas, including leading whitespace and controls.
    return preg_match('/^(?:[\t\r\n]|[\s\p{Cc}\x{FEFF}]*[=+@\-＝＋＠－])/u', $text) === 1
        ? "'" . $text : $text;
}

function brvtalTicketAdminCsv(array $rows, string $kind): string
{
    $columns = $kind === 'orders'
        ? ['id','buyer_id','event_id','status','currency','total_cop','units','approvals','last_payment_state','mail_state']
        : ['id','status','order_count','paid_total_cop'];
    $out = fopen('php://memory', 'w+b');
    if ($out === false) throw new RuntimeException('EXPORT_BUFFER');
    try {
        if (fputcsv($out, $columns, escape: '') === false) throw new RuntimeException('EXPORT_BUFFER');
        foreach ($rows as $row) {
            $cells = array_map(static fn(string $key): string => brvtalTicketAdminCsvCell($row[$key] ?? null), $columns);
            if (fputcsv($out, $cells, escape: '') === false) throw new RuntimeException('EXPORT_BUFFER');
        }
        rewind($out);
        $csv = stream_get_contents($out);
        if ($csv === false) throw new RuntimeException('EXPORT_BUFFER');
        return $csv;
    } finally {
        fclose($out);
    }
}

/** One bounded consistent read, then audit before releasing any CSV bytes. */
function brvtalTicketAdminExport(PDO $pdo, array $filter, callable $audit): array
{
    $all = $filter['export_scope'] === 'all';
    if ($all) {
        $filter['cursor'] = null;
        $filter['limit'] = BRVTAL_TICKET_EXPORT_MAX;
    }
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
    $pdo->beginTransaction();
    try {
        $snapshot = (new DateTimeImmutable('now', new DateTimeZone('America/Bogota')))->format(DATE_ATOM);
        $page = brvtalTicketAdminPage($pdo, $filter, false);
        if ($all && $page['has_more']) throw new LengthException('EXPORT_LIMIT');
        $count = count($page['rows']);
        $csv = brvtalTicketAdminCsv($page['rows'], $filter['kind']);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    $audit([
        'kind'=>$filter['kind'], 'scope'=>$filter['export_scope'], 'event_id'=>$filter['event_id'],
        'status'=>$filter['status'], 'from'=>$filter['from'], 'to'=>$filter['to'],
        'count'=>$count, 'snapshot'=>$snapshot,
    ]);
    return ['csv'=>$csv, 'count'=>$count, 'snapshot'=>$snapshot];
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
    // A reduced legacy/disposable fixture may omit the events table. The
    // source order remains readable instead of converting detail to HTTP 503.
    $order = null;
    if (brvtalTicketAdminHasTable($pdo,'events')) {
        $identity = $pdo->prepare('SELECT o.id,o.status,o.total_cop,o.currency,o.created_at_utc,
            e.title AS event_title FROM ticket_orders o JOIN events e ON e.id=o.event_id WHERE o.id=?');
        $identity->execute([$orderId]);
        $order = $identity->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $notes = [];
    if (brvtalTicketAdminHasTable($pdo,'ticket_order_notes')) {
        $q = $pdo->prepare('SELECT n.note_text,n.created_at_utc,
            a.name AS actor_name FROM ticket_order_notes n
            LEFT JOIN admins a ON a.id=n.actor_admin_id
            WHERE n.order_id=? ORDER BY n.created_at_utc,n.id');
        $q->execute([$orderId]);
        $notes = $q->fetchAll(PDO::FETCH_ASSOC);
    }
    $entries = ['total'=>0,'used'=>0,'first_issued_at'=>null,'last_used_at'=>null];
    if (brvtalTicketAdminHasTable($pdo,'ticket_entries')) {
        $probe = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ticket_entries'
              AND COLUMN_NAME IN ('status','issued_at_utc','used_at_utc')");
        $fullEntries = (int)$probe->fetchColumn()===3;
        $q = $pdo->prepare($fullEntries
            ? "SELECT COUNT(*) AS total, COALESCE(SUM(e.status='used'),0) AS used,
                  MIN(e.issued_at_utc) AS first_issued_at,
                  MAX(e.used_at_utc) AS last_used_at
               FROM ticket_entries e JOIN ticket_order_items i ON i.id=e.order_item_id
               WHERE i.order_id=?"
            : "SELECT COUNT(*) AS total, 0 AS used, NULL AS first_issued_at,
                  NULL AS last_used_at
               FROM ticket_entries e JOIN ticket_order_items i ON i.id=e.order_item_id
               WHERE i.order_id=?");
        $q->execute([$orderId]);
        $entries = $q->fetch(PDO::FETCH_ASSOC) ?: $entries;
    }
    $paymentsFound=$payments->fetchAll(PDO::FETCH_ASSOC);
    $itemsFound=$items->fetchAll(PDO::FETCH_ASSOC);
    $source = $pdo->prepare('SELECT id,status,total_cop,currency FROM ticket_orders WHERE id=?');
    $source->execute([$orderId]);
    $sourceOrder = $source->fetch(PDO::FETCH_ASSOC);
    $paymentEvidence = $sourceOrder ? brvtalTicketAdminPaymentEvidence($pdo,$sourceOrder) : null;
    return ['order'=>$order ?: null,'items'=>$itemsFound,'payments'=>$paymentsFound,
        'payment_evidence'=>$paymentEvidence,
        'mail'=>$mails, 'issue'=>$issue,'notes'=>$notes,'entries'=>$entries];
}
