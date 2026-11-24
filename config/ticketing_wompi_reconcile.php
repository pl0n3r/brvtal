<?php
declare(strict_types=1);

require_once __DIR__ . '/ticketing_wompi_webhook.php';

/**
 * #1952: confirm a payment whose signed webhook never arrived by asking Wompi
 * directly, server to server, with the active environment's private key.
 * Only an APPROVED transaction with the exact reference, amount and currency
 * confirms; it then goes through the same transactional rules as the webhook
 * (brvtal_wompi_hook_apply). Rejections and network errors never change state.
 */
const BRVTAL_WOMPI_RECONCILE_WINDOW_HOURS = 48;
const BRVTAL_WOMPI_RECONCILE_BACKOFF_SECONDS = 60;

/** Marks a journal row as "verified by the Wompi API", never as a signed event. */
function brvtal_wompi_reconcile_checksum(string $transactionId): string
{
    return hash('sha256', 'wompi-api-verified:' . $transactionId . ':APPROVED');
}

/** GET {apiBase}/transactions?reference=… authenticated with the private key. */
function brvtal_wompi_reconcile_fetch(array $config, string $reference, ?callable $transport = null): ?array
{
    if (!preg_match('/^BW7-[1-9][0-9]*-(?:CARD|NEQUI|PSE)-[a-f0-9]{24}$/D', $reference)) {
        throw new DomainException('RECONCILE_REFERENCE_INVALID');
    }
    $url = ($config['apiBase'] ?? '') . '/transactions?reference=' . rawurlencode($reference);
    $headers = ['Authorization' => 'Bearer ' . ($config['private'] ?? '')];
    if ($transport !== null) {
        $response = $transport($url, $headers);
    } else {
        if (!function_exists('curl_init')) throw new RuntimeException('RECONCILE_PROVIDER_UNAVAILABLE');
        $handle = curl_init($url);
        if ($handle === false) throw new RuntimeException('RECONCILE_PROVIDER_UNAVAILABLE');
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>4,
            CURLOPT_CONNECTTIMEOUT=>2, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_HTTPHEADER=>['Authorization: Bearer ' . ($config['private'] ?? '')]]);
        $body = curl_exec($handle);
        $response = ['status'=>curl_getinfo($handle, CURLINFO_HTTP_CODE), 'body'=>$body];
    }
    if (!is_array($response) || (int)($response['status'] ?? 0) !== 200
        || !is_string($response['body'] ?? null) || strlen($response['body']) > 262144) {
        throw new RuntimeException('RECONCILE_PROVIDER_UNAVAILABLE');
    }
    $json = json_decode($response['body'], true);
    $list = is_array($json) ? ($json['data'] ?? null) : null;
    if (!is_array($list)) throw new RuntimeException('RECONCILE_PROVIDER_UNAVAILABLE');
    $match = null;
    foreach ($list as $tx) {
        if (!is_array($tx) || ($tx['reference'] ?? null) !== $reference) continue;
        if (($tx['status'] ?? null) === 'APPROVED') return $tx;
        $match ??= $tx;
    }
    return $match;
}

/**
 * Reconcile one open attempt. Returns a state, never buyer data or keys:
 * skipped | not_found | not_approved | approved | duplicate | unavailable.
 */
function brvtal_wompi_reconcile_attempt(PDO $db, int $attemptId, ?array $config = null, ?callable $transport = null): string
{
    if ($attemptId < 1 || $db->inTransaction()) throw new LogicException('RECONCILE_INVALID_REQUEST');
    $config ??= brvtal_ticket_wompi_config();
    // Mock never talks to Wompi; only a real environment can confirm money.
    if (!in_array($config['mode'] ?? '', ['sandbox', 'production'], true)) return 'skipped';
    $read = $db->prepare("SELECT id,provider_reference,status,gross_amount_cop FROM ticket_payment_attempts
        WHERE id=? AND provider='wompi'");
    $read->execute([$attemptId]);
    $attempt = $read->fetch(PDO::FETCH_ASSOC);
    if (!is_array($attempt) || !in_array($attempt['status'], ['created', 'pending'], true)) return 'skipped';
    // Back off before the network call so a slow provider never hammers itself.
    $db->prepare('UPDATE ticket_payment_attempts SET updated_at_utc=UTC_TIMESTAMP(6) WHERE id=?')
        ->execute([$attemptId]);
    try {
        $tx = brvtal_wompi_reconcile_fetch($config, (string)$attempt['provider_reference'], $transport);
    } catch (RuntimeException) {
        return 'unavailable';
    }
    if ($tx === null) return 'not_found';
    if (($tx['status'] ?? null) !== 'APPROVED') return 'not_approved';
    if (!is_string($tx['id'] ?? null) || !preg_match('/^[a-zA-Z0-9-]{1,128}$/D', $tx['id'])
        || ($tx['currency'] ?? null) !== 'COP' || !is_int($tx['amount_in_cents'] ?? null)
        || $tx['amount_in_cents'] !== (int)$attempt['gross_amount_cop'] * 100) {
        return 'not_approved';
    }
    $verified = ['id'=>$tx['id'], 'reference'=>$attempt['provider_reference'], 'status'=>'APPROVED',
        'currency'=>'COP', 'amount_in_cents'=>$tx['amount_in_cents'],
        '_event_checksum'=>brvtal_wompi_reconcile_checksum($tx['id']), '_event_timestamp'=>time()];
    $db->beginTransaction();
    try {
        $result = brvtal_wompi_hook_apply($db, $verified, $verified);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        if ($error instanceof DomainException) return 'not_approved';
        return 'unavailable';
    }
    return $result === 'approved' ? 'approved' : 'duplicate';
}

/** Worker step: at most one open attempt from the last 48 h, oldest check first. */
function brvtal_wompi_reconcile_next(PDO $db, ?array $config = null, ?callable $transport = null): string
{
    $next = $db->prepare("SELECT id FROM ticket_payment_attempts
        WHERE provider='wompi' AND status IN ('created','pending')
          AND created_at_utc >= UTC_TIMESTAMP(6) - INTERVAL " . BRVTAL_WOMPI_RECONCILE_WINDOW_HOURS . " HOUR
          AND updated_at_utc <= UTC_TIMESTAMP(6) - INTERVAL " . BRVTAL_WOMPI_RECONCILE_BACKOFF_SECONDS . " SECOND
        ORDER BY updated_at_utc, id LIMIT 1");
    $next->execute();
    $id = $next->fetchColumn();
    return $id === false ? 'idle' : brvtal_wompi_reconcile_attempt($db, (int)$id, $config, $transport);
}
