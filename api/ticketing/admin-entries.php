<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/admin_auth.php';
require_once __DIR__ . '/../../config/ticketing_staff_permissions.php';
require_once __DIR__ . '/../../config/ticketing_checkin.php';
require_once __DIR__ . '/../../config/ticketing_mail_outbox.php';
require_once __DIR__ . '/../../config/ticketing_mail_events.php';
/** Isolated transactional T19 service, also exercised on disposable MariaDB. */
function brvtalTicketAdminEntryMutation(PDO $pdo,int $entryId,int $operator,string $role,
    string $action,string $reason,string $key): array
{
    if ($entryId<1 || $operator<1
        || !in_array($action,['reissue','void'],true)
        || !in_array($reason,['lost','compromised','buyer_request'],true)
        || !preg_match('/^[A-Za-z0-9_-]{20,64}$/D',$key)) {
        throw new InvalidArgumentException('INVALID_REQUEST');
    }
    if (!brvtalTicketingCan($role,$action==='reissue'?'reissue':'void')) {
        throw new DomainException('FORBIDDEN');
    }
    $requestId=substr(hash('sha256','t19:'.$operator.':'.$key,true),0,16);
    $reasonCode=($action==='reissue'?'credential_reissued_':'entry_revoked_').$reason;
    $pdo->beginTransaction();
    try {
        $old=$pdo->prepare('SELECT entry_id,reason_code,operator_admin_id FROM ticket_checkins
            WHERE request_id=? FOR UPDATE');
        $old->execute([$requestId]);$prior=$old->fetch(PDO::FETCH_ASSOC);
        if ($prior) {
            if ((int)$prior['entry_id']!==$entryId
                || (int)$prior['operator_admin_id']!==$operator
                || $prior['reason_code']!==$reasonCode) {
                throw new DomainException('IDEMPOTENCY_CONFLICT');
            }
            $pdo->commit();
            return ['replayed'=>true,'qr'=>null];
        }
        $entry=$pdo->prepare('SELECT e.id,e.event_id,e.status,LOWER(HEX(e.public_id)) AS public_id,
                o.id AS order_id,o.status AS order_status,o.buyer_id,b.email,b.consent_version,b.consent_recorded_at
            FROM ticket_entries e JOIN ticket_order_items i ON i.id=e.order_item_id
            JOIN ticket_orders o ON o.id=i.order_id JOIN ticket_buyers b ON b.id=o.buyer_id
            WHERE e.id=? FOR UPDATE');
        $entry->execute([$entryId]);$row=$entry->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['status']!=='issued') throw new DomainException('ENTRY_NOT_ISSUED');
        $credential=$pdo->prepare("SELECT version FROM ticket_entry_credentials
            WHERE entry_id=? AND status='active' FOR UPDATE");
        $credential->execute([$entryId]);$version=$credential->fetchColumn();
        if ($version===false) throw new DomainException('CREDENTIAL_UNAVAILABLE');
        $qr=null;
        if ($action==='reissue') {
            if ($row['order_status']!=='paid') throw new DomainException('ORDER_NOT_PAID');
            $proof=$pdo->prepare("SELECT 1 FROM ticket_issue_outbox b
              JOIN ticket_payment_attempts a ON a.id=b.payment_attempt_id
              JOIN ticket_payment_webhook_events w ON w.attempt_id=a.id
                AND w.provider_transaction_id=a.provider_transaction_id
              WHERE b.order_id=? AND b.state='done' AND a.status='approved'
                AND a.provider='wompi' AND w.signed_status='APPROVED' LIMIT 1");
            $proof->execute([(int)$row['order_id']]);
            if (!$proof->fetchColumn()) throw new DomainException('PAYMENT_UNVERIFIED');
            if (!is_string($row['email']) || !filter_var($row['email'],FILTER_VALIDATE_EMAIL)
                || !trim((string)$row['consent_version']) || !$row['consent_recorded_at']) {
                throw new DomainException('BUYER_CONSENT_REQUIRED');
            }
        }
        $pdo->prepare("UPDATE ticket_entry_credentials SET status='revoked',
          revoked_at_utc=UTC_TIMESTAMP(6) WHERE entry_id=? AND status='active'")->execute([$entryId]);
        $newVersion=(int)$version;
        if ($action==='reissue') {
            $newVersion++;
            $secret=random_bytes(32);
            $pdo->prepare('INSERT INTO ticket_entry_credentials
                (entry_id,version,token_hash,created_at_utc) VALUES (?,?,?,UTC_TIMESTAMP(6))')
                ->execute([$entryId,$newVersion,hash('sha256',$secret,true)]);
            $qr='BRVTAL:T1:'.$row['public_id'].':'.rtrim(strtr(base64_encode($secret),'+/','-_'),'=');
            // Queue only a generic alert; QR plaintext is never persisted in the mail outbox.
            $keyMail='ticket:reissue:'.$entryId.':'.$newVersion;
            $mailId=brvtal_ticket_mail_enqueue($pdo,$keyMail,'ticket:'.$entryId,$row['email'],
                'BRVTAL · entrada reemitida',
                'Se ha reemitido una entrada de tu pedido. Solicita el nuevo QR por el canal seguro de soporte. No compartas el anterior.');
            brvtal_mail_history_record($pdo,$mailId,['event_id'=>(int)$row['event_id'],
                'buyer_id'=>(int)$row['buyer_id'],'order_id'=>(int)$row['order_id'],
                'ticket_id'=>$entryId,'actor_kind'=>'admin','template_key'=>'reissue']);
        } else {
            $pdo->prepare("UPDATE ticket_entries SET status='revoked',
                revoked_at_utc=UTC_TIMESTAMP(6) WHERE id=? AND status='issued'")->execute([$entryId]);
        }
        $pdo->prepare("INSERT INTO ticket_checkins
          (request_id,event_id,entry_id,credential_version,operator_admin_id,device_id,outcome,reason_code,attempted_at_utc)
          VALUES (?,?,?,?,?,'discadmin','rejected',?,UTC_TIMESTAMP(6))")
          ->execute([$requestId,(int)$row['event_id'],$entryId,$newVersion,$operator,$reasonCode]);
        $pdo->commit();
        return ['action'=>$action,'qr'=>$qr,'replayed'=>false,
            'notification'=>$action==='reissue'?'queued':'not_required'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/* Direct CLI fixture includes this service without opening an HTTP session. */
if (defined('BRVTAL_T19_LIBRARY_ONLY') && PHP_SAPI==='cli') return;

header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
brvtal_admin_require();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET','POST'], true)) {
    json_response(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405,['Allow'=>'GET, POST']);
}
if ($method === 'POST') {
    if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
        json_response(['ok'=>false,'error'=>'HTTPS_REQUIRED'],403);
    }
    brvtal_admin_require_csrf();
}
try {
    $pdo = db();
    $operator = (int)($_SESSION['admin_id'] ?? 0);
    $roleStmt = $pdo->prepare('SELECT staff_role FROM admins WHERE id=? AND is_active=1');
    $roleStmt->execute([$operator]);
    $role = (string)($roleStmt->fetchColumn() ?: '');
    if ($operator < 1 || !brvtalTicketingCan($role,'entries')) {
        json_response(['ok'=>false,'error'=>'FORBIDDEN'],403);
    }
    if ($method === 'GET') {
        $id = filter_var($_GET['entry_id'] ?? 0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        $before = filter_var($_GET['before_id'] ?? PHP_INT_MAX,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if ($id === false || $before === false || count($_GET) > 2
            || array_diff(array_keys($_GET),['entry_id','before_id'])) {
            json_response(['ok'=>false,'error'=>'INVALID_FILTER'],422);
        }
        $hasMail = (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ticket_mail_history'")->fetchColumn();
        $mail = $hasMail ? '(SELECT h.delivery_state FROM ticket_mail_history h
            WHERE h.ticket_id=e.id OR h.order_id=i.order_id ORDER BY h.id DESC LIMIT 1)' : 'NULL';
        $sql = "SELECT e.id,LOWER(HEX(e.public_id)) AS public_id,e.event_id,e.status,
            i.order_id,o.buyer_id,o.status AS order_status,
            (SELECT COUNT(*) FROM ticket_checkins a WHERE a.entry_id=e.id AND a.outcome='accepted') AS uses,
            (SELECT a.reason_code FROM ticket_checkins a WHERE a.entry_id=e.id ORDER BY a.id DESC LIMIT 1) AS last_audit,
            $mail AS mail_state
            FROM ticket_entries e JOIN ticket_order_items i ON i.id=e.order_item_id
            JOIN ticket_orders o ON o.id=i.order_id
            WHERE e.id" . ($id > 0 ? '=?' : '<?') . ' ORDER BY e.id DESC LIMIT 25';
        $query=$pdo->prepare($sql);$query->execute([$id > 0 ? $id : $before]);
        json_response(['ok'=>true,'data'=>$query->fetchAll(PDO::FETCH_ASSOC),'read_only'=>true]);
    }
    $raw=file_get_contents('php://input',false,null,0,2049);
    if (!is_string($raw) || strlen($raw)>2048
        || !str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/json')) {
        json_response(['ok'=>false,'error'=>'INVALID_REQUEST'],422);
    }
    $input=json_decode($raw,true,12,JSON_THROW_ON_ERROR);
    if (!is_array($input) || array_diff(array_keys($input),
        ['action','entry_id','qr','reason','idempotency_key','confirm'])) {
        json_response(['ok'=>false,'error'=>'INVALID_REQUEST'],422);
    }
    $entryId=filter_var($input['entry_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $action=$input['action']??'';
    if ($entryId===false || !is_string($action)
        || !in_array($action,['preview','reissue','void'],true)) {
        json_response(['ok'=>false,'error'=>'INVALID_REQUEST'],422);
    }
    if ($action==='preview') {
        $qr=$input['qr']??null;
        $parsed=is_string($qr) ? brvtalTicketCheckinQr($qr) : null;
        if (!$parsed) json_response(['ok'=>false,'error'=>'QR_UNAVAILABLE'],404);
        $q=$pdo->prepare("SELECT c.token_hash FROM ticket_entries e
          JOIN ticket_entry_credentials c ON c.entry_id=e.id AND c.status='active'
          JOIN ticket_order_items i ON i.id=e.order_item_id
          JOIN ticket_orders o ON o.id=i.order_id
          WHERE e.id=? AND e.public_id=UNHEX(?) AND e.status='issued' AND o.status='paid'");
        $q->execute([$entryId,$parsed['public_id']]);
        $hash=$q->fetchColumn();
        if (!is_string($hash) || !hash_equals($hash,$parsed['hash'])) {
            json_response(['ok'=>false,'error'=>'QR_UNAVAILABLE'],404);
        }
        // No new sale, stock allocation, credential or token. Caller supplied the original.
        json_response(['ok'=>true,'data'=>['qr'=>$qr,'rerender'=>true]]);
    }
    $capability=$action==='reissue'?'reissue':'void';
    if (!brvtalTicketingCan($role,$capability)) {
        json_response(['ok'=>false,'error'=>'FORBIDDEN'],403);
    }
    $reason=$input['reason']??'';
    $key=$input['idempotency_key']??'';
    if (!is_string($reason) || !in_array($reason,['lost','compromised','buyer_request'],true)
        || !is_string($key) || !preg_match('/^[A-Za-z0-9_-]{20,64}$/D',$key)
        || ($action==='void' && ($input['confirm']??'')!=='ANULAR')) {
        json_response(['ok'=>false,'error'=>'INVALID_REASON_OR_KEY'],422);
    }
    json_response(['ok'=>true,'data'=>brvtalTicketAdminEntryMutation(
        $pdo,$entryId,$operator,$role,$action,$reason,$key)]);
} catch (DomainException $e) {
    json_response(['ok'=>false,'error'=>$e->getMessage()],409);
} catch (JsonException|InvalidArgumentException $e) {
    json_response(['ok'=>false,'error'=>'INVALID_REQUEST'],422);
} catch (Throwable) {
    json_response(['ok'=>false,'error'=>'ENTRIES_UNAVAILABLE'],503);
}
