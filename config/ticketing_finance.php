<?php
declare(strict_types=1);
require_once __DIR__ . '/ticketing_admin_orders.php';

/** T20: read-only financial projection; evidence fields never become estimated fees. */
function brvtalFinanceAdd(?int $sum, mixed $value): ?int
{
    return $sum === null || $value === null ? null : $sum + (int)$value;
}

/** @param array<int,array<string,mixed>> $orders
 *  @param array<int,array<string,mixed>> $items
 *  @param array<int,array<string,mixed>> $attempts
 */
function brvtalFinanceRefundAmounts(array $rows, int $eventId, int $collected): ?array
{
    $seen=[]; $total=0; $period=0;
    foreach ($rows as $row) {
        $id=(int)($row['refund_id']??0);
        $amount=(int)($row['amount_cop']??0);
        if ($id<1 || (int)($row['event_id']??0)!==$eventId
            || $amount<1 || $amount>$collected) return null;
        if (isset($seen[$id])) {
            if ($seen[$id]!==$amount) return null;
            continue; // Same refund receipt can never be counted twice.
        }
        $seen[$id]=$amount;
        if ($total>$collected-$amount) return null;
        $total+=$amount;
        if ($row['in_period']??false) $period+=$amount;
    }
    return ['total'=>$total,'in_period'=>$period];
}

/** Group by stable type ID, retaining every historical name rather than today's catalog label. */
function brvtalFinanceAccumulateType(array &$types, array $line): void
{
    $id=(int)$line['ticket_type_id'];
    $type=$types[$id]??['ticket_type_id'=>$id,'quantity'=>0,'gross_cop'=>0,
        'ticket_names'=>[],'unnamed_quantity'=>0];
    $quantity=(int)$line['quantity'];
    $name=trim((string)($line['ticket_name_snapshot']??''));
    $type['quantity']+=$quantity;
    $type['gross_cop']+=(int)$line['subtotal_cop'];
    if ($name === '') {
        $type['unnamed_quantity'] += $quantity;
    } elseif (!in_array($name, $type['ticket_names'], true)) {
        $type['ticket_names'][] = $name;
    }
    $types[$id]=$type;
}

/** A receipt is evidence, never a second sale or a settlement. */
function brvtalFinanceDiscrepancy(array $order, array $evidence): ?array
{
    $proof = $evidence['classification'];
    if (!in_array($proof['state'], ['discrepancy', 'unavailable'], true)) {
        return null;
    }
    $receipts = [];
    $unproven = 0;
    foreach ($evidence['attempts'] as $attempt) {
        if ($attempt['provider'] !== 'wompi' || !in_array($attempt['status'], ['approved', 'refunded'], true)) {
            continue;
        }
        $tx = (string)($attempt['provider_transaction_id'] ?? '');
        if (!(bool)($attempt['verified_event'] ?? false) || ($attempt['provider_reference'] ?? '') === ''
            || !preg_match('/^[a-zA-Z0-9-]{1,128}$/D', $tx)) {
            $unproven++;
            continue;
        }
        $amount = (int)($attempt['gross_amount_cop'] ?? 0);
        $amount = $amount > 0 && $amount === (int)$order['total_cop'] && $order['currency'] === 'COP'
            ? $amount : null;
        if (array_key_exists($tx, $receipts) && $receipts[$tx] !== $amount) {
            $amount = null;
        }
        $receipts[$tx] = $amount;
    }
    ksort($receipts, SORT_STRING);
    $rows = [];
    $sum = $receipts && $unproven === 0 ? 0 : null;
    foreach ($receipts as $tx => $amount) {
        $rows[] = ['transaction_id'=>(string)$tx, 'gross_cop'=>$amount];
        $sum = $sum === null || $amount === null || $sum > PHP_INT_MAX - $amount ? null : $sum + $amount;
    }
    return ['order_id'=>(int)$order['id'], 'state'=>$proof['state'], 'reason'=>$proof['reason'],
        'channel'=>$proof['channel'], 'wompi_receipts'=>$rows, 'authenticated_cop'=>$sum,
        'unproven_attempts'=>$unproven];
}

