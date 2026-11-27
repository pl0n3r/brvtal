<?php
declare(strict_types=1);

require_once __DIR__ . '/migration_reconcile.php';

/**
 * Internal projection only. Scope IDs must come from the caller's trusted
 * order/item/event context, never from an unauthenticated request. This is
 * neither an authorization decision nor a credential/transfer operation.
 */
function brvtal_ticket_entry_holder(PDO $db, int $entryId, int $eventId, int $orderId, int $itemId): array
{
    if (min($entryId, $eventId, $orderId, $itemId) < 1) {
        throw new DomainException('ENTRY_HOLDER_SCOPE_INVALID');
    }
    try {
        // Reuse the canonical structural proof: a missing or partial migration
        // is not evidence of a legacy entry. Do not cache proof across requests.
        if (!brvtalMigrationSchemaProof($db, 'migration_ticketing_entry_holders_01.sql')['complete']) {
            throw new RuntimeException('ENTRY_HOLDER_UNAVAILABLE');
        }
        $query = $db->prepare("SELECT e.status AS entry_status,o.status AS order_status,
            o.buyer_id AS financial_buyer_id,b.status AS financial_buyer_status,
            b.email_verified_at IS NOT NULL AS financial_verified,
            h.entry_id AS explicit_entry_id,h.revision,h.buyer_id AS explicit_buyer_id,
            hb.id AS explicit_buyer_exists,hb.status AS explicit_buyer_status,
            hb.email_verified_at IS NOT NULL AS explicit_verified
            FROM ticket_entries e
            JOIN ticket_order_items i ON i.id=e.order_item_id AND i.event_id=e.event_id
                AND i.ticket_type_id=e.ticket_type_id AND e.unit_number BETWEEN 1 AND i.quantity
            JOIN ticket_orders o ON o.id=i.order_id AND o.event_id=i.event_id
            JOIN events v ON v.id=e.event_id
            JOIN event_ticket_types t ON t.id=e.ticket_type_id AND t.event_id=e.event_id
            JOIN ticket_buyers b ON b.id=o.buyer_id
            LEFT JOIN ticket_entry_holders h ON h.entry_id=e.id
            LEFT JOIN ticket_buyers hb ON hb.id=h.buyer_id
            WHERE e.id=? AND e.event_id=? AND o.id=? AND i.id=? LIMIT 2");
        if (!$query->execute([$entryId, $eventId, $orderId, $itemId])) {
            throw new RuntimeException('ENTRY_HOLDER_UNAVAILABLE');
        }
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        // Do not disclose SQL, buyer information or credentials to the caller.
        throw new RuntimeException('ENTRY_HOLDER_UNAVAILABLE');
    }
    if (count($rows) !== 1) {
        throw new DomainException('ENTRY_HOLDER_SCOPE_INVALID');
    }
    $row = $rows[0];
    $explicit = $row['explicit_entry_id'] !== null;
    $financial = filter_var($row['financial_buyer_id'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    $holder = $explicit
        ? filter_var($row['explicit_buyer_id'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]])
        : $financial;
    $revision = $explicit
        ? filter_var($row['revision'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]])
        : 0;
    $status = $explicit ? $row['explicit_buyer_status'] : $row['financial_buyer_status'];
    if ($financial === false || $holder === false || $revision === false
        || ($explicit && $row['explicit_buyer_exists'] === null)
        || !in_array($status, ['guest','active','blocked'], true)) {
        throw new DomainException('ENTRY_HOLDER_SCOPE_INVALID');
    }
    return [
        'entry_id'=>$entryId, 'event_id'=>$eventId, 'order_id'=>$orderId, 'order_item_id'=>$itemId,
        'financial_buyer_id'=>$financial, 'holder_buyer_id'=>$holder, 'holder_revision'=>$revision,
        'holder_source'=>$explicit ? 'explicit' : 'legacy', 'holder_account_status'=>$status,
        'holder_verified'=>(bool)($explicit ? $row['explicit_verified'] : $row['financial_verified']),
        'entry_status'=>$row['entry_status'], 'order_status'=>$row['order_status'],
        // Necessary state only; not permission to view, download or rotate a QR.
        'credential_state_eligible'=>$row['entry_status']==='issued' && $row['order_status']==='paid',
    ];
}
