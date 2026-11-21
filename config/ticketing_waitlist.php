<?php
declare(strict_types=1);

require_once __DIR__ . '/ticketing_inventory.php';
require_once __DIR__ . '/ticketing_mail_outbox.php';

/** T28 is opt-in and pre-live by default; no unapproved PII or provider writes. */
function brvtal_waitlist_enabled(): bool
{
    return getenv('BRVTAL_WAITLIST_TESTING') === '1'
        || (getenv('BRVTAL_WAITLIST_ENABLED') === '1'
            && getenv('BRVTAL_TICKET_MAIL_PRIVACY_APPROVED') === '1');
}

/**
 * Invitations cannot be activated merely by enabling signups. Until an
 * independently verified checkout-token redemption flow is deployed, offers
 * stay blocked. No self-attestation here replaces D-063 / release approval.
 */
function brvtal_waitlist_offers_enabled(): bool
{
    if (getenv('BRVTAL_WAITLIST_TESTING') === '1') {
        return true;
    }
    return brvtal_waitlist_enabled()
        && getenv('BRVTAL_WAITLIST_OFFERS_ENABLED') === '1'
        && getenv('BRVTAL_WAITLIST_REDEMPTION_VERIFIED') === '1';
}

/**
 * Testing flags must never turn a production database into a synthetic fixture.
 * Actual production operations remain protected by the independent privacy
 * and offer gates; tests may mutate only disposable brvtal_test* databases.
 */
function brvtal_waitlist_assert_test_storage(PDO $db): void
{
    if (getenv('BRVTAL_WAITLIST_TESTING') !== '1') return;
    if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
        throw new DomainException('WAITLIST_TEST_DATABASE_REQUIRED');
    }
    $database = $db->query('SELECT DATABASE()')->fetchColumn();
    if (!is_string($database)
        || preg_match('/^brvtal_test[A-Za-z0-9_]*$/D', $database) !== 1) {
        throw new DomainException('WAITLIST_TEST_DATABASE_REQUIRED');
    }
}

function brvtal_waitlist_clock(?DateTimeImmutable $now = null): DateTimeImmutable
{
    return ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('UTC'));
}

