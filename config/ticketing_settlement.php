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

/** #2198: amounts are integers from durable evidence, not estimates. */
function brvtalSettlementMoney(mixed $value): ?int
{
    if (!is_int($value) && !is_string($value)) return null;
    $number = filter_var($value, FILTER_VALIDATE_INT);
    return $number === false ? null : $number;
}

function brvtalSettlementAdd(?int $a, ?int $b): ?int
{
    if ($a === null || $b === null) return null;
    $sum = $a + $b;
    return is_int($sum) ? $sum : null;
}

/** A reference is counted once across ALL active payouts, never once per line. */
function brvtalSettlementProject(array $lines, array $evidence): array
{
    $keys = ['gross_cop','fee_cop','fee_vat_cop','withheld_cop','adjustment_cop','credited_cop'];
    $groups = [];
    foreach ($lines as $line) {
        $ref = (string)$line['transaction_reference'];
        $groups[$ref][] = $line;
    }
    $proofs = [];
    foreach ($evidence as $row) $proofs[(string)$row['reference']][] = $row;
    $result = [];
    foreach ($groups as $ref => $rows) {
        $totals = array_fill_keys($keys, 0);
        $matches = $proofs[$ref] ?? [];
        $proof = count($matches) === 1 ? $matches[0] : null;
        $expected = array_fill_keys($keys, null);
        if ($proof !== null) {
            foreach ($keys as $key) $expected[$key] = brvtalSettlementMoney($proof[$key] ?? null);
        }
        $invalid = false;
        foreach ($rows as $line) {
            $values = [];
            foreach ($keys as $key) {
                $values[$key] = brvtalSettlementMoney($line[$key] ?? null);
                $totals[$key] = brvtalSettlementAdd($totals[$key], $values[$key]);
            }
            foreach (['fee_cop','fee_vat_cop','withheld_cop'] as $key) {
                if ($values[$key] !== null && $values[$key] < 0) $invalid = true;
            }
            if ($values['adjustment_cop'] !== null && $values['adjustment_cop'] !== 0) $invalid = true;
            if ($proof !== null && $values['gross_cop'] !== null
                && (($proof['kind'] === 'payment' && $values['gross_cop'] <= 0)
                || ($proof['kind'] === 'refund' && $values['gross_cop'] >= 0))) $invalid = true;
            if (in_array(null, $values, true)) continue;
            $net = $values['gross_cop'] - $values['fee_cop'] - $values['fee_vat_cop']
                - $values['withheld_cop'] + $values['adjustment_cop'];
            if ($net !== $values['credited_cop']) $invalid = true;
        }
        $difference = $expected['credited_cop'] === null || $totals['credited_cop'] === null
            ? null : brvtalSettlementMoney($totals['credited_cop'] - $expected['credited_cop']);
        $status = 'pending';
        if ($invalid || count($matches) > 1) $status = 'discrepancy';
        elseif ($proof !== null && !in_array(null,$expected,true) && !in_array(null,$totals,true)) {
            $expectedNet = $expected['gross_cop'] - $expected['fee_cop'] - $expected['fee_vat_cop']
                - $expected['withheld_cop'] + $expected['adjustment_cop'];
            $compatible = $expectedNet === $expected['credited_cop'];
            foreach ($keys as $key) {
                $compatible = $compatible && $totals[$key] >= min(0,$expected[$key])
                    && $totals[$key] <= max(0,$expected[$key]);
            }
            if (!$compatible) $status = 'discrepancy';
            elseif ($totals === $expected) $status = 'reconciled';
            elseif (abs($totals['gross_cop']) < abs($expected['gross_cop'])) $status = 'partial';
            else $status = 'discrepancy';
        }
        $result[] = ['reference'=>(string)$ref,'kind'=>$proof['kind']??null,
            'source_id'=>$proof['source_id']??null,'order_id'=>$proof['order_id']??null,'status'=>$status,
            'expected'=>$expected,'observed'=>$totals,'difference_cop'=>$difference,'lines'=>$rows];
    }
    return $result;
}

/** Read-only matching of the synthetic settlement contract; never mutates sales.
 * Signed APPROVED payment proof and confirmed refund adjustment are mandatory.
 * A refund uses its own provider refund ID, not the original payment ID.
 * Synthetic refund rows describe the confirmed principal adjustment only;
 * no extra fee, VAT or retention is accepted without a separate contract.
 * Nonzero CSV adjustments remain auditable via import evidence but are not
 * silently recognized without an independent adjustment contract.
 */
function brvtalSettlementReconcile(PDO $db, int $actorId): array
{
    return brvtalSettlementTransaction($db,$actorId,static function () use ($db): array {
        $lines = $db->query("SELECT l.*,s.payout_reference,s.file_sha256,s.imported_by,s.imported_at_utc
            FROM ticket_settlement_lines l JOIN ticket_settlements s ON s.id=l.settlement_id
            WHERE s.status='imported' ORDER BY l.id")->fetchAll(PDO::FETCH_ASSOC);
        if (!$lines) return [];
        $active = "EXISTS (SELECT 1 FROM ticket_settlement_lines l JOIN ticket_settlements s
            ON s.id=l.settlement_id WHERE s.status='imported' AND l.transaction_reference=BINARY ";
        $verified = "a.provider='wompi' AND a.status IN ('approved','refunded')
            AND a.gross_amount_cop=o.total_cop AND o.currency='COP'
            AND o.status IN ('paid','refunded') AND EXISTS (SELECT 1 FROM ticket_payment_webhook_events w
            WHERE w.attempt_id=a.id AND w.provider_transaction_id=a.provider_transaction_id
            AND w.signed_status='APPROVED')";
        $payments = $db->query("SELECT a.provider_transaction_id AS reference,'payment' AS kind,a.id AS source_id,a.order_id,
            a.gross_amount_cop AS gross_cop,a.fee_amount_cop AS fee_cop,a.fee_vat_cop,
            a.withheld_amount_cop AS withheld_cop,0 AS adjustment_cop,a.net_amount_cop AS credited_cop
            FROM ticket_payment_attempts a JOIN ticket_orders o ON o.id=a.order_id
            WHERE $verified AND $active a.provider_transaction_id)")->fetchAll(PDO::FETCH_ASSOC);
        $refunds = $db->query("SELECT r.provider_refund_id AS reference,'refund' AS kind,r.id AS source_id,r.order_id,
            -CAST(x.amount_cop AS SIGNED) AS gross_cop,0 AS fee_cop,0 AS fee_vat_cop,
            0 AS withheld_cop,0 AS adjustment_cop,-CAST(x.amount_cop AS SIGNED) AS credited_cop
            FROM ticket_refunds r JOIN ticket_refund_adjustments x ON x.refund_id=r.id
            JOIN ticket_orders o ON o.id=r.order_id
            WHERE r.status='refunded' AND x.order_id=r.order_id AND x.event_id=r.event_id
            AND r.event_id=o.event_id AND x.amount_cop=r.amount_cop AND x.amount_cop>0
            AND x.amount_cop<=o.total_cop AND $active r.provider_refund_id)
            AND 1=(SELECT COUNT(*) FROM ticket_payment_attempts a WHERE a.order_id=o.id AND $verified)")->fetchAll(PDO::FETCH_ASSOC);
        return brvtalSettlementProject($lines,array_merge($payments,$refunds));
    });
}
