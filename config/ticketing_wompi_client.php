<?php
declare(strict_types=1);
require_once __DIR__ . '/secrets.php';

/**
 * Wompi environments. The owner picks one in DISCADMIN (BRVTAL_TICKET_WOMPI_MODE):
 * - sandbox: test keys only (pub_test_/prv_test_/test_integrity_/test_events_); no real money.
 * - production: BRVTAL_TICKET_WOMPI_PROD_* keys only (pub_prod_/prv_prod_/prod_integrity_/
 *   prod_events_); real charges. The admin API only stores this mode with explicit
 *   confirmation and a complete production key set.
 * - mock: automated tests only (requires BRVTAL_TICKET_WOMPI_TESTING=1), test keys.
 * Anything else (off, live, empty…) keeps checkout disabled.
 */
function brvtal_ticket_wompi_environments(): array
{
    return [
        'sandbox' => ['prefix'=>'BRVTAL_TICKET_WOMPI_', 'public'=>'pub_test_', 'private'=>'prv_test_',
            'integrity'=>'test_integrity_', 'events'=>'test_events_', 'event_environment'=>'test',
            'api'=>'https://sandbox.wompi.co/v1'],
        'production' => ['prefix'=>'BRVTAL_TICKET_WOMPI_PROD_', 'public'=>'pub_prod_', 'private'=>'prv_prod_',
            'integrity'=>'prod_integrity_', 'events'=>'prod_events_', 'event_environment'=>'prod',
            'api'=>'https://production.wompi.co/v1'],
    ];
}

/** Hosted Wompi checkout preparation; never confirms a sale (the signed webhook does). */
function brvtal_ticket_wompi_config(?array $override = null): array
{
    $env = static function (string $key) use ($override): string {
        $value = brvtal_secret_value($key, $override);
        return is_string($value) ? trim($value) : '';
    };
    $mode = $env('BRVTAL_TICKET_WOMPI_MODE');
    if ($mode === 'mock' && $env('BRVTAL_TICKET_WOMPI_TESTING') !== '1') {
        throw new DomainException('WOMPI_DISABLED');
    }
    $environments = brvtal_ticket_wompi_environments();
    $profile = $environments[$mode === 'mock' ? 'sandbox' : $mode] ?? null;
    if ($profile === null) throw new DomainException('WOMPI_DISABLED');
    $prefix = $profile['prefix'];
    $public = $env($prefix . 'PUBLIC_KEY');
    $private = $env($prefix . 'PRIVATE_KEY');
    $integrity = $env($prefix . 'INTEGRITY_SECRET');
    if (!str_starts_with($public, $profile['public']) || strlen($public) < strlen($profile['public']) + 4
        || !str_starts_with($private, $profile['private']) || strlen($private) < strlen($profile['private']) + 4
        || !str_starts_with($integrity, $profile['integrity'])
        || strlen($integrity) < strlen($profile['integrity']) + 5) {
        throw new DomainException('WOMPI_CREDENTIALS_MISSING');
    }
    $raw = $env('BRVTAL_TICKET_WOMPI_ENABLED_METHODS');
    $methods = array_values(array_unique(array_filter(array_map(trim(...), explode(',', $raw)))));
    if (!$methods || array_diff($methods, ['CARD', 'NEQUI', 'PSE'])) {
        throw new DomainException('WOMPI_METHODS_UNAVAILABLE');
    }
    // The operator pins the HTTPS return URL. The frontend cannot supply one.
    $returnUrl = $env('BRVTAL_TICKET_WOMPI_RETURN_URL');
    $parts = parse_url($returnUrl);
    if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
        || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['fragment']) || strlen($returnUrl) > 500
        || !filter_var($returnUrl, FILTER_VALIDATE_URL)) {
        throw new DomainException('WOMPI_RETURN_URL_INVALID');
    }
    $apiBase = $profile['api'];
    $eventsPrefix = $profile['events'];
    $eventEnvironment = $profile['event_environment'];
    $keyPrefix = $prefix;
    return compact('mode', 'public', 'private', 'integrity', 'methods', 'returnUrl',
        'apiBase', 'eventsPrefix', 'eventEnvironment', 'keyPrefix');
}

