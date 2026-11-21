<?php
declare(strict_types=1);

require_once __DIR__ . '/rate_limit_store.php';
require_once __DIR__ . '/ticketing_courtesy.php';

/** Parse strictly typed scanner input; never trust order, cost or ownership from clients. */
function brvtalTicketCheckinInput(array $body): array
{
    $keys=array_keys($body); sort($keys);
    if ($keys !== ['device_id','event_id','qr']
        || !is_int($body['event_id']) || $body['event_id'] < 1
        || !is_string($body['device_id'])
        || preg_match('/^[A-Za-z0-9._:-]{1,96}$/D',$body['device_id']) !== 1
        || !is_string($body['qr']) || strlen($body['qr']) > 256) {
        throw new InvalidArgumentException('CHECKIN_INVALID_INPUT');
    }
    return $body;
}

/** Shared, file-backed, fail-closed budget; no QR or PII is stored. */
function brvtalTicketCheckinRate(int $operator,string $ip,?int $now=null,?string $dir=null): void
{
    if ($operator < 1 || !filter_var($ip,FILTER_VALIDATE_IP)) {
        throw new RuntimeException('CHECKIN_RATE_UNAVAILABLE');
    }
    $now ??= time();
    $dir ??= __DIR__ . '/../storage/rate_limits/ticket_checkin';
    $file=rtrim($dir,'/\\').'/rate-'.hash('sha256',$operator.'|'.$ip).'.json';
    $lock=brvtal_rate_limit_store_open_lock($file);
    if (!$lock) throw new RuntimeException('CHECKIN_RATE_UNAVAILABLE');
    try {
        if (!flock($lock,LOCK_EX)) throw new RuntimeException('CHECKIN_RATE_UNAVAILABLE');
        $raw=is_file($file)?@file_get_contents($file):'{}';
        $state=is_string($raw)?json_decode($raw,true):null;
        if (!is_array($state)) throw new RuntimeException('CHECKIN_RATE_UNAVAILABLE');
        if ($state===[]) $state=['hits'=>[]];
        if (!is_array($state['hits']??null)) throw new RuntimeException('CHECKIN_RATE_UNAVAILABLE');
        $hits=array_values(array_filter($state['hits'],
            static fn($t):bool=>is_int($t)&&$t>$now-60&&$t<=$now));
        if (count($hits)>=30) throw new DomainException('CHECKIN_RATE_LIMITED');
        $hits[]=$now;
        if (!brvtal_rate_limit_store_atomic_replace($file,json_encode(['hits'=>$hits],JSON_THROW_ON_ERROR))) {
            throw new RuntimeException('CHECKIN_RATE_UNAVAILABLE');
        }
    } finally {
        flock($lock,LOCK_UN); fclose($lock);
    }
}

/**
 * Record rate-limited authenticated check-in attempts without accepting a QR.
 * The device identifier is pseudonymized; no QR, IP, or token is persisted.
 */
function brvtalTicketCheckinAuditRateLimited(PDO $pdo,int $eventId,int $operator,string $device): void
{
    if ($eventId<1 || $operator<1
        || preg_match('/^[A-Za-z0-9._:-]{1,96}$/D',$device)!==1
        || $pdo->inTransaction()) {
        throw new InvalidArgumentException('CHECKIN_INVALID_INPUT');
    }
    try {
        $pdo->beginTransaction();
        $event=$pdo->prepare('SELECT status FROM events WHERE id=? FOR UPDATE');
        $event->execute([$eventId]);
        if ($event->fetchColumn()===false) throw new DomainException('CHECKIN_EVENT_NOT_FOUND');
        $audit=$pdo->prepare('INSERT INTO ticket_checkins
            (request_id,event_id,entry_id,credential_version,operator_admin_id,device_id,outcome,reason_code,attempted_at_utc)
            VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))');
        $audit->execute([random_bytes(16),$eventId,null,null,$operator,
            'rate-'.substr(hash('sha256',$device),0,32),'rejected','rate_limited']);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($error instanceof PDOException) {
            throw new RuntimeException('CHECKIN_STORAGE_UNAVAILABLE',0,$error);
        }
        throw $error;
    }
}

/** Validate a T09 credential without logging, returning, or persisting QR plaintext. */
function brvtalTicketCheckinQr(string $qr): ?array
{
    if (!preg_match('/^BRVTAL:T1:([a-f0-9]{32}):([A-Za-z0-9_-]{43})$/D',$qr,$parts)) return null;
    $raw=base64_decode(strtr($parts[2],'-_','+/').'=',true);
    if (!is_string($raw)||strlen($raw)!==32
        || !hash_equals(rtrim(strtr(base64_encode($raw),'+/','-_'),'='),$parts[2])) return null;
    return ['public_id'=>$parts[1],'hash'=>hash('sha256',$raw,true)];
}

/**
 * Each valid event's request is audited (including rejected/replayed/malformed).
 * One InnoDB row lock, conditional update and audit row share a transaction.
 * @return array{ok:bool,reason:string}
 */
