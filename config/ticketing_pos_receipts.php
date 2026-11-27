<?php
declare(strict_types=1);

function brvtalPosReceiptMethod(string $method): string
{
    if (!in_array($method, ['transfer', 'nequi', 'daviplata', 'terminal'], true)) {
        throw new InvalidArgumentException('POS_RECEIPT_METHOD');
    }
    return $method;
}

function brvtalPosReceiptNormalize(string $reference): string
{
    // Preserve significant zeroes and punctuation; follow the existing POS ASCII contract.
    if (!preg_match('/\A[ A-Za-z0-9._\/-]{1,160}\z/D', $reference)) {
        throw new InvalidArgumentException('POS_RECEIPT_REFERENCE');
    }
    $normalized = strtoupper((string)preg_replace('/ +/', ' ', trim($reference, ' ')));
    if (!preg_match('/\A[A-Z0-9][A-Z0-9 ._\/-]{3,79}\z/D', $normalized)) {
        throw new InvalidArgumentException('POS_RECEIPT_REFERENCE');
    }
    return $normalized;
}

function brvtalPosDestinationAlias(string $alias): string
{
    $alias = trim($alias, ' ');
    if (!preg_match('/\A[\p{L}\p{M}0-9 ._·…\-]{1,80}\z/uD', $alias)
        || preg_match_all('/[0-9]/', $alias) > 4 || !preg_match('/\p{L}/u', $alias)) {
        throw new InvalidArgumentException('POS_DESTINATION_ALIAS');
    }
    return $alias;
}

function brvtalPosDestinationRequireOwner(PDO $db, int $actor): void
{
    $q = $db->prepare("SELECT id FROM admins WHERE id=? AND staff_role='owner' AND is_active=1 FOR UPDATE");
    $q->execute([$actor]);
    if (!$q->fetchColumn()) throw new DomainException('POS_DESTINATION_FORBIDDEN');
}

function brvtalPosDestinations(PDO $db, string $method): array
{
    $q = $db->prepare('SELECT id,method,alias FROM ticket_pos_payment_destinations WHERE method=? AND is_active=1 ORDER BY alias,id');
    $q->execute([brvtalPosReceiptMethod($method)]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

/** Catalog mutation and actor check share one transaction. */
function brvtalPosDestinationWrite(PDO $db, int $actor, callable $operation): mixed
{
    if ($db->inTransaction()) throw new LogicException('POS_DESTINATION_TRANSACTION');
    $db->beginTransaction();
    try {
        brvtalPosDestinationRequireOwner($db, $actor);
        $result = $operation();
        $db->commit();
        return $result;
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function brvtalPosDestinationCreate(PDO $db, int $actor, string $method, string $alias): int
{
    $method = brvtalPosReceiptMethod($method);
    $alias = brvtalPosDestinationAlias($alias);
    return brvtalPosDestinationWrite($db, $actor, static function () use ($db, $actor, $method, $alias): int {
        $db->prepare('INSERT INTO ticket_pos_payment_destinations (method,alias,created_by_admin_id) VALUES (?,?,?)')
            ->execute([$method, $alias, $actor]);
        return (int)$db->lastInsertId();
    });
}

function brvtalPosDestinationDeactivate(PDO $db, int $actor, int $destination): void
{
    brvtalPosDestinationWrite($db, $actor, static function () use ($db, $actor, $destination): void {
        $q = $db->prepare('UPDATE ticket_pos_payment_destinations SET is_active=0,deactivated_by_admin_id=?,deactivated_at_utc=UTC_TIMESTAMP(6) WHERE id=? AND is_active=1');
        $q->execute([$actor, $destination]);
        if ($q->rowCount() !== 1) throw new DomainException('POS_DESTINATION_UNAVAILABLE');
    });
}

function brvtalPosReceiptFind(PDO $db, string $method, int $destination, string $reference): ?array
{
    $q = $db->prepare('SELECT order_id,method,destination_id,normalized_reference,request_hash,created_at_utc FROM ticket_pos_payment_receipts WHERE destination_id=? AND method=? AND normalized_reference=?');
    $q->execute([$destination, brvtalPosReceiptMethod($method), brvtalPosReceiptNormalize($reference)]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

/** Caller owns commit/rollback. A destination lock serializes against deactivation. */
function brvtalPosReceiptRegister(PDO $db, int $order, string $method, int $destination, string $reference, string $hash): void
{
    if (!$db->inTransaction()) throw new LogicException('POS_RECEIPT_TRANSACTION');
    $method = brvtalPosReceiptMethod($method);
    $reference = brvtalPosReceiptNormalize($reference);
    if ($order < 1 || !preg_match('/\A[a-f0-9]{64}\z/D', $hash)) throw new InvalidArgumentException('POS_RECEIPT_INTENT');
    $q = $db->prepare('SELECT id FROM ticket_pos_payment_destinations WHERE id=? AND method=? AND is_active=1 FOR UPDATE');
    $q->execute([$destination, $method]);
    if (!$q->fetchColumn()) throw new DomainException('POS_DESTINATION_UNAVAILABLE');
    $db->prepare('INSERT INTO ticket_pos_payment_receipts (order_id,method,destination_id,normalized_reference,request_hash) VALUES (?,?,?,?,?)')
        ->execute([$order, $method, $destination, $reference, $hash]);
}
