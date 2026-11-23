<?php
declare(strict_types=1);
require_once __DIR__ . '/ticketing_wompi_client.php';

/** T08: the events secret is NOT the checkout integrity secret, and belongs to the active environment. */
function brvtal_wompi_hook_config(?array $env = null): array
{
    $base = brvtal_ticket_wompi_config($env);
    $prefix = (string)($base['eventsPrefix'] ?? 'test_events_');
    $secret = brvtal_secret_value(($base['keyPrefix'] ?? 'BRVTAL_TICKET_WOMPI_') . 'EVENTS_SECRET', $env);
    if (!is_string($secret) || !str_starts_with($secret, $prefix) || strlen($secret) < strlen($prefix) + 8) {
        throw new DomainException('WEBHOOK_DISABLED');
    }
    return $base + ['eventsSecret' => $secret];
}

/** Authenticate signed fields before looking at any untrusted transaction metadata. */
function brvtal_wompi_hook_verify(string $raw, string $headerChecksum, array $settings, ?int $now = null): array
{
    if ($raw === '' || strlen($raw) > 65536) throw new DomainException('WEBHOOK_INVALID');
    try { $event = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new DomainException('WEBHOOK_INVALID'); }
    if (!is_array($event) || ($event['event'] ?? null) !== 'transaction.updated'
        || ($event['environment'] ?? null) !== ($settings['eventEnvironment'] ?? 'test') || !is_int($event['timestamp'] ?? null)
        || !is_array($event['data']['transaction'] ?? null)
        || !is_array($event['signature']['properties'] ?? null)) {
        throw new DomainException('WEBHOOK_INVALID');
    }
    $stamp = $event['timestamp'];
    $clock = $now ?? time();
    // Wompi retries for up to 24 h; older or future events are never authorization.
    if ($stamp < $clock - 172800 || $stamp > $clock + 300) throw new DomainException('WEBHOOK_REPLAY');
    $props = $event['signature']['properties'];
    if (count($props) < 3 || count($props) > 12 || count(array_unique($props)) !== count($props)) {
        throw new DomainException('WEBHOOK_INVALID');
    }
    $must = ['transaction.id','transaction.status','transaction.amount_in_cents'];
    if (array_diff($must, $props)) throw new DomainException('WEBHOOK_UNSIGNED_FIELDS');
    $text = '';
    foreach ($props as $prop) {
        if (!is_string($prop) || !preg_match('/^transaction\.[a-z][a-z0-9_]*$/D', $prop)) {
            throw new DomainException('WEBHOOK_INVALID');
        }
        $field = substr($prop, 12);
        $value = $event['data']['transaction'][$field] ?? null;
        if ((!is_string($value) && !is_int($value)) || is_bool($value)) {
            throw new DomainException('WEBHOOK_INVALID');
        }
        $text .= (string) $value;
    }
    $checksum = $event['signature']['checksum'] ?? null;
    if (!is_string($checksum) || !preg_match('/^[a-f0-9]{64}$/iD', $checksum)
        || ($headerChecksum !== '' && (!preg_match('/^[a-f0-9]{64}$/iD', $headerChecksum)
            || !hash_equals(strtolower($checksum), strtolower($headerChecksum))))) {
        throw new DomainException('WEBHOOK_SIGNATURE');
    }
    if (!is_string($settings['eventsSecret'] ?? null)
        || !str_starts_with($settings['eventsSecret'], (string)($settings['eventsPrefix'] ?? 'test_events_'))) {
        throw new DomainException('WEBHOOK_DISABLED');
    }
    $expected = hash('sha256', $text . $stamp . $settings['eventsSecret']);
    if (!hash_equals($expected, strtolower($checksum))) throw new DomainException('WEBHOOK_SIGNATURE');
    $tx = $event['data']['transaction'];
    if (!is_string($tx['id'] ?? null) || !preg_match('/^[a-zA-Z0-9-]{1,128}$/D', $tx['id'])
        || !in_array($tx['status'] ?? null, ['PENDING','APPROVED','DECLINED','VOIDED','ERROR'], true)
        || !is_int($tx['amount_in_cents'] ?? null) || $tx['amount_in_cents'] < 100
        || $tx['amount_in_cents'] > 1000000000 || $tx['amount_in_cents'] % 100 !== 0
        || !is_string($tx['reference'] ?? null)
        || !preg_match('/^BW7-[1-9][0-9]*-(?:CARD|NEQUI|PSE)-[a-f0-9]{24}$/D', $tx['reference'])
        || ($tx['currency'] ?? null) !== 'COP') {
        throw new DomainException('WEBHOOK_INVALID');
    }
    return $tx + ['_event_checksum'=>strtolower($checksum), '_event_timestamp'=>$stamp];
}

/** The private GET authenticates reference/currency, which Wompi may NOT sign. */
function brvtal_wompi_hook_lookup(array $settings, string $id, ?callable $transport = null): array
{
    $url = ($settings['apiBase'] ?? 'https://sandbox.wompi.co/v1') . '/transactions/' . rawurlencode($id);
    if ($transport !== null) {
        $response = $transport($url, ['Authorization' => 'Bearer ' . $settings['private']]);
    } else {
        if (!in_array($settings['mode'] ?? '', ['sandbox','production'], true) || !function_exists('curl_init')) {
            throw new RuntimeException('WEBHOOK_PROVIDER_UNAVAILABLE');
        }
        $handle = curl_init($url);
        if ($handle === false) throw new RuntimeException('WEBHOOK_PROVIDER_UNAVAILABLE');
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>4,
            CURLOPT_CONNECTTIMEOUT=>2, CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_HTTPHEADER=>['Authorization: Bearer ' . $settings['private']]]);
        $body = curl_exec($handle);
        $response = ['status'=>curl_getinfo($handle,CURLINFO_HTTP_CODE), 'body'=>$body];
    }
    if (!is_array($response) || (int)($response['status'] ?? 0) !== 200
        || !is_string($response['body'] ?? null) || strlen($response['body']) > 65536) {
        throw new RuntimeException('WEBHOOK_PROVIDER_UNAVAILABLE');
    }
    $json = json_decode($response['body'], true);
    $tx = is_array($json) ? ($json['data'] ?? null) : null;
    return is_array($tx) ? $tx : throw new RuntimeException('WEBHOOK_PROVIDER_UNAVAILABLE');
}

