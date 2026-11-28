<?php
declare(strict_types=1);
require_once __DIR__ . '/ticketing_issue.php';
require_once __DIR__ . '/ticketing_admin_orders.php';

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
        $date = $value === '' ? null : DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $value,
            new DateTimeZone('America/Bogota')
        );
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

/**
 * Only a verified buyer can see all their orders. A paid-checkout guest
 * session must remain limited to its single server-issued checkout grant.
 */
function brvtalBuyerAccountCheckoutScope(PDO $db, int $buyerId): ?int
{
    if ($buyerId < 1) throw new DomainException('TICKET_UNAVAILABLE');
    $q = $db->prepare('SELECT status,email_verified_at FROM ticket_buyers WHERE id=? LIMIT 1');
    $q->execute([$buyerId]);
    $buyer = $q->fetch(PDO::FETCH_ASSOC);
    if (!is_array($buyer)) throw new DomainException('TICKET_UNAVAILABLE');
    if ($buyer['status'] === 'active' && $buyer['email_verified_at'] !== null) return null;
    if ($buyer['status'] !== 'guest' || $buyer['email_verified_at'] !== null
        || (int)($_SESSION['buyer_id'] ?? 0) !== $buyerId) {
        throw new DomainException('TICKET_UNAVAILABLE');
    }
    $order = (int)($_SESSION['buyer_checkout_new_order'] ?? 0);
    if ($order < 1) throw new DomainException('TICKET_UNAVAILABLE');
    $paid = $db->prepare("SELECT 1 FROM ticket_orders WHERE id=? AND buyer_id=? AND status='paid' LIMIT 1");
    $paid->execute([$order, $buyerId]);
    if ($paid->fetchColumn() === false) throw new DomainException('TICKET_UNAVAILABLE');
    return $order;
}

/** Keep non-financial receipts out of the purchase ledger and its pagination. */
function brvtalBuyerAccountCourtesies(PDO $db, int $buyerId, array $filters): array
{
    $rows = brvtal_ticket_issue_courtesy_rows($db, $buyerId, null, $filters);
    $more = count($rows) > 10;
    if ($more) array_pop($rows);
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $courtesies = [];
    foreach ($rows as $row) {
        $cutoff = brvtal_ticket_issue_courtesy_cutoff($row);
        $status = $row['status'];
        if ($status === 'issued' && (!$cutoff || $cutoff <= $now)) $status = 'expired';
        $active = $status === 'issued' && (bool)$row['qr_stored'];
        $courtesies[] = [
            'origin'=>'courtesy', 'status'=>$status, 'ticket_id'=>$row['ticket_id'],
            'event_id'=>(int)$row['event_id'], 'event_title'=>$row['event_title'],
            'event_date'=>$row['event_date'], 'venue'=>$row['venue'], 'name'=>$row['name'],
            'created_at_utc'=>$row['created_at_utc'],
            'qr_requires_original_token'=>$active, 'qr_stored'=>$active,
            'refundable'=>false,
        ];
    }
    return ['courtesies'=>$courtesies, 'courtesies_has_more'=>$more];
}

