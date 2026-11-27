<?php
declare(strict_types=1);
require_once __DIR__ . '/ticketing_staff_permissions.php';
require_once __DIR__ . '/ticketing_mail_mode.php';

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
    if (!brvtalRecoverySynthetic($db)) return brvtalRecoveryLiveMetrics($db);
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
    if ($buyerId<1 || $eventId<1) return false;
    $q=$db->prepare('UPDATE ticket_recovery_consents SET revoked_at_utc=UTC_TIMESTAMP(6)
        WHERE buyer_id=? AND event_id=? AND revoked_at_utc IS NULL');
    $q->execute([$buyerId,$eventId]);
    return $q->rowCount()===1;
}


/** #1983: one marketing reminder per order, queued only by authorized staff. */
/** New commercial flow stays disabled after source deploy until deliberately enabled. */
function brvtalRecoveryEnabled(): bool
{
    return getenv('BRVTAL_RECOVERY_ENABLED') === '1';
}

function brvtalRecoveryWindow(): array
{
    $delay = getenv('BRVTAL_RECOVERY_DELAY_MINUTES');
    $window = getenv('BRVTAL_RECOVERY_WINDOW_MINUTES');
    $delay = $delay === false ? '5' : $delay; // checkout hold normally expires in 10 minutes
    $window = $window === false ? '1440' : $window;
    if (!ctype_digit($delay) || !ctype_digit($window)) return [0, 0];
    $min = (int)$delay; $max = (int)$window;
    return ($min >= 1 && $min <= 1440 && $max > $min && $max <= 10080)
        ? [$min, $max] : [0, 0];
}

