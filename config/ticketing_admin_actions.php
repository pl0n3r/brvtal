<?php
declare(strict_types=1);

require_once __DIR__ . '/ticketing_staff_permissions.php';
require_once __DIR__ . '/ticketing_mail_outbox.php';
require_once __DIR__ . '/ticketing_mail_events.php';
require_once __DIR__ . '/ticketing_mail_templates.php';

/**
 * T18b is an offline-only feature. Even a valid admin session cannot mutate
 * production orders or contact buyers: execution requires a disposable test DB
 * AND the integration-test flag. No payment/mail provider is called here.
 */
function brvtalTicketAdminSyntheticMode(PDO $pdo): bool
{
    if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') return false;
    $db = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    return (bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/D', $db);
}

/** @return array{order_id:int,action:string,idempotency_key:string,note:string} */
function brvtalTicketAdminActionInput(array $input): array
{
    $allowed = ['order_id','action','idempotency_key','note'];
    foreach ($input as $name => $value) {
        if (!in_array($name, $allowed, true) || !is_string($value)) {
            throw new InvalidArgumentException('INVALID_REQUEST');
        }
    }
    $id = $input['order_id'] ?? '';
    if (!ctype_digit($id) || (int)$id < 1 || (string)(int)$id !== $id) {
        throw new InvalidArgumentException('INVALID_ORDER');
    }
    $action = $input['action'] ?? '';
    if (!in_array($action, ['note','resend','cancel_pending','contact_preview','recovery_preview','resend_preview'], true)) {
        throw new InvalidArgumentException('INVALID_ACTION');
    }
    $key = $input['idempotency_key'] ?? '';
    if (!preg_match('/^[a-zA-Z0-9_-]{20,64}$/D', $key)) {
        throw new InvalidArgumentException('INVALID_KEY');
    }
    $note = trim($input['note'] ?? '');
    if ($action === 'note') {
        if (strlen($note) < 3 || strlen($note) > 280
            || preg_match('/[\x00-\x1F\x7F]/u', $note)
            || preg_match('/@|https?:|www\.|(?:\+?\d[\s().-]*){7,}|contrase.a|token|password|secret|tarjeta|cuenta bancaria/iu', $note)) {
            throw new InvalidArgumentException('NOTE_NOT_MINIMAL');
        }
    } elseif ($note !== '') {
        throw new InvalidArgumentException('UNEXPECTED_NOTE');
    }
    return ['order_id'=>(int)$id,'action'=>$action,'idempotency_key'=>$key,'note'=>$note];
}

function brvtalTicketAdminCanAction(string $role, string $action): bool
{
    if (!brvtalTicketingCan($role, 'orders') || !brvtalTicketingCan($role, 'buyers')) return false;
    return match ($action) {
        'note' => brvtalTicketingCan($role, 'orders'),
        'resend' => brvtalTicketingCan($role, 'reissue'),
        'cancel_pending' => brvtalTicketingCan($role, 'finance'),
        'contact_preview' => brvtalTicketingCan($role, 'mail'),
        'recovery_preview', 'resend_preview' => brvtalTicketingCan($role, 'reissue'),
        default => false,
    };
}

/**
 * Atomically record a synthetic-only action. Rows expire after 30 days and are
 * pruned on the next synthetic write. Never changes ticket_orders, payments,
 * QR, outbox or provider state. No buyer email is returned or logged.
 */
function brvtalTicketAdminRecordAction(PDO $pdo, int $actorId, string $role, array $input): array
{
    if ($actorId < 1 || !brvtalTicketAdminCanAction($role, $input['action'])) {
        throw new DomainException('FORBIDDEN');
    }
    if (in_array($input['action'], ['note','resend','cancel_pending'], true)) {
        return brvtalTicketAdminRecordOrderAction($pdo,$actorId,$role,$input);
    }
    if (!brvtalTicketAdminSyntheticMode($pdo)) {
        throw new DomainException('SYNTHETIC_ONLY');
    }
    $actorKey = hash('sha256', $actorId . ':' . $input['idempotency_key']);
    $digest = hash('sha256', json_encode([
        $input['order_id'], $input['action'], $input['note']
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM ticket_admin_actions WHERE retention_until_utc <= UTC_TIMESTAMP(6)');
        $existing = $pdo->prepare('SELECT id,request_hash,action,status FROM ticket_admin_actions WHERE request_key_hash=? FOR UPDATE');
        $existing->execute([$actorKey]);
        $prior = $existing->fetch(PDO::FETCH_ASSOC);
        if ($prior) {
            if (!hash_equals((string)$prior['request_hash'], $digest)) {
                throw new DomainException('IDEMPOTENCY_CONFLICT');
            }
            $pdo->commit();
            return ['receipt_id'=>(int)$prior['id'],'action'=>$prior['action'],
                'status'=>$prior['status'],'replayed'=>true,'email_sent'=>false,
                'payment_changed'=>false];
        }

        $rowStmt = $pdo->prepare('SELECT o.id,b.consent_version,b.consent_recorded_at
            FROM ticket_orders o JOIN ticket_buyers b ON b.id=o.buyer_id
            WHERE o.id=? FOR UPDATE');
        $rowStmt->execute([$input['order_id']]);
        $order = $rowStmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) throw new DomainException('ORDER_NOT_FOUND');

        $hasConsent = trim((string)($order['consent_version'] ?? '')) !== ''
            && !empty($order['consent_recorded_at']);
        $status = $input['action'] === 'note' ? 'recorded' :
            ($hasConsent ? 'pending' : 'blocked');

        $ins = $pdo->prepare('INSERT INTO ticket_admin_actions
            (order_id,actor_admin_id,action,request_key_hash,request_hash,note_text,status,retention_until_utc)
            VALUES (?,?,?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 30 DAY))');
        $ins->execute([$input['order_id'],$actorId,$input['action'],$actorKey,$digest,
            $input['action'] === 'note' ? $input['note'] : null,$status]);
        $receipt = (int)$pdo->lastInsertId();
        $pdo->commit();
        return ['receipt_id'=>$receipt,'action'=>$input['action'],'status'=>$status,
            'replayed'=>false,'email_sent'=>false,'payment_changed'=>false];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}



/**
 * Internal metadata only, for #2101. Caller must already have a trusted staff
 * session and keep the transaction open through the eventual delivery decision.
 * The recipient is a candidate to validate, not an address to override.
 * @return array{buyer_id:int,entries:list<array{entry_id:int,state:string,version:?int}>}
 */
function brvtalTicketAdminRecoveryProjection(
    PDO $pdo,
    int $actorId,
    string $role,
    int $orderId,
    int $eventId,
    string $recipient
): array {
    if ($actorId < 1 || !brvtalTicketAdminCanAction($role, 'resend')) {
        throw new DomainException('FORBIDDEN');
    }
    if (!$pdo->inTransaction()) {
        throw new LogicException('RECOVERY_TRANSACTION_REQUIRED');
    }
    if ($orderId < 1 || $eventId < 1 || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        throw new DomainException('RECOVERY_ORDER_UNAVAILABLE');
    }
    require_once __DIR__ . '/ticketing_issue.php';
    try {
        // Order before outbox, payment, items, entries and credentials.
        $q = $pdo->prepare('SELECT event_id,buyer_id,status FROM ticket_orders WHERE id=? FOR UPDATE');
        $q->execute([$orderId]);
        $order = $q->fetch(PDO::FETCH_ASSOC);
        if (!$order || $order['status'] !== 'paid' || (int)$order['event_id'] !== $eventId) {
            throw new DomainException('RECOVERY_ORDER_UNAVAILABLE');
        }
        $q = $pdo->prepare('SELECT email,status FROM ticket_buyers WHERE id=? FOR UPDATE');
        $q->execute([(int)$order['buyer_id']]);
        $buyer = $q->fetch(PDO::FETCH_ASSOC);
        if (!$buyer || !in_array($buyer['status'], ['guest','active'], true)
            || !is_string($buyer['email']) || strtolower($buyer['email']) !== strtolower($recipient)) {
            throw new DomainException('RECOVERY_ORDER_UNAVAILABLE');
        }
        $q = $pdo->prepare("SELECT payment_attempt_id FROM ticket_issue_outbox
            WHERE order_id=? AND state='done' FOR UPDATE");
        $q->execute([$orderId]);
        $job = $q->fetch(PDO::FETCH_ASSOC);
        if (!$job) {
            throw new DomainException('RECOVERY_PAYMENT_UNVERIFIED');
        }
        if ($job['payment_attempt_id'] === null) {
            $verified = brvtal_ticket_pos_verified($pdo, $orderId);
        } else {
            // Same exact outbox/attempt/signed journal relationship as the mail gate.
            $q = $pdo->prepare("SELECT a.id FROM ticket_payment_attempts a
                JOIN ticket_payment_webhook_events w ON w.attempt_id=a.id
                  AND w.provider_transaction_id=a.provider_transaction_id
                WHERE a.id=? AND a.order_id=? AND a.provider='wompi'
                  AND a.status='approved' AND a.provider_transaction_id IS NOT NULL
                  AND w.signed_status='APPROVED' LIMIT 1 FOR UPDATE");
            $q->execute([(int)$job['payment_attempt_id'], $orderId]);
            $verified = $q->fetchColumn() !== false;
        }
        if (!$verified) {
            throw new DomainException('RECOVERY_PAYMENT_UNVERIFIED');
        }
        $q = $pdo->prepare('SELECT id,event_id,ticket_type_id,quantity FROM ticket_order_items
            WHERE order_id=? ORDER BY id FOR UPDATE');
        $q->execute([$orderId]);
        $items = $q->fetchAll(PDO::FETCH_ASSOC);
        $entries = [];
        $total = 0;
        $encrypted = brvtal_ticket_issue_has_encrypted_column($pdo);
        foreach ($items as $item) {
            $quantity = (int)$item['quantity'];
            $total += $quantity;
            if ($quantity < 1 || $quantity > 200 || $total > 400 || (int)$item['event_id'] !== $eventId) {
                throw new DomainException('RECOVERY_INCONSISTENT');
            }
            $q = $pdo->prepare('SELECT id,public_id,event_id,ticket_type_id,unit_number,status
                FROM ticket_entries WHERE order_item_id=? ORDER BY unit_number FOR UPDATE');
            $q->execute([(int)$item['id']]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) !== $quantity) {
                throw new DomainException('RECOVERY_INCONSISTENT');
            }
            foreach ($rows as $offset => $entry) {
                if ((int)$entry['event_id'] !== $eventId
                    || (int)$entry['ticket_type_id'] !== (int)$item['ticket_type_id']
                    || (int)$entry['unit_number'] !== $offset + 1) {
                    throw new DomainException('RECOVERY_INCONSISTENT');
                }
                $entries[] = brvtalTicketAdminRecoveryEntry($pdo, $entry, $encrypted);
            }
        }
        if ($total < 1) {
            throw new DomainException('RECOVERY_INCONSISTENT');
        }
        return ['buyer_id'=>(int)$order['buyer_id'], 'entries'=>$entries];
    } catch (PDOException) {
        throw new RuntimeException('RECOVERY_STORAGE_UNAVAILABLE');
    }
}

/** @return array{entry_id:int,state:string,version:?int} */
function brvtalTicketAdminRecoveryEntry(PDO $pdo, array $entry, bool $encrypted): array
{
    $result = ['entry_id'=>(int)$entry['id'], 'state'=>'inconsistent', 'version'=>null];
    if (in_array($entry['status'], ['used','revoked','refunded'], true)) {
        $result['state'] = $entry['status'];
        return $result;
    }
    if ($entry['status'] !== 'issued') {
        return $result;
    }
    $q = $pdo->prepare('SELECT version,status,token_algorithm,token_hash,'
        . ($encrypted ? 'token_encrypted' : 'NULL AS token_encrypted')
        . ' FROM ticket_entry_credentials WHERE entry_id=? ORDER BY version DESC FOR UPDATE');
    $q->execute([(int)$entry['id']]);
    $credentials = $q->fetchAll(PDO::FETCH_ASSOC);
    $active = array_values(array_filter($credentials, static fn(array $row): bool => $row['status'] === 'active'));
    if (count($active) !== 1 || (int)$active[0]['version'] < 1
        || $active[0]['version'] !== $credentials[0]['version']
        || $active[0]['token_algorithm'] !== 'sha256-v1') {
        return $result;
    }
    $credential = $active[0];
    $result['version'] = (int)$credential['version'];
    if ($credential['token_encrypted'] === null) {
        $result['state'] = 'historical';
        return $result;
    }
    try {
        $raw = brvtal_ticket_issue_open_token((string)$credential['token_encrypted'], (string)$entry['public_id']);
        $valid = hash_equals((string)$credential['token_hash'], hash('sha256', $raw, true));
        unset($raw);
        $result['state'] = $valid ? 'recoverable' : 'invalid_ciphertext';
    } catch (DomainException) {
        $result['state'] = 'invalid_ciphertext';
    }
    return $result;
}

/**
 * #1979: production-capable order-only actions. The order row is the serialization
 * point for concurrent staff, payment webhooks and inventory changes. No provider
 * calls occur in the request; an approved resend only creates an outbox job.
 */
function brvtalTicketAdminRecordOrderAction(PDO $pdo, int $actorId, string $role, array $input): array
{
    $action = $input['action'];
    if ($actorId < 1 || !in_array($action, ['note','resend','cancel_pending'], true)
        || !brvtalTicketAdminCanAction($role,$action)) {
        throw new DomainException('FORBIDDEN');
    }
    if ($pdo->inTransaction()) throw new LogicException('ADMIN_ACTION_TRANSACTION_ACTIVE');
    $orderId = $input['order_id'];
    $keyHash = hash('sha256', $actorId . ':' . $input['idempotency_key']);
    $payloadHash = hash('sha256', json_encode([$orderId,$action,$input['note']],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT id,event_id,buyer_id,status FROM ticket_orders WHERE id=? FOR UPDATE');
        $q->execute([$orderId]);
        $order = $q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($order)) throw new DomainException('ORDER_NOT_FOUND');
        $q = $pdo->prepare('SELECT id,request_hash,action,status,mail_outbox_id,note_id
            FROM ticket_order_action_requests WHERE request_key_hash=? FOR UPDATE');
        $q->execute([$keyHash]);
        $old = $q->fetch(PDO::FETCH_ASSOC);
        if ($old) {
            if (!hash_equals((string)$old['request_hash'],$payloadHash)) {
                throw new DomainException('IDEMPOTENCY_CONFLICT');
            }
            $pdo->commit();
            return ['receipt_id'=>(int)$old['id'],'action'=>$old['action'],
                'status'=>$old['status'],'replayed'=>true,
                'email_queued'=>$old['mail_outbox_id']!==null,'payment_changed'=>false];
        }
        $noteId = null; $outboxId = null;
        if ($action === 'note') {
            $q = $pdo->prepare('INSERT INTO ticket_order_notes
                (order_id,actor_admin_id,note_text) VALUES (?,?,?)');
            $q->execute([$orderId,$actorId,$input['note']]);
            $noteId = (int)$pdo->lastInsertId();
            $state = 'recorded';
        } elseif ($action === 'cancel_pending') {
            if (!in_array($order['status'],['draft','pending'],true)) {
                throw new DomainException('ORDER_NOT_PENDING');
            }
            // A payment with an unresolved/approved provider attempt must never
            // be invalidated locally. The buyer needs the separate refund path.
            $q = $pdo->prepare("SELECT 1 FROM ticket_payment_attempts
                WHERE order_id=? AND status NOT IN ('declined','error') LIMIT 1 FOR UPDATE");
            $q->execute([$orderId]);
            if ($q->fetchColumn()!==false) throw new DomainException('PAYMENT_RECONCILIATION_REQUIRED');
            $q = $pdo->prepare("SELECT 1 FROM ticket_issue_outbox WHERE order_id=? LIMIT 1 FOR UPDATE");
            $q->execute([$orderId]);
            if ($q->fetchColumn()!==false) throw new DomainException('ORDER_ALREADY_ISSUED');
            $q = $pdo->prepare("UPDATE ticket_orders
                SET status='cancelled',updated_at_utc=UTC_TIMESTAMP(6)
                WHERE id=? AND status IN ('draft','pending')");
            $q->execute([$orderId]);
            if ($q->rowCount()!==1) throw new DomainException('ORDER_NOT_PENDING');
            $q = $pdo->prepare("UPDATE ticket_inventory_holds
                SET status='released' WHERE order_id=? AND status='active'");
            $q->execute([$orderId]);
            // Same order lock and transaction: enqueue only once, after a
            // provider-safe cancellation. No network request runs here.
            $outboxId = brvtal_ticket_mail_queue_buyer_state(
                $pdo,$orderId,'order_cancelled');
            $state = 'cancelled';
        } else {
            if ($order['status']!=='paid') throw new DomainException('ORDER_NOT_PAID');
            $q = $pdo->prepare("SELECT 1 FROM ticket_issue_outbox
                WHERE order_id=? AND state='done' LIMIT 1");
            $q->execute([$orderId]);
            if ($q->fetchColumn()===false) throw new DomainException('TICKETS_NOT_ISSUED');
            // Only a currently verified buyer can recover from Mi Cuenta without
            // creating another bearer credential or replaying historical activation links.
            $q = $pdo->prepare("SELECT email FROM ticket_buyers
                WHERE id=? AND status='active' AND email_verified_at IS NOT NULL
                  AND password_hash IS NOT NULL AND password_hash<>'' FOR UPDATE");
            $q->execute([(int)$order['buyer_id']]);
            $recipient = $q->fetchColumn();
            if (!is_string($recipient) || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                throw new DomainException('MAIL_PAYLOAD_NOT_RECOVERABLE');
            }
            $projection = brvtalTicketAdminRecoveryProjection(
                $pdo,$actorId,$role,$orderId,(int)$order['event_id'],$recipient);
            if (!in_array('recoverable',array_column($projection['entries'],'state'),true)) {
                throw new DomainException('MAIL_PAYLOAD_NOT_RECOVERABLE');
            }
            $q = $pdo->prepare('SELECT title FROM events WHERE id=?');
            $q->execute([(int)$order['event_id']]);
            $event = $q->fetchColumn();
            if (!is_string($event) || trim($event)==='') {
                throw new DomainException('MAIL_PAYLOAD_NOT_RECOVERABLE');
            }
            $mode = (string)brvtal_secret_value('BRVTAL_TICKET_MAIL_MODE');
            if ($mode==='live') {
                if (brvtal_secret_value('BRVTAL_TICKET_MAIL_ALLOW_LIVE')!=='1'
                    || brvtal_secret_value('BRVTAL_TICKET_MAIL_PRIVACY_APPROVED')!=='1'
                    || brvtal_secret_value('BRVTAL_TICKET_MAIL_DNS_VERIFIED')!=='1') {
                    throw new DomainException('MAIL_NOT_ENABLED');
                }
            } elseif ($mode==='test') {
                if (!brvtal_ticket_test_recipient_allowed($recipient)) {
                    throw new DomainException('MAIL_NOT_ENABLED');
                }
            } elseif (!($mode==='mock' && brvtal_secret_value('BRVTAL_TICKET_MAIL_TESTING')==='1')) {
                throw new DomainException('MAIL_NOT_ENABLED');
            }
            $mailKey = 'order:'.$orderId.':resend:'.hash('sha256',$actorId.':'.$input['idempotency_key']);
            // The account URL carries no order, entry, activation or QR secret.
            // Account access checks the current buyer, order and credential at
            // retrieval time even if the entry changes after this transaction.
            $mail = brvtal_ticket_mail_render('payment_approved', [
                'event'=>$event,'link'=>'https://www.brvtal.com.co/buyer/',
                'order_number'=>$orderId,
            ],$pdo);
            $outboxId = brvtal_ticket_mail_enqueue($pdo,$mailKey,'order:'.$orderId,
                $recipient,$mail['subject'],brvtalBuyerMailSeal($mail['text'],50000),
                brvtalBuyerMailSeal($mail['html'],50000),
                $mail['template_key'],$mail['template_version']);
            brvtal_mail_history_record($pdo,$outboxId,[
                'event_id'=>(int)$order['event_id'],'buyer_id'=>(int)$order['buyer_id'],
                'order_id'=>$orderId,'template_key'=>'payment_approved',
                'actor_kind'=>'admin']);
            $state = 'queued';
        }
        $q = $pdo->prepare('INSERT INTO ticket_order_action_requests
            (order_id,actor_admin_id,action,request_key_hash,request_hash,status,mail_outbox_id,note_id)
            VALUES (?,?,?,?,?,?,?,?)');
        $q->execute([$orderId,$actorId,$action,$keyHash,$payloadHash,$state,$outboxId,$noteId]);
        $receipt = (int)$pdo->lastInsertId();
        $pdo->commit();
        return ['receipt_id'=>$receipt,'action'=>$action,'status'=>$state,
            'replayed'=>false,'email_queued'=>$outboxId!==null,'payment_changed'=>false];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
