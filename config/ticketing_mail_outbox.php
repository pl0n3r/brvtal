<?php
declare(strict_types=1);

require_once __DIR__ . '/ticketing_resend.php';
require_once __DIR__ . '/ticketing_mail_events.php';
require_once __DIR__ . '/public_abuse_guard.php';
require_once __DIR__ . '/mail_template_renderer.php';

/**
 * Enqueue INSIDE the caller's existing order/payment transaction. This does
 * not issue tickets, commit a sale, contact Resend or log recipient details.
 * The same key MUST identify the same content, not a second email.
 */
function brvtal_ticket_mail_enqueue(
    PDO $pdo, string $key, string $reference, string $recipient, string $subject, string $body,
    ?string $html = null, ?string $templateKey = null, ?int $templateVersion = null
): int {
    // No personal-data persistence without explicit privacy gate (or test fixture).
    // Private config wins over env. Only explicit "1" may bypass privacy.
    $testing = brvtal_secret_value('BRVTAL_TICKET_MAIL_TESTING') === '1';
    if (!$testing && brvtal_secret_value('BRVTAL_TICKET_MAIL_PRIVACY_APPROVED') !== '1') {
        throw new RuntimeException('MAIL_PRIVACY_NOT_APPROVED');
    }
    if (!$pdo->inTransaction()) {
        throw new RuntimeException('MAIL_TRANSACTION_REQUIRED');
    }
    if (!preg_match('/^[A-Za-z0-9:_-]{1,128}$/D', $key)
        || !preg_match('/^[A-Za-z0-9:_-]{1,128}$/D', $reference)
        || !filter_var($recipient, FILTER_VALIDATE_EMAIL)
        || $subject === '' || strlen($subject) > 255
        || $body === '' || strlen($body) > 50000) {
        throw new InvalidArgumentException('MAIL_INVALID_INPUT');
    }
    $sensitive = str_starts_with($body, BRVTAL_BUYER_MAIL_SEAL_PREFIX);
    if ($sensitive && $html === null) {
        // Existing BuyerAuth flow passes only the sealed text. Resolve the
        // published snapshot here, inside the caller's transaction, without
        // persisting a raw one-use bearer or changing its external API.
        if (!preg_match('/^buyer:[0-9]+:(verify_account|reset_password):[0-9]+$/D', $key, $matched)) {
            throw new DomainException('MAIL_TOKEN_CONTEXT_INVALID');
        }
        $plaintext = brvtalBuyerMailUnseal($body);
        if ($plaintext === null) throw new DomainException('MAIL_TOKEN_CONTEXT_INVALID');
        $link = brvtalBuyerMailExtractLink($plaintext, $matched[1]);
        if ($link === null) throw new DomainException('MAIL_TOKEN_CONTEXT_INVALID');
        $snapshot = brvtal_mail_delivery_render($pdo, $matched[1], ['link'=>$link]);
        $subject = $snapshot['subject'];
        $body = brvtalBuyerMailSeal($snapshot['text']);
        $html = brvtalBuyerMailSeal($snapshot['html'], 50000);
        $templateKey = $matched[1];
        $templateVersion = $snapshot['template_version'];
    }
    if ($html !== null && ($html === '' || strlen($html) > 150000
        || $templateKey === null || !preg_match('/^[a-z_]{1,64}$/D',$templateKey)
        || ($templateVersion !== null && $templateVersion < 1)
        || ($sensitive && !str_starts_with($html, BRVTAL_BUYER_MAIL_SEAL_PREFIX)))) {
        throw new InvalidArgumentException('MAIL_HTML_INVALID');
    }
    // A legacy, pre-migration database keeps a sealed/plain text-only path.
    // The new schema is required to preserve the HTML + template metadata.
    $schemaReady = false;
    if ($html !== null) {
        $columns = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ticket_mail_outbox'
            AND COLUMN_NAME IN ('body_html','template_key','template_version')");
        $schemaReady = (int)$columns->fetchColumn() === 3;
    }
    $fingerprint = hash('sha256', json_encode($html === null
        ? [$reference,$recipient,$subject,$body]
        : [$reference,$recipient,$subject,
            $sensitive ? hash('sha256',(string)brvtalBuyerMailUnseal($body)) : $body,
            $sensitive ? hash('sha256',(string)brvtalBuyerMailUnseal($html)) : $html,
            $templateKey,$templateVersion], JSON_THROW_ON_ERROR));
    // An idempotent replay must not spend the budget or enqueue a second email.
    $existing = $pdo->prepare('SELECT id,payload_hash FROM ticket_mail_outbox WHERE message_key=? LIMIT 1');
    $existing->execute([$key]);
    $row = $existing->fetch(PDO::FETCH_ASSOC);
    if (is_array($row)) {
        if (!hash_equals($fingerprint, (string)$row['payload_hash'])) {
            throw new DomainException('MAIL_KEY_CONFLICT');
        }
        return (int)$row['id'];
    }
    // #1966: the public anti-abuse limit protects user-triggered mail. Tickets and refunds
    // of a verified payment are queued only by the server and must never be dropped by it.
    // #1984: trusted server order transitions are not buyer-triggered mail.
    $paymentBacked = preg_match('/^order:[1-9][0-9]*:(payment_approved|refund(:[1-9][0-9]*)?|admin_new_order|order_cancelled|order_expired)$/D', $key) === 1;
    if (!$testing && !$paymentBacked) {
        $rate = brvtal_public_abuse_mail_limit($recipient);
        if (!$rate['allowed']) {
            throw new DomainException('MAIL_RATE_LIMITED');
        }
    }
    if ($schemaReady) {
        $stmt = $pdo->prepare('INSERT INTO ticket_mail_outbox
            (message_key,payload_hash,related_ref,recipient_email,subject,body_text,
             body_html,template_key,template_version,next_attempt_at,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)');
        $stmt->execute([$key,$fingerprint,$reference,$recipient,$subject,$body,
            $html,$templateKey,$templateVersion]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO ticket_mail_outbox
            (message_key,payload_hash,related_ref,recipient_email,subject,body_text,next_attempt_at,created_at,updated_at)
            VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)');
        $stmt->execute([$key,$fingerprint,$reference,$recipient,$subject,$body]);
    }
    $id = (int)$pdo->lastInsertId();
    $read = $pdo->prepare('SELECT payload_hash FROM ticket_mail_outbox WHERE id=?');
    $read->execute([$id]);
    if (!hash_equals($fingerprint, (string)$read->fetchColumn())) {
        throw new DomainException('MAIL_KEY_CONFLICT');
    }
    return $id;
}

/**
 * Reconcile a bounded set of expired leases and exhausted attempts without any
 * provider call. A possibly sent message is uncertain, never an automatic retry.
 * Row locks serialize workers; generation and expiry predicates fence updates.
 */
function brvtal_ticket_mail_reconcile_stale(PDO $pdo, int $limit = 25): int
{
    if ($limit < 1 || $limit > 100) {
        throw new InvalidArgumentException('MAIL_RECONCILE_INVALID_LIMIT');
    }
    if ($pdo->inTransaction()) {
        throw new RuntimeException('MAIL_RECONCILE_TRANSACTION_ACTIVE');
    }
    $where = "state IN ('queued','retry','sending')
        AND provider_message_id IS NULL AND accepted_at IS NULL
        AND (locked_until IS NULL OR locked_until < UTC_TIMESTAMP())
        AND (
            (state='sending' AND locked_until IS NOT NULL AND locked_until < UTC_TIMESTAMP())
            OR (state IN ('queued','retry') AND (
                attempts >= 5
                OR first_attempt_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 23 HOUR)
            ))
        )";
    $pdo->beginTransaction();
    try {
        // The same row cannot be reconciled by two workers or claimed while locked.
        $rows = $pdo->query("SELECT id,attempts,state FROM ticket_mail_outbox
            WHERE {$where} ORDER BY id LIMIT {$limit} FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC);
        $update = $pdo->prepare("UPDATE ticket_mail_outbox
            SET state='uncertain',locked_until=NULL,
                last_error_code=COALESCE(last_error_code,'receipt_unknown'),
                updated_at=UTC_TIMESTAMP()
            WHERE id=? AND attempts=? AND state=? AND {$where}");
        $changed = 0;
        foreach ($rows as $row) {
            $update->execute([(int)$row['id'], (int)$row['attempts'], (string)$row['state']]);
            $changed += $update->rowCount();
        }
        $pdo->commit();
        return $changed;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

/** Claim using a DB row lock; attempts is the fencing generation. */
function brvtal_ticket_mail_claim(PDO $pdo): ?array
{
    $pdo->beginTransaction();
    try {
        // A previously attempted unknown request is never auto-replayed after
        // Resend's 24h idempotency window (leave it for manual reconciliation).
        $row = $pdo->query("SELECT * FROM ticket_mail_outbox
            WHERE state IN ('queued','retry') AND attempts<5
            AND next_attempt_at<=UTC_TIMESTAMP()
            AND (locked_until IS NULL OR locked_until<UTC_TIMESTAMP())
            AND (first_attempt_at IS NULL OR first_attempt_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 23 HOUR))
            ORDER BY id LIMIT 1 FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            $pdo->commit();
            return null;
        }
        $update = $pdo->prepare("UPDATE ticket_mail_outbox
            SET state='sending',attempts=attempts+1,
                first_attempt_at=COALESCE(first_attempt_at,UTC_TIMESTAMP()),
                locked_until=DATE_ADD(UTC_TIMESTAMP(), INTERVAL 2 MINUTE),
                updated_at=UTC_TIMESTAMP() WHERE id=?");
        $update->execute([(int)$row['id']]);
        $pdo->commit();
        $row['attempts'] = (int)$row['attempts'] + 1;
        return $row;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

/**
 * Fence recovery dispatch at the last local authorization point. Lock the
 * order/consent before the outbox (producer lock order), then commit BEFORE
 * the provider call. A payment/opt-out after this boundary cannot revoke an
 * already authorized/in-flight request.
 */
function brvtal_ticket_mail_prepare_dispatch(PDO $pdo, array $job): string
{
    if (!preg_match('/^order:.*:(abandoned_checkout|reminder)$/D', (string)$job['message_key'])) {
        return 'ready';
    }
    require_once __DIR__ . '/ticketing_recovery.php';
    $pdo->beginTransaction();
    try {
        $historyReady = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ticket_mail_history'")->fetchColumn() === 1;
        $reason = brvtalRecoveryDispatchReason($pdo, $job, $historyReady);
        $fence = $pdo->prepare("SELECT id FROM ticket_mail_outbox
            WHERE id=? AND attempts=? AND state='sending' AND locked_until>=UTC_TIMESTAMP()
              AND provider_message_id IS NULL AND accepted_at IS NULL FOR UPDATE");
        $fence->execute([$job['id'], $job['attempts']]);
        if (!$fence->fetchColumn()) {
            $pdo->rollBack();
            return 'lease_lost';
        }
        if ($reason === null) {
            $pdo->commit();
            return 'ready';
        }
        // An earlier attempt may have reached the provider, even on 5xx.
        // Do not manufacture proof that no message was sent in that case.
        $state = (int)$job['attempts'] === 1 ? 'suppressed' : 'uncertain';
        if ($historyReady) {
            $history = $pdo->prepare('SELECT delivery_state,resend_email_id FROM ticket_mail_history
                WHERE outbox_id=? AND message_key=? FOR UPDATE');
            $history->execute([$job['id'], $job['message_key']]);
            $receipt = $history->fetch(PDO::FETCH_ASSOC);
            if ($receipt && ($receipt['resend_email_id'] !== null || $receipt['delivery_state'] !== 'queued')) {
                $state = 'uncertain';
            }
            if ($state === 'suppressed' && $receipt) {
                $audit = $pdo->prepare("UPDATE ticket_mail_history
                    SET delivery_state='suppressed',state_at=UTC_TIMESTAMP()
                    WHERE outbox_id=? AND message_key=? AND delivery_state='queued' AND resend_email_id IS NULL");
                $audit->execute([$job['id'], $job['message_key']]);
            }
        }
        $update = $pdo->prepare("UPDATE ticket_mail_outbox
            SET state=?,last_error_code=?,locked_until=NULL,updated_at=UTC_TIMESTAMP()
            WHERE id=? AND attempts=? AND state='sending' AND locked_until>=UTC_TIMESTAMP()
              AND provider_message_id IS NULL AND accepted_at IS NULL");
        $update->execute([$state, $state === 'uncertain' ? 'receipt_unknown' : $reason,
            $job['id'], $job['attempts']]);
        if ($update->rowCount() !== 1) {
            $pdo->rollBack();
            return 'lease_lost';
        }
        $pdo->commit();
        return $state;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

/** One attempt per worker invocation, no caller-visible PII. */
function brvtal_ticket_mail_deliver_one(PDO $pdo, ?callable $request = null): string
{
    brvtal_ticket_mail_reconcile_stale($pdo);
    $job = brvtal_ticket_mail_claim($pdo);
    if ($job === null) {
        return 'idle';
    }
    $dispatch = brvtal_ticket_mail_prepare_dispatch($pdo, $job);
    if ($dispatch !== 'ready') return $dispatch;
    try {
        $result = brvtal_ticket_resend_send($job, $request);
    } catch (Throwable) {
        // Ambiguous outcome: no automatic replay of a request that may have arrived.
        $result = ['state' => 'uncertain', 'code' => 'network_unknown'];
    }
    $state = (string)($result['state'] ?? 'uncertain');
    $attempts = (int)$job['attempts'];
    if (!in_array($state, ['accepted', 'retry', 'failed', 'uncertain'], true)) {
        $state = 'uncertain';
    }
    if ($state === 'retry' && $attempts >= 5) {
        $state = 'uncertain';
    }
    $next = max(min(300, (int)($result['retry_after'] ?? 0)), min(3600, 30 * (2 ** ($attempts - 1))));
    $code = (string)($result['code'] ?? '');
    $allowedCodes = ['rate_limited','provider_5xx','network_unknown',
        'receipt_unknown','provider_rejected','invalid_configuration',
        'transport_disabled','transport_unavailable'];
    $code = in_array($code, $allowedCodes, true) ? $code : 'unspecified';
    $accepted = $state === 'accepted';
    $providerId = $accepted ? (string)($result['id'] ?? '') : '';
    if ($accepted && preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $providerId) !== 1) {
        // A provider response without a usable receipt must never be replayed.
        $accepted = false;
        $state = 'uncertain';
        $code = 'receipt_unknown';
    }
    $sql = "UPDATE ticket_mail_outbox
        SET state=?,next_attempt_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL {$next} SECOND),
            locked_until=NULL,last_error_code=?,provider_message_id=?,
            accepted_at=IF(?=1,UTC_TIMESTAMP(),accepted_at),updated_at=UTC_TIMESTAMP()
        WHERE id=? AND attempts=? AND state='sending'";
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $state, $accepted ? null : $code,
            $accepted ? $providerId : null,
            $accepted ? 1 : 0, (int)$job['id'], $attempts
        ]);
        if ($stmt->rowCount() !== 1) {
            $pdo->rollBack();
            return 'lease_lost';
        }
        // Same transaction and fencing generation: preserve the original
        // history scope and reconcile webhooks that arrived before this receipt.
        if ($accepted) brvtal_mail_history_accept($pdo, (int)$job['id'], $providerId);
        $pdo->commit();
        return $state;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($accepted) {
            // Resend already accepted the email. If receipt/history persistence
            // fails, fence out automatic retry rather than sending it twice.
            // Resend's 24h idempotency and the first_attempt_at cutoff remain.
            try {
                $uncertain = $pdo->prepare("UPDATE ticket_mail_outbox
                    SET state='uncertain',locked_until=NULL,last_error_code='receipt_unknown',
                        updated_at=UTC_TIMESTAMP()
                    WHERE id=? AND attempts=? AND state='sending'");
                $uncertain->execute([(int)$job['id'], $attempts]);
            } catch (Throwable) {
                // Database unavailable: preserve the exception for operator recovery.
            }
        }
        throw $error;
    }
}

/** Manual redrive only for definite, non-accepted failures; never uncertain. */
function brvtal_ticket_mail_redrive(PDO $pdo, int $id): bool
{
    if ($id < 1) {
        return false;
    }
    $stmt = $pdo->prepare("UPDATE ticket_mail_outbox
        SET state='queued',attempts=0,first_attempt_at=NULL,
            next_attempt_at=UTC_TIMESTAMP(),locked_until=NULL,last_error_code=NULL
        WHERE id=? AND state='failed' AND
          last_error_code IN ('transport_disabled','invalid_configuration',
              'provider_rejected','transport_unavailable')");
    $stmt->execute([$id]);
    return $stmt->rowCount() === 1;
}
