<?php
declare(strict_types=1);

require_once __DIR__ . '/ticketing_issue.php';
require_once __DIR__ . '/ticketing_mail_templates.php';

/**
 * #1945: issue the oldest paid order and queue its QR email atomically.
 * Runs from the trusted CLI mail worker (cron), never from a public route.
 * The QR secret only travels inside the sealed mail body, in a URL fragment.
 */
/** #1956: last issuance failure, kept so every cron run can show it. */
function brvtal_ticket_issue_last_failure_path(): string
{
    return (string)(getenv('BRVTAL_TICKET_ISSUE_LAST_FAILURE_FILE') ?: __DIR__ . '/../storage/ticket-issue-last-failure.json');
}

/** @return array{code:string,at:string}|null */
function brvtal_ticket_issue_last_failure(): ?array
{
    $raw = @file_get_contents(brvtal_ticket_issue_last_failure_path());
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data) || !is_string($data['code'] ?? null) || !is_string($data['at'] ?? null)
        || brvtal_ticket_issue_failure_code($data['code']) !== $data['code']
        || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $data['at'])) {
        return null;
    }
    return ['code' => $data['code'], 'at' => $data['at']];
}

function brvtal_ticket_issue_remember_failure(?string $code): void
{
    $path = brvtal_ticket_issue_last_failure_path();
    // Best effort only: diagnostics must never break issuance.
    if ($code === null) {
        @unlink($path);
        return;
    }
    @file_put_contents($path, json_encode(['code' => brvtal_ticket_issue_failure_code($code),
        'at' => gmdate('Y-m-d\TH:i:s\Z')]), LOCK_EX);
}

/** #1954: a safe, controlled failure code for the worker output. */
function brvtal_ticket_issue_failure_code(string $message): string
{
    return preg_match('/^[A-Z][A-Z0-9_]{2,63}$/D', $message) === 1 ? $message : 'ISSUE_FAILED';
}

/** Wait without spending an attempt: the job stays pending for a later run. */
const BRVTAL_TICKET_ISSUE_WAIT_CODES = ['MAIL_PRIVACY_NOT_APPROVED', 'MAIL_RATE_LIMITED'];

function brvtal_ticket_issue_ticket_url(string $publicId, string $token, string $origin): string
{
    if (!preg_match('/^[a-f0-9]{32}$/D', $publicId) || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $token)) {
        throw new DomainException('ISSUE_DELIVERY_INVALID');
    }
    return $origin . '/mi-cuenta/boleta#BRVTAL:T1:' . $publicId . ':' . $token;
}

