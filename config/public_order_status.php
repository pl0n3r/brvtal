<?php
declare(strict_types=1);

require_once __DIR__ . '/public_ticket_checkout.php';

/**
 * #1958: purchase confirmation by opaque order token (128-bit public_id), no session.
 * Read-only. Exposes no personal data except a masked email so the buyer knows
 * where the tickets go. A pending order is reconciled with Wompi (#1952) first.
 */
function brvtal_public_order_mask_email(string $email): string
{
    $parts = explode('@', strtolower(trim($email)), 2);
    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
        return '';
    }
    return substr($parts[0], 0, 1) . '***@' . $parts[1];
}

/** @return array<string,mixed>|null */
function brvtal_public_order_status(PDO $db, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/D', $token)) {
        return null;
    }
    $q = $db->prepare('SELECT o.id,o.status,o.total_cop,o.event_id,e.title AS event_title,e.slug AS event_slug,b.email
        FROM ticket_orders o JOIN events e ON e.id=o.event_id JOIN ticket_buyers b ON b.id=o.buyer_id
        WHERE o.public_id=UNHEX(?) LIMIT 1');
    $q->execute([$token]);
    $order = $q->fetch(PDO::FETCH_ASSOC);
    if (!is_array($order)) {
        return null;
    }
    $status = brvtal_public_ticket_checkout_refreshed_status($db, $order) ?? (string)$order['status'];
    $items = $db->prepare('SELECT ticket_name_snapshot AS name,quantity FROM ticket_order_items
        WHERE order_id=? ORDER BY id');
    $items->execute([(int)$order['id']]);
    return [
        'order_number' => (int)$order['id'],
        'state' => match ($status) {
            'paid' => 'approved', 'failed' => 'failed', 'cancelled' => 'cancelled',
            'expired' => 'expired', 'refunded' => 'refunded', 'draft', 'pending' => 'pending',
            default => 'unverified',
        },
        'event_title' => (string)$order['event_title'],
        'event_url' => '/events/' . rawurlencode((string)$order['event_slug']),
        'total_cop' => (int)$order['total_cop'],
        'items' => array_map(
            static fn(array $row): array => ['name' => (string)$row['name'], 'quantity' => (int)$row['quantity']],
            $items->fetchAll(PDO::FETCH_ASSOC)
        ),
        'email_hint' => brvtal_public_order_mask_email((string)($order['email'] ?? '')),
    ];
}
