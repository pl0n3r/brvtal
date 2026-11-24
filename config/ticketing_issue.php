<?php
declare(strict_types=1);

/**
 * T09: offline issuance worker. Either a verified signed Wompi webhook or a
 * trusted, operator-confirmed POS sale. NEVER called from a public route.
 * Secrets are returned exactly once, in memory, to the trusted delivery layer;
 * their SHA-256 digests and (after migration) AES-GCM ciphertext are persisted. A lost secret must be reissued
 * under a separate, audited owner-authorized flow, never inferred from its hash.
 */

/**
 * Recoverable QR secrets are sealed only when the additive migration exists.
 * Ciphertext is versioned, AES-256-GCM authenticated and bound to the entry ID.
 * Missing encryption configuration fails closed; it never stores cleartext.
 */
function brvtal_ticket_issue_seal_token(string $secret, string $entryPublicId): string
{
    if (strlen($secret) !== 32 || strlen($entryPublicId) !== 16) {
        throw new DomainException('ISSUE_ENCRYPTION_UNAVAILABLE');
    }
    $master = $GLOBALS['config']['security']['encryption_key'] ?? null;
    if (!is_string($master) || strlen($master) < 32) {
        throw new DomainException('ISSUE_ENCRYPTION_UNAVAILABLE');
    }
    $key = hash_hkdf('sha256', $master, 32, 'brvtal-ticket-entry-secret-v1');
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($secret, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $entryPublicId, 16);
    if (!is_string($ciphertext) || strlen($ciphertext) !== 32 || strlen($tag) !== 16) {
        throw new DomainException('ISSUE_ENCRYPTION_UNAVAILABLE');
    }
    return "\x01" . $iv . $tag . $ciphertext;
}

function brvtal_ticket_issue_open_token(string $sealed, string $entryPublicId): string
{
    $master = $GLOBALS['config']['security']['encryption_key'] ?? null;
    if (strlen($entryPublicId) !== 16 || strlen($sealed) !== 61 || ord($sealed[0]) !== 1
        || !is_string($master) || strlen($master) < 32) {
        throw new DomainException('TICKET_UNAVAILABLE');
    }
    $key = hash_hkdf('sha256', $master, 32, 'brvtal-ticket-entry-secret-v1');
    $plain = openssl_decrypt(substr($sealed, 29), 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
        substr($sealed, 1, 12), substr($sealed, 13, 16), $entryPublicId);
    if (!is_string($plain) || strlen($plain) !== 32) {
        throw new DomainException('TICKET_UNAVAILABLE');
    }
    return $plain;
}

