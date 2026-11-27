<?php
declare(strict_types=1);
require_once __DIR__ . '/ticketing_staff_permissions.php';

/** #2197: synthetic CSV v1, ASCII comma-separated, no quotes or free text.
 * One payout per file; each row has a distinct line reference. COP integer
 * amounts are signed (refunds/adjustments); blank means unknown, not zero.
 * credited_cop is mandatory evidence from the fixture, never calculated.
 */
function brvtalSettlementParse(string $csv): array
{
    if (strlen($csv) > 1048576) throw new LengthException('SETTLEMENT_FILE_TOO_LARGE');
    $lines = preg_split('/\r\n|\n/', $csv);
    if (end($lines) === '') array_pop($lines);
    $header = 'payout_reference,payout_date,line_reference,transaction_reference,gross_cop,fee_cop,fee_vat_cop,withheld_cop,adjustment_cop,credited_cop';
    if (array_shift($lines) !== $header || !$lines) throw new InvalidArgumentException('SETTLEMENT_INVALID_HEADER');
    if (count($lines) > 5000) throw new LengthException('SETTLEMENT_TOO_MANY_ROWS');
    $payout = null; $date = null; $rows = []; $seen = [];
    foreach ($lines as $line) {
        $cells = explode(',', $line);
        if (count($cells) !== 10) throw new InvalidArgumentException('SETTLEMENT_INVALID_ROW');
        [$ref, $day, $lineRef, $tx] = $cells;
        foreach ([$ref, $lineRef, $tx] as $identifier) {
            // Reject formula prefixes and arbitrary text instead of changing evidence identifiers.
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $identifier)) {
                throw new InvalidArgumentException('SETTLEMENT_INVALID_REFERENCE');
            }
        }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $day, $parts)
            || (int)$parts[1] < 1000 || !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) {
            throw new InvalidArgumentException('SETTLEMENT_INVALID_DATE');
        }
        $payout ??= $ref; $date ??= $day;
        if ($payout !== $ref || $date !== $day || isset($seen[$lineRef])) {
            throw new InvalidArgumentException('SETTLEMENT_INCONSISTENT_ROWS');
        }
        $seen[$lineRef] = true;
        $amounts = [];
        foreach (array_slice($cells, 4) as $i => $value) {
            if ($value === '' && $i < 5) { $amounts[] = null; continue; }
            if (!preg_match('/^-?(?:0|[1-9][0-9]{0,11})$/D', $value)) {
                throw new InvalidArgumentException('SETTLEMENT_INVALID_AMOUNT');
            }
            $amounts[] = (int)$value;
        }
        $rows[] = [$lineRef, $tx, ...$amounts];
    }
    return ['reference'=>$payout, 'date'=>$date, 'sha256'=>hash('sha256', $csv), 'rows'=>$rows];
}

function brvtalSettlementTransaction(PDO $db, int $actorId, callable $action): mixed
{
    if ($db->inTransaction()) throw new LogicException('SETTLEMENT_REQUIRES_OWN_TRANSACTION');
    $db->beginTransaction();
    try {
        $q = $db->prepare('SELECT staff_role FROM admins WHERE id=? AND is_active=1 FOR UPDATE');
        $q->execute([$actorId]);
        $role = $q->fetchColumn();
        if (!is_string($role) || !brvtalTicketingCan($role, 'finance')) {
            throw new DomainException('SETTLEMENT_FORBIDDEN');
        }
        $result = $action();
        $db->commit();
        return $result;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/** No matching, payment mutation, provider communication or real CSV contract. */
function brvtalSettlementImport(PDO $db, int $actorId, string $csv): int
{
    $report = brvtalSettlementParse($csv);
    return brvtalSettlementTransaction($db, $actorId, static function () use ($db, $actorId, $report): int {
        $q = $db->prepare('INSERT INTO ticket_settlements
            (payout_reference,payout_date,file_sha256,imported_by,imported_at_utc) VALUES (?,?,?,?,UTC_TIMESTAMP(6))');
        try {
            $q->execute([$report['reference'],$report['date'],$report['sha256'],$actorId]);
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062) throw new DomainException('SETTLEMENT_DUPLICATE');
            throw $e;
        }
        $id = (int)$db->lastInsertId();
        $q = $db->prepare('INSERT INTO ticket_settlement_lines
            (settlement_id,line_reference,transaction_reference,gross_cop,fee_cop,fee_vat_cop,withheld_cop,adjustment_cop,credited_cop)
            VALUES (?,?,?,?,?,?,?,?,?)');
        foreach ($report['rows'] as $row) $q->execute([$id,...$row]);
        return $id;
    });
}

/** Keep evidence and duplicate keys forever; rolled-back imports are not active. */
function brvtalSettlementRollback(PDO $db, int $actorId, int $id): void
{
    brvtalSettlementTransaction($db, $actorId, static function () use ($db, $actorId, $id): void {
        $q = $db->prepare("UPDATE ticket_settlements SET status='rolled_back', rolled_back_by=?,
            rolled_back_at_utc=UTC_TIMESTAMP(6) WHERE id=? AND status='imported'");
        $q->execute([$actorId,$id]);
        if ($q->rowCount() !== 1) throw new DomainException('SETTLEMENT_NOT_ACTIVE');
    });
}
