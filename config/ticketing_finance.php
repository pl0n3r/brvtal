<?php
declare(strict_types=1);

/** T20: read-only financial projection; evidence fields never become estimated fees. */
function brvtalFinanceAdd(?int $sum, mixed $value): ?int
{
    return $sum === null || $value === null ? null : $sum + (int)$value;
}

/** @param array<int,array<string,mixed>> $orders
 *  @param array<int,array<string,mixed>> $items
 *  @param array<int,array<string,mixed>> $attempts
 */
function brvtalFinanceSummarize(array $orders, array $items, array $attempts): array
{
    $lineByOrder = [];
    foreach ($items as $item) {
        $id = (int)$item['order_id'];
        $lineByOrder[$id]['gross'] = ($lineByOrder[$id]['gross'] ?? 0) + (int)$item['subtotal_cop'];
        $lineByOrder[$id]['quantity'] = ($lineByOrder[$id]['quantity'] ?? 0) + (int)$item['quantity'];
        $lineByOrder[$id]['items'][] = $item;
    }
    $paymentByOrder = [];
    foreach ($attempts as $attempt) {
        if (($attempt['status'] ?? '') !== 'approved') continue;
        $id = (int)$attempt['order_id'];
        if (!isset($paymentByOrder[$id]) || (int)$attempt['id'] < (int)$paymentByOrder[$id]['id']) {
            $paymentByOrder[$id] = $attempt;
        }
    }
    $events = [];
    foreach ($orders as $order) {
        $eventId = (int)$order['event_id'];
        $summary = $events[$eventId] ?? [
            'event_id'=>$eventId, 'gross_cop'=>0, 'discount_cop'=>0,
            'approved_cop'=>0, 'paid_orders'=>0, 'pending_orders'=>0,
            'declined_orders'=>0, 'refunded_orders'=>0, 'unverified_orders'=>0,
            'tickets'=>0, 'fee_cop'=>0, 'fee_vat_cop'=>0,
            'withheld_cop'=>0, 'payout_cop'=>0, 'difference_cop'=>0,
            'expenses_cop'=>null, 'profit_cop'=>null,
            'settlement_status'=>'unverified', 'capacity'=>null, 'checkins'=>null,
            'types'=>[], 'transactions'=>[],
        ];
        $id = (int)$order['id'];
        $status = (string)$order['status'];
        if ($status === 'refunded') $summary['refunded_orders']++;
        elseif (in_array($status, ['pending','draft'], true)) $summary['pending_orders']++;
        elseif (in_array($status, ['failed','cancelled','expired'], true)) $summary['declined_orders']++;
        elseif ($status === 'paid') {
            $payment = $paymentByOrder[$id] ?? null;
            if (!$payment || (int)($payment['gross_amount_cop'] ?? -1) !== (int)$order['total_cop']) {
                $summary['unverified_orders']++; $events[$eventId]=$summary; continue;
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
                $key = (int)$line['ticket_type_id'];
                $type = $summary['types'][$key] ?? ['ticket_type_id'=>$key,'quantity'=>0,'gross_cop'=>0];
                $type['quantity'] += (int)$line['quantity'];
                $type['gross_cop'] += (int)$line['subtotal_cop'];
                $summary['types'][$key] = $type;
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
    foreach ($events as &$event) $event['types'] = array_values($event['types']);
    unset($event);
    return array_values($events);
}

function brvtalFinanceReport(PDO $db, ?int $eventId, ?string $from, ?string $to, string $channel): array
{
    $where = ["o.currency='COP'"];
    $params = [];
    if ($eventId !== null) { $where[]='o.event_id=?'; $params[]=$eventId; }
    if ($from !== null) { $where[]='o.created_at_utc>=?'; $params[]=$from.' 00:00:00'; }
    if ($to !== null) {
        $where[]='o.created_at_utc<?';
        $params[]=new DateTimeImmutable($to, new DateTimeZone('UTC'))->modify('+1 day')->format('Y-m-d').' 00:00:00';
    }
    $sql = 'SELECT o.id,o.event_id,o.total_cop,o.currency,o.status FROM ticket_orders o WHERE ' .
        implode(' AND ', $where) . ' ORDER BY o.id DESC LIMIT 5001';
    $stmt=$db->prepare($sql); $stmt->execute($params);
    $orders=$stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($orders)>5000) throw new LengthException('REPORT_RANGE_TOO_LARGE');
    if (!$orders) return [];
    $ids=array_column($orders,'id');
    $in=implode(',',array_fill(0,count($ids),'?'));
    $stmt=$db->prepare("SELECT order_id,ticket_type_id,quantity,subtotal_cop FROM ticket_order_items WHERE order_id IN ($in)");
    $stmt->execute($ids); $items=$stmt->fetchAll(PDO::FETCH_ASSOC);
    $paymentSql="SELECT id,order_id,provider,provider_transaction_id,status,gross_amount_cop,fee_amount_cop,fee_vat_cop,withheld_amount_cop,net_amount_cop
        FROM ticket_payment_attempts WHERE order_id IN ($in)";
    if ($channel!=='all') $paymentSql.=" AND provider='wompi'";
    $stmt=$db->prepare($paymentSql); $stmt->execute($ids);
    $report=brvtalFinanceSummarize($orders,$items,$stmt->fetchAll(PDO::FETCH_ASSOC));
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
