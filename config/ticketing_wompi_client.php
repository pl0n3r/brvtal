<?php
declare(strict_types=1);
require_once __DIR__ . '/secrets.php';

/** T07: sandbox-only hosted Wompi checkout preparation; never charges or confirms a sale. */
function brvtal_ticket_wompi_config(?array $override = null): array
{
    $env = static function (string $key) use ($override): string {
        $value = brvtal_secret_value($key, $override);
        return is_string($value) ? trim($value) : '';
    };
    $mode = $env('BRVTAL_TICKET_WOMPI_MODE');
    if (!in_array($mode, ['mock', 'sandbox'], true)
        || ($mode === 'mock' && $env('BRVTAL_TICKET_WOMPI_TESTING') !== '1')
        || ($mode === 'sandbox' && $env('BRVTAL_TICKET_WOMPI_SANDBOX_ENABLED') !== '1')) {
        throw new DomainException('WOMPI_DISABLED');
    }
    $public = $env('BRVTAL_TICKET_WOMPI_PUBLIC_KEY');
    $private = $env('BRVTAL_TICKET_WOMPI_PRIVATE_KEY');
    $integrity = $env('BRVTAL_TICKET_WOMPI_INTEGRITY_SECRET');
    if (!str_starts_with($public, 'pub_test_') || strlen($public) < 13
        || !str_starts_with($private, 'prv_test_') || strlen($private) < 13
        || !str_starts_with($integrity, 'test_integrity_') || strlen($integrity) < 20) {
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
    return compact('mode', 'public', 'private', 'integrity', 'methods', 'returnUrl');
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
        $qty = (int)$row['quantity']; $unit = (int)$row['unit_price_cop'];
        if ($row['currency'] !== 'COP' || $qty < 1 || $qty > 20 || $unit < 1
            || $unit > 10000000 || $unit * $qty !== (int)$row['subtotal_cop']) {
            throw new DomainException('WOMPI_PRICE_MISMATCH');
        }
        $total += $unit * $qty;
        $quantities[(int)$row['ticket_type_id']] = $qty;
    }
    if ($total !== (int)$order['total_cop']) throw new DomainException('WOMPI_PRICE_MISMATCH');
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

/** Read-only sandbox observation; even APPROVED here never changes order state. */
function brvtal_ticket_wompi_observe(array $config, string $providerId, ?callable $transport): array
{
    if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $providerId) || $transport === null) {
        return ['state'=>'unverified'];
    }
    try {
        $reply = $transport('https://sandbox.wompi.co/v1/transactions/' . rawurlencode($providerId),
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
