<?php
declare(strict_types=1);
require_once __DIR__.'/ticketing_promos.php';
require_once __DIR__.'/ticketing_finance.php';

/** Public referral aliases, not names, secrets, cookies or buyer identifiers. */
function brvtalPromoterCode(string $value): ?string {
    if (strlen($value)>64) return null;
    $value=strtoupper(trim($value));
    return preg_match('/^[A-Z0-9][A-Z0-9_-]{7,31}$/D',$value)===1 ? $value : null;
}

function brvtalPromoterCreate(PDO $db,string $key): int {
    brvtalPromoSynthetic($db);
    $key=brvtalPromoterCode($key);
    if ($key===null) throw new InvalidArgumentException('INVALID_PROMOTER_KEY');
    $db->prepare('INSERT INTO promoters (promoter_key) VALUES (?)')->execute([$key]);
    return (int)$db->lastInsertId();
}

function brvtalPromoterCreateCode(PDO $db,int $promoterId,string $code): int {
    brvtalPromoSynthetic($db);
    $code=brvtalPromoterCode($code);
    if ($promoterId<1 || $code===null) throw new InvalidArgumentException('INVALID_REFERRAL_CODE');
    $q=$db->prepare('INSERT INTO promoter_codes (promoter_id,code)
        SELECT id,? FROM promoters WHERE id=? AND active=1');
    $q->execute([$code,$promoterId]);
    if ($q->rowCount()!==1) throw new DomainException('PROMOTER_UNAVAILABLE');
    return (int)$db->lastInsertId();
}

function brvtalPromoterDeactivate(PDO $db,int $id): void {
    brvtalPromoSynthetic($db);
    if ($id<1) throw new InvalidArgumentException('INVALID_PROMOTER_ID');
    $db->prepare('UPDATE promoters SET active=0 WHERE id=?')->execute([$id]);
}

function brvtalPromoterDeactivateCode(PDO $db,int $promoterId,int $id): void {
    brvtalPromoSynthetic($db);
    if ($id<1 || $promoterId<1) throw new InvalidArgumentException('INVALID_REFERRAL_ID');
    $db->prepare('UPDATE promoter_codes SET active=0 WHERE id=? AND promoter_id=?')
        ->execute([$id,$promoterId]);
}

/** Trusted checkout caller supplies explicit codes oldest to newest inside its
 * order transaction. Freeze before the first payment attempt; never rewrite
 * paid history, or persist invalid/disabled aliases or browsing history.
 */
function brvtalPromoterAttribute(PDO $db,int $orderId,array $codes): ?int {
    brvtalPromoSynthetic($db);
    if (!$db->inTransaction() || $orderId<1 || !array_is_list($codes) || count($codes)>16) {
        throw new InvalidArgumentException('INVALID_ATTRIBUTION');
    }
    $q=$db->prepare('SELECT status,promoter_code_id FROM ticket_orders WHERE id=? FOR UPDATE');
    $q->execute([$orderId]);
    $order=$q->fetch(PDO::FETCH_ASSOC);
    if (!$order || !in_array($order['status'],['draft','pending'],true)) {
        throw new DomainException('ATTRIBUTION_FROZEN');
    }
    $q=$db->prepare('SELECT id FROM ticket_payment_attempts WHERE order_id=? LIMIT 1 FOR UPDATE');
    $q->execute([$orderId]);
    if ($q->fetchColumn()!==false) throw new DomainException('ATTRIBUTION_FROZEN');
    $query='SELECT c.id FROM promoter_codes c JOIN promoters p ON p.id=c.promoter_id
        WHERE c.active=1 AND p.active=1 AND ';
    $lookup=$db->prepare($query.'c.code=? FOR UPDATE');
    $selected=null;
    foreach (array_reverse($codes) as $raw) {
        $code=is_string($raw)?brvtalPromoterCode($raw):null;
        if ($code===null) continue;
        $lookup->execute([$code]);
        $id=$lookup->fetchColumn();
        if ($id!==false) { $selected=(int)$id; break; }
    }
    if ($selected===null && $order['promoter_code_id']!==null) {
        $lookup=$db->prepare($query.'c.id=? FOR UPDATE');
        $lookup->execute([$order['promoter_code_id']]);
        $id=$lookup->fetchColumn();
        if ($id!==false) $selected=(int)$id;
    }
    $db->prepare('UPDATE ticket_orders SET promoter_code_id=? WHERE id=?')->execute([$selected,$orderId]);
    return $selected;
}

/** One aggregate per code of the supplied trusted promoter scope, including
 * inactive historic codes. Gross is collected COP before confirmed refunds;
 * net is after refunds, not settlement or commission. No buyer/order output.
 */
function brvtalPromoterSales(PDO $db,int $promoterId): array {
    brvtalPromoSynthetic($db);
    if ($promoterId<1 || $db->inTransaction()) throw new InvalidArgumentException('INVALID_PROMOTER_SCOPE');
    $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
    $db->beginTransaction();
    try {
        $result=brvtalPromoterSalesSnapshot($db,$promoterId);
        $db->commit();
        return $result;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function brvtalPromoterSalesSnapshot(PDO $db,int $promoterId): array {
    brvtalPromoSynthetic($db);
    if (!$db->inTransaction() || $promoterId<1) throw new InvalidArgumentException('INVALID_PROMOTER_SCOPE');
    $schema=brvtalTicketAdminPaymentSchema($db);
    foreach (['pos_sales','pos_shifts','courtesy','wompi_events'] as $table) {
        if (!$schema[$table]) throw new DomainException('ATTRIBUTION_EVIDENCE_UNAVAILABLE');
    }
    if (!brvtalTicketAdminHasTable($db,'ticket_refunds') || !brvtalTicketAdminHasTable($db,'ticket_refund_adjustments')) {
        throw new DomainException('ATTRIBUTION_EVIDENCE_UNAVAILABLE');
    }
    $q=$db->prepare('SELECT id,code FROM promoter_codes WHERE promoter_id=? ORDER BY id LIMIT 5001');
    $q->execute([$promoterId]); $rows=$q->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows)>5000) throw new LengthException('ATTRIBUTION_RANGE_TOO_LARGE');
    $totals=[];
    foreach ($rows as $row) {
        $totals[(int)$row['id']]=['code'=>$row['code'],'paid_orders'=>0,'gross_cop'=>0,'net_cop'=>0];
    }
    $q=$db->prepare("SELECT o.id,o.event_id,o.status,o.currency,o.total_cop,o.promoter_code_id
        FROM ticket_orders o JOIN promoter_codes c ON c.id=o.promoter_code_id
        WHERE c.promoter_id=? AND o.status='paid' AND o.currency='COP'
        ORDER BY o.id LIMIT 5001");
    $q->execute([$promoterId]); $orders=$q->fetchAll(PDO::FETCH_ASSOC);
    if (count($orders)>5000) throw new LengthException('ATTRIBUTION_RANGE_TOO_LARGE');
    $refund=$db->prepare("SELECT r.id AS refund_id,r.event_id,a.amount_cop
        FROM ticket_refunds r LEFT JOIN ticket_refund_adjustments a ON a.refund_id=r.id
        AND a.order_id=r.order_id AND a.event_id=r.event_id AND a.amount_cop=r.amount_cop
        WHERE r.order_id=? AND r.status='refunded'");
    foreach ($orders as $order) {
        $amount=filter_var($order['total_cop'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if ($amount===false) throw new DomainException('ATTRIBUTION_EVIDENCE_UNAVAILABLE');
        $proof=brvtalTicketAdminPaymentEvidence($db,$order,$schema);
        if (!in_array($proof['state'],['verified','registered_offline'],true)) continue;
        $refund->execute([$order['id']]);
        $adjust=brvtalFinanceRefundAmounts($refund->fetchAll(PDO::FETCH_ASSOC),(int)$order['event_id'],$amount);
        if ($adjust===null) throw new DomainException('ATTRIBUTION_EVIDENCE_UNAVAILABLE');
        if ($adjust['total']===$amount) continue;
        $id=(int)$order['promoter_code_id'];
        $gross=brvtalFinanceSafeSum([$totals[$id]['gross_cop'],$amount]);
        $net=brvtalFinanceSafeSum([$totals[$id]['net_cop'],$amount-$adjust['total']]);
        if ($gross===null || $net===null) throw new LengthException('ATTRIBUTION_RANGE_TOO_LARGE');
        $totals[$id]['gross_cop']=$gross;
        $totals[$id]['net_cop']=$net;
        $totals[$id]['paid_orders']++;
    }
    return array_values($totals);
}