/** The consumer in T09 owns issuance; enqueue one durable request per paid order. */
function brvtal_wompi_hook_enqueue(PDO $db, int $orderId, int $attemptId): void
{
    $db->prepare("INSERT INTO ticket_issue_outbox (order_id,payment_attempt_id)
        SELECT id,? FROM ticket_orders WHERE id=? AND status='paid'
        ON DUPLICATE KEY UPDATE payment_attempt_id=ticket_issue_outbox.payment_attempt_id")->execute([$attemptId,$orderId]);
}

/** Single row-locked transaction; repeated/out-of-order notifications are no-ops. */
function brvtal_wompi_hook_apply(PDO $db, array $signed, array $provider): string
{
    foreach (['id','reference','status','currency','amount_in_cents'] as $key) {
        if (!array_key_exists($key, $provider) || $signed[$key] !== $provider[$key]) {
            // A later provider status can supersede a delayed signed notification.
            if ($key !== 'status' || !in_array($provider['status'], ['APPROVED','PENDING','DECLINED','VOIDED','ERROR'], true)) {
                throw new DomainException('WEBHOOK_PROVIDER_MISMATCH');
            }
        }
    }
    if (!$db->inTransaction()) throw new LogicException('WEBHOOK_TRANSACTION_REQUIRED');
    // Same lock order as T07: parent order first, then payment attempt.
    $find = $db->prepare("SELECT order_id FROM ticket_payment_attempts
        WHERE provider='wompi' AND provider_reference=?");
    $find->execute([$signed['reference']]);
    $orderId = $find->fetchColumn();
    if ($orderId === false) throw new DomainException('WEBHOOK_ORDER_MISMATCH');
    $order = $db->prepare('SELECT status,currency,total_cop FROM ticket_orders WHERE id=? FOR UPDATE');
    $order->execute([(int)$orderId]);
    $row = $order->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['currency'] !== 'COP' || (int)$row['total_cop'] * 100 !== $signed['amount_in_cents']) {
        throw new DomainException('WEBHOOK_ORDER_MISMATCH');
    }
    $get = $db->prepare("SELECT id,order_id,provider_transaction_id,gross_amount_cop,status
        FROM ticket_payment_attempts WHERE provider='wompi' AND provider_reference=? FOR UPDATE");
    $get->execute([$signed['reference']]);
    $attempt = $get->fetch(PDO::FETCH_ASSOC);
    if (!$attempt || (int)$attempt['order_id'] !== (int)$orderId
        || (int)$attempt['gross_amount_cop'] * 100 !== $signed['amount_in_cents']
        || ($attempt['provider_transaction_id'] !== null && $attempt['provider_transaction_id'] !== $signed['id'])) {
        throw new DomainException('WEBHOOK_ORDER_MISMATCH');
    }
    if (!is_string($signed['_event_checksum'] ?? null)
        || !preg_match('/^[a-f0-9]{64}$/D', $signed['_event_checksum'])
        || !is_int($signed['_event_timestamp'] ?? null)) {
        throw new DomainException('WEBHOOK_INVALID');
    }
    $journal = $db->prepare("INSERT INTO ticket_payment_webhook_events
        (attempt_id,provider_transaction_id,event_checksum,signed_status,event_timestamp)
        VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id");
    $journal->execute([(int)$attempt['id'],$signed['id'],$signed['_event_checksum'],
        $signed['status'],$signed['_event_timestamp']]);
    $status = ['APPROVED'=>'approved','PENDING'=>'pending','DECLINED'=>'declined',
        'VOIDED'=>'declined','ERROR'=>'error'][$provider['status']];
    if ($attempt['status'] === 'approved' || $attempt['status'] === 'refunded') {
        if ($attempt['status'] === 'approved') {
            brvtal_wompi_hook_enqueue($db,(int)$attempt['order_id'],(int)$attempt['id']);
        }
        return 'duplicate'; // Approved/settled payment never downgrades.
    }
    if ($status === $attempt['status'] && $attempt['provider_transaction_id'] === $signed['id']) {
        return 'duplicate';
    }
    $update = $db->prepare('UPDATE ticket_payment_attempts SET status=?,provider_transaction_id=?,
        updated_at_utc=UTC_TIMESTAMP(6) WHERE id=?');
    $update->execute([$status,$signed['id'],(int)$attempt['id']]);
    if ($status === 'approved') {
        // A paid order is the durable signal for T09 issuance; no ticket emitted here.
        $db->prepare("UPDATE ticket_orders SET status='paid',updated_at_utc=UTC_TIMESTAMP(6)
            WHERE id=? AND status IN ('pending','draft')")->execute([(int)$attempt['order_id']]);
        brvtal_wompi_hook_enqueue($db,(int)$attempt['order_id'],(int)$attempt['id']);
    }
    return $status === 'approved' ? 'approved' : 'accepted';
}
