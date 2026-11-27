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
const BRVTAL_WOMPI_RECONCILE_LEASE_SECONDS = 15;
const BRVTAL_WOMPI_RECONCILE_BUDGET_SECONDS = 60;

/** Same eligibility for candidate selection and transactional lease acquisition. */
function brvtal_wompi_reconcile_due_sql(): string
{
    return "a.provider='wompi' AND a.status IN ('created','pending')
        AND (c.claim_id IS NULL OR c.expires_at_utc <= UTC_TIMESTAMP(6))
        AND COALESCE(c.last_started_at_utc,a.updated_at_utc) <= UTC_TIMESTAMP(6) - INTERVAL (CASE
            WHEN a.created_at_utc <= UTC_TIMESTAMP(6) - INTERVAL " . BRVTAL_WOMPI_RECONCILE_STALE_HOURS . " HOUR
                THEN " . BRVTAL_WOMPI_RECONCILE_STALE_BACKOFF_SECONDS . "
            WHEN a.created_at_utc <= UTC_TIMESTAMP(6) - INTERVAL " . BRVTAL_WOMPI_RECONCILE_WINDOW_HOURS . " HOUR
                THEN " . BRVTAL_WOMPI_RECONCILE_OLD_BACKOFF_SECONDS . "
            ELSE " . BRVTAL_WOMPI_RECONCILE_BACKOFF_SECONDS . " END) SECOND";
}

/** No numerical provider quota is assumed: limits are explicitly configured per
 * environment by the operator; injected values are for internal fixtures. */
function brvtal_wompi_reconcile_policy(array $config): ?array
{
    $mode = $config['mode'] ?? '';
    if (!in_array($mode, ['sandbox','production'], true)) return null;
    $prefix = $mode === 'production' ? 'BRVTAL_TICKET_WOMPI_PROD_' : 'BRVTAL_TICKET_WOMPI_';
    $limits = [];
    foreach (['requests_per_minute'=>'REQUESTS_PER_MINUTE','max_in_flight'=>'MAX_IN_FLIGHT'] as $key=>$suffix) {
        $raw = $config['reconcile_'.$key] ?? brvtal_secret_value($prefix.'RECONCILE_'.$suffix);
        if (!is_int($raw) && !is_string($raw)) return null;
        $value = filter_var($raw, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>10000]]);
        if ($value === false) return null;
        $limits[$key] = $value;
    }
    return $limits['max_in_flight'] <= $limits['requests_per_minute'] ? $limits : null;
}

