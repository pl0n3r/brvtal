<?php
declare(strict_types=1);
require_once __DIR__ . '/ticketing_issue.php';

/** Read-only account scope: all order/entry queries must bind the session buyer. */
function brvtalBuyerAccountFilters(array $input): array
{
    if (array_diff(array_keys($input), ['page','event_id','from','to'])) {
        throw new InvalidArgumentException('FILTER_INVALID');
    }
    $page = $input['page'] ?? '0';
    if (!is_string($page) || !preg_match('/^(0|[1-9][0-9]{0,2})$/D', $page) || (int)$page > 99) {
        throw new InvalidArgumentException('FILTER_INVALID');
    }
    $event = $input['event_id'] ?? '';
    if (!is_string($event) || ($event !== '' && (!preg_match('/^[1-9][0-9]{0,8}$/D', $event)))) {
        throw new InvalidArgumentException('FILTER_INVALID');
    }
    $dates = [];
    foreach (['from','to'] as $key) {
        $value = $input[$key] ?? '';
        if (!is_string($value) || ($value !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value))) {
            throw new InvalidArgumentException('FILTER_INVALID');
        }
        $date = $value === '' ? null : DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if ($value !== '' && (!$date || $date->format('Y-m-d') !== $value)) {
            throw new InvalidArgumentException('FILTER_INVALID');
        }
        $dates[$key] = $date;
    }
    if ($dates['from'] && $dates['to'] && $dates['from'] > $dates['to']) {
        throw new InvalidArgumentException('FILTER_INVALID');
    }
    return ['page'=>(int)$page,'event_id'=>$event === '' ? null : (int)$event] + $dates;
}

function brvtalBuyerAccountAuthorized(PDO $db, int $buyerId): bool
{
    if ($buyerId <= 0 || (int)($_SESSION['buyer_id'] ?? 0) !== $buyerId) return false;
    $checkoutOrder = (int)($_SESSION['buyer_checkout_new_order'] ?? 0);
    $q = $db->prepare("SELECT id FROM ticket_buyers b WHERE b.id=?
        AND ((b.status='active' AND b.email_verified_at IS NOT NULL)
             OR (b.status='guest' AND b.email_verified_at IS NULL
                 AND ? > 0 AND EXISTS (
                     SELECT 1 FROM ticket_orders o WHERE o.id=?
                       AND o.buyer_id=b.id AND o.status='paid'))) LIMIT 1");
    $q->execute([$buyerId, $checkoutOrder, $checkoutOrder]);
    return $q->fetchColumn() !== false;
}

function brvtalBuyerAccountOrders(PDO $db, int $buyerId, array $f): array
{
    if ($buyerId < 1) throw new DomainException('AUTH_REQUIRED');
    $where = ['o.buyer_id=?']; $args = [$buyerId];
    if ($f['event_id'] !== null) { $where[] = 'o.event_id=?'; $args[] = $f['event_id']; }
    if ($f['from']) { $where[] = 'o.created_at_utc>=?'; $args[] = $f['from']->format('Y-m-d 00:00:00'); }
    if ($f['to']) { $where[] = 'o.created_at_utc<?'; $args[] = $f['to']->modify('+1 day')->format('Y-m-d 00:00:00'); }
    $sql = 'SELECT o.id,LOWER(HEX(o.public_id)) AS public_id,o.event_id,e.title AS event_title,
            o.status,o.total_cop,o.currency,o.created_at_utc,
            (SELECT a.status FROM ticket_payment_attempts a WHERE a.order_id=o.id ORDER BY a.id DESC LIMIT 1) AS payment_status
            FROM ticket_orders o JOIN events e ON e.id=o.event_id WHERE '.implode(' AND ', $where).
            ' ORDER BY o.id DESC LIMIT 11 OFFSET ?';
    $stmt = $db->prepare($sql); $args[] = $f['page'] * 10;
    foreach ($args as $i => $value) $stmt->bindValue($i+1,$value,is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $more = count($orders) > 10;
    if ($more) array_pop($orders);
    if (!$orders) return ['orders'=>[],'has_more'=>false,'page'=>$f['page']];
    $ids = array_map(static fn($row) => (int)$row['id'], $orders);
    $items = $db->prepare('SELECT i.order_id,i.ticket_name_snapshot,i.quantity,
        LOWER(HEX(t.public_id)) AS ticket_id,t.status AS ticket_status,
        CASE WHEN c.id IS NULL THEN 0 ELSE 1 END AS credential_active
        FROM ticket_order_items i LEFT JOIN ticket_entries t ON t.order_item_id=i.id
        LEFT JOIN ticket_entry_credentials c ON c.entry_id=t.id AND c.status=\'active\'
        WHERE i.order_id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY i.id,t.unit_number');
    foreach ($ids as $i=>$id) $items->bindValue($i+1,$id,PDO::PARAM_INT);
    $items->execute();
    $byOrder=[];
    foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byOrder[(int)$row['order_id']][] = [
            'name'=>$row['ticket_name_snapshot'],'quantity'=>(int)$row['quantity'],
            'ticket_id'=>$row['ticket_id'], 'status'=>$row['ticket_status'] ?? 'pending',
            'qr_requires_original_token'=>(bool)$row['credential_active'],
        ];
    }
    foreach ($orders as &$order) {
        $id=(int)$order['id']; unset($order['id']);
        // #1950: the buyer's own order number, the same one in the Wompi reference BW7-<n>-….
        $order['order_number']=$id;
        $order['total_cop']=(int)$order['total_cop'];
        $order['items']=$byOrder[$id] ?? [];
    }
    unset($order);
    return ['orders'=>$orders,'has_more'=>$more,'page'=>$f['page']];
}

/** Only the original opaque credential can recover a scannable payload. */
function brvtalBuyerAccountQr(PDO $db, int $buyerId, string $ticketId, string $token): array
{
    if ($buyerId < 1 || !preg_match('/^[a-f0-9]{32}$/D', $ticketId)) {
        throw new DomainException('TICKET_UNAVAILABLE');
    }
    $q=$db->prepare('SELECT e.event_date FROM ticket_entries t
        JOIN ticket_order_items i ON i.id=t.order_item_id
        JOIN ticket_orders o ON o.id=i.order_id
        JOIN events e ON e.id=o.event_id
        WHERE t.public_id=UNHEX(?) AND o.buyer_id=? AND o.status=\'paid\'
        AND t.status=\'issued\' LIMIT 1');
    $q->execute([$ticketId,$buyerId]);
    $eventDate=$q->fetchColumn();
    if (!is_string($eventDate) || $eventDate === '') throw new DomainException('TICKET_UNAVAILABLE');
    $cutoff=new DateTimeImmutable($eventDate,new DateTimeZone('America/Bogota'))
        ->modify('+1 day')->setTimezone(new DateTimeZone('UTC'));
    return brvtal_ticket_issue_download($db,$buyerId,$ticketId,$token,$cutoff);
}