/** On pre-migration deployments the original email QR remains available. */
function brvtal_ticket_issue_has_encrypted_column(PDO $db): bool
{
    $stmt = $db->prepare("SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ticket_entry_credentials'
          AND COLUMN_NAME='token_encrypted' LIMIT 1");
    $stmt->execute();
    return $stmt->fetchColumn() !== false;
}

/**
 * Proof of a manual sale must agree with the order, single line, cash
 * transaction, operator, and shift. Never use fake Wompi approvals.
 */
function brvtal_ticket_pos_verified(PDO $db, int $orderId): bool
{
    if ($orderId<1) return false;
    try {
        $q=$db->prepare("SELECT 1 FROM ticket_pos_sales s
            JOIN ticket_pos_shifts sh ON sh.id=s.shift_id
                AND sh.event_id=s.event_id AND sh.operator_admin_id=s.operator_admin_id
            JOIN ticket_orders o ON o.id=s.order_id AND o.event_id=s.event_id
                AND o.status='paid' AND o.currency='COP' AND o.total_cop=s.amount_cop
            JOIN ticket_order_items i ON i.order_id=o.id AND i.event_id=s.event_id
                AND i.ticket_type_id=s.ticket_type_id AND i.quantity=s.quantity
                AND i.unit_price_cop=s.unit_price_cop AND i.subtotal_cop=s.amount_cop
            WHERE s.order_id=? AND s.status='paid' AND s.method IN ('cash','terminal')
                AND s.completed_at_utc IS NOT NULL
                AND (SELECT COUNT(*) FROM ticket_order_items x WHERE x.order_id=o.id)=1
                AND NOT EXISTS(SELECT 1 FROM ticket_payment_attempts a WHERE a.order_id=o.id)
            LIMIT 1");
        $q->execute([$orderId]);
        return $q->fetchColumn()!==false;
    } catch (PDOException $e) {
        if ($e->getCode()==='42S02') return false;
        throw $e;
    }
}

/** Requires the operator's sale and order to have committed in this transaction. */
function brvtal_ticket_pos_enqueue(PDO $db, int $orderId): void
{
    if ($orderId<1 || !$db->inTransaction()) throw new DomainException('POS_ISSUE_TRANSACTION');
    if (!brvtal_ticket_pos_verified($db,$orderId)) throw new DomainException('POS_ISSUE_UNVERIFIED');
    $db->prepare("INSERT INTO ticket_issue_outbox (order_id,payment_attempt_id)
        VALUES (?,NULL) ON DUPLICATE KEY UPDATE order_id=ticket_issue_outbox.order_id")
        ->execute([$orderId]);
    $q=$db->prepare("SELECT id,payment_attempt_id FROM ticket_issue_outbox WHERE order_id=? FOR UPDATE");
    $q->execute([$orderId]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || $row['payment_attempt_id']!==null) {
        throw new DomainException('POS_ISSUE_CONFLICT');
    }
}

function brvtal_ticket_issue_consume(PDO $db, int $outboxId, ?callable $deliver = null): array
{
    if ($outboxId < 1 || $db->inTransaction()) {
        throw new DomainException('ISSUE_INVALID_REQUEST');
    }
    try {
        $lookup = $db->prepare('SELECT order_id FROM ticket_issue_outbox WHERE id=?');
        $lookup->execute([$outboxId]);
        $orderId = $lookup->fetchColumn();
        if ($orderId === false) {
            throw new DomainException('ISSUE_UNAVAILABLE');
        }
        $db->beginTransaction();
        try {
            // Match T08 lock order: order BEFORE the outbox or payment attempt.
            $orderStmt = $db->prepare('SELECT id,event_id,buyer_id,status
                FROM ticket_orders WHERE id=? FOR UPDATE');
            $orderStmt->execute([(int)$orderId]);
            $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
            $jobStmt = $db->prepare('SELECT order_id,payment_attempt_id,state
                FROM ticket_issue_outbox WHERE id=? FOR UPDATE');
            $jobStmt->execute([$outboxId]);
            $job = $jobStmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($order) || !is_array($job)
                || (int)$job['order_id'] !== (int)$orderId) {
                throw new DomainException('ISSUE_UNAVAILABLE');
            }
            if ($job['state'] === 'done') {
                $db->commit();
                return ['state'=>'duplicate', 'tickets'=>[]];
            }
            if (!in_array($job['state'], ['pending','failed'], true)) {
                throw new DomainException('ISSUE_BUSY');
            }
            // Fail closed on unpaid, refunded, forged, or orphaned outbox rows.
            if ($order['status'] !== 'paid') {
                throw new DomainException('ISSUE_NOT_PAID');
            }
            if ($job['payment_attempt_id'] === null) {
                if (!brvtal_ticket_pos_verified($db,(int)$orderId)) {
                    throw new DomainException('ISSUE_UNVERIFIED_PAYMENT');
                }
            } else {
                // Preserve the signed Wompi gate for web payments.
                $approval = $db->prepare("SELECT a.id FROM ticket_payment_attempts a
                    WHERE a.id=? AND a.order_id=? AND a.provider='wompi'
                      AND a.status='approved' AND a.provider_transaction_id IS NOT NULL
                      AND EXISTS (SELECT 1 FROM ticket_payment_webhook_events w
                          WHERE w.attempt_id=a.id
                            AND w.provider_transaction_id=a.provider_transaction_id)
                    LIMIT 1");
                $approval->execute([(int)$job['payment_attempt_id'],(int)$orderId]);
                if ($approval->fetchColumn() === false) {
                    throw new DomainException('ISSUE_UNVERIFIED_PAYMENT');
                }
            }
            $items = $db->prepare('SELECT id,event_id,ticket_type_id,quantity
                FROM ticket_order_items WHERE order_id=? ORDER BY id FOR UPDATE');
            $items->execute([(int)$orderId]);
            $rows = $items->fetchAll(PDO::FETCH_ASSOC);
            $total = 0;
            foreach ($rows as $item) {
                $quantity = (int)$item['quantity'];
                $total += $quantity;
                if ($quantity < 1 || $quantity > 200 || $total > 400
                    || (int)$item['event_id'] !== (int)$order['event_id']) {
                    throw new DomainException('ISSUE_INVALID_ORDER');
                }
            }
            if ($total === 0) {
                throw new DomainException('ISSUE_INVALID_ORDER');
            }
            $exists = $db->prepare('SELECT COUNT(*) FROM ticket_entries WHERE order_item_id=?');
            $entry = $db->prepare('INSERT INTO ticket_entries
                (public_id,order_item_id,event_id,ticket_type_id,unit_number,issued_at_utc)
                VALUES (?,?,?,?,?,UTC_TIMESTAMP(6))');
            $storeEncrypted = brvtal_ticket_issue_has_encrypted_column($db);
            $credential = $db->prepare($storeEncrypted
                ? 'INSERT INTO ticket_entry_credentials
                    (entry_id,version,token_hash,token_encrypted,created_at_utc)
                    VALUES (?,1,?,?,UTC_TIMESTAMP(6))'
                : 'INSERT INTO ticket_entry_credentials
                    (entry_id,version,token_hash,created_at_utc)
                    VALUES (?,1,?,UTC_TIMESTAMP(6))');
            $tickets = [];
            foreach ($rows as $item) {
                $exists->execute([(int)$item['id']]);
                // Crash recovery must not silently mint a new secret for a
                // partially issued order. Atomic success is all-or-nothing.
                if ((int)$exists->fetchColumn() !== 0) {
                    throw new DomainException('ISSUE_PARTIAL');
                }
                for ($unit = 1; $unit <= (int)$item['quantity']; $unit++) {
                    $id = random_bytes(16);
                    $secret = random_bytes(32);
                    $entry->execute([$id,(int)$item['id'],(int)$item['event_id'],
                        (int)$item['ticket_type_id'],$unit]);
                    $entryId = (int)$db->lastInsertId();
                    $params = [$entryId, hash('sha256', $secret, true)];
                    if ($storeEncrypted) {
                        $params[] = brvtal_ticket_issue_seal_token($secret, $id);
                    }
                    $credential->execute($params);
                    $tickets[] = ['public_id'=>bin2hex($id),
                        'token'=>rtrim(strtr(base64_encode($secret),'+/','-_'),'='),
                        'version'=>1];
                }
            }
            $finish = $db->prepare("UPDATE ticket_issue_outbox
                SET state='done',attempts=attempts+1,updated_at_utc=UTC_TIMESTAMP(6)
                WHERE id=? AND state IN ('pending','failed')");
            $finish->execute([$outboxId]);
            if ($finish->rowCount() !== 1) {
                throw new DomainException('ISSUE_BUSY');
            }
            // The one-time secrets reach the delivery layer inside this same
            // transaction: if delivery cannot be queued, nothing is issued.
            if ($deliver !== null) {
                $deliver($db, (int)$orderId, $tickets);
            }
            $db->commit();
            return ['state'=>'issued', 'tickets'=>$tickets];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    } catch (PDOException) {
        // Never bubble SQL, parameter values, buyer data or raw QR material.
        throw new RuntimeException('ISSUE_STORAGE_UNAVAILABLE');
    }
}

/**
 * Return the SAME QR credential when an authorized buyer presents its token.
 * Expiration comes from trusted event/access policy, never from the client.
 * No QR secret can be reconstructed from the stored SHA-256 digest alone.
 */
function brvtal_ticket_issue_qr_payload(
    PDO $db, int $buyerId, string $publicId, string $token,
    DateTimeImmutable $validUntilUtc, ?DateTimeImmutable $nowUtc = null
): string {
    $nowUtc ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    if ($buyerId < 1 || $validUntilUtc <= $nowUtc
        || !preg_match('/^[a-f0-9]{32}$/D', $publicId)
        || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $token)) {
        throw new DomainException('TICKET_UNAVAILABLE');
    }
    $raw = base64_decode(strtr($token,'-_','+/').'=',true);
    if (!is_string($raw) || strlen($raw) !== 32
        || !hash_equals(rtrim(strtr(base64_encode($raw),'+/','-_'),'='),$token)) {
        throw new DomainException('TICKET_UNAVAILABLE');
    }
    try {
        $read = $db->prepare("SELECT c.token_hash
            FROM ticket_entries e
            JOIN ticket_order_items i ON i.id=e.order_item_id
            JOIN ticket_orders o ON o.id=i.order_id
            JOIN ticket_issue_outbox b ON b.order_id=o.id AND b.state='done'
            JOIN ticket_entry_credentials c ON c.entry_id=e.id AND c.status='active'
            WHERE e.public_id=UNHEX(?) AND o.buyer_id=? AND o.status='paid'
              AND e.status='issued' AND e.event_id=o.event_id
              AND EXISTS (SELECT 1 FROM ticket_payment_attempts a
                WHERE a.order_id=o.id AND a.status='approved' AND a.provider='wompi')
            LIMIT 1");
        $read->execute([$publicId,$buyerId]);
        $hash = $read->fetchColumn();
        if ($hash === false) {
            $pos=$db->prepare("SELECT c.token_hash,o.id AS order_id
                FROM ticket_entries e
                JOIN ticket_order_items i ON i.id=e.order_item_id
                JOIN ticket_orders o ON o.id=i.order_id
                JOIN ticket_issue_outbox b ON b.order_id=o.id
                    AND b.state='done' AND b.payment_attempt_id IS NULL
                JOIN ticket_entry_credentials c ON c.entry_id=e.id AND c.status='active'
                WHERE e.public_id=UNHEX(?) AND o.buyer_id=? AND o.status='paid'
                  AND e.status='issued' AND e.event_id=o.event_id LIMIT 1");
            $pos->execute([$publicId,$buyerId]);
            $row=$pos->fetch(PDO::FETCH_ASSOC);
            if (is_array($row) && brvtal_ticket_pos_verified($db,(int)$row['order_id'])) {
                $hash=$row['token_hash'];
            }
        }
    } catch (PDOException $e) {
        if ($e->getCode()==='42S02') throw new DomainException('TICKET_UNAVAILABLE');
        throw new RuntimeException('TICKET_STORAGE_UNAVAILABLE');
    }
    if (!is_string($hash) || !hash_equals($hash, hash('sha256',$raw,true))) {
        throw new DomainException('TICKET_UNAVAILABLE');
    }
    // No buyer email, name, order number, sequential entry id or event PII.
    // Frontend can render this payload as a QR only behind buyer authorization.
    return 'BRVTAL:T1:' . $publicId . ':' . $token;
}

/** Safe download descriptor; the caller MUST check buyer session/CSRF first. */
function brvtal_ticket_issue_download(
    PDO $db, int $buyerId, string $publicId, string $token,
    DateTimeImmutable $validUntilUtc, ?DateTimeImmutable $nowUtc = null
): array {
    $payload = brvtal_ticket_issue_qr_payload($db,$buyerId,$publicId,$token,$validUntilUtc,$nowUtc);
    return ['filename'=>'entrada-' . $publicId . '.txt',
        'content_type'=>'text/plain; charset=utf-8',
        'cache_control'=>'private, no-store',
        'content'=>$payload];
}