/** Serialize claim acquisition with the environment budget row, commit before I/O. */
function brvtal_wompi_reconcile_claim(PDO $db, int $id, string $mode, array $policy): ?array
{
    $identity = bin2hex(random_bytes(16));
    $db->beginTransaction();
    try {
        $budget = $db->prepare("INSERT INTO ticket_wompi_reconcile_budget(environment,window_started_at_utc)
            VALUES (?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE environment=environment");
        $budget->execute([$mode]);
        $read = $db->prepare("SELECT a.id,a.provider_reference,a.status,a.gross_amount_cop,
            (a.created_at_utc <= UTC_TIMESTAMP(6) - INTERVAL " . BRVTAL_WOMPI_RECONCILE_STALE_HOURS . " HOUR) AS stale_pending
            FROM ticket_payment_attempts a LEFT JOIN ticket_wompi_reconcile_claims c ON c.attempt_id=a.id
            WHERE a.id=? AND (c.environment IS NULL OR c.environment=?) AND " . brvtal_wompi_reconcile_due_sql() . " FOR UPDATE");
        $read->execute([$id,$mode]);
        $attempt = $read->fetch(PDO::FETCH_ASSOC);
        $active = $db->prepare("SELECT COUNT(*) FROM ticket_wompi_reconcile_claims
            WHERE environment=? AND expires_at_utc>UTC_TIMESTAMP(6)");
        $active->execute([$mode]);
        if (!$attempt || (int)$active->fetchColumn() >= $policy['max_in_flight']) {
            $db->rollBack(); return null;
        }
        $window = 'UTC_TIMESTAMP(6) - INTERVAL ' . BRVTAL_WOMPI_RECONCILE_BUDGET_SECONDS . ' SECOND';
        $consume = $db->prepare("UPDATE ticket_wompi_reconcile_budget
            SET request_count=CASE WHEN window_started_at_utc <= $window THEN 1 ELSE request_count+1 END,
                window_started_at_utc=CASE WHEN window_started_at_utc <= $window THEN UTC_TIMESTAMP(6) ELSE window_started_at_utc END
            WHERE environment=? AND (window_started_at_utc <= $window OR request_count < ?)");
        $consume->execute([$mode,$policy['requests_per_minute']]);
        if ($consume->rowCount() !== 1) { $db->rollBack(); return null; }
        $claim = $db->prepare("INSERT INTO ticket_wompi_reconcile_claims
            (attempt_id,environment,last_started_at_utc,claim_id,expires_at_utc)
            VALUES (?,?,UTC_TIMESTAMP(6),UNHEX(?),UTC_TIMESTAMP(6)+INTERVAL " . BRVTAL_WOMPI_RECONCILE_LEASE_SECONDS . " SECOND)
            ON DUPLICATE KEY UPDATE last_started_at_utc=VALUES(last_started_at_utc),
                claim_id=VALUES(claim_id),expires_at_utc=VALUES(expires_at_utc)");
        $claim->execute([$id,$mode,$identity]);
        $db->commit();
        return $attempt + ['claim_identity'=>$identity];
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function brvtal_wompi_reconcile_release(PDO $db, int $id, string $mode, string $identity): void
{
    $release = $db->prepare('UPDATE ticket_wompi_reconcile_claims SET claim_id=NULL,expires_at_utc=NULL
        WHERE attempt_id=? AND environment=? AND claim_id=UNHEX(?)');
    $release->execute([$id,$mode,$identity]);
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
    $policy = brvtal_wompi_reconcile_policy($config);
    if ($policy === null) return 'unavailable';
    $mode = $config['mode'];
    try { $attempt = brvtal_wompi_reconcile_claim($db,$attemptId,$mode,$policy); }
    catch (Throwable) { return 'unavailable'; }
    if ($attempt === null) return 'skipped';
    $identity = $attempt['claim_identity'];
    try {
        return brvtal_wompi_reconcile_claimed($db,$attemptId,$mode,$identity,$attempt,$config,$transport);
    } finally {
        // An abandoned lease expires; its identity cannot release a replacement.
        try { brvtal_wompi_reconcile_release($db,$attemptId,$mode,$identity); } catch (Throwable) {}
    }
}

function brvtal_wompi_reconcile_claimed(PDO $db, int $attemptId, string $mode, string $identity,
    array $attempt, array $config, ?callable $transport): string
{
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
        // Lock in the same order as acquisition. Otherwise an expired claimant
        // can hold the lease row while its successor holds the attempt row.
        // This mutex covers only local database work, never provider I/O.
        $mutex = $db->prepare('SELECT environment FROM ticket_wompi_reconcile_budget WHERE environment=? FOR UPDATE');
        $mutex->execute([$mode]);
        if ($mutex->fetchColumn() === false) { $db->rollBack(); return 'skipped'; }
        $fence = $db->prepare('SELECT 1 FROM ticket_wompi_reconcile_claims
            WHERE attempt_id=? AND environment=? AND claim_id=UNHEX(?) AND expires_at_utc>UTC_TIMESTAMP(6) FOR UPDATE');
        $fence->execute([$attemptId,$mode,$identity]);
        if ($fence->fetchColumn() === false) { $db->rollBack(); return 'skipped'; }
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
    $sql = 'SELECT a.id FROM ticket_payment_attempts a LEFT JOIN ticket_wompi_reconcile_claims c
        ON c.attempt_id=a.id WHERE ' . brvtal_wompi_reconcile_due_sql();
    $order = ' ORDER BY COALESCE(c.last_started_at_utc,a.updated_at_utc),a.id LIMIT 1';
    if ($db->query($sql.$order)->fetchColumn() === false) return 'idle';
    $config ??= brvtal_ticket_wompi_config();
    if (!in_array($config['mode'] ?? '', ['sandbox','production'], true)) return 'skipped';
    $next = $db->prepare($sql.' AND (c.environment IS NULL OR c.environment=?)'.$order);
    $next->execute([$config['mode']]);
    $id = $next->fetchColumn();
    return $id === false ? 'idle' : brvtal_wompi_reconcile_attempt($db, (int)$id, $config, $transport);
}
