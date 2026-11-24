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
            $q = $pdo->prepare("SELECT COALESCE(SUM(i.quantity),0) AS expected,
                COALESCE((SELECT COUNT(*) FROM ticket_entries e
                  JOIN ticket_order_items x ON x.id=e.order_item_id
                  WHERE x.order_id=? AND e.status IN ('issued','used')),0) AS ready
                FROM ticket_order_items i WHERE i.order_id=?");
            $q->execute([$orderId,$orderId]);
            $counts = $q->fetch(PDO::FETCH_ASSOC);
            if (!$counts || (int)$counts['expected']<1
                || (int)$counts['expected']!==(int)$counts['ready']) {
                throw new DomainException('TICKETS_NOT_ISSUED');
            }
            $q = $pdo->prepare('SELECT recipient_email,subject,body_text,body_html,
                template_key,template_version FROM ticket_mail_outbox
                WHERE message_key=? AND related_ref=? LIMIT 1 FOR UPDATE');
            $q->execute(["order:{$orderId}:payment_approved","order:{$orderId}"]);
            $mail = $q->fetch(PDO::FETCH_ASSOC);
            // Lost QR secrets cannot be regenerated from SHA-256. Only resend
            // the original already-sealed payload; never create replacement QR.
            if (!$mail || !str_starts_with((string)$mail['body_text'],
                BRVTAL_BUYER_MAIL_SEAL_PREFIX)
                || brvtalBuyerMailUnseal((string)$mail['body_text'])===null
                || !is_string($mail['body_html'])
                || !str_starts_with((string)$mail['body_html'],BRVTAL_BUYER_MAIL_SEAL_PREFIX)
                || brvtalBuyerMailUnseal((string)$mail['body_html'])===null) {
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
                if (!brvtal_ticket_test_recipient_allowed((string)$mail['recipient_email'])) {
                    throw new DomainException('MAIL_NOT_ENABLED');
                }
            } elseif (!($mode==='mock' && brvtal_secret_value('BRVTAL_TICKET_MAIL_TESTING')==='1')) {
                throw new DomainException('MAIL_NOT_ENABLED');
            }
            $mailKey = 'order:'.$orderId.':resend:'.hash('sha256',$actorId.':'.$input['idempotency_key']);
            $outboxId = brvtal_ticket_mail_enqueue($pdo,$mailKey,'order:'.$orderId,
                (string)$mail['recipient_email'],(string)$mail['subject'],
                (string)$mail['body_text'],$mail['body_html'],
                (string)$mail['template_key'],$mail['template_version']===null
                    ? null : (int)$mail['template_version']);
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