/** Exact token DISCADMIN must send to switch Wompi to real charges. */
const BRVTAL_WOMPI_PRODUCTION_CONFIRMATION = 'COBROS_REALES';

/**
 * Guard for the DISCADMIN mode selector. Switching to production requires the
 * confirmation token and a production key set that passes the checkout and
 * webhook rules. Returns an error code, or null when the change is allowed.
 */
function brvtal_ticket_wompi_mode_change_error(string $mode, mixed $confirm, ?array $override = null): ?string
{
    if ($mode !== 'production') return null; // off/sandbox never charge; format is validated by the store.
    if ($confirm !== BRVTAL_WOMPI_PRODUCTION_CONFIRMATION) return 'WOMPI_PRODUCTION_CONFIRMATION_REQUIRED';
    $candidate = ['BRVTAL_TICKET_WOMPI_MODE' => 'production'];
    foreach (['BRVTAL_TICKET_WOMPI_PROD_PUBLIC_KEY','BRVTAL_TICKET_WOMPI_PROD_PRIVATE_KEY',
        'BRVTAL_TICKET_WOMPI_PROD_INTEGRITY_SECRET','BRVTAL_TICKET_WOMPI_PROD_EVENTS_SECRET',
        'BRVTAL_TICKET_WOMPI_ENABLED_METHODS','BRVTAL_TICKET_WOMPI_RETURN_URL'] as $key) {
        $candidate[$key] = brvtal_secret_value($key, $override);
    }
    try {
        require_once __DIR__ . '/ticketing_wompi_webhook.php';
        brvtal_wompi_hook_config($candidate); // Checkout rules plus the production events secret.
        return null;
    } catch (Throwable) {
        return 'WOMPI_PRODUCTION_KEYS_INCOMPLETE';
    }
}

/** Status for administrators: effective mode and why checkout is off. Never returns keys. */
function brvtal_ticket_wompi_status(?array $override = null): array
{
    $mode = trim(brvtal_secret_value('BRVTAL_TICKET_WOMPI_MODE', $override));
    try {
        brvtal_ticket_wompi_config($override);
        return ['mode'=>$mode, 'ready'=>true, 'reason'=>null];
    } catch (DomainException $e) {
        return ['mode'=>$mode === '' ? 'off' : $mode, 'ready'=>false, 'reason'=>$e->getMessage()];
    } catch (Throwable) {
        return ['mode'=>$mode === '' ? 'off' : $mode, 'ready'=>false, 'reason'=>'WOMPI_CONFIG_UNAVAILABLE'];
    }
}

function brvtal_ticket_wompi_payload(array $config, string $reference, int $amountCop, string $method): array
{
    if (!in_array($method, $config['methods'] ?? [], true)
        || !preg_match('/^BW7-[1-9][0-9]*-(?:CARD|NEQUI|PSE)-[a-f0-9]{24}$/D', $reference)
        || $amountCop < 1 || $amountCop > 10000000) {
        throw new DomainException('WOMPI_INVALID_CHECKOUT');
    }
    $cents = $amountCop * 100;
    // Wompi Widget: SHA256(reference + amount-in-cents + currency + integrity secret).
    $signature = hash('sha256', $reference . $cents . 'COP' . $config['integrity']);
    return [
        'state' => 'awaiting_provider', 'environment' => $config['mode'],
        'public_key' => $config['public'], 'currency' => 'COP',
        'amount_in_cents' => $cents, 'reference' => $reference,
        'signature_integrity' => $signature, 'redirect_url' => $config['returnUrl'],
        'selected_method' => $method, 'enabled_methods' => $config['methods'],
        'checkout_widget' => 'https://checkout.wompi.co/widget.js',
    ];
}