/** Refunds must be confirmed adjustment records, never inferred from order status. */
function brvtalFinanceSummarize(array $orders, array $items, array $attempts,
    array $posSales=[], array $refundsByOrder=[], bool $refundEvidence=true,
    ?array $evidenceByOrder=null): array
{
    $lineByOrder = [];
    foreach ($items as $item) {
        $id = (int)$item['order_id'];
        $lineByOrder[$id]['gross'] = ($lineByOrder[$id]['gross'] ?? 0) + (int)$item['subtotal_cop'];
        $lineByOrder[$id]['quantity'] = ($lineByOrder[$id]['quantity'] ?? 0) + (int)$item['quantity'];
        $lineByOrder[$id]['items'][] = $item;
    }
    $attemptByOrder = []; $paymentByOrder = [];
    foreach ($attempts as $attempt) {
        $id = (int)$attempt['order_id'];
        $attemptByOrder[$id][] = $attempt;
        if (in_array($attempt['status'] ?? '', ['approved','refunded'], true)
            && ($attempt['provider']??'')==='wompi') {
            $paymentByOrder[$id][] = $attempt;
        }
    }
    $posByOrder=[];
    foreach ($posSales as $sale) {
        $posByOrder[(int)$sale['order_id']][]=$sale;
    }
    $events = [];
    foreach ($orders as $order) {
        $eventId = (int)$order['event_id'];
        $summary = $events[$eventId] ?? [
            'event_id'=>$eventId, 'gross_cop'=>0, 'discount_cop'=>0,
            'approved_cop'=>0, 'pos_cop'=>0, 'pos_orders'=>0,
            'refund_cop'=>0, 'refund_in_period_cop'=>0, 'net_after_refunds_cop'=>null,
            'paid_orders'=>0, 'pending_orders'=>0,
            'declined_orders'=>0, 'refunded_orders'=>0, 'unverified_orders'=>0,
            'tickets'=>0, 'fee_cop'=>0, 'fee_vat_cop'=>0,
            'withheld_cop'=>0, 'payout_cop'=>0, 'difference_cop'=>0,
            'expenses_cop'=>null, 'profit_cop'=>null,
            'settlement_status'=>'unverified', 'capacity'=>null, 'checkins'=>null,
            'types'=>[], 'transactions'=>[], 'discrepancies'=>[],
        ];
        $id = (int)$order['id'];
        $status = (string)$order['status'];
        if (isset($evidenceByOrder[$id])) {
            $discrepancy = brvtalFinanceDiscrepancy($order, $evidenceByOrder[$id]);
            if ($discrepancy !== null) {
                $summary['discrepancies'][] = $discrepancy;
            }
        }
        if ($status === 'refunded') $summary['refunded_orders']++;
        if (in_array($status, ['pending','draft'], true)) $summary['pending_orders']++;
        elseif (in_array($status, ['failed','cancelled','expired'], true)) $summary['declined_orders']++;
        elseif (in_array($status, ['paid','refunded'], true)) {
            $payments = $paymentByOrder[$id] ?? [];
            $sales = $posByOrder[$id] ?? [];
            $payment = count($payments)===1 ? $payments[0] : null;
            $pos = count($sales)===1 ? $sales[0] : null;
            $proof = $evidenceByOrder[$id]['classification'] ?? [];
            $wompiAllowed = $evidenceByOrder===null
                || ($proof['channel']??null)==='wompi' && ($proof['state']??null)==='verified';
            $posAllowed = $evidenceByOrder===null
                || ($proof['channel']??null)==='pos' && ($proof['state']??null)==='registered_offline';
            $lines=$lineByOrder[$id]['items']??[];
            if ($posAllowed && $pos && ($pos['status']??null)==='paid'
                && empty($attemptByOrder[$id]) && count($lines)===1
                && in_array($pos['method'] ?? '', ['cash', 'terminal', 'transfer'], true)
                && (int)$pos['amount_cop']===(int)$order['total_cop']
                && (int)$pos['event_id']===$eventId
                && (int)$pos['ticket_type_id']===(int)$lines[0]['ticket_type_id']
                && (int)$pos['quantity']===(int)$lines[0]['quantity']
                && (int)$pos['amount_cop']===(int)$lines[0]['subtotal_cop']
                && (string)($order['currency']??'COP')==='COP') {
                $amount=(int)$pos['amount_cop'];
                $summary['paid_orders']++;
                $summary['pos_orders']++;
                $summary['pos_cop']+=$amount;
                $refund=$refundEvidence
                    ? brvtalFinanceRefundAmounts($refundsByOrder[$id]??[], $eventId, $amount) : null;
                // A legacy fully refunded order without a confirmed adjustment
                // is unknown, not a verified zero-refund sale.
                if ($status==='refunded' && ($refund['total']??0)===0) $refund=null;
                $summary['refund_cop']=brvtalFinanceAdd($summary['refund_cop'], $refund['total']??null);
                $summary['refund_in_period_cop']=brvtalFinanceAdd(
                    $summary['refund_in_period_cop'], $refund['in_period']??null);
                $summary['gross_cop']+=$amount;
                $summary['tickets']+=(int)$pos['quantity'];
                brvtalFinanceAccumulateType($summary['types'], $lines[0]);
                $summary['transactions'][]=['order_id'=>$id,'provider'=>'pos',
                    'method'=>$pos['method'],'transaction_id'=>null,'gross_cop'=>$amount,
                    'fee_cop'=>null,'fee_vat_cop'=>null,'withheld_cop'=>null,
                    'payout_cop'=>null,'settlement_status'=>'manual_unverified'];
                $events[$eventId]=$summary;
                continue;
            }
            if (!$wompiAllowed || !$payment || $sales
                || (int)($payment['gross_amount_cop'] ?? -1) !== (int)$order['total_cop']) {
                // Historical refunded orders without payment evidence are UNKNOWN;
                // they are not extra unverified *paid* orders.
                if ($status==='refunded') $summary['refund_cop']=null;
                else $summary['unverified_orders']++;
                if ($evidenceByOrder!==null || count($payments)>1 || $sales
                    || !empty($refundsByOrder[$id])) {
                    $summary['refund_cop']=null;
                    $summary['refund_in_period_cop']=null;
                }
                $events[$eventId]=$summary; continue;
            }
            $gross = $lineByOrder[$id]['gross'] ?? 0;
            $amount = (int)$order['total_cop'];
            if ($gross < $amount || (string)($order['currency'] ?? 'COP') !== 'COP') {
                $summary['unverified_orders']++; $events[$eventId]=$summary; continue;
            }
            $summary['paid_orders']++;
            $summary['gross_cop'] += $gross;
            $summary['discount_cop'] += $gross - $amount;
            $summary['approved_cop'] += $amount;
            $refund=$refundEvidence
                ? brvtalFinanceRefundAmounts($refundsByOrder[$id]??[], $eventId, $amount) : null;
            if ($status==='refunded' && ($refund['total']??0)===0) $refund=null;
            $summary['refund_cop']=brvtalFinanceAdd($summary['refund_cop'], $refund['total']??null);
            $summary['refund_in_period_cop']=brvtalFinanceAdd(
                $summary['refund_in_period_cop'], $refund['in_period']??null);
            $summary['tickets'] += $lineByOrder[$id]['quantity'] ?? 0;
            $summary['transactions'][] = [
                'order_id'=>$id,'provider'=>$payment['provider'] ?? null,
                'transaction_id'=>$payment['provider_transaction_id'] ?? null,
                'gross_cop'=>$amount,'fee_cop'=>$payment['fee_amount_cop'],
                'fee_vat_cop'=>$payment['fee_vat_cop'],
                'withheld_cop'=>$payment['withheld_amount_cop'],
                'payout_cop'=>$payment['net_amount_cop'],
                'settlement_status'=>'unverified',
            ];
            foreach ($lineByOrder[$id]['items'] ?? [] as $line) {
                brvtalFinanceAccumulateType($summary['types'], $line);
            }
            foreach (['fee_amount_cop'=>'fee_cop','fee_vat_cop'=>'fee_vat_cop',
                      'withheld_amount_cop'=>'withheld_cop','net_amount_cop'=>'payout_cop'] as $source=>$target) {
                $summary[$target] = brvtalFinanceAdd($summary[$target], $payment[$source] ?? null);
            }
            $expected = null;
            if ($payment['fee_amount_cop'] !== null && $payment['fee_vat_cop'] !== null
                && $payment['withheld_amount_cop'] !== null && $payment['net_amount_cop'] !== null) {
                $expected = (int)$payment['net_amount_cop'] -
                    ($amount - (int)$payment['fee_amount_cop'] -
                     (int)$payment['fee_vat_cop'] - (int)$payment['withheld_amount_cop']);
            }
            $summary['difference_cop'] = brvtalFinanceAdd($summary['difference_cop'], $expected);
        }
        $events[$eventId] = $summary;
    }
    ksort($events);
    foreach ($events as &$event) {
        ksort($event['types'], SORT_NUMERIC);
        foreach ($event['types'] as &$type) {
            sort($type['ticket_names'], SORT_STRING);
        }
        unset($type);
        $event['types']=array_values($event['types']);
        // Sale cohort: recorded refunds up to the selected end date, not payouts.
        $event['net_after_refunds_cop']=$event['refund_cop']===null ? null :
            $event['approved_cop']+$event['pos_cop']-$event['refund_cop'];
    }
    unset($event);
    return array_values($events);
}