function brvtalTicketCheckinConsume(PDO $pdo,int $eventId,int $operator,string $device,string $qr): array
{
    if ($eventId<1||$operator<1||preg_match('/^[A-Za-z0-9._:-]{1,96}$/D',$device)!==1) {
        throw new InvalidArgumentException('CHECKIN_INVALID_INPUT');
    }
    if ($pdo->inTransaction()) throw new RuntimeException('CHECKIN_BUSY');
    $token=brvtalTicketCheckinQr($qr);
    try {
        $pdo->beginTransaction();
        try {
            $event=$pdo->prepare('SELECT status FROM events WHERE id=? FOR UPDATE');
            $event->execute([$eventId]);
            $status=$event->fetchColumn();
            if ($status===false) throw new DomainException('CHECKIN_EVENT_NOT_FOUND');
            $reason=!in_array((string)$status,['active','published'],true)
                ?'event_closed':($token===null?'malformed_qr':'unknown_credential');
            $entryId=null; $version=null;
            if ($token!==null&&$reason==='unknown_credential') {
                $stmt=$pdo->prepare('SELECT id,event_id,status,order_item_id,ticket_type_id FROM ticket_entries
                    WHERE public_id=UNHEX(?) FOR UPDATE');
                $stmt->execute([$token['public_id']]);
                $entry=$stmt->fetch(PDO::FETCH_ASSOC);
                if (is_array($entry)) {
                    if ((int)$entry['event_id']!==$eventId) $reason='wrong_event';
                    elseif ($entry['status']!=='issued') {
                        $reason=$entry['status']==='used'?'already_used':'entry_revoked';
                    } else {
                        $st=$pdo->prepare("SELECT version,token_hash FROM ticket_entry_credentials
                            WHERE entry_id=? AND status='active' LIMIT 1");
                        $st->execute([(int)$entry['id']]);
                        $credential=$st->fetch(PDO::FETCH_ASSOC);
                        if (!is_array($credential)
                            || !hash_equals($credential['token_hash'],$token['hash'])) {
                            $reason='credential_revoked';
                        } else {
                            $paid=$pdo->prepare("SELECT o.status,o.event_id,
                                EXISTS(SELECT 1 FROM ticket_issue_outbox b WHERE b.order_id=o.id AND b.state='done') AS issued,
                                EXISTS(SELECT 1 FROM ticket_payment_attempts a
                                    WHERE a.order_id=o.id AND a.provider='wompi'
                                      AND a.status='approved' AND a.provider_transaction_id IS NOT NULL
                                      AND EXISTS(SELECT 1 FROM ticket_payment_webhook_events w
                                        WHERE w.attempt_id=a.id AND w.provider_transaction_id=a.provider_transaction_id
                                          AND w.signed_status='APPROVED')) AS verified
                                FROM ticket_order_items i JOIN ticket_orders o ON o.id=i.order_id
                                WHERE i.id=? AND i.event_id=? AND i.ticket_type_id=?");
                            $paid->execute([(int)$entry['order_item_id'],$eventId,(int)$entry['ticket_type_id']]);
                            $order=$paid->fetch(PDO::FETCH_ASSOC);
                            $paidValid=is_array($order)&&$order['status']==='paid'
                                &&(int)$order['event_id']===$eventId
                                &&(int)$order['issued']===1&&(int)$order['verified']===1;
                            $courtesyValid=false;
                            // T25 is disabled by default. Existing paid QR scans never
                            // depend on a pending courtesy migration or a fabricated payment.
                            if (!$paidValid && brvtalCourtesyEnabled($pdo)) {
                                $proof=$pdo->prepare("SELECT c.id FROM ticket_courtesy_invites c
                                    JOIN ticket_orders o ON o.id=c.order_id
                                    JOIN ticket_inventory_holds h ON h.order_id=o.id
                                      AND h.event_id=c.event_id AND h.ticket_type_id=c.ticket_type_id
                                    WHERE c.entry_id=? AND c.event_id=? AND c.ticket_type_id=?
                                      AND c.status='issued' AND c.origin='courtesy'
                                      AND o.status='draft' AND o.total_cop=0
                                      AND h.kind='courtesy' AND h.status='consumed'
                                      AND NOT EXISTS(SELECT 1 FROM ticket_payment_attempts a
                                        WHERE a.order_id=o.id) LIMIT 1");
                                $proof->execute([(int)$entry['id'],$eventId,(int)$entry['ticket_type_id']]);
                                $courtesyValid=$proof->fetchColumn()!==false;
                            }
                            if (!$paidValid && !$courtesyValid) {
                                $reason='payment_unverified';
                            } else {
                                $up=$pdo->prepare("UPDATE ticket_entries
                                    SET status='used',used_at_utc=UTC_TIMESTAMP(6)
                                    WHERE id=? AND status='issued'");
                                $up->execute([(int)$entry['id']]);
                                if ($up->rowCount()!==1) throw new RuntimeException('CHECKIN_CONFLICT');
                                if ($courtesyValid) {
                                    $pdo->prepare("UPDATE ticket_courtesy_invites
                                        SET status='used' WHERE entry_id=? AND status='issued'")
                                        ->execute([(int)$entry['id']]);
                                }
                                $entryId=(int)$entry['id'];
                                $version=(int)$credential['version'];
                                $reason='accepted';
                            }
                        }
                    }
                }
            }
            $accepted=$reason==='accepted';
            $audit=$pdo->prepare('INSERT INTO ticket_checkins
                (request_id,event_id,entry_id,credential_version,operator_admin_id,device_id,outcome,reason_code,attempted_at_utc)
                VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))');
            $audit->execute([random_bytes(16),$eventId,$accepted?$entryId:null,
                $accepted?$version:null,$operator,$device,$accepted?'accepted':'rejected',
                $accepted?null:$reason]);
            $pdo->commit();
            return ['ok'=>$accepted,'reason'=>$reason];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    } catch (PDOException $error) {
        throw new RuntimeException('CHECKIN_STORAGE_UNAVAILABLE',0,$error);
    }
}
