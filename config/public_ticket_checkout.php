<?php
declare(strict_types=1);

require_once __DIR__ . '/ticketing_inventory.php';
require_once __DIR__ . '/ticketing_wompi_client.php';
require_once __DIR__ . '/ticketing_stages.php';

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
            if ($free < 1) continue;
            $offers[] = [
                'id'=>(int)$type['id'], 'title'=>(string)$type['name'],
                'price_cop'=>$price, 'available'=>min(20,$free),
            ];
        }
        return $offers ? ['offers'=>$offers, 'methods'=>$settings['methods']] : [];
    } catch (Throwable) {
        return []; // Missing schema, disabled merchant, query errors: legacy stays intact.
    }
}

/**
 * Stage pricing is ONLY test-enabled on disposable MariaDB. Never start selling
 * a new stage just because its migration or UI was merged. When enabled, this
 * lock and T05 inventory counts serialize the quote with all concurrent holds.
 */
function brvtal_public_ticket_checkout_stage_quote(
    PDO $db, int $eventId, int $typeId, int $quantity
): ?array {
    if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1'
        || !preg_match('/^brvtal_test[a-zA-Z0-9_]*$/D',
            (string)$db->query('SELECT DATABASE()')->fetchColumn())) return null;
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
    $now = brvtal_ticket_inventory_now();
    $utc = $now->format('Y-m-d H:i:s.u');
    $eventUsage = brvtal_ticket_inventory_usage($db,$eventId,0,$utc);
    $typeUsage = brvtal_ticket_inventory_usage($db,$eventId,$typeId,$utc);
    $used = array_sum($typeUsage);
    $free = min((int)$eventCap - array_sum($eventUsage),(int)$typeCap - $used);
    return brvtalTicketStageQuote($rows,$used,$quantity,max(0,$free),$now);
}

/** Transactional UI adapter: same idempotency key => one order + one hold. */
function brvtal_public_ticket_checkout_prepare(PDO $db, int $eventId, int $buyerId, array $input): array
{
    $typeId = $input['ticket_type_id'] ?? null;
    $quantity = $input['quantity'] ?? null;
    $key = $input['idempotency_key'] ?? null;
    $method = $input['method'] ?? null;
    if ($buyerId < 1 || $eventId < 1 || !is_int($typeId) || $typeId < 1
        || !is_int($quantity) || $quantity < 1 || $quantity > 20
        || !is_string($key) || !preg_match('/^[A-Za-z0-9:_-]{16,128}$/D',$key)
        || !is_string($method) || count($input) !== 4) {
        throw new InvalidArgumentException('INVALID_CHECKOUT');
    }
    $settings = brvtal_ticket_wompi_config();
    if (!in_array($method,$settings['methods'],true)) {
        throw new InvalidArgumentException('INVALID_CHECKOUT_METHOD');
    }
    $digest = hash('sha256','t13|'.$buyerId.'|'.$eventId.'|'.$key);
    if ($db->inTransaction()) throw new LogicException('CHECKOUT_TRANSACTION_ALREADY_ACTIVE');
    $db->beginTransaction();
    try {
        $q = $db->prepare('SELECT id,status,event_id,buyer_id,expires_at_utc FROM ticket_orders
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
            $total = brvtal_ticket_checkout_price($items[0]['unit_price_cop'],$quantity);
            if ((int)$items[0]['subtotal_cop'] !== $total) {
                throw new DomainException('CHECKOUT_RETRY_CONFLICT');
            }
        } else {
            // This path remains dormant outside disposable integration DBs.
            $quote = brvtal_public_ticket_checkout_stage_quote($db,$eventId,$typeId,$quantity);
            $price = $quote['unit_price_cop'] ?? $type['price'];
            $total = brvtal_ticket_checkout_price($price,$quantity);
            $q = $db->prepare("INSERT INTO ticket_orders
                (public_id,buyer_id,event_id,checkout_key_hash,status,currency,total_cop,expires_at_utc)
                VALUES (?,?,?,?,'draft','COP',?,DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 10 MINUTE))");
            $q->execute([random_bytes(16),$buyerId,$eventId,$digest,$total]);
            $orderId = (int)$db->lastInsertId();
            $q = $db->prepare("INSERT INTO ticket_order_items
                (order_id,event_id,ticket_type_id,ticket_name_snapshot,quantity,
                 unit_price_cop,subtotal_cop,currency) VALUES (?,?,?,?,?,?,?,'COP')");
            $q->execute([$orderId,$eventId,$typeId,mb_substr((string)$type['name'],0,120),
                $quantity,(int)$price,$total]);
        }
        try {
            brvtal_ticket_inventory_hold($db,$orderId,$typeId,$quantity);
        } catch (DomainException $error) {
            if ($prior && $error->getMessage() === 'INVENTORY_HOLD_CONFLICT') {
                throw new DomainException('CHECKOUT_EXPIRED', 0, $error);
            }
            throw $error;
        }
        $checkout = brvtal_ticket_wompi_prepare($db,$buyerId,$orderId,$key,$method,$settings);
        $db->commit();
        return ['order_id'=>$orderId,'checkout'=>$checkout];
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

/** Only verified database state, updated by the signed T08 webhook, is authoritative. */
function brvtal_public_ticket_checkout_state(PDO $db, int $eventId, int $buyerId, int $orderId): string
{
    $q = $db->prepare('SELECT status FROM ticket_orders
        WHERE id=? AND event_id=? AND buyer_id=? LIMIT 1');
    $q->execute([$orderId,$eventId,$buyerId]);
    return match ($q->fetchColumn()) {
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
        $buyerId = (int)($_SESSION['buyer_id'] ?? 0);
        if ($buyerId < 1) json_response(['ok'=>false,'error'=>'AUTH_REQUIRED'],401);
        if ($action==='status') {
            if (($_SERVER['REQUEST_METHOD']??'')!=='GET'
                || !preg_match('/^[1-9][0-9]{0,17}$/D',(string)($_GET['order_id']??''))) {
                json_response(['ok'=>false,'error'=>'INVALID_REQUEST'],422);
            }
            $state = brvtal_public_ticket_checkout_state($db,$eventId,$buyerId,(int)$_GET['order_id']);
            json_response(['ok'=>true,'state'=>$state],200);
        }
        if (($_SERVER['REQUEST_METHOD']??'')!=='POST'
            || !brvtalBuyerCsrfValid($_SERVER['HTTP_X_CSRF_TOKEN']??null)) {
            json_response(['ok'=>false,'error'=>'CSRF_OR_METHOD'],403);
        }
        $body = input_json();
        $result = brvtal_public_ticket_checkout_prepare($db,$eventId,$buyerId,$body);
        json_response(['ok'=>true]+$result,200);
    } catch (InvalidArgumentException) {
        json_response(['ok'=>false,'error'=>'INVALID_REQUEST'],422);
    } catch (DomainException $error) {
        $reason = $error->getMessage();
        if (in_array($reason, ['CHECKOUT_RETRYABLE','CHECKOUT_EXPIRED'], true)) {
            json_response(['ok'=>false,'error'=>$reason],409);
        }
        json_response(['ok'=>false,'error'=>'CHECKOUT_UNAVAILABLE'],409);
    } catch (Throwable) {
        json_response(['ok'=>false,'error'=>'CHECKOUT_UNAVAILABLE'],503);
    }
}
