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
const BRVTAL_WOMPI_RECONCILE_OLD_BACKOFF_SECONDS = 900;
const BRVTAL_WOMPI_RECONCILE_STALE_HOURS = 168;
const BRVTAL_WOMPI_RECONCILE_STALE_BACKOFF_SECONDS = 21600;

/** Same eligibility for the candidate and its atomic, autocommitted claim. */
function brvtal_wompi_reconcile_due_sql(): string
{
    return "provider='wompi' AND status IN ('created','pending')
        AND updated_at_utc <= UTC_TIMESTAMP(6) - INTERVAL (CASE
            WHEN created_at_utc <= UTC_TIMESTAMP(6) - INTERVAL " . BRVTAL_WOMPI_RECONCILE_STALE_HOURS . " HOUR
                THEN " . BRVTAL_WOMPI_RECONCILE_STALE_BACKOFF_SECONDS . "
            WHEN created_at_utc <= UTC_TIMESTAMP(6) - INTERVAL " . BRVTAL_WOMPI_RECONCILE_WINDOW_HOURS . " HOUR
                THEN " . BRVTAL_WOMPI_RECONCILE_OLD_BACKOFF_SECONDS . "
            ELSE " . BRVTAL_WOMPI_RECONCILE_BACKOFF_SECONDS . " END) SECOND";
}

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
 * skipped | not_found | not_approved | stale_pending | approved | approved_unfulfilled | duplicate | unavailable.
 * stale_pending is an operational signal, not a financial state or a release of inventory.
 */
function brvtal_wompi_reconcile_attempt(PDO $db, int $attemptId, ?array $config = null, ?callable $transport = null): string
{
    if ($attemptId < 1 || $db->inTransaction()) throw new LogicException('RECONCILE_INVALID_REQUEST');
    $config ??= brvtal_ticket_wompi_config();
    // Mock never talks to Wompi; only a real environment can confirm money.
    if (!in_array($config['mode'] ?? '', ['sandbox', 'production'], true)) return 'skipped';
    $read = $db->prepare("SELECT id,provider_reference,status,gross_amount_cop,
        (created_at_utc <= UTC_TIMESTAMP(6) - INTERVAL " . BRVTAL_WOMPI_RECONCILE_STALE_HOURS . " HOUR) AS stale_pending
        FROM ticket_payment_attempts WHERE id=? AND " . brvtal_wompi_reconcile_due_sql());
    $read->execute([$attemptId]);
    $attempt = $read->fetch(PDO::FETCH_ASSOC);
    if (!is_array($attempt) || !in_array($attempt['status'], ['created', 'pending'], true)) return 'skipped';
    // Atomic conditional claim before I/O; no DB lock held across the provider call.
    $claim = $db->prepare('UPDATE ticket_payment_attempts SET updated_at_utc=UTC_TIMESTAMP(6)
        WHERE id=? AND ' . brvtal_wompi_reconcile_due_sql());
    $claim->execute([$attemptId]);
    if ($claim->rowCount() !== 1) return 'skipped';
    $stale = (int)$attempt['stale_pending'] === 1;
    try {
        $tx = brvtal_wompi_reconcile_fetch($config, (string)$attempt['provider_reference'], $transport);
    } catch (RuntimeException) {
        return 'unavailable';
    }
    if ($tx === null) return $stale ? 'stale_pending' : 'not_found';
    if (($tx['status'] ?? null) !== 'APPROVED') return $stale ? 'stale_pending' : 'not_approved';
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
    return in_array($result, ['approved','approved_unfulfilled'], true) ? $result : 'duplicate';
}

/** Worker step: at most one due open attempt, oldest check first, irrespective of age. */
function brvtal_wompi_reconcile_next(PDO $db, ?array $config = null, ?callable $transport = null): string
{
    $next = $db->prepare('SELECT id FROM ticket_payment_attempts WHERE '
        . brvtal_wompi_reconcile_due_sql() . ' ORDER BY updated_at_utc, id LIMIT 1');
    $next->execute();
    $id = $next->fetchColumn();
    return $id === false ? 'idle' : brvtal_wompi_reconcile_attempt($db, (int)$id, $config, $transport);
}