/** Immutable, no tracking: the opaque /compra URL only exposes the existing order page. */
function brvtalRecoveryReminder(string $event, string $token): array
{
    if (!preg_match('/^[0-9a-f]{32}$/D', $token)) {
        throw new DomainException('RECOVERY_ORDER_TOKEN_INVALID');
    }
    $event = trim(preg_replace('/[\r\n\x00-\x1f]+/u', ' ', $event) ?? '');
    $event = mb_substr($event, 0, 120, 'UTF-8');
    if ($event === '') $event = 'tu evento';
    $link = 'https://www.brvtal.com.co/compra/' . $token;
    $optOut = 'https://www.brvtal.com.co/contact';
    $subject = 'BRVTAL | Tu compra sigue pendiente';
    $body = "Tu compra para {$event} todavía está pendiente.\n\n"
        . "Si deseas continuar, consulta tu pedido aquí:\n{$link}\n\n"
        . "Los cupos y precios pueden cambiar. Este mensaje no confirma el pago ni reserva boletas.\n\n"
        . "Recibes este recordatorio comercial porque aceptaste comunicaciones del evento. "
        . "Puedes solicitar la baja de estos mensajes en {$optOut}.\n\nBRVTAL";
    $safeEvent = htmlspecialchars($event, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeLink = htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = '<!doctype html><html lang="es"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;background:#111;color:#f7f7f7;font:16px/1.6 Arial,sans-serif">'
        . '<main style="max-width:600px;margin:auto;padding:32px 20px">'
        . '<h1 style="font-size:27px;margin:0 0 20px">Tu compra sigue pendiente</h1>'
        . '<p>Puedes volver a consultar tu compra para ' . $safeEvent . '.</p>'
        . '<p><a style="display:inline-block;padding:14px 18px;background:#ff2038;color:#090b0d;'
        . 'text-decoration:none;font-weight:bold" href="' . $safeLink . '">Consultar mi compra</a></p>'
        . '<p>Los cupos y precios pueden cambiar. Este aviso no confirma pagos ni reservas.</p>'
        . '<p style="font-size:13px;color:#b5bcc3">Es un recordatorio comercial solicitado. '
        . 'Puedes <a style="color:#f7f7f7" href="' . $optOut . '">solicitar la baja</a> '
        . 'de comunicaciones de marketing.</p>'
        . '<p>BRVTAL · RAVE TILL GRAVE</p></main></body></html>';
    return ['subject'=>$subject, 'text'=>$body, 'html'=>$html, 'link'=>$link];
}


/** The producer and pre-transport gate use the same locked order/consent snapshot. */
function brvtalRecoveryLockedOrder(PDO $db, int|string $orderId): array|false
{
    $q = $db->prepare('SELECT o.id,o.buyer_id,o.event_id,o.status,o.created_at_utc,o.expires_at_utc,
        LOWER(HEX(o.public_id)) AS public_token,b.email,b.status AS buyer_status,
        b.email_verified_at,c.policy_version,c.consented_at_utc,c.revoked_at_utc,
        c.expires_at_utc AS consent_expires,e.title AS event_title
        FROM ticket_orders o JOIN ticket_buyers b ON b.id=o.buyer_id
        JOIN events e ON e.id=o.event_id
        LEFT JOIN ticket_recovery_consents c ON c.buyer_id=o.buyer_id AND c.event_id=o.event_id
        WHERE o.id=? FOR UPDATE');
    $q->execute([$orderId]);
    return $q->fetch(PDO::FETCH_ASSOC);
}

function brvtalRecoveryEligibilityReason(PDO $db, array|false $order, int $minAge, int $maxAge): ?string
{
    if (!$order || $order['status'] !== 'pending') return 'order_not_pending';
    if (empty($order['expires_at_utc'])) return 'checkout_expired';
    if (empty($order['created_at_utc'])) return 'outside_window';
    $utc = new DateTimeZone('UTC');
    $now = new DateTimeImmutable('now', $utc);
    $created = new DateTimeImmutable((string)$order['created_at_utc'], $utc);
    $expiry = new DateTimeImmutable((string)$order['expires_at_utc'], $utc);
    if ($expiry <= $now) return 'checkout_expired';
    if ($created > $now->modify("-{$minAge} minutes")
        || $created < $now->modify("-{$maxAge} minutes")) {
        return 'outside_window';
    }
    $nowText = $now->format('Y-m-d H:i:s.u');
    if ($order['buyer_status'] !== 'active' || !$order['email_verified_at']
        || !filter_var($order['email'], FILTER_VALIDATE_EMAIL)
        || !is_string($order['policy_version'])
        || !preg_match('/^marketing-[a-z0-9._-]{1,30}$/D', $order['policy_version'])
        || !$order['consented_at_utc'] || (string)$order['consented_at_utc'] > $nowText
        || $order['revoked_at_utc'] !== null
        || !$order['consent_expires'] || (string)$order['consent_expires'] <= $nowText) {
        return 'consent_unavailable';
    }
    $payment = $db->prepare("SELECT id FROM ticket_payment_attempts
        WHERE order_id=? AND status='approved' LIMIT 1 FOR UPDATE");
    $payment->execute([$order['id']]);
    if ($payment->fetchColumn()) return 'payment_confirmed';
    return null;
}

/** Only a scoped transactional receipt disambiguates the historical shared key. */
function brvtalRecoveryDispatchReason(PDO $db, array $job, bool $historyReady): ?string
{
    if (!preg_match('/^order:([1-9][0-9]*):(abandoned_checkout|reminder)$/D',
        (string)$job['message_key'], $match)
        || $job['related_ref'] !== 'order:' . $match[1]) {
        return 'recovery_relation_invalid';
    }
    if ($match[2] === 'reminder') {
        if (!$historyReady) return 'legacy_reminder_undetermined';
        $proof = $db->prepare("SELECT 1 FROM ticket_mail_history h
            JOIN ticket_orders o ON o.id=h.order_id AND o.event_id=h.event_id AND o.buyer_id=h.buyer_id
            WHERE h.outbox_id=? AND h.message_key=? AND h.order_id=?
              AND h.template_key='reminder' AND h.actor_kind='system' LIMIT 1 FOR UPDATE");
        $proof->execute([$job['id'], $job['message_key'], $match[1]]);
        return $proof->fetchColumn() ? null : 'legacy_reminder_undetermined';
    }
    if (!brvtalRecoveryEnabled()) return 'recovery_disabled';
    [$minAge, $maxAge] = brvtalRecoveryWindow();
    if ($minAge === 0) return 'window_unavailable';
    $order = brvtalRecoveryLockedOrder($db, $match[1]);
    $reason = brvtalRecoveryEligibilityReason($db, $order, $minAge, $maxAge);
    if ($reason !== null) return $reason;
    // Never send to a stale queued address after the current buyer email changes.
    return strcasecmp((string)$order['email'], (string)$job['recipient_email']) === 0
        ? null : 'recovery_recipient_changed';
}

/** Only queue. The CLI mail worker performs delivery under existing transport gates. */
function brvtalRecoverySend(PDO $db, int $actor, string $role, array $input, ?array $mailState = null): array
{
    $input = brvtalRecoveryInput($input);
    if ($actor < 1 || !brvtalTicketingCan($role, 'mail')
        || !brvtalTicketingCan($role, 'buyers')) throw new DomainException('FORBIDDEN');
    if (!brvtalRecoveryEnabled()) {
        return ['state'=>'suppressed','reason'=>'recovery_disabled','queued'=>false];
    }
    $mode = $mailState ?? brvtal_ticket_mail_status();
    if (($mode['ready'] ?? false) !== true || !in_array($mode['mode'] ?? '', ['test','live'], true)) {
        return ['state'=>'suppressed', 'reason'=>'mail_unavailable', 'queued'=>false];
    }
    [$minAge, $maxAge] = brvtalRecoveryWindow();
    if ($minAge === 0) return ['state'=>'suppressed', 'reason'=>'window_unavailable', 'queued'=>false];

    $db->beginTransaction();
    try {
        $suppress = static function (string $reason) use ($db): array {
            $db->commit();
            return ['state'=>'suppressed','reason'=>$reason,'queued'=>false];
        };
        $order = brvtalRecoveryLockedOrder($db, $input['order_id']);
        $reason = brvtalRecoveryEligibilityReason($db, $order, $minAge, $maxAge);
        if ($reason !== null) return $suppress($reason);
        $key = 'order:' . $input['order_id'] . ':abandoned_checkout';
        $prior = $db->prepare('SELECT id FROM ticket_mail_outbox WHERE message_key=? LIMIT 1 FOR UPDATE');
        $prior->execute([$key]);
        if ($prior->fetchColumn()) return $suppress('already_reminded');
        // The old key is shared with the paid-event template. Never infer its purpose
        // from the order's current state or replay a possibly delivered legacy message.
        $prior->execute(['order:' . $input['order_id'] . ':reminder']);
        if ($prior->fetchColumn()) return $suppress('legacy_reminder_undetermined');
        if (($mode['mode'] ?? '') === 'test') {
            if (!function_exists('brvtal_ticket_test_recipient_allowed')) {
                require_once __DIR__ . '/ticketing_resend.php';
            }
            if (!brvtal_ticket_test_recipient_allowed((string)$order['email'])) {
                return $suppress('test_recipient_blocked');
            }
        }
        $message = brvtalRecoveryReminder((string)$order['event_title'], (string)$order['public_token']);
        if (!function_exists('brvtal_ticket_mail_enqueue')) {
            require_once __DIR__ . '/ticketing_mail_outbox.php';
        }
        $mailId = brvtal_ticket_mail_enqueue($db, $key, 'order:' . $input['order_id'],
            (string)$order['email'], $message['subject'], $message['text'],
            $message['html'], 'reminder');
        $db->commit();
        return ['state'=>'queued', 'queued'=>true, 'replayed'=>false, 'mail_id'=>$mailId];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/** Aggregated operational results, without leaking buyer data or bearer URLs. */
function brvtalRecoveryLiveMetrics(PDO $db): array
{
    // A scoped history receipt proves an event reminder, not the old shared key alone.
    $historyReady = (int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ticket_mail_history'")->fetchColumn() === 1;
    $eventReceipt = $historyReady ? " AND NOT EXISTS (SELECT 1 FROM ticket_mail_history h
        WHERE h.outbox_id=m.id AND h.message_key=m.message_key AND h.order_id=o.id
          AND h.event_id=o.event_id AND h.buyer_id=o.buyer_id AND h.template_key='reminder'
          AND h.actor_kind='system')" : '';
    $sql = "SELECT o.event_id,
        SUM(m.message_key=CONCAT('order:',o.id,':abandoned_checkout')) AS reminders,
        SUM(m.message_key=CONCAT('order:',o.id,':abandoned_checkout') AND m.state IN ('queued','retry','sending')) AS queued,
        SUM(m.message_key=CONCAT('order:',o.id,':abandoned_checkout') AND m.state='accepted') AS accepted,
        SUM(m.message_key=CONCAT('order:',o.id,':abandoned_checkout') AND m.state IN ('failed','dead')) AS failed,
        SUM(m.message_key=CONCAT('order:',o.id,':reminder')) AS legacy_undetermined
        FROM ticket_mail_outbox m
        JOIN ticket_orders o ON m.related_ref=CONCAT('order:',o.id)
        WHERE m.message_key=CONCAT('order:',o.id,':abandoned_checkout')
           OR (m.message_key=CONCAT('order:',o.id,':reminder')" . $eventReceipt . ")
        GROUP BY o.event_id ORDER BY o.event_id";
    return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}