function brvtalBuyerAccountOrders(PDO $db, int $buyerId, array $f): array
{
    if ($buyerId < 1) throw new DomainException('AUTH_REQUIRED');
    $scope = brvtalBuyerAccountCheckoutScope($db, $buyerId);
    $where = ['o.buyer_id=?']; $args = [$buyerId];
    // Even inconsistent or foreign courtesy receipts cannot appear as purchases.
    if (brvtal_ticket_issue_has_courtesy_table($db)) {
        $where[] = 'NOT EXISTS (SELECT 1 FROM ticket_courtesy_invites ci WHERE ci.order_id=o.id)';
    }
    $courtesies = $scope === null ? brvtalBuyerAccountCourtesies($db, $buyerId, $f)
        : ['courtesies'=>[], 'courtesies_has_more'=>false];
    if ($scope !== null) { $where[] = 'o.id=?'; $args[] = $scope; }
    if ($f['event_id'] !== null) { $where[] = 'o.event_id=?'; $args[] = $f['event_id']; }
    if ($f['from']) {
        $where[] = 'o.created_at_utc>=?';
        $args[] = brvtalBuyerAccountCalendarBound($f['from'], false);
    }
    if ($f['to']) {
        $where[] = 'o.created_at_utc<?';
        $args[] = brvtalBuyerAccountCalendarBound($f['to'], true);
    }
    // Only expose a server-owned incident, never trust client cancellation flags.
    // No dependency on unapplied migration on ordinary production installations.
    $causeColumn=(getenv('BRVTAL_TICKET_REFUNDS_POLICY_ENABLED')==='1')
        ? '(SELECT d.cause FROM ticket_refund_event_decisions d WHERE d.event_id=o.event_id) AS refund_cause'
        : 'NULL AS refund_cause';
    $sql = 'SELECT o.id,LOWER(HEX(o.public_id)) AS public_id,o.event_id,e.title AS event_title,e.event_date,e.venue,
            o.status,o.total_cop,o.currency,o.created_at_utc,'
            .$causeColumn.'
            FROM ticket_orders o JOIN events e ON e.id=o.event_id WHERE '.implode(' AND ', $where).
            ' ORDER BY o.id DESC LIMIT 11 OFFSET ?';
    $stmt = $db->prepare($sql); $args[] = $f['page'] * 10;
    foreach ($args as $i => $value) $stmt->bindValue($i+1,$value,is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $more = count($orders) > 10;
    if ($more) array_pop($orders);
    if (!$orders) return ['orders'=>[],'has_more'=>false,'page'=>$f['page']] + $courtesies;
    $ids = array_map(static fn($row) => (int)$row['id'], $orders);
    $encryptedAvailable = brvtal_ticket_issue_has_encrypted_column($db);
    // EXISTS avoids multiplying entries by credential history.
    $activeSql = "EXISTS (SELECT 1 FROM ticket_entry_credentials c WHERE c.entry_id=t.id AND c.status='active')";
    $storedSql = $encryptedAvailable
        ? "EXISTS (SELECT 1 FROM ticket_entry_credentials c WHERE c.entry_id=t.id "
            . "AND c.status='active' AND c.token_encrypted IS NOT NULL)"
        : '0';
    $items = $db->prepare('SELECT i.id AS item_id,i.order_id,i.ticket_name_snapshot,i.quantity,
        LOWER(HEX(t.public_id)) AS ticket_id,t.status AS ticket_status,
        ' . $activeSql . ' AS credential_active, ' . $storedSql . ' AS qr_stored
        FROM ticket_order_items i LEFT JOIN ticket_entries t ON t.order_item_id=i.id
        WHERE i.order_id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY i.id,t.unit_number');
    foreach ($ids as $i=>$id) $items->bindValue($i+1,$id,PDO::PARAM_INT);
    $items->execute();
    $lines = [];
    foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = (int)$row['item_id'];
        $lines[$key] ??= ['order_id'=>(int)$row['order_id'], 'name'=>$row['ticket_name_snapshot'],
            'quantity'=>(int)$row['quantity'], 'entries'=>[]];
        if ($row['ticket_id'] === null) {
            continue;
        }
        $active = $row['ticket_status'] === 'issued' && (bool)$row['credential_active'];
        $lines[$key]['entries'][] = [
            'name'=>$row['ticket_name_snapshot'], 'quantity'=>1,
            'ticket_id'=>$row['ticket_id'], 'status'=>$row['ticket_status'],
            'qr_requires_original_token'=>$active, 'qr_stored'=>$active && (bool)$row['qr_stored'],
        ];
    }
    $byOrder = [];
    foreach ($lines as $line) {
        $orderId = $line['order_id'];
        foreach ($line['entries'] as $entry) {
            $byOrder[$orderId][] = $entry;
        }
        $remaining = $line['quantity'] - count($line['entries']);
        if ($remaining > 0) {
            $byOrder[$orderId][] = ['name'=>$line['name'], 'quantity'=>$remaining,
                'ticket_id'=>null, 'status'=>'pending', 'qr_requires_original_token'=>false, 'qr_stored'=>false];
        }
    }
    $paymentSchema = brvtalTicketAdminPaymentSchema($db);
    foreach ($orders as &$order) {
        $id=(int)$order['id'];
        // Reuse #2041 proof without exposing internal reasons or provider identifiers.
        try {
            $proof = brvtalTicketAdminPaymentEvidence($db, $order, $paymentSchema);
        } catch (PDOException $e) {
            if (!in_array($e->getCode(), ['42S02','42S22'], true)) {
                throw $e;
            }
            $proof = ['channel'=>'unknown','state'=>'unavailable'];
        }
        $order['payment_channel'] = $proof['channel'];
        $order['payment_status'] = $proof['state'];
        unset($order['id']);
        // #1950: the buyer's own order number, the same one in the Wompi reference BW7-<n>-….
        $order['order_number']=$id;
        $order['total_cop']=(int)$order['total_cop'];
        $order['items']=$byOrder[$id] ?? [];
    }
    unset($order);
    return ['orders'=>$orders,'has_more'=>$more,'page'=>$f['page']] + $courtesies;
}

/** Only the original opaque credential can recover a scannable payload. */
function brvtalBuyerAccountQr(PDO $db, int $buyerId, string $ticketId, string $token): array
{
    if ($buyerId < 1 || !preg_match('/^[a-f0-9]{32}$/D', $ticketId)) {
        throw new DomainException('TICKET_UNAVAILABLE');
    }
    $scope = brvtalBuyerAccountCheckoutScope($db, $buyerId);
    $q=$db->prepare('SELECT e.event_date FROM ticket_entries t
        JOIN ticket_order_items i ON i.id=t.order_item_id
        JOIN ticket_orders o ON o.id=i.order_id
        JOIN events e ON e.id=o.event_id
        WHERE t.public_id=UNHEX(?) AND o.buyer_id=? AND o.status=\'paid\'
        AND t.status=\'issued\'' . ($scope === null ? '' : ' AND o.id=?') . ' LIMIT 1');
    $args = [$ticketId, $buyerId];
    if ($scope !== null) $args[] = $scope;
    $q->execute($args);
    $eventDate=$q->fetchColumn();
    if (!is_string($eventDate) || $eventDate === '') {
        $courtesy = $scope === null ? (brvtal_ticket_issue_courtesy_rows($db, $buyerId, $ticketId)[0] ?? null) : null;
        $cutoff = $courtesy === null ? null : brvtal_ticket_issue_courtesy_cutoff($courtesy);
        if (!$cutoff) throw new DomainException('TICKET_UNAVAILABLE');
        return brvtal_ticket_issue_download($db,$buyerId,$ticketId,$token,$cutoff);
    }
    $cutoff=new DateTimeImmutable($eventDate,new DateTimeZone('America/Bogota'))
        ->modify('+1 day')->setTimezone(new DateTimeZone('UTC'));
    return brvtal_ticket_issue_download($db,$buyerId,$ticketId,$token,$cutoff);
}

/** Account-only QR recovery, preserving the original-token path for historical entries. */
function brvtalBuyerAccountStoredQr(PDO $db, int $buyerId, string $ticketId): array
{
    if ($buyerId < 1 || !preg_match('/^[a-f0-9]{32}$/D', $ticketId)
        || !brvtal_ticket_issue_has_encrypted_column($db)) {
        throw new DomainException('TICKET_UNAVAILABLE');
    }
    $scope = brvtalBuyerAccountCheckoutScope($db, $buyerId);
    try {
        $stmt = $db->prepare("SELECT t.public_id,c.token_encrypted
            FROM ticket_entries t
            JOIN ticket_order_items i ON i.id=t.order_item_id
            JOIN ticket_orders o ON o.id=i.order_id
            JOIN ticket_entry_credentials c ON c.entry_id=t.id AND c.status='active'
            WHERE t.public_id=UNHEX(?) AND o.buyer_id=? AND o.status='paid'
              AND t.status='issued' AND t.event_id=o.event_id
              AND c.token_encrypted IS NOT NULL" . ($scope === null ? '' : ' AND o.id=?') . " LIMIT 1");
        $params = [$ticketId, $buyerId];
        if ($scope !== null) $params[] = $scope;
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException) {
        throw new DomainException('TICKET_UNAVAILABLE');
    }
    if (!is_array($row) && $scope === null) {
        $row = brvtal_ticket_issue_courtesy_rows($db, $buyerId, $ticketId)[0] ?? null;
    }
    if (!is_array($row) || !is_string($row['token_encrypted'] ?? null)
        || !is_string($row['public_id'] ?? null)) {
        throw new DomainException('TICKET_UNAVAILABLE');
    }
    $raw = brvtal_ticket_issue_open_token($row['token_encrypted'], $row['public_id']);
    $token = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    return brvtalBuyerAccountQr($db, $buyerId, $ticketId, $token);
}
