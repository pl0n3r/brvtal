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
function brvtalFinanceSummarize(array $orders, array $items, array $attempts, array $posSales=[]): array
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
        if (($attempt['status'] ?? '') !== 'approved' || ($attempt['provider']??'')!=='wompi') continue;
        $id = (int)$attempt['order_id'];
        if (!isset($paymentByOrder[$id]) || (int)$attempt['id'] < (int)$paymentByOrder[$id]['id']) {
            $paymentByOrder[$id] = $attempt;
        }
    }
    $posByOrder=[];
    foreach ($posSales as $sale) {
        if (($sale['status']??'')==='paid'
            && in_array($sale['method']??'', ['cash','terminal','transfer'],true)
            && (int)($sale['order_id']??0)>0 && (int)($sale['id']??0)>0) {
            $posByOrder[(int)$sale['order_id']]=$sale;
        }
    }
    $events = [];
    foreach ($orders as $order) {
        $eventId = (int)$order['event_id'];
        $summary = $events[$eventId] ?? [
            'event_id'=>$eventId, 'gross_cop'=>0, 'discount_cop'=>0,
            'approved_cop'=>0, 'pos_cop'=>0, 'pos_orders'=>0,
            'paid_orders'=>0, 'pending_orders'=>0,
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
            $pos = $posByOrder[$id] ?? null;
            $lines=$lineByOrder[$id]['items']??[];
            if ($pos && !$payment && count($lines)===1
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
                $summary['gross_cop']+=$amount;
                $summary['tickets']+=(int)$pos['quantity'];
                $type=(int)$pos['ticket_type_id'];
                $current=$summary['types'][$type]??['ticket_type_id'=>$type,'quantity'=>0,'gross_cop'=>0];
                $current['quantity']+=(int)$pos['quantity'];
                $current['gross_cop']+=$amount;
                $summary['types'][$type]=$current;
                $summary['transactions'][]=['order_id'=>$id,'provider'=>'pos',
                    'method'=>$pos['method'],'transaction_id'=>null,'gross_cop'=>$amount,
                    'fee_cop'=>null,'fee_vat_cop'=>null,'withheld_cop'=>null,
                    'payout_cop'=>null,'settlement_status'=>'manual_unverified'];
                $events[$eventId]=$summary;
                continue;
            }
            if (!$payment || $pos || (int)($payment['gross_amount_cop'] ?? -1) !== (int)$order['total_cop']) {
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
    $attempts=$stmt->fetchAll(PDO::FETCH_ASSOC);
    $sales=[];
    if ($channel!=='wompi') {
        try {
            $pos=$db->prepare("SELECT s.id,s.order_id,s.event_id,s.ticket_type_id,s.quantity,
                    s.amount_cop,s.method,s.status
                FROM ticket_pos_sales s
                JOIN ticket_pos_shifts sh ON sh.id=s.shift_id AND sh.event_id=s.event_id
                    AND sh.operator_admin_id=s.operator_admin_id
                WHERE s.order_id IN ($in) AND s.status='paid' AND s.completed_at_utc IS NOT NULL");
            $pos->execute($ids);
            $sales=$pos->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            if ($e->getCode()!=='42S02' || $channel==='pos') throw $e;
        }
    }
    if ($channel==='pos') {
        $allowed=array_fill_keys(array_map(intval(...),array_column($sales,'order_id')),true);
        $orders=array_values(array_filter($orders,static fn($o)=>isset($allowed[(int)$o['id']])));
    } elseif ($channel==='wompi') {
        $allowed=array_fill_keys(array_map(intval(...),array_column($attempts,'order_id')),true);
        $orders=array_values(array_filter($orders,static fn($o)=>isset($allowed[(int)$o['id']])));
    }
    // A channel may remove every order after the initial nonempty query.
    // Never generate SQL metadata IN () from an empty report.
    if (!$orders) return [];
    $report=brvtalFinanceSummarize($orders,$items,$attempts,$sales);
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