function brvtal_waitlist_token(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function brvtal_waitlist_subscribe(
    PDO $db, int $eventId, int $typeId, int $buyerId, string $consent,
    string $honeypot = '', ?DateTimeImmutable $now = null
): string {
    if (!brvtal_waitlist_enabled()) throw new DomainException('WAITLIST_DISABLED');
    if ($eventId < 1 || $typeId < 1 || $buyerId < 1 || $honeypot !== ''
        || $consent !== 'waitlist-v1') throw new InvalidArgumentException('WAITLIST_INVALID');
    if ($db->inTransaction()) throw new LogicException('WAITLIST_TRANSACTION_OWNED');
    brvtal_waitlist_assert_test_storage($db);
    $db->beginTransaction();
    try {
        // Same Event mutex as checkout T05. Never join cancelled, past or draft nights.
        $q = $db->prepare('SELECT status,event_date FROM events WHERE id=? FOR UPDATE');
        $q->execute([$eventId]);
        $event = $q->fetch(PDO::FETCH_ASSOC);
        $clock = brvtal_waitlist_clock($now);
        if (!$event || !in_array($event['status'],
            ['published','sold_out','tickets_available','last_tickets'], true)
            || (string)$event['event_date'] <= $clock->setTimezone(
                new DateTimeZone('America/Bogota'))->format('Y-m-d H:i:s')) {
            throw new DomainException('WAITLIST_EVENT_CLOSED');
        }
        $q = $db->prepare("SELECT id FROM event_ticket_types
            WHERE id=? AND event_id=? AND status='active' AND currency='COP'");
        $q->execute([$typeId,$eventId]);
        if (!$q->fetchColumn()) throw new DomainException('WAITLIST_TYPE_CLOSED');
        $q = $db->prepare('SELECT email,email_verified_at,status FROM ticket_buyers
            WHERE id=? FOR UPDATE');
        $q->execute([$buyerId]);
        $buyer = $q->fetch(PDO::FETCH_ASSOC);
        if (!$buyer || $buyer['status'] !== 'active'
            || !$buyer['email_verified_at']
            || !filter_var($buyer['email'], FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('WAITLIST_VERIFIED_BUYER_REQUIRED');
        }
        $q = $db->prepare('SELECT id,status FROM ticket_waitlist_entries
            WHERE event_id=? AND ticket_type_id=? AND buyer_id=? FOR UPDATE');
        $q->execute([$eventId,$typeId,$buyerId]);
        $existing = $q->fetch(PDO::FETCH_ASSOC);
        if ($existing && $existing['status'] !== 'unsubscribed') {
            $db->commit();
            return 'already_registered'; // No repeat email / double enrollment.
        }
        $q = $db->prepare('SELECT COUNT(*) FROM ticket_waitlist_entries
            WHERE buyer_id=? AND created_at_utc>DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 DAY)');
        $q->execute([$buyerId]);
        if (!$existing && (int)$q->fetchColumn() >= 5) {
            throw new DomainException('WAITLIST_RATE_LIMIT');
        }
        $token = brvtal_waitlist_token();
        $digest = hash('sha256', $token);
        if ($existing) {
            $id = (int)$existing['id'];
            $q = $db->prepare("UPDATE ticket_waitlist_entries SET
                status='waiting',unsubscribe_hash=?,offer_hash=NULL,
                offer_expires_at_utc=NULL,consent_version=?,consent_at_utc=?
                WHERE id=?");
            $q->execute([$digest,$consent,$clock->format('Y-m-d H:i:s.u'),$id]);
        } else {
            $q = $db->prepare('INSERT INTO ticket_waitlist_entries
                (event_id,ticket_type_id,buyer_id,consent_version,consent_at_utc,unsubscribe_hash)
                VALUES (?,?,?,?,?,?)');
            $q->execute([$eventId,$typeId,$buyerId,$consent,
                $clock->format('Y-m-d H:i:s.u'),$digest]);
            $id = (int)$db->lastInsertId();
        }
        brvtal_ticket_mail_enqueue($db,'waitlist:join:'.$id.':'.bin2hex(random_bytes(5)),
            'waitlist:'.$id,(string)$buyer['email'],'Lista de espera BRVTAL',
            "Confirmamos tu inscripción consentida. No es una compra ni reserva. ".
            "Para cancelar la inscripción y cualquier invitación, confirma tu baja en ".
            "https://www.brvtal.com.co/api/ticketing/waitlist.php?token=".$token);
        $db->commit();
        return 'waiting'; // No order, provider charge, hold or issued ticket.
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/** Hashed secret grants only unsubscribe, not login, purchase or an inventory hold. */
function brvtal_waitlist_unsubscribe(PDO $db, string $token): bool
{
    if (!preg_match('/^[A-Za-z0-9_-]{43}$/D', $token)) {
        throw new InvalidArgumentException('WAITLIST_INVALID_TOKEN');
    }
    if ($db->inTransaction()) throw new LogicException('WAITLIST_TRANSACTION_OWNED');
    brvtal_waitlist_assert_test_storage($db);
    $db->beginTransaction();
    try {
        $q = $db->prepare('SELECT id,status FROM ticket_waitlist_entries
            WHERE unsubscribe_hash=? FOR UPDATE');
        $q->execute([hash('sha256', $token)]);
        $entry = $q->fetch(PDO::FETCH_ASSOC);
        if (!$entry || in_array($entry['status'], ['unsubscribed','redeemed'], true)) {
            $db->commit();
            return false;
        }
        $id = (int)$entry['id'];
        $q = $db->prepare("UPDATE ticket_waitlist_entries
            SET status='unsubscribed',offer_hash=NULL,offer_expires_at_utc=NULL
            WHERE id=? AND status<>'unsubscribed'");
        $q->execute([$id]);
        if ($q->rowCount() !== 1) throw new DomainException('WAITLIST_UNSUBSCRIBE_CONFLICT');
        // Revoke ALL undelivered/retryable messages in the same transaction.
        // Already accepted provider messages cannot be recalled.
        $cancel = $db->prepare("UPDATE ticket_mail_outbox
            SET state='failed',last_error_code='consent_revoked',
                locked_until=NULL,updated_at=UTC_TIMESTAMP()
            WHERE related_ref=? AND state IN ('queued','retry','sending')");
        $cancel->execute(['waitlist:'.$id]);
        $db->commit();
        return true;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/**
 * Convert exactly one still-valid invitation into a paid checkout hold.
 * The caller owns the SAME checkout transaction and must roll it back on ANY
 * exception. No payment, ticket, message or commit happens in this helper.
 * The event mutex serializes competing checkout and admin offers across types.
 * The checkout entrypoint may call this only after its T22 claims are released.
 */
function brvtal_waitlist_redeem_into_hold(
    PDO $db, string $token, int $orderId, int $buyerId, int $typeId,
    ?DateTimeImmutable $now = null
): array {
    if (!brvtal_waitlist_offers_enabled()) {
        throw new DomainException('WAITLIST_OFFERS_DISABLED');
    }
    if (!preg_match('/^[A-Za-z0-9_-]{43}$/D', $token)
        || $orderId < 1 || $buyerId < 1 || $typeId < 1) {
        throw new InvalidArgumentException('WAITLIST_REDEMPTION_INVALID');
    }
    if (!$db->inTransaction()) {
        throw new LogicException('WAITLIST_CHECKOUT_TRANSACTION_REQUIRED');
    }
    brvtal_waitlist_assert_test_storage($db);

    // Match inventory_hold's lock order: first resolve the immutable mapping,
    // then lock the event BEFORE reading any mutable order or offer rows.
    $lookup = $db->prepare('SELECT event_id FROM ticket_orders WHERE id=?');
    $lookup->execute([$orderId]);
    $eventId = (int)$lookup->fetchColumn();
    if ($eventId < 1) throw new DomainException('WAITLIST_ORDER_NOT_ELIGIBLE');

    $lock = $db->prepare('SELECT id FROM events WHERE id=? FOR UPDATE');
    $lock->execute([$eventId]);
    if (!$lock->fetchColumn()) throw new DomainException('WAITLIST_EVENT_CLOSED');
    $order = $db->prepare('SELECT buyer_id,status FROM ticket_orders
        WHERE id=? AND event_id=? FOR UPDATE');
    $order->execute([$orderId, $eventId]);
    $current = $order->fetch(PDO::FETCH_ASSOC);
    if (!$current || (int)$current['buyer_id'] !== $buyerId
        || !in_array($current['status'], ['draft','pending'], true)) {
        throw new DomainException('WAITLIST_ORDER_NOT_ELIGIBLE');
    }

    $entry = $db->prepare("SELECT id,buyer_id,event_id,ticket_type_id,offer_expires_at_utc
        FROM ticket_waitlist_entries WHERE offer_hash=? AND status='offered' FOR UPDATE");
    $entry->execute([hash('sha256', $token)]);
    $offer = $entry->fetch(PDO::FETCH_ASSOC);
    $clock = brvtal_waitlist_clock($now);
    if (!$offer || (int)$offer['buyer_id'] !== $buyerId
        || (int)$offer['event_id'] !== $eventId
        || (int)$offer['ticket_type_id'] !== $typeId
        || (string)$offer['offer_expires_at_utc'] <= $clock->format('Y-m-d H:i:s.u')) {
        throw new DomainException('WAITLIST_OFFER_NOT_ELIGIBLE');
    }

    $buyer = $db->prepare('SELECT status,email_verified_at FROM ticket_buyers
        WHERE id=? FOR UPDATE');
    $buyer->execute([$buyerId]);
    $verified = $buyer->fetch(PDO::FETCH_ASSOC);
    if (!$verified || $verified['status'] !== 'active' || !$verified['email_verified_at']) {
        throw new DomainException('WAITLIST_VERIFIED_BUYER_REQUIRED');
    }

    // Release ONLY this offer's counted unit, then claim it in T05 within
    // the caller-owned transaction. An unsuccessful hold MUST roll back both.
    $mark = $db->prepare("UPDATE ticket_waitlist_entries
        SET status='redeemed',offer_hash=NULL,offer_expires_at_utc=NULL
        WHERE id=? AND status='offered'");
    $mark->execute([(int)$offer['id']]);
    if ($mark->rowCount() !== 1) throw new DomainException('WAITLIST_OFFER_COLLISION');
    return brvtal_ticket_inventory_hold($db, $orderId, $typeId, 1, 'paid', 600, $clock);
}

/** One private invitation at a time, serialized by T05 Event row lock. */
function brvtal_waitlist_offer(PDO $db, int $eventId, int $typeId, ?DateTimeImmutable $now = null): ?array
{
    if (!brvtal_waitlist_offers_enabled()) throw new DomainException('WAITLIST_OFFERS_DISABLED');
    if ($eventId < 1 || $typeId < 1 || $db->inTransaction()) {
        throw new InvalidArgumentException('WAITLIST_INVALID_OFFER');
    }
    brvtal_waitlist_assert_test_storage($db);
    $db->beginTransaction();
    try {
        $q=$db->prepare('SELECT e.id,e.status,e.event_date,e.published_at,
            i.capacity AS event_capacity FROM events e
            JOIN ticket_inventory_events i ON i.event_id=e.id WHERE e.id=? FOR UPDATE');
        $q->execute([$eventId]); $event=$q->fetch(PDO::FETCH_ASSOC);
        $q=$db->prepare('SELECT t.id,t.status,t.currency,t.available_from,t.available_until,
            i.capacity FROM event_ticket_types t
            JOIN ticket_inventory_types i ON i.ticket_type_id=t.id
            WHERE t.id=? AND t.event_id=? FOR UPDATE');
        $q->execute([$typeId,$eventId]); $type=$q->fetch(PDO::FETCH_ASSOC);
        $clock=brvtal_waitlist_clock($now);
        if (!$event || !$type || !brvtal_ticket_inventory_saleable($event,$type,$clock)) {
            throw new DomainException('WAITLIST_EVENT_CLOSED');
        }
        $utc=$clock->format('Y-m-d H:i:s.u');
        $q=$db->prepare("UPDATE ticket_waitlist_entries SET status='waiting',
            offer_hash=NULL,offer_expires_at_utc=NULL WHERE event_id=?
            AND status='offered' AND offer_expires_at_utc<=?");
        $q->execute([$eventId,$utc]);
        $eventUsage=brvtal_ticket_inventory_usage($db,$eventId,0,$utc);
        $typeUsage=brvtal_ticket_inventory_usage($db,$eventId,$typeId,$utc);
        if (array_sum($eventUsage)>=(int)$event['event_capacity']
            || array_sum($typeUsage)>=(int)$type['capacity']) {
            $db->commit();
            return null;
        }
        $q=$db->prepare("SELECT w.id,b.email FROM ticket_waitlist_entries w
            JOIN ticket_buyers b ON b.id=w.buyer_id
            WHERE w.event_id=? AND w.ticket_type_id=? AND w.status='waiting'
            AND b.status='active' AND b.email_verified_at IS NOT NULL
            ORDER BY w.created_at_utc,w.id LIMIT 1 FOR UPDATE");
        $q->execute([$eventId,$typeId]); $entry=$q->fetch(PDO::FETCH_ASSOC);
        if (!$entry) { $db->commit(); return null; }
        $token=brvtal_waitlist_token();
        $expiry=$clock->modify('+10 minutes')->format('Y-m-d H:i:s.u');
        $q=$db->prepare("UPDATE ticket_waitlist_entries
            SET status='offered',offer_hash=?,offer_expires_at_utc=?
            WHERE id=? AND status='waiting'");
        $q->execute([hash('sha256',$token),$expiry,(int)$entry['id']]);
        if ($q->rowCount()!==1) throw new DomainException('WAITLIST_OFFER_COLLISION');
        brvtal_ticket_mail_enqueue($db,'waitlist:offer:'.$entry['id'].':'.bin2hex(random_bytes(5)),
            'waitlist:'.$entry['id'],(string)$entry['email'],'Cupo liberado en BRVTAL',
            "Hay una invitación limitada hasta ".$expiry." UTC. Código: ".$token.
            ". No es una compra, un pago ni una boleta. El checkout requiere validación independiente.");
        $db->commit();
        return ['entry_id'=>(int)$entry['id'],'expires_at_utc'=>$expiry];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
