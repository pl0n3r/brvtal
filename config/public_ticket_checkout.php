<?php
declare(strict_types=1);

require_once __DIR__ . '/ticketing_inventory.php';
require_once __DIR__ . '/ticketing_wompi_client.php';
require_once __DIR__ . '/ticketing_stages.php';
require_once __DIR__ . '/ticketing_waitlist.php';
require_once __DIR__ . '/ticketing_promos.php';

/** Monetary snapshots are integer COP. Never trust a DOM/form price. */
function brvtal_ticket_checkout_price(mixed $price, int $quantity): int
{
    if (!is_numeric($price) || $quantity < 1 || $quantity > 20
        || (float)$price !== (float)(int)$price || (int)$price < 1
        || (int)$price > intdiv(10000000, $quantity)) {
        throw new DomainException('CHECKOUT_PRICE_INVALID');
    }
    return (int)$price * $quantity;
}

/** Public display data, derived from T05 inventory; no source schema => no sale. */
function brvtal_public_ticket_checkout_offers(PDO $db, int $eventId): array
{
    try {
        $settings = brvtal_ticket_wompi_config(); // Default disabled, NEVER live.
        $q = $db->prepare('SELECT e.id,e.status,e.event_date,e.published_at,
            c.capacity AS event_capacity FROM events e
            JOIN ticket_inventory_events c ON c.event_id=e.id WHERE e.id=?');
        $q->execute([$eventId]);
        $event = $q->fetch(PDO::FETCH_ASSOC);
        if (!$event || (int)$event['event_capacity'] < 1) return [];
        $q = $db->prepare('SELECT t.id,t.name,t.price,t.currency,t.status,
            t.available_from,t.available_until,c.capacity
            FROM event_ticket_types t JOIN ticket_inventory_types c ON c.ticket_type_id=t.id
            WHERE t.event_id=? ORDER BY t.sort_order,t.id LIMIT 30');
        $q->execute([$eventId]);
        $now = brvtal_ticket_inventory_now();
        $utc = $now->format('Y-m-d H:i:s.u');
        $eventUsage = brvtal_ticket_inventory_usage($db,$eventId,0,$utc);
        $eventFree = (int)$event['event_capacity'] - array_sum($eventUsage);
        $offers = [];
        $inviteMode = brvtal_waitlist_offers_enabled();
        // Display the same stage price as checkout instead of the legacy type
        // price. The final checkout re-quotes under the Event mutex.
        $stageTable = (int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ticket_type_stages'")->fetchColumn();
        $stageLookup = $stageTable ? $db->prepare('SELECT code,price_cop,cumulative_capacity,
            opens_at_local,closes_at_local FROM ticket_type_stages
            WHERE event_id=? AND ticket_type_id=?') : null;
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $type) {
            if (!brvtal_ticket_inventory_saleable($event,$type,$now)
                || (int)$type['capacity'] < 1) continue;
            try {
                $price = brvtal_ticket_checkout_price($type['price'],1);
            } catch (DomainException) {
                continue;
            }
            $usage = brvtal_ticket_inventory_usage($db,$eventId,(int)$type['id'],$utc);
            $free = min($eventFree,(int)$type['capacity'] - array_sum($usage));
            if ($stageLookup !== null) {
                $stageLookup->execute([$eventId,(int)$type['id']]);
                $stages = $stageLookup->fetchAll(PDO::FETCH_ASSOC);
                if ($stages) {
                    foreach ($stages as &$stage) {
                        $stage['price_cop'] = (int)$stage['price_cop'];
                        $stage['cumulative_capacity'] = (int)$stage['cumulative_capacity'];
                    }
                    unset($stage);
                    try {
                        $quote = brvtalTicketStageQuote($stages,array_sum($usage),
                            1,max(0,$free),$now);
                        $price = $quote['unit_price_cop'];
                        $free = min($free,$quote['available']);
                    } catch (DomainException) {
                        if (!$inviteMode) continue;
                        $free = 0; // Invitation only; no payment quote is promised.
                    }
                }
            }
            if ($free < 1 && !$inviteMode) continue;
            $offers[] = [
                'id'=>(int)$type['id'], 'title'=>(string)$type['name'],
                'price_cop'=>$price, 'available'=>max(0,min(20,$free)),
                'invite_only'=>$free < 1,
            ];
        }
        return $offers ? ['offers'=>$offers, 'methods'=>$settings['methods'],
            'invite_mode'=>$inviteMode] : [];
    } catch (Throwable) {
        return []; // Missing schema, disabled merchant, query errors: legacy stays intact.
    }
}

/**
 * A configured stage is the authoritative price for new orders. The event row
 * mutex serializes stage selection with T05 inventory holds and price snapshots.
 * Missing stage tables preserve legacy pricing until the additive migration runs.
 */
function brvtal_public_ticket_checkout_stage_quote(
    PDO $db, int $eventId, int $typeId, int $quantity,
    ?DateTimeImmutable $clock = null, int $offeredUnit = 0
): ?array {
    if (!$db->inTransaction()) throw new LogicException('STAGE_TRANSACTION_REQUIRED');
    $q = $db->prepare('SELECT id FROM events WHERE id=? FOR UPDATE');
    $q->execute([$eventId]);
    if (!$q->fetchColumn()) throw new DomainException('CHECKOUT_UNAVAILABLE');
    $q = $db->query("SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ticket_type_stages'");
    if ((int)$q->fetchColumn() === 0) return null;
    $q = $db->prepare('SELECT code,price_cop,cumulative_capacity,opens_at_local,closes_at_local
        FROM ticket_type_stages WHERE event_id=? AND ticket_type_id=? FOR UPDATE');
    $q->execute([$eventId,$typeId]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return null;
    foreach ($rows as &$row) {
        $row['price_cop'] = (int)$row['price_cop'];
        $row['cumulative_capacity'] = (int)$row['cumulative_capacity'];
    }
    unset($row);
    $q = $db->prepare('SELECT capacity FROM ticket_inventory_events
        WHERE event_id=? FOR UPDATE');
    $q->execute([$eventId]);
    $eventCap = $q->fetchColumn();
    $q = $db->prepare('SELECT capacity FROM ticket_inventory_types
        WHERE event_id=? AND ticket_type_id=? FOR UPDATE');
    $q->execute([$eventId,$typeId]);
    $typeCap = $q->fetchColumn();
    if ($eventCap === false || $typeCap === false) {
        throw new DomainException('CHECKOUT_UNAVAILABLE');
    }
    $now = brvtal_ticket_inventory_now($clock);
    $utc = $now->format('Y-m-d H:i:s.u');
    $eventUsage = brvtal_ticket_inventory_usage($db,$eventId,0,$utc);
    $typeUsage = brvtal_ticket_inventory_usage($db,$eventId,$typeId,$utc);
    // A verified token converts this same T28 offered unit into a T05 hold.
    // Restore one slot only for pricing; invalid tokens roll the entire tx back.
    if ($offeredUnit < 0 || $offeredUnit > 1) {
        throw new InvalidArgumentException('CHECKOUT_OFFER_COUNT');
    }
    $used = array_sum($typeUsage) - $offeredUnit;
    $free = min((int)$eventCap - array_sum($eventUsage) + $offeredUnit,
        (int)$typeCap - array_sum($typeUsage) + $offeredUnit);
    return brvtalTicketStageQuote($rows,max(0,$used),$quantity,max(0,$free),$now);
}

/** Transactional UI adapter: same idempotency key => one order + one hold. */
/**
 * #1929 cart: validate `lines` as 1..10 distinct ticket types with 1..20 units each and
 * at most 20 units in total. Returns lines sorted by ticket type id (canonical order).
 */
function brvtal_public_ticket_checkout_cart_lines(mixed $lines): array
{
    if (!is_array($lines) || !array_is_list($lines) || $lines === [] || count($lines) > 10) {
        throw new InvalidArgumentException('INVALID_CHECKOUT');
    }
    $byType = [];
    foreach ($lines as $line) {
        if (!is_array($line) || array_is_list($line) || count($line) !== 2
            || !is_int($line['ticket_type_id'] ?? null) || $line['ticket_type_id'] < 1
            || !is_int($line['quantity'] ?? null) || $line['quantity'] < 1 || $line['quantity'] > 20
            || isset($byType[$line['ticket_type_id']])) {
            throw new InvalidArgumentException('INVALID_CHECKOUT');
        }
        $byType[$line['ticket_type_id']] = $line['quantity'];
    }
    if (array_sum($byType) > 20) throw new InvalidArgumentException('INVALID_CHECKOUT');
    ksort($byType);
    return $byType;
}

/**
 * #1929 cart: one order with one item and one T05 hold per ticket type, in ONE
 * transaction. Prices and availability come from the server; a retry with the same
 * idempotency key must carry exactly the same lines. Promo codes and waitlist
 * invitations stay single-type and use brvtal_public_ticket_checkout_prepare().
 */
function brvtal_public_ticket_checkout_prepare_cart(PDO $db, int $eventId, int $buyerId, array $input): array
{
    $key = $input['idempotency_key'] ?? null;
    $method = $input['method'] ?? null;
    if ($buyerId < 1 || $eventId < 1 || count($input) !== 3
        || !is_string($key) || !preg_match('/^[A-Za-z0-9:_-]{16,128}$/D', $key) || !is_string($method)) {
        throw new InvalidArgumentException('INVALID_CHECKOUT');
    }
    $lines = brvtal_public_ticket_checkout_cart_lines($input['lines'] ?? null);
    $settings = brvtal_ticket_wompi_config();
    if (!in_array($method, $settings['methods'], true)) {
        throw new InvalidArgumentException('INVALID_CHECKOUT_METHOD');
    }
    $digest = hash('sha256', 't13|' . $buyerId . '|' . $eventId . '|' . $key);
    if ($db->inTransaction()) throw new LogicException('CHECKOUT_TRANSACTION_ALREADY_ACTIVE');
    brvtal_public_ticket_checkout_fast_locks($db);
    $db->beginTransaction();
    try {
        // Event row FIRST: the same global mutex order as T05 holds.
        $lock = $db->prepare('SELECT id FROM events WHERE id=? FOR UPDATE');
        $lock->execute([$eventId]);
        if (!$lock->fetchColumn()) throw new DomainException('CHECKOUT_UNAVAILABLE');
        $q = $db->prepare('SELECT id,status,event_id,buyer_id,expires_at_utc,total_cop FROM ticket_orders
            WHERE checkout_key_hash=? FOR UPDATE');
        $q->execute([$digest]);
        $prior = $q->fetch(PDO::FETCH_ASSOC);
        if ($prior && ((int)$prior['buyer_id'] !== $buyerId || (int)$prior['event_id'] !== $eventId
            || !in_array($prior['status'], ['draft','pending'], true))) {
            throw new DomainException('CHECKOUT_RETRY_CONFLICT');
        }
        if ($prior && (string)($prior['expires_at_utc'] ?? '') !== ''
            && (string)$prior['expires_at_utc'] <= gmdate('Y-m-d H:i:s')) {
            throw new DomainException('CHECKOUT_EXPIRED');
        }
        if ($prior) {
            // A retry binds the original immutable snapshot and the exact same lines.
            $orderId = (int)$prior['id'];
            $q = $db->prepare('SELECT ticket_type_id,quantity,unit_price_cop,subtotal_cop
                FROM ticket_order_items WHERE order_id=? ORDER BY ticket_type_id');
            $q->execute([$orderId]);
            $saved = [];
            $total = 0;
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $qty = (int)$item['quantity'];
                if (brvtal_ticket_checkout_price($item['unit_price_cop'], $qty) !== (int)$item['subtotal_cop']) {
                    throw new DomainException('CHECKOUT_RETRY_CONFLICT');
                }
                $saved[(int)$item['ticket_type_id']] = $qty;
                $total += (int)$item['subtotal_cop'];
            }
            if ($saved !== $lines || $total !== (int)$prior['total_cop']) {
                throw new DomainException('CHECKOUT_RETRY_CONFLICT');
            }
            brvtalPromoRejectUncodedReplay($db, $orderId);
        } else {
            $typeQuery = $db->prepare('SELECT id,name,price,currency FROM event_ticket_types
                WHERE id=? AND event_id=?');
            $items = [];
            $total = 0;
            foreach ($lines as $typeId => $quantity) {
                $typeQuery->execute([$typeId, $eventId]);
                $type = $typeQuery->fetch(PDO::FETCH_ASSOC);
                if (!$type || $type['currency'] !== 'COP') throw new DomainException('CHECKOUT_UNAVAILABLE');
                // The same transactional stage quote applies to each cart line.
                $quote = brvtal_public_ticket_checkout_stage_quote($db, $eventId, $typeId, $quantity);
                $price = $quote['unit_price_cop'] ?? $type['price'];
                $subtotal = brvtal_ticket_checkout_price($price, $quantity);
                $items[] = [$typeId, mb_substr((string)$type['name'], 0, 120), $quantity, (int)$price, $subtotal];
                $total += $subtotal;
            }
            if ($total > 10000000) throw new DomainException('CHECKOUT_UNAVAILABLE');
            $q = $db->prepare("INSERT INTO ticket_orders
                (public_id,buyer_id,event_id,checkout_key_hash,status,currency,total_cop,expires_at_utc)
                VALUES (?,?,?,?,'draft','COP',?,DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 10 MINUTE))");
            $q->execute([random_bytes(16), $buyerId, $eventId, $digest, $total]);
            $orderId = (int)$db->lastInsertId();
            $insert = $db->prepare("INSERT INTO ticket_order_items
                (order_id,event_id,ticket_type_id,ticket_name_snapshot,quantity,
                 unit_price_cop,subtotal_cop,currency) VALUES (?,?,?,?,?,?,?,'COP')");
            foreach ($items as [$typeId, $name, $quantity, $price, $subtotal]) {
                $insert->execute([$orderId, $eventId, $typeId, $name, $quantity, $price, $subtotal]);
            }
        }
        foreach ($lines as $typeId => $quantity) {
            try {
                // Matching idempotent retries reuse the existing holds.
                brvtal_ticket_inventory_hold($db, $orderId, $typeId, $quantity);
            } catch (DomainException $error) {
                if ($prior && $error->getMessage() === 'INVENTORY_HOLD_CONFLICT') {
                    throw new DomainException('CHECKOUT_EXPIRED', 0, $error);
                }
                throw $error;
            }
        }
        $checkout = brvtal_ticket_wompi_prepare($db, $buyerId, $orderId, $key, $method, $settings);
        $db->commit();
        return ['order_id'=>$orderId, 'checkout'=>$checkout];
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        if (in_array((int)($e->errorInfo[1] ?? 0), [1062, 1213, 1205], true)
            || (string)$e->getCode() === '40001') {
            throw new DomainException('CHECKOUT_RETRYABLE', 0, $e);
        }
        throw $e;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function brvtal_public_ticket_checkout_prepare(PDO $db, int $eventId, int $buyerId, array $input): array
{
    if (array_key_exists('lines', $input)) {
        return brvtal_public_ticket_checkout_prepare_cart($db, $eventId, $buyerId, $input);
    }
    $typeId = $input['ticket_type_id'] ?? null;
    $quantity = $input['quantity'] ?? null;
    $key = $input['idempotency_key'] ?? null;
    $method = $input['method'] ?? null;
    $withPromo = array_key_exists('promo_code',$input);
    $promoCode = $withPromo ? $input['promo_code'] : null;
    $withOffer = array_key_exists('waitlist_token', $input);
    $offerToken = $withOffer ? $input['waitlist_token'] : null;
    if ($buyerId < 1 || $eventId < 1 || !is_int($typeId) || $typeId < 1
        || !is_int($quantity) || $quantity < 1 || $quantity > 20
        || !is_string($key) || !preg_match('/^[A-Za-z0-9:_-]{16,128}$/D',$key)
        || !is_string($method) || count($input) !== (4 + (int)$withOffer + (int)$withPromo)
        || ($withPromo && ($withOffer || !is_string($promoCode) ||
            !preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{7,31}$/D',strtoupper(trim($promoCode)))))
        || ($withOffer && ($quantity !== 1 || !is_string($offerToken)
            || preg_match('/^[A-Za-z0-9_-]{43}$/D', $offerToken) !== 1))) {
        throw new InvalidArgumentException('INVALID_CHECKOUT');
    }
    if ($withOffer && !brvtal_waitlist_offers_enabled()) {
        throw new DomainException('WAITLIST_OFFERS_DISABLED');
    }
    // D-063: preparation only on isolated disposable DB until the owner
    // completes the privacy/go-live gate; no real coupon payments are enabled.
    if ($withPromo) brvtalPromoSynthetic($db);
    $settings = brvtal_ticket_wompi_config();
    if ($withPromo && $settings['mode'] === 'production') {
        throw new DomainException('PROMOS_NOT_ENABLED');
    }
    if (!in_array($method,$settings['methods'],true)) {
        throw new InvalidArgumentException('INVALID_CHECKOUT_METHOD');
    }
    $digest = hash('sha256','t13|'.$buyerId.'|'.$eventId.'|'.$key);
    if ($db->inTransaction()) throw new LogicException('CHECKOUT_TRANSACTION_ALREADY_ACTIVE');
    $promoBreakdown = null; // Defined on both first purchase and idempotent replay.
    brvtal_public_ticket_checkout_fast_locks($db);
    $db->beginTransaction();
    try {
        // T05/Event mutex FIRST for every checkout, before an order/idempotency
        // lock. Stage pricing and inventory holds must share the same lock order.
        $lock = $db->prepare('SELECT id FROM events WHERE id=? FOR UPDATE');
        $lock->execute([$eventId]);
        if (!$lock->fetchColumn()) {
            throw new DomainException('CHECKOUT_UNAVAILABLE');
        }
        $q = $db->prepare('SELECT id,status,event_id,buyer_id,expires_at_utc,total_cop FROM ticket_orders
            WHERE checkout_key_hash=? FOR UPDATE');
        $q->execute([$digest]);
        $prior = $q->fetch(PDO::FETCH_ASSOC);
        if ($prior && ((int)$prior['buyer_id'] !== $buyerId
            || (int)$prior['event_id'] !== $eventId
            || !in_array($prior['status'],['draft','pending'],true))) {
            throw new DomainException('CHECKOUT_RETRY_CONFLICT');
        }
        if ($prior && (string)($prior['expires_at_utc'] ?? '') !== ''
            && (string)$prior['expires_at_utc'] <= gmdate('Y-m-d H:i:s')) {
            throw new DomainException('CHECKOUT_EXPIRED');
        }
        $q = $db->prepare('SELECT id,name,price,currency,status,available_from,available_until
            FROM event_ticket_types WHERE id=? AND event_id=?');
        $q->execute([$typeId,$eventId]);
        $type = $q->fetch(PDO::FETCH_ASSOC);
        if (!$type || $type['currency'] !== 'COP') {
            throw new DomainException('CHECKOUT_UNAVAILABLE');
        }
        if ($prior) {
            // A retry binds the original immutable price snapshot, not a new
            // stage/clock or the current editorial ticket price.
            $orderId = (int)$prior['id'];
            $q = $db->prepare('SELECT ticket_type_id,quantity,unit_price_cop,subtotal_cop
                FROM ticket_order_items WHERE order_id=?');
            $q->execute([$orderId]);
            $items = $q->fetchAll(PDO::FETCH_ASSOC);
            if (count($items)!==1 || (int)$items[0]['ticket_type_id']!==$typeId
                || (int)$items[0]['quantity']!==$quantity) {
                throw new DomainException('CHECKOUT_RETRY_CONFLICT');
            }
            $subtotal = brvtal_ticket_checkout_price($items[0]['unit_price_cop'],$quantity);
            if ((int)$items[0]['subtotal_cop'] !== $subtotal) {
                throw new DomainException('CHECKOUT_RETRY_CONFLICT');
            }
            $total = (int)$prior['total_cop'];
            if ($withPromo) {
                // A retry uses the immutable redemption, NOT current rule/price.
                $q = $db->prepare('SELECT code_digest,buyer_id,event_id,ticket_type_id,
                    quantity,subtotal_cop,discount_cop,total_cop,campaign_key
                    FROM ticket_promo_redemptions WHERE order_id=?');
                $q->execute([$orderId]);
                $savedPromo=$q->fetch(PDO::FETCH_ASSOC);
                if (!$savedPromo || !hash_equals((string)$savedPromo['code_digest'],
                        brvtalPromoDigest($promoCode))
                    || (int)$savedPromo['buyer_id']!==$buyerId
                    || (int)$savedPromo['event_id']!==$eventId
                    || (int)$savedPromo['ticket_type_id']!==$typeId
                    || (int)$savedPromo['quantity']!==$quantity
                    || (int)$savedPromo['subtotal_cop']!==$subtotal
                    || (int)$savedPromo['total_cop']!==$total
                    || (int)$savedPromo['discount_cop']!==$subtotal-$total) {
                    throw new DomainException('CHECKOUT_RETRY_CONFLICT');
                }
                $promoBreakdown=$savedPromo;
            } else {
                // A zero-discount bundle still has a redemption and must not
                // fall through to a payment-provider attempt on retry.
                if ($subtotal !== $total) throw new DomainException('CHECKOUT_RETRY_CONFLICT');
                brvtalPromoRejectUncodedReplay($db,$orderId);
            }
        } else {
            // A first checkout snapshots its configured stage price under the event lock.
            $quote = brvtal_public_ticket_checkout_stage_quote(
                $db,$eventId,$typeId,$quantity,null,$withOffer ? 1 : 0);
            $price = $quote['unit_price_cop'] ?? $type['price'];
            $subtotal = brvtal_ticket_checkout_price($price,$quantity);
            $promoBreakdown = $withPromo
                ? brvtalPromoQuote($db,$eventId,$typeId,$buyerId,$quantity,(int)$price,$promoCode)
                : null;
            $total = $promoBreakdown['total_cop'] ?? $subtotal;
            $q = $db->prepare("INSERT INTO ticket_orders
                (public_id,buyer_id,event_id,checkout_key_hash,status,currency,total_cop,expires_at_utc)
                VALUES (?,?,?,?,'draft','COP',?,DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 10 MINUTE))");
            $q->execute([random_bytes(16),$buyerId,$eventId,$digest,$total]);
            $orderId = (int)$db->lastInsertId();
            $q = $db->prepare("INSERT INTO ticket_order_items
                (order_id,event_id,ticket_type_id,ticket_name_snapshot,quantity,
                 unit_price_cop,subtotal_cop,currency) VALUES (?,?,?,?,?,?,?,'COP')");
            $q->execute([$orderId,$eventId,$typeId,mb_substr((string)$type['name'],0,120),
                $quantity,(int)$price,$subtotal]);
        }
        if ($withPromo && !$prior) {
            // Same transaction as the T05 hold and eventual WOMPI preparation.
            $promoBreakdown=brvtalPromoReserve($db,$orderId,$eventId,$typeId,
                $buyerId,$quantity,(int)$price,$promoCode);
        }
        try {
            if ($withOffer && !$prior) {
                // Consume the invitation only when its hold is successfully
                // created in this SAME transaction; exception rolls back both.
                brvtal_waitlist_redeem_into_hold(
                    $db,$offerToken,$orderId,$buyerId,$typeId);
            } else {
                // Matching idempotent retries reuse the existing paid hold.
                brvtal_ticket_inventory_hold($db,$orderId,$typeId,$quantity);
            }
        } catch (DomainException $error) {
            if ($prior && $error->getMessage() === 'INVENTORY_HOLD_CONFLICT') {
                throw new DomainException('CHECKOUT_EXPIRED', 0, $error);
            }
            throw $error;
        }
        // T07 checks the frozen coupon ledger before signing the net COP.
        // T23 still fails closed outside disposable test DBs (D-063).
        $checkout = brvtal_ticket_wompi_prepare($db, $buyerId, $orderId, $key, $method, $settings);
        $db->commit();
        $response=['order_id'=>$orderId,'checkout'=>$checkout];
        if ($withPromo) {
            if (!is_array($promoBreakdown)) {
                throw new LogicException('PROMO_SNAPSHOT_MISSING');
            }
            $response['promo_breakdown']=[
                'subtotal_cop'=>(int)$promoBreakdown['subtotal_cop'],
                'discount_cop'=>(int)$promoBreakdown['discount_cop'],
                'total_cop'=>(int)$promoBreakdown['total_cop']
            ];
        }
        return $response;
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        // A concurrent request can win the same idempotency key. The client
        // retries the persisted key after a deadlock or unique-index race.
        if (in_array((int)($e->errorInfo[1] ?? 0), [1062, 1213, 1205], true)
            || (string)$e->getCode() === '40001') {
            throw new DomainException('CHECKOUT_RETRYABLE', 0, $e);
        }
        throw $e;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/**
 * #1940: a checkout must fail in seconds, never hang the buyer. A short lock wait turns
 * contention on the event row into MariaDB error 1205, which the callers already map to
 * CHECKOUT_RETRYABLE. Best effort: a server that refuses the setting keeps its default.
 */
function brvtal_public_ticket_checkout_fast_locks(PDO $db): void
{
    try {
        $db->exec('SET SESSION innodb_lock_wait_timeout = 5');
    } catch (Throwable) {
    }
}

/** #1940: log checkout failures by class and code only (no buyer data, no secrets). */
function brvtal_public_ticket_checkout_log(Throwable $error, string $action): void
{
    if (!function_exists('brvtal_log')) return;
    $message = $error->getMessage();
    brvtal_log('TICKET_CHECKOUT_ERROR', 'Public ticket checkout failed', [
        'action' => $action,
        'class' => $error::class,
        'code' => preg_match('/^[A-Z][A-Z0-9_]{2,60}$/D', $message) ? $message : null,
        'driver_code' => $error instanceof PDOException ? (int)($error->errorInfo[1] ?? 0) : null,
    ]);
}

/** #1943 · Consent recorded for buyers who check out without an account. */
const BRVTAL_GUEST_CHECKOUT_CONSENT = 'checkout-v1';

/** Strict guest buyer input: {email, name, consent: true, website: ""} (website is a honeypot). */
function brvtal_public_ticket_checkout_guest_input(mixed $guest): array
{
    require_once __DIR__ . '/buyer_auth.php';
    if (!is_array($guest) || array_is_list($guest) || array_diff(array_keys($guest), ['email','name','consent','website'])) {
        throw new InvalidArgumentException('GUEST_INVALID');
    }
    if (($guest['website'] ?? '') !== '') throw new InvalidArgumentException('GUEST_INVALID');
    $email = brvtalBuyerEmail($guest['email'] ?? null);
    if ($email === null) throw new InvalidArgumentException('GUEST_EMAIL_INVALID');
    $name = is_string($guest['name'] ?? null) ? trim((string)preg_replace('/\s+/u', ' ', $guest['name'])) : '';
    if (mb_strlen($name) < 2 || mb_strlen($name) > 120 || preg_match('/\p{C}/u', $name) === 1) {
        throw new InvalidArgumentException('GUEST_NAME_INVALID');
    }
    if (($guest['consent'] ?? null) !== true) throw new InvalidArgumentException('GUEST_CONSENT_REQUIRED');
    return ['email'=>$email, 'name'=>$name];
}

/**
 * #1943 · The buyer for a checkout without an account: the existing account for this
 * email (never a blocked one) or a new 'guest' with name and consent. Tickets are only
 * ever delivered to this email, so reusing an account does not need its password.
 */
function brvtal_public_ticket_checkout_guest_buyer(PDO $db, array $guest): int
{
    if ($db->inTransaction()) throw new LogicException('GUEST_TRANSACTION_ALREADY_ACTIVE');
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $db->beginTransaction();
        try {
            $q = $db->prepare('SELECT id,status,full_name FROM ticket_buyers WHERE email=? FOR UPDATE');
            $q->execute([$guest['email']]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                if ($row['status'] === 'blocked') throw new DomainException('BUYER_BLOCKED');
                if (trim((string)($row['full_name'] ?? '')) === '') {
                    $db->prepare('UPDATE ticket_buyers SET full_name=? WHERE id=?')->execute([$guest['name'], (int)$row['id']]);
                }
                $db->commit();
                return (int)$row['id'];
            }
            $db->prepare("INSERT INTO ticket_buyers(public_id,email,full_name,status,consent_version,consent_recorded_at)
                VALUES (?,?,?,'guest',?,UTC_TIMESTAMP(6))")
                ->execute([random_bytes(16), $guest['email'], $guest['name'], BRVTAL_GUEST_CHECKOUT_CONSENT]);
            $id = (int)$db->lastInsertId();
            $db->commit();
            return $id;
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            // A concurrent first purchase created the same email: read it on the next pass.
            if ((int)($e->errorInfo[1] ?? 0) !== 1062 || $attempt > 0) throw $e;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }
    throw new DomainException('CHECKOUT_RETRYABLE');
}

/** #1943 · Without a session there is no CSRF token: only accept requests from this site. */
function brvtal_public_ticket_checkout_same_origin(): bool
{
    $site = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
    if ($site !== '') return $site === 'same-origin';
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    return $host !== '' && hash_equals('https://' . strtolower($host), strtolower($origin));
}

/** #1943 · Opaque per-order token (the order's random public_id) for status without a session. */
function brvtal_public_ticket_checkout_order_token(PDO $db, int $orderId): string
{
    $q = $db->prepare('SELECT LOWER(HEX(public_id)) FROM ticket_orders WHERE id=?');
    $q->execute([$orderId]);
    return (string)$q->fetchColumn();
}

/**
 * #1952: when the buyer asks about a still-pending order, ask Wompi directly
 * (server to server) in case the signed webhook was lost. Best effort: any
 * failure keeps the stored status; only a verified APPROVED changes it.
 */
function brvtal_public_ticket_checkout_refreshed_status(PDO $db, mixed $order): ?string
{
    if (!is_array($order)) return null;
    if (!in_array($order['status'], ['draft','pending'], true)) return (string)$order['status'];
    try {
        require_once __DIR__ . '/ticketing_wompi_reconcile.php';
        // A public trigger never queries Wompi more than once every 10 s per attempt.
        $open = $db->prepare("SELECT id FROM ticket_payment_attempts
            WHERE order_id=? AND provider='wompi' AND status IN ('created','pending')
              AND updated_at_utc <= UTC_TIMESTAMP(6) - INTERVAL 10 SECOND ORDER BY id DESC LIMIT 1");
        $open->execute([(int)$order['id']]);
        $attempt = $open->fetchColumn();
        if ($attempt !== false) brvtal_wompi_reconcile_attempt($db, (int)$attempt);
        $again = $db->prepare('SELECT status FROM ticket_orders WHERE id=?');
        $again->execute([(int)$order['id']]);
        return (string)$again->fetchColumn();
    } catch (Throwable) {
        return (string)$order['status'];
    }
}

function brvtal_public_ticket_checkout_state_by_token(PDO $db, int $eventId, string $token): string
{
    if (!preg_match('/^[a-f0-9]{32}$/D', $token)) return 'unverified';
    $q = $db->prepare('SELECT id,status FROM ticket_orders WHERE public_id=UNHEX(?) AND event_id=? LIMIT 1');
    $q->execute([$token, $eventId]);
    return match (brvtal_public_ticket_checkout_refreshed_status($db, $q->fetch(PDO::FETCH_ASSOC))) {
        'paid' => 'approved', 'failed' => 'failed', 'cancelled' => 'cancelled',
        'draft','pending' => 'pending', default => 'unverified',
    };
}

/** Only verified database state (signed T08 webhook or #1952 Wompi API check) is authoritative. */
function brvtal_public_ticket_checkout_state(PDO $db, int $eventId, int $buyerId, int $orderId): string
{
    // Numeric order IDs require the authenticated buyer's canonical account scope.
    // The independent opaque-token route does not use this session capability.
    if ($buyerId < 1 || (int)($_SESSION['buyer_id'] ?? 0) !== $buyerId) {
        return 'unverified';
    }
    require_once __DIR__ . '/buyer_account.php';
    try {
        $scope = brvtalBuyerAccountCheckoutScope($db, $buyerId);
    } catch (DomainException) {
        return 'unverified';
    }
    // An old guest session must not become a full account if activation races
    // with this request after SessionStart validated the persisted epoch.
    if (($scope !== null && $scope !== $orderId)
        || ($scope === null && isset($_SESSION['buyer_checkout_new_order']))) {
        return 'unverified';
    }
    $q = $db->prepare('SELECT id,status FROM ticket_orders
        WHERE id=? AND event_id=? AND buyer_id=? LIMIT 1');
    $q->execute([$orderId,$eventId,$buyerId]);
    return match (brvtal_public_ticket_checkout_refreshed_status($db, $q->fetch(PDO::FETCH_ASSOC))) {
        'paid' => 'approved', 'failed' => 'failed', 'cancelled' => 'cancelled',
        'draft','pending' => 'pending', default => 'unverified',
    };
}

/** Same-origin event URL is the endpoint; never accept amount/status from client. */
function brvtal_public_ticket_checkout_http(PDO $db, int $eventId): void
{
    $action = (string)($_SERVER['HTTP_X_BRVTAL_TICKET_CHECKOUT'] ?? '');
    if (!in_array($action,['prepare','status'],true)) return;
    header('Cache-Control: private, no-store');
    header('Referrer-Policy: no-referrer');
    if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS']==='off') {
        json_response(['ok'=>false,'error'=>'HTTPS_REQUIRED'],403);
    }
    require_once __DIR__ . '/buyer_auth.php';
    try {
        brvtalBuyerSessionStart();
        // PHP's session cache limiter can replace the endpoint's Cache-Control.
        header('Cache-Control: private, no-store');
        $buyerId = (int)($_SESSION['buyer_id'] ?? 0);
        $csrfValid = brvtalBuyerCsrfValid($_SERVER['HTTP_X_CSRF_TOKEN']??null);
        // #1940: nothing below writes the session; releasing its lock means a slow
        // checkout can never block the buyer's other requests.
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        if ($action==='status') {
            if (($_SERVER['REQUEST_METHOD']??'')!=='GET') json_response(['ok'=>false,'error'=>'INVALID_REQUEST'],422);
            // #1943: a guest checks its order with the opaque order token, no session needed.
            $token = (string)($_GET['order_token'] ?? '');
            if ($token !== '') {
                json_response(['ok'=>true,'state'=>brvtal_public_ticket_checkout_state_by_token($db,$eventId,$token)],200);
            }
            if ($buyerId < 1) json_response(['ok'=>false,'error'=>'AUTH_REQUIRED'],401);
            if (!preg_match('/^[1-9][0-9]{0,17}$/D',(string)($_GET['order_id']??''))) {
                json_response(['ok'=>false,'error'=>'INVALID_REQUEST'],422);
            }
            $state = brvtal_public_ticket_checkout_state($db,$eventId,$buyerId,(int)$_GET['order_id']);
            json_response(['ok'=>true,'state'=>$state],200);
        }
        if (($_SERVER['REQUEST_METHOD']??'')!=='POST') {
            json_response(['ok'=>false,'error'=>'CSRF_OR_METHOD'],403);
        }
        $body = input_json();
        if ($buyerId < 1) {
            // #1943: checkout without an account; the buyer is identified by email.
            if (!array_key_exists('buyer', $body)) json_response(['ok'=>false,'error'=>'AUTH_REQUIRED'],401);
            if (!brvtal_public_ticket_checkout_same_origin()) json_response(['ok'=>false,'error'=>'CSRF_OR_METHOD'],403);
            $guest = brvtal_public_ticket_checkout_guest_input($body['buyer']);
            require_once __DIR__ . '/password_rate_limit.php';
            $limit = brvtal_password_rate_limit_failure('__buyer_guest_checkout_email__' . $guest['email'],
                __DIR__ . '/../storage/buyer_rate_limits', null, '__account__');
            if ($limit['limited']) json_response(['ok'=>false,'error'=>'RATE_LIMITED'],429);
            $buyerId = brvtal_public_ticket_checkout_guest_buyer($db, $guest);
        } elseif (!$csrfValid) {
            json_response(['ok'=>false,'error'=>'CSRF_OR_METHOD'],403);
        }
        unset($body['buyer']); // A signed-in buyer keeps their account; the field is ignored.
        $result = brvtal_public_ticket_checkout_prepare($db,$eventId,$buyerId,$body);
        $result['order_token'] = brvtal_public_ticket_checkout_order_token($db, (int)$result['order_id']);
        // #1958: Wompi returns the buyer to this order's confirmation page (no login needed),
        // on the same HTTPS origin the operator pinned as the return URL.
        $pinned = parse_url((string)($result['checkout']['redirect_url'] ?? ''));
        if (is_array($pinned) && ($pinned['scheme'] ?? '') === 'https' && isset($pinned['host'])
            && preg_match('/^[a-f0-9]{32}$/D', $result['order_token'])) {
            $result['checkout']['redirect_url'] = 'https://' . $pinned['host'] . '/compra/' . $result['order_token'];
        }
        json_response(['ok'=>true]+$result,200);
    } catch (InvalidArgumentException $error) {
        brvtal_public_ticket_checkout_log($error, $action);
        $code = $error->getMessage();
        // Guest field errors are safe to name so the form can point at the field.
        json_response(['ok'=>false,'error'=>str_starts_with($code, 'GUEST_') ? $code : 'INVALID_REQUEST'],422);
    } catch (DomainException $error) {
        brvtal_public_ticket_checkout_log($error, $action);
        $reason = $error->getMessage();
        if (in_array($reason, ['CHECKOUT_RETRYABLE','CHECKOUT_EXPIRED'], true)) {
            json_response(['ok'=>false,'error'=>$reason],409);
        }
        json_response(['ok'=>false,'error'=>'CHECKOUT_UNAVAILABLE'],409);
    } catch (Throwable $error) {
        brvtal_public_ticket_checkout_log($error, $action);
        json_response(['ok'=>false,'error'=>'CHECKOUT_UNAVAILABLE'],503);
    }
}