/** Caller owns transaction + authenticated buyer identity. All money is read from DB. */
function brvtal_ticket_wompi_prepare(
    PDO $db, int $buyerId, int $orderId, string $key, string $method, array $config
): array {
    if (!$db->inTransaction()) throw new LogicException('WOMPI_TRANSACTION_REQUIRED');
    if ($buyerId < 1 || $orderId < 1 || !preg_match('/^[A-Za-z0-9:_-]{16,128}$/D', $key)
        || !in_array($method, $config['methods'] ?? [], true)) {
        throw new InvalidArgumentException('WOMPI_INVALID_REQUEST');
    }
    $get = $db->prepare('SELECT id,buyer_id,status,currency,total_cop,expires_at_utc
        FROM ticket_orders WHERE id=? AND buyer_id=? FOR UPDATE');
    $get->execute([$orderId, $buyerId]);
    $order = $get->fetch(PDO::FETCH_ASSOC);
    if (!$order) throw new DomainException('WOMPI_ORDER_NOT_FOUND');
    if (!in_array($order['status'], ['draft','pending'], true)
        || $order['currency'] !== 'COP' || !ctype_digit((string)$order['total_cop'])
        || (int)$order['total_cop'] < 1 || (int)$order['total_cop'] > 10000000) {
        throw new DomainException('WOMPI_ORDER_NOT_ELIGIBLE');
    }
    $items = $db->prepare('SELECT ticket_type_id,quantity,unit_price_cop,subtotal_cop,currency
        FROM ticket_order_items WHERE order_id=? FOR UPDATE');
    $items->execute([$orderId]);
    $rows = $items->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) throw new DomainException('WOMPI_ORDER_NOT_ELIGIBLE');
    $total = 0;
    $quantities = [];
    foreach ($rows as $row) {
        $qty = (int)$row['quantity'];
        $unit = (int)$row['unit_price_cop'];
        if ($row['currency'] !== 'COP' || $qty < 1 || $qty > 20 || $unit < 1
            || $unit > 10000000 || $unit * $qty !== (int)$row['subtotal_cop']) {
            throw new DomainException('WOMPI_PRICE_MISMATCH');
        }
        $total += $unit * $qty;
        $quantities[(int)$row['ticket_type_id']] = $qty;
    }
    // A coupon preserves the gross item snapshots. Its immutable, locked ledger
    // must reconcile the authorized net charged by Wompi, down to the peso.
    // Older installations without T23 tables support ONLY undiscounted orders.
    $redemption = false;
    try {
        $r = $db->prepare('SELECT promo_id,buyer_id,event_id,ticket_type_id,quantity,
                subtotal_cop,discount_cop,total_cop,code_digest
            FROM ticket_promo_redemptions WHERE order_id=? FOR UPDATE');
        $r->execute([$orderId]);
        $redemption = $r->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $error) {
        if ((int)($error->errorInfo[1] ?? 0) !== 1146) throw $error;
    }
    if ($redemption) {
        // The standalone /api/ticketing/checkout.php path also calls T07.
        // Deny real-money coupon charges here, not only at public checkout.
        if (($config['mode'] ?? '') === 'production') {
            throw new DomainException('PROMOS_NOT_ENABLED');
        }
        // Promotions apply to one ticket type per order, never mixed baskets.
        // Query the event only when a coupon exists, preserving older T07 fixtures.
        $event = $db->prepare('SELECT event_id FROM ticket_orders WHERE id=? FOR UPDATE');
        $event->execute([$orderId]);
        $orderEvent = $event->fetchColumn();
        $discount = (int)$redemption['discount_cop'];
        if (count($rows) !== 1 || $orderEvent === false
            || (int)$redemption['promo_id'] < 1
            || (int)$redemption['buyer_id'] !== $buyerId
            || (int)$redemption['event_id'] !== (int)$orderEvent
            || (int)$redemption['ticket_type_id'] !== (int)$rows[0]['ticket_type_id']
            || (int)$redemption['quantity'] !== (int)$rows[0]['quantity']
            || (int)$redemption['subtotal_cop'] !== $total
            || $discount < 0 || $discount >= $total
            || (int)$redemption['total_cop'] !== $total - $discount
            || (int)$order['total_cop'] !== $total - $discount
            || preg_match('/^[a-f0-9]{64}$/D', (string)$redemption['code_digest']) !== 1) {
            throw new DomainException('WOMPI_PRICE_MISMATCH');
        }
    } elseif ($total !== (int)$order['total_cop']) {
        throw new DomainException('WOMPI_PRICE_MISMATCH');
    }
    // From here on every signature and provider attempt uses ONLY the net COP.
    $total = (int)$order['total_cop'];
    $holds = $db->prepare('SELECT ticket_type_id,quantity,status,expires_at_utc
        FROM ticket_inventory_holds WHERE order_id=? FOR UPDATE');
    $holds->execute([$orderId]);
    $reserved = [];
    foreach ($holds->fetchAll(PDO::FETCH_ASSOC) as $hold) {
        if ($hold['status'] !== 'active' || (string)$hold['expires_at_utc'] <= gmdate('Y-m-d H:i:s')) {
            throw new DomainException('WOMPI_RESERVATION_EXPIRED');
        }
        $reserved[(int)$hold['ticket_type_id']] = (int)$hold['quantity'];
    }
    ksort($reserved); ksort($quantities);
    if (!$reserved || $reserved !== $quantities
        || ($order['expires_at_utc'] !== null
            && (string)$order['expires_at_utc'] <= gmdate('Y-m-d H:i:s'))) {
        throw new DomainException('WOMPI_RESERVATION_MISSING');
    }
    $digest = hash('sha256', 't07|' . $buyerId . '|' . $orderId . '|' . $key);
    $prior = $db->prepare('SELECT provider_reference,idempotency_key_hash,gross_amount_cop
        FROM ticket_payment_attempts WHERE order_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE');
    $prior->execute([$orderId]);
    $existing = $prior->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        $reference = (string)$existing['provider_reference'];
        if (!hash_equals((string)$existing['idempotency_key_hash'], $digest)
            || (int)$existing['gross_amount_cop'] !== $total
            || !str_starts_with($reference, 'BW7-' . $orderId . '-' . $method . '-')) {
            throw new DomainException('WOMPI_ATTEMPT_CONFLICT');
        }
        return brvtal_ticket_wompi_payload($config, $reference, $total, $method);
    }
    $reference = 'BW7-' . $orderId . '-' . $method . '-' . bin2hex(random_bytes(12));
    $create = $db->prepare("INSERT INTO ticket_payment_attempts
        (order_id,provider,provider_reference,idempotency_key_hash,gross_amount_cop,status)
        VALUES (?,'wompi',?,?,?,'created')");
    $create->execute([$orderId,$reference,$digest,$total]);
    $db->prepare("UPDATE ticket_orders SET status='pending',updated_at_utc=UTC_TIMESTAMP(6)
        WHERE id=? AND status='draft'")->execute([$orderId]);
    // No network charge, no status=paid, no issuance. T08 verifies signed webhook.
    return brvtal_ticket_wompi_payload($config, $reference, $total, $method);
}

/** Read-only observation; even APPROVED here never changes order state. */
function brvtal_ticket_wompi_observe(array $config, string $providerId, ?callable $transport): array
{
    if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $providerId) || $transport === null) {
        return ['state'=>'unverified'];
    }
    try {
        $reply = $transport(($config['apiBase'] ?? 'https://sandbox.wompi.co/v1') . '/transactions/' . rawurlencode($providerId),
            ['Authorization'=>'Bearer ' . $config['private']]);
        $status = (int)($reply['status'] ?? 0);
        if ($status !== 200) return ['state'=>'unverified'];
        $data = json_decode((string)($reply['body'] ?? ''), true);
        $observed = is_array($data) ? (string)($data['data']['status'] ?? '') : '';
        return in_array($observed, ['PENDING','APPROVED','DECLINED','VOIDED','ERROR'], true)
            ? ['state'=>'observed_only','provider_status'=>$observed]
            : ['state'=>'unverified'];
    } catch (Throwable) {
        return ['state'=>'unverified']; // Timeouts and 5xx never authorize a sale.
    }
}