/** Builds the in-transaction deliverer used by brvtal_ticket_issue_consume. */
function brvtal_ticket_issue_mail_deliverer(string $origin): callable
{
    return static function (PDO $db, int $orderId, array $tickets) use ($origin): void {
        $order = $db->prepare('SELECT b.email,e.title FROM ticket_orders o
            JOIN ticket_buyers b ON b.id=o.buyer_id JOIN events e ON e.id=o.event_id
            WHERE o.id=? LIMIT 1');
        $order->execute([$orderId]);
        $row = $order->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !is_string($row['email']) || $row['email'] === '') {
            throw new DomainException('ISSUE_DELIVERY_RECIPIENT');
        }
        $names = $db->prepare('SELECT LOWER(HEX(t.public_id)) AS id,i.ticket_name_snapshot AS name
            FROM ticket_entries t JOIN ticket_order_items i ON i.id=t.order_item_id
            WHERE i.order_id=?');
        $names->execute([$orderId]);
        $byId = [];
        foreach ($names->fetchAll(PDO::FETCH_ASSOC) as $entry) {
            $byId[$entry['id']] = trim((string)$entry['name']) ?: 'Boleta';
        }
        $links = [];
        foreach ($tickets as $index => $ticket) {
            $id = (string)($ticket['public_id'] ?? '');
            if (!isset($byId[$id])) throw new DomainException('ISSUE_DELIVERY_INVALID');
            $links[] = ['label' => sprintf('Boleta %d · %s', $index + 1, $byId[$id]),
                'url' => brvtal_ticket_issue_ticket_url($id, (string)($ticket['token'] ?? ''), $origin)];
        }
        // The trusted issuance transaction has already verified payment and
        // moved the issue outbox to "done". The token and sealed message either
        // commit together or both roll back.
        // Lazy-load the account-creation integration only after the paid
        // issuance path has been reached. The mail-only CLI worker also runs
        // on pre-ticketing / restricted schemas and must remain functional.
        require_once __DIR__ . '/buyer_auth_flows.php';
        $guestPasswordLink = brvtalBuyerAuthGuestPurchaseLink($db, $orderId);
        $mailData = [
            'event' => (string)$row['title'],
            'link' => $origin . '/buyer/',
            'order_number' => $orderId,
            'tickets' => $links,
        ];
        if ($guestPasswordLink !== null) {
            $mailData['guest_password_link'] = $guestPasswordLink;
        }
        brvtal_ticket_mail_queue_order($db, $orderId, 'payment_approved', $row['email'], $mailData);
    };
}

/**
 * Processes at most one issuance job. Returns a state, never buyer data or tokens:
 * idle | issued | duplicate | waiting | failed:<CODE>.
 */
function brvtal_ticket_issue_process_next(PDO $db, string $origin = 'https://www.brvtal.com.co'): string
{
    if (!in_array($origin, ['https://www.brvtal.com.co', 'https://brvtal.invalid'], true)) {
        throw new InvalidArgumentException('ISSUE_DELIVERY_ORIGIN');
    }
    // #1954: no fixed attempt cap. A paid order must get its tickets once the
    // cause is fixed; the growing backoff keeps a broken job from starving others.
    $next = $db->prepare("SELECT id FROM ticket_issue_outbox
        WHERE state IN ('pending','failed')
          AND (next_attempt_at_utc IS NULL OR next_attempt_at_utc <= UTC_TIMESTAMP(6))
        ORDER BY COALESCE(next_attempt_at_utc, created_at_utc), id LIMIT 1");
    $next->execute();
    $jobId = $next->fetchColumn();
    if ($jobId === false) return 'idle';
    $jobId = (int)$jobId;
    try {
        $result = brvtal_ticket_issue_consume($db, $jobId, brvtal_ticket_issue_mail_deliverer($origin));
        brvtal_ticket_issue_remember_failure(null);
        return $result['state'] === 'duplicate' ? 'duplicate' : 'issued';
    } catch (Throwable $error) {
        $code = $error->getMessage();
        $wait = in_array($code, BRVTAL_TICKET_ISSUE_WAIT_CODES, true);
        $attempts = $db->prepare('SELECT attempts FROM ticket_issue_outbox WHERE id=?');
        $attempts->execute([$jobId]);
        // Back off so one broken order never starves the rest of the queue: 1, 2, 4 … 60 min.
        $delay = $wait ? 300 : min(3600, 60 * (2 ** min(6, (int)$attempts->fetchColumn())));
        $db->prepare("UPDATE ticket_issue_outbox
            SET state=IF(?=1,state,'failed'),attempts=attempts+IF(?=1,0,1),
                next_attempt_at_utc=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL ? SECOND),
                updated_at_utc=UTC_TIMESTAMP(6)
            WHERE id=? AND state IN ('pending','failed')")
            ->execute([$wait ? 1 : 0, $wait ? 1 : 0, $delay, $jobId]);
        brvtal_ticket_issue_remember_failure($code);
        // Only controlled codes leave the worker: never emails, tokens or SQL text.
        return $wait ? 'waiting' : 'failed:' . brvtal_ticket_issue_failure_code($code);
    }
}

/**
 * #1976: an admin asks for the next issuance attempt of one order to happen now.
 * Only moves the due time of a pending or failed job; the CLI worker still issues.
 */
function brvtal_ticket_issue_retry_now(PDO $db, int $orderId): bool
{
    if ($orderId < 1) throw new InvalidArgumentException('ISSUE_RETRY_ORDER');
    $retry = $db->prepare("UPDATE ticket_issue_outbox
        SET next_attempt_at_utc=UTC_TIMESTAMP(6),updated_at_utc=UTC_TIMESTAMP(6)
        WHERE order_id=? AND state IN ('pending','failed')");
    $retry->execute([$orderId]);
    if ($retry->rowCount() > 0) return true;
    // Already due: rowCount can be 0 when nothing changed, so ask again.
    $due = $db->prepare("SELECT 1 FROM ticket_issue_outbox
        WHERE order_id=? AND state IN ('pending','failed') LIMIT 1");
    $due->execute([$orderId]);
    return $due->fetchColumn() !== false;
}
