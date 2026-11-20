<?php
declare(strict_types=1);

require_once __DIR__ . '/ticketing_staff_permissions.php';

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
    if (!in_array($action, ['note','contact_preview','recovery_preview','resend_preview'], true)) {
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
