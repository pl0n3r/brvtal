<?php
declare(strict_types=1);
require_once __DIR__ . '/ticketing_staff_permissions.php';

/** Share one verified, read-only staff-role lookup across view and JSON controller. */
function brvtalRecoveryAdminIdentity(PDO $db): array
{
    $actor = (int)($_SESSION['admin_id'] ?? 0);
    if ($actor < 1) {
        return [0, ''];
    }
    $lookup = $db->prepare(
        'SELECT staff_role FROM admins WHERE id=? AND is_active=1 LIMIT 1'
    );
    $lookup->execute([$actor]);
    return [$actor, (string)($lookup->fetchColumn() ?: '')];
}

/** T29: explicit marketing opt-in, separate from terms/transactional mail. */
function brvtalRecoverySynthetic(PDO $db): bool {
    if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') return false;
    return preg_match('/^brvtal_test[a-zA-Z0-9_]*$/D',
        (string)$db->query('SELECT DATABASE()')->fetchColumn()) === 1;
}
function brvtalRecoveryInput(array $value): array {
    if (array_diff(array_keys($value), ['order_id','campaign','idempotency_key'])
        || !is_int($value['order_id'] ?? null) || $value['order_id'] < 1
        || !is_string($value['campaign'] ?? null)
        || !preg_match('/^[a-z0-9_-]{1,32}$/D', $value['campaign'])
        || !is_string($value['idempotency_key'] ?? null)
        || !preg_match('/^[A-Za-z0-9_-]{20,64}$/D', $value['idempotency_key'])) {
        throw new InvalidArgumentException('RECOVERY_INVALID_INPUT');
    }
    return $value;
}
function brvtalRecoveryTemplate(string $event, string $slug): array {
    $event = trim(preg_replace('/[\r\n\x00-\x1f]+/u', ' ', $event) ?? '');
    $event = mb_substr($event, 0, 120, 'UTF-8');
    $link = preg_match('/^[a-z0-9-]{1,100}$/D', $slug)
        ? 'https://www.brvtal.com.co/events/' . $slug
        : 'https://www.brvtal.com.co/';
    return ['subject'=>'¿Sigues interesado en ' . $event . '?',
        'text'=>'Mensaje publicitario de prueba, NO enviado. Puedes volver al evento y elegir de nuevo. '
          . 'Los cupos y precios deben verificarse de nuevo; no se recupera ninguna reserva, '
          . 'ni se procesa un pago. Puedes retirar tu consentimiento de marketing en cualquier momento.',
        'event_url'=>$link,'marketing'=>true,'sent'=>false,'restore_hold'=>false];
}
/** Only durable PREVIEWS. No provider, mail outbox, checkout or inventory writes. */
function brvtalRecoveryPlan(PDO $db, int $actor, string $role, array $input): array {
    $input = brvtalRecoveryInput($input);
    if ($actor < 1 || !brvtalTicketingCan($role, 'mail')
        || !brvtalTicketingCan($role, 'buyers')) throw new DomainException('FORBIDDEN');
    if (!brvtalRecoverySynthetic($db)) throw new DomainException('SYNTHETIC_ONLY');
    $db->beginTransaction();
    try {
        $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $db->exec('DELETE FROM ticket_recovery_previews WHERE retention_until_utc<=UTC_TIMESTAMP(6)');
        $q = $db->prepare('SELECT o.id,o.buyer_id,o.event_id,o.status,o.expires_at_utc,
            b.status AS buyer_status,b.email_verified_at,
            c.policy_version,c.consented_at_utc,c.revoked_at_utc,c.expires_at_utc AS consent_expires,
            e.title AS event_title,e.slug AS event_slug
            FROM ticket_orders o JOIN ticket_buyers b ON b.id=o.buyer_id
            JOIN events e ON e.id=o.event_id
            LEFT JOIN ticket_recovery_consents c ON c.buyer_id=o.buyer_id AND c.event_id=o.event_id
            WHERE o.id=? FOR UPDATE');
        $q->execute([$input['order_id']]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        $suppressed = static function (string $reason) use ($db): array {
            $db->commit();
            return ['state'=>'suppressed','reason'=>$reason,'queued'=>false,'sent'=>false];
        };
        if (!$row || !in_array($row['status'], ['draft','pending'], true)) return $suppressed('order_terminal');
        if ($row['buyer_status'] !== 'active' || empty($row['email_verified_at'])
            || !is_string($row['policy_version'])
            || !preg_match('/^marketing-[a-z0-9._-]{1,30}$/D', $row['policy_version'])
            || empty($row['consented_at_utc'])
            || (string)$row['consented_at_utc'] > $nowUtc
            || !empty($row['revoked_at_utc'])
            || (string)$row['consent_expires'] <= $nowUtc) {
            return $suppressed('consent_unavailable');
        }
        if (empty($row['expires_at_utc'])
            || (string)$row['expires_at_utc'] <= $nowUtc) return $suppressed('checkout_expired');
        $payment = $db->prepare("SELECT status FROM ticket_payment_attempts
            WHERE order_id=? AND status IN ('approved','pending','created') FOR UPDATE");
        $payment->execute([$input['order_id']]);
        $states = $payment->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('approved', $states, true)) return $suppressed('payment_confirmed');
        $stage = in_array('pending', $states, true) || in_array('created', $states, true)
            ? 'payment_open' : 'cart';
        $items = $db->prepare('SELECT ticket_type_id,quantity FROM ticket_order_items
            WHERE order_id=? ORDER BY id LIMIT 30');
        $items->execute([$input['order_id']]);
        $selection = $items->fetchAll(PDO::FETCH_ASSOC);
        if ($selection === []) return $suppressed('selection_unavailable');
        $idempotency = hash('sha256', $actor . ':' . $input['idempotency_key']);
        $lookup = $db->prepare('SELECT order_id,campaign_key,stage FROM ticket_recovery_previews
            WHERE request_hash=? FOR UPDATE');
        $lookup->execute([$idempotency]);
        $previous = $lookup->fetch(PDO::FETCH_ASSOC);
        if ($previous) {
            if ((int)$previous['order_id'] !== $input['order_id']
                || $previous['campaign_key'] !== $input['campaign'])
                throw new DomainException('RECOVERY_KEY_CONFLICT');
            $db->commit();
            return ['state'=>'planned','replayed'=>true,'queued'=>false,'sent'=>false,
                'stage'=>$previous['stage'],'restore_hold'=>false];
        }
        $rate = $db->prepare("SELECT COUNT(*) AS weekly,
            SUM(created_at_utc>DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 24 HOUR)) AS daily
            FROM ticket_recovery_previews WHERE buyer_id=? AND event_id=?
              AND created_at_utc>DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 7 DAY)");
        $rate->execute([(int)$row['buyer_id'],(int)$row['event_id']]);
        $counts = $rate->fetch(PDO::FETCH_ASSOC);
        if ((int)($counts['daily'] ?? 0)>0 || (int)($counts['weekly'] ?? 0)>=2)
            return $suppressed('frequency_limit');
        $insert = $db->prepare('INSERT INTO ticket_recovery_previews
            (order_id,buyer_id,event_id,campaign_key,request_hash,stage,retention_until_utc)
            VALUES (?,?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 30 DAY))');
        $insert->execute([$input['order_id'],$row['buyer_id'],$row['event_id'],
            $input['campaign'],$idempotency,$stage]);
        $db->commit();
        return ['state'=>'planned','replayed'=>false,'queued'=>false,'sent'=>false,
            'stage'=>$stage,'selection'=>$selection,'restore_hold'=>false,
            'template'=>brvtalRecoveryTemplate((string)$row['event_title'],(string)$row['event_slug'])];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
/** Aggregate only eligible *current* attempts, never paid, refunded, opted-out. */
function brvtalRecoveryMetrics(PDO $db): array {
    if (!brvtalRecoverySynthetic($db)) return [];
    $sql = "SELECT p.event_id,p.campaign_key,p.stage,COUNT(*) AS previews
        FROM ticket_recovery_previews p
        JOIN ticket_orders o ON o.id=p.order_id AND o.status IN ('draft','pending')
            AND o.expires_at_utc>UTC_TIMESTAMP(6)
        JOIN ticket_recovery_consents c ON c.buyer_id=p.buyer_id AND c.event_id=p.event_id
            AND c.revoked_at_utc IS NULL AND c.expires_at_utc>UTC_TIMESTAMP(6)
            AND c.consented_at_utc<=UTC_TIMESTAMP(6) AND c.policy_version LIKE 'marketing-%'
        JOIN ticket_buyers b ON b.id=p.buyer_id AND b.status='active'
            AND b.email_verified_at IS NOT NULL
        WHERE p.retention_until_utc>UTC_TIMESTAMP(6)
          AND NOT EXISTS (SELECT 1 FROM ticket_payment_attempts a
              WHERE a.order_id=o.id AND a.status='approved')
        GROUP BY p.event_id,p.campaign_key,p.stage ORDER BY p.event_id,p.campaign_key";
    return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}
/** Future authenticated buyer action may call this. Never exposed to staff. */
function brvtalRecoveryOptOut(PDO $db, int $buyerId, int $eventId): bool {
    if (!brvtalRecoverySynthetic($db) || $buyerId<1 || $eventId<1) return false;
    $q=$db->prepare('UPDATE ticket_recovery_consents SET revoked_at_utc=UTC_TIMESTAMP(6)
        WHERE buyer_id=? AND event_id=? AND revoked_at_utc IS NULL');
    $q->execute([$buyerId,$eventId]);
    return $q->rowCount()===1;
}