/** Calendar cut in Colombia; UTC SQL bounds remain half-open. */
function brvtalFinanceCalendarBound(string $value, bool $exclusiveEnd): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('America/Bogota'));
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException('INVALID_DATE');
    }
    return ($exclusiveEnd ? $date->modify('+1 day') : $date)
        ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/** Acquire complete channel evidence before choosing presentation membership.
 * The canonical T18 classifier owns proof semantics; no monetary rules here.
 */
function brvtalFinanceChannelEvidence(PDO $db, array $orders): array
{
    if (!$orders) return ['attempts'=>[], 'sales'=>[], 'by_order'=>[]];
    $ids=array_column($orders,'id');
    $in=implode(',',array_fill(0,count($ids),'?'));
    $schema=brvtalTicketAdminPaymentSchema($db);
    // Same durable attempt/transaction identity used by T18; EXISTS never
    // multiplies receipts when callbacks repeat. Never expose provider_reference.
    $verifiedEvent = $schema['wompi_events']
        ? "EXISTS (SELECT 1 FROM ticket_payment_webhook_events w
            WHERE w.attempt_id=a.id AND w.provider_transaction_id=a.provider_transaction_id)"
        : 'FALSE';
    $stmt=$db->prepare("SELECT a.id,a.order_id,a.provider,a.provider_reference,a.provider_transaction_id,a.status,
        a.gross_amount_cop,a.fee_amount_cop,a.fee_vat_cop,a.withheld_amount_cop,a.net_amount_cop,
        $verifiedEvent AS verified_event FROM ticket_payment_attempts a WHERE a.order_id IN ($in)");
    $stmt->execute($ids);
    $attempts=$stmt->fetchAll(PDO::FETCH_ASSOC);
    // Do not erase invalid, incomplete or duplicate POS rows with an inner
    // join or status predicate; proof of a valid sale is a separate question.
    $stmt=$db->prepare("SELECT id,order_id,event_id,ticket_type_id,quantity,
        amount_cop,method,status FROM ticket_pos_sales WHERE order_id IN ($in)");
    $stmt->execute($ids);
    $sales=$stmt->fetchAll(PDO::FETCH_ASSOC);
    $byOrder=[];
    foreach ($orders as $order) {
        $id=(int)$order['id'];
        $classification=brvtalTicketAdminPaymentEvidence($db,$order,$schema);
        // An absent courtesy ledger cannot establish absence of a competing
        // channel. Keep known discrepancies, but do not certify a sale.
        if (!$schema['courtesy'] && in_array($classification['state'], ['verified','registered_offline'], true)) {
            $classification=['channel'=>'unknown','state'=>'unavailable',
                'reason'=>'courtesy_schema_missing','anomaly'=>false];
        }
        $byOrder[$id]=['attempts'=>[], 'pos_sales'=>[],
            'availability'=>$schema, 'classification'=>$classification];
    }
    foreach ($attempts as $attempt) $byOrder[(int)$attempt['order_id']]['attempts'][]=$attempt;
    foreach ($sales as $sale) $byOrder[(int)$sale['order_id']]['pos_sales'][]=$sale;
    return ['attempts'=>$attempts, 'sales'=>$sales, 'by_order'=>$byOrder];
}

/** Membership never changes the evidence carried by a selected order. */
function brvtalFinanceFilterOrders(array $orders, array $byOrder, string $channel): array
{
    if ($channel==='all') return $orders;
    return array_values(array_filter($orders, static function (array $order) use ($byOrder,$channel): bool {
        $evidence=$byOrder[(int)$order['id']];
        if ($channel==='pos') return $evidence['pos_sales']!==[];
        if ($channel==='wompi') {
            foreach ($evidence['attempts'] as $attempt) {
                if ($attempt['provider']==='wompi') return true;
            }
        }
        return false;
    }));
}

/** Own one consistent read-only snapshot unless the caller already owns a transaction. */
function brvtalFinanceReport(PDO $db, ?int $eventId, ?string $from, ?string $to, string $channel): array
{
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) {
        $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        $db->beginTransaction();
    }
    try {
        $result = brvtalFinanceReportSnapshot($db, $eventId, $from, $to, $channel);
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

function brvtalFinanceReportSnapshot(PDO $db, ?int $eventId, ?string $from, ?string $to, string $channel): array
{
    $start = $from === null ? null : brvtalFinanceCalendarBound($from, false);
    $end = $to === null ? null : brvtalFinanceCalendarBound($to, true);
    if ($from !== null && $to !== null && $from > $to) {
        throw new InvalidArgumentException('INVALID_RANGE');
    }
    $where = ["o.currency='COP'"];
    $params = [];
    if ($eventId !== null) { $where[]='o.event_id=?'; $params[]=$eventId; }
    if ($start !== null) {
        $where[] = 'o.created_at_utc>=?';
        $params[] = $start;
    }
    if ($end !== null) {
        $where[] = 'o.created_at_utc<?';
        $params[] = $end;
    }
    $sql = 'SELECT o.id,o.event_id,o.total_cop,o.currency,o.status FROM ticket_orders o WHERE ' .
        implode(' AND ', $where) . ' ORDER BY o.id DESC LIMIT 5001';
    $stmt=$db->prepare($sql); $stmt->execute($params);
    $orders=$stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($orders)>5000) throw new LengthException('REPORT_RANGE_TOO_LARGE');
    if (!$orders) return [];
    $ids=array_column($orders,'id');
    $in=implode(',',array_fill(0,count($ids),'?'));
    $stmt=$db->prepare("SELECT order_id,ticket_type_id,quantity,subtotal_cop,ticket_name_snapshot
        FROM ticket_order_items WHERE order_id IN ($in)");
    $stmt->execute($ids); $items=$stmt->fetchAll(PDO::FETCH_ASSOC);
    $evidence=brvtalFinanceChannelEvidence($db,$orders);
    $orders=brvtalFinanceFilterOrders($orders,$evidence['by_order'],$channel);
    // A channel may remove every order after the initial nonempty query.
    // Never generate SQL metadata IN () from an empty report.
    if (!$orders) return [];
    $refundEvidence=true; $refundsByOrder=[];
    // Reuse the same calendar cut for recorded refunds, without changing sale-cohort semantics.
    try {
        $adjust=$db->prepare("SELECT a.refund_id,a.order_id,a.event_id,a.amount_cop,a.recorded_at_utc
            FROM ticket_refund_adjustments a
            JOIN ticket_refunds r ON r.id=a.refund_id AND r.status='refunded'
                AND r.order_id=a.order_id AND r.event_id=a.event_id
            WHERE a.order_id IN ($in) ORDER BY a.refund_id");
        $adjust->execute($ids);
        foreach ($adjust->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $at=(string)$row['recorded_at_utc'];
            if ($end!==null && $at >= $end) continue;
            $row['in_period']=$start===null || $at >= $start;
            $refundsByOrder[(int)$row['order_id']][]=$row;
        }
    } catch (PDOException $e) {
        if ($e->getCode()!=='42S02') throw $e;
        $refundEvidence=false; // Missing ledger is UNKNOWN, not zero refunds.
    }
    $report=brvtalFinanceSummarize(
        $orders,$items,$evidence['attempts'],$evidence['sales'],
        $refundsByOrder,$refundEvidence,$evidence['by_order']);
    $eventIds=array_column($report,'event_id');
    $eventIn=implode(',',array_fill(0,count($eventIds),'?'));
    $metadata=[
        'capacity'=>"SELECT event_id,capacity AS value FROM ticket_inventory_events WHERE event_id IN ($eventIn)",
        'checkins'=>"SELECT event_id,COUNT(DISTINCT entry_id) AS value FROM ticket_checkins WHERE outcome='accepted' AND event_id IN ($eventIn) GROUP BY event_id",
    ];
    foreach ($metadata as $field=>$sql) {
        try {
            $stmt=$db->prepare($sql); $stmt->execute($eventIds);
            $values=[];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $values[(int)$row['event_id']] = (int)$row['value'];
            foreach ($report as &$event) $event[$field]=$values[$event['event_id']] ?? ($field==='checkins'?0:null);
            unset($event);
        } catch (PDOException $e) {
            // Migration not installed: keep optional operational figures UNKNOWN.
            if ($e->getCode() !== '42S02') throw $e;
        }
    }
    return $report;
}

/** #2190: internal ledger only; no projection, payment or HTTP side effects. */
function brvtalFinanceLedgerText(mixed $value, int $max): string
{
    if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')
        || trim($value) === '' || mb_strlen(trim($value), 'UTF-8') > $max
        || preg_match('/[\x00-\x1f\x7f]/', $value)) {
        throw new InvalidArgumentException('INVALID_LEDGER_TEXT');
    }
    return trim($value);
}

/** Values are full snapshots, not signed cash movements. A void copies the prior snapshot. */
function brvtalFinanceLedgerValues(string $kind, array $input): array
{
    if (!in_array($kind, ['expense','budget'], true)) {
        throw new InvalidArgumentException('INVALID_LEDGER_KIND');
    }
    $expense = $kind === 'expense';
    $category = $input['category'] ?? null;
    $amount = $input['amount_cop'] ?? null;
    $date = $input['entry_date'] ?? null;
    $status = $input['status'] ?? null;
    if (!in_array($category, ['artistas','lugar','produccion','publicidad','logistica','otros'], true)
        || !is_int($amount) || $amount < ($expense ? 1 : 0)
        || !is_string($date) || !preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D', $date)
        || !in_array($status, $expense ? ['committed','paid'] : ['active'], true)) {
        throw new InvalidArgumentException('INVALID_LEDGER_VALUES');
    }
    brvtalFinanceCalendarBound($date, false);
    $result = ['category'=>$category,'amount_cop'=>$amount,'entry_date'=>$date,'status'=>$status];
    if ($expense) $result['concept'] = brvtalFinanceLedgerText($input['concept'] ?? null, 180);
    return $result;
}

/**
 * Actor comes from the trusted admin caller, not a public payload.
 * New revisions and the existing admin audit journal commit atomically.
 * The event lock serializes budget creation and competing corrections.
 */
function brvtalFinanceLedgerWrite(PDO $db, string $kind, int $eventId, int $actorId,
    string $action, ?int $previousId, array $input, string $reason): int
{
    require_once __DIR__ . '/ticketing_staff_permissions.php';
    if (!in_array($kind, ['expense','budget'], true)
        || !in_array($action, ['create','adjust','void'], true)
        || $eventId < 1 || $actorId < 1
        || ($action === 'create' ? $previousId !== null : ($previousId ?? 0) < 1)
        || ($action === 'void' && $input !== [])) {
        throw new InvalidArgumentException('INVALID_LEDGER_ACTION');
    }
    $reason = brvtalFinanceLedgerText($reason, 320);
    $values = $action === 'void' ? [] : brvtalFinanceLedgerValues($kind, $input);
    $table = $kind === 'expense' ? 'event_expenses' : 'event_budgets';
    if ($db->inTransaction()) throw new LogicException('LEDGER_REQUIRES_OWN_TRANSACTION');
    $db->beginTransaction();
    try {
        $q = $db->prepare('SELECT staff_role FROM admins WHERE id=? AND is_active=1 FOR UPDATE');
        $q->execute([$actorId]);
        $role = $q->fetchColumn();
        if (!is_string($role) || !brvtalTicketingCan($role, 'finance')) {
            throw new DomainException('LEDGER_FORBIDDEN');
        }
        $q = $db->prepare('SELECT id FROM events WHERE id=? FOR UPDATE');
        $q->execute([$eventId]);
        if (!$q->fetchColumn()) throw new DomainException('LEDGER_EVENT_NOT_FOUND');
        $old = null;
        if ($previousId !== null) {
            $q = $db->prepare("SELECT * FROM $table WHERE id=? AND event_id=? FOR UPDATE");
            $q->execute([$previousId,$eventId]);
            $old = $q->fetch(PDO::FETCH_ASSOC);
            $q = $db->prepare("SELECT id FROM $table WHERE supersedes_id=? FOR UPDATE");
            $q->execute([$previousId]);
            if (!$old || $old['status'] === 'void' || $q->fetchColumn()) {
                throw new DomainException('LEDGER_STALE_REVISION');
            }
            if ($action === 'void') {
                $values = array_intersect_key($old, array_flip(['category','amount_cop','entry_date','status','concept']));
                $values['status'] = 'void';
            } elseif ($kind === 'budget' && $old['category'] !== $values['category']) {
                throw new DomainException('LEDGER_BUDGET_CATEGORY_IMMUTABLE');
            }
        } elseif ($kind === 'budget') {
            $q = $db->prepare("SELECT b.id FROM event_budgets b
                WHERE b.event_id=? AND b.category=? AND b.status='active'
                AND NOT EXISTS (SELECT 1 FROM event_budgets n WHERE n.supersedes_id=b.id)
                FOR UPDATE");
            $q->execute([$eventId,$values['category']]);
            if ($q->fetchColumn()) throw new DomainException('LEDGER_BUDGET_EXISTS');
        }
        $columns = ['event_id','category','amount_cop','entry_date','status','supersedes_id'];
        $params = [$eventId,$values['category'],$values['amount_cop'],$values['entry_date'],$values['status'],$previousId];
        if ($kind === 'expense') {
            $columns[] = 'concept';
            $params[] = $values['concept'];
        }
        $q = $db->prepare("INSERT INTO $table (".implode(',',$columns).",created_at_utc) VALUES ("
            .implode(',',array_fill(0,count($params),'?')).",UTC_TIMESTAMP(6))");
        $q->execute($params);
        $id = (int)$db->lastInsertId();
        // Reuse the existing admin audit treatment; no duplicate identity columns in the ledger.
        $q = $db->prepare('INSERT INTO admin_activity_log
            (admin_id,action,resource,resource_id,meta_json,request_id,created_at)
            VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())');
        $q->execute([$actorId,$action,$table,$id,
            json_encode(['reason'=>$reason,'previous_id'=>$previousId], JSON_THROW_ON_ERROR),
            bin2hex(random_bytes(16))]);
        $db->commit();
        return $id;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
