<?php
declare(strict_types=1);
require_once __DIR__ . '/secrets.php';
/** T11: internal server-only durable mail audit, retaining no full recipient or recovery token. */
function brvtal_mail_history_allowed(): bool {
    return brvtal_secret_value('BRVTAL_TICKET_MAIL_TESTING') === '1'
        || brvtal_secret_value('BRVTAL_TICKET_MAIL_PRIVACY_APPROVED') === '1';
}
/** Materialize a T10 outbox receipt after commit, with explicit relation IDs. */
function brvtal_mail_history_record(PDO $pdo, int $outboxId, array $meta = []): int {
    if (!brvtal_mail_history_allowed()) throw new RuntimeException('MAIL_PRIVACY_NOT_APPROVED');
    if ($outboxId < 1) throw new InvalidArgumentException('MAIL_INVALID_OUTBOX');
    $q = $pdo->prepare('SELECT message_key,recipient_email,provider_message_id,state FROM ticket_mail_outbox WHERE id=?');
    $q->execute([$outboxId]);
    $mail = $q->fetch(PDO::FETCH_ASSOC);
    if (!is_array($mail)) throw new DomainException('MAIL_OUTBOX_MISSING');
    $secret = (string)brvtal_secret_value('BRVTAL_TICKET_MAIL_LOOKUP_SECRET');
    if ($secret === '' && brvtal_secret_value('BRVTAL_TICKET_MAIL_TESTING') !== '1') throw new RuntimeException('MAIL_LOOKUP_SECRET_MISSING');
    $email = strtolower((string)$mail['recipient_email']);
    $parts = explode('@',$email,2);
    $hint = substr($parts[0],0,1).'***@'.($parts[1] ?? 'redacted');
    $digest = hash_hmac('sha256',$email,$secret ?: 'test-fixture-only');
    $ids = [];
    foreach (['event_id','buyer_id','order_id','ticket_id'] as $key) {
        $id = $meta[$key] ?? null;
        if ($id !== null && (!is_int($id) || $id < 1)) throw new InvalidArgumentException('MAIL_INVALID_SCOPE');
        $ids[] = $id;
    }
    $template = (string)($meta['template_key'] ?? 'ticket');
    $actor = (string)($meta['actor_kind'] ?? 'system');
    if (!preg_match('/^[a-z0-9_-]{1,64}$/D',$template)
        || !in_array($actor,['system','admin','buyer'],true)) throw new InvalidArgumentException('MAIL_INVALID_META');
    $provider = (string)($mail['provider_message_id'] ?? '');
    if ($provider !== '' && !preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$provider)) throw new InvalidArgumentException('MAIL_INVALID_PROVIDER');
    $state = $mail['state'] === 'accepted' ? 'accepted' : 'queued';
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $add = $pdo->prepare("INSERT INTO ticket_mail_history
          (outbox_id,message_key,template_key,recipient_hint,recipient_digest,event_id,buyer_id,order_id,ticket_id,
           actor_kind,resend_email_id,delivery_state,retention_until)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 180 DAY))
          ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),
          resend_email_id=COALESCE(resend_email_id,VALUES(resend_email_id)),
          delivery_state=IF(delivery_state='queued' AND VALUES(delivery_state)='accepted','accepted',delivery_state)");
        $add->execute([$outboxId,$mail['message_key'],$template,$hint,$digest,...$ids,$actor,$provider ?: null,$state]);
        $id = (int)$pdo->lastInsertId();
        $check = $pdo->prepare('SELECT outbox_id FROM ticket_mail_history WHERE id=?');
        $check->execute([$id]);
        if ((int)$check->fetchColumn() !== $outboxId) throw new DomainException('MAIL_HISTORY_CONFLICT');
        if ($provider !== '') brvtal_mail_history_reconcile($pdo,$provider);
        if ($own) $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Link a successful Resend receipt without replacing the saved history scope.
 * Called only within the outbox worker's fenced acceptance transaction.
 * Terminal delivery events win over the initial provider acceptance.
 */
function brvtal_mail_history_accept(PDO $pdo, int $outboxId, string $provider): void {
    if (!brvtal_mail_history_allowed()) return;
    if (!$pdo->inTransaction()) throw new RuntimeException('MAIL_TRANSACTION_REQUIRED');
    if ($outboxId < 1 || preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$provider) !== 1)
        throw new InvalidArgumentException('MAIL_INVALID_PROVIDER');
    // T10 predates T11: workers must still accept messages on an outbox-only
    // database. Never create history without its scoped T11 audit record.
    $historyReady=$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ticket_mail_history'");
    if ((int)$historyReady->fetchColumn()===0) return;
    $q=$pdo->prepare('SELECT id,resend_email_id FROM ticket_mail_history WHERE outbox_id=? FOR UPDATE');
    $q->execute([$outboxId]);
    $history=$q->fetch(PDO::FETCH_ASSOC);
    if (!is_array($history)) return; // Future scope-aware history record will link the accepted outbox.
    $linked=(string)($history['resend_email_id'] ?? '');
    if ($linked !== '' && !hash_equals($linked,$provider))
        throw new DomainException('MAIL_HISTORY_PROVIDER_CONFLICT');
    $update=$pdo->prepare("UPDATE ticket_mail_history SET resend_email_id=?,
        state_at=IF(delivery_state='queued',UTC_TIMESTAMP(),state_at),
        delivery_state=IF(delivery_state='queued','accepted',delivery_state)
        WHERE id=?");
    $update->execute([$provider,(int)$history['id']]);
    brvtal_mail_history_reconcile($pdo,$provider);
}

/** Durable unique receipt even if history/backfill has not yet linked the Resend email_id. */
function brvtal_mail_history_event(PDO $pdo, string $webhookId, string $provider,
    string $state, DateTimeImmutable $when, string $hash): void {
    $pdo->beginTransaction();
    try {
        $add = $pdo->prepare('INSERT IGNORE INTO ticket_mail_provider_events
           (webhook_id,resend_email_id,delivery_state,occurred_at,payload_sha256) VALUES (?,?,?,?,?)');
        $add->execute([$webhookId,$provider,$state,$when->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),$hash]);
        if ($add->rowCount() === 0) {
            $q=$pdo->prepare('SELECT payload_sha256 FROM ticket_mail_provider_events WHERE webhook_id=?');
            $q->execute([$webhookId]);
            if (!hash_equals($hash,(string)$q->fetchColumn())) throw new DomainException('MAIL_WEBHOOK_CONFLICT');
        }
        brvtal_mail_history_reconcile($pdo,$provider);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
function brvtal_mail_history_reconcile(PDO $pdo, string $provider): void {
    $q=$pdo->prepare('SELECT id,delivery_state,state_at FROM ticket_mail_history WHERE resend_email_id=? FOR UPDATE');
    $q->execute([$provider]);
    $mail=$q->fetch(PDO::FETCH_ASSOC);
    if (!is_array($mail)) return;
    $q=$pdo->prepare('SELECT delivery_state,occurred_at FROM ticket_mail_provider_events WHERE resend_email_id=? ORDER BY occurred_at,id');
    $q->execute([$provider]);
    $rank=['queued'=>0,'accepted'=>1,'deferred'=>2,'delivered'=>3,'bounced'=>4,'failed'=>4];
    $state=(string)$mail['delivery_state'];$at=(string)($mail['state_at'] ?? '');
    foreach ($q as $event) {
        $next=(string)$event['delivery_state'];$time=(string)$event['occurred_at'];
        $nextRank=$rank[$next] ?? -1;
        $previousRank=$rank[$state] ?? -1;
        // Never downgrade a provider terminal state because of a delayed event.
        if ($nextRank>$previousRank || ($nextRank===$previousRank && $time>$at)) {
            $state=$next;$at=$time;
        }
    }
    $q=$pdo->prepare('UPDATE ticket_mail_history SET delivery_state=?,state_at=? WHERE id=?');
    $q->execute([$state,$at ?: null,(int)$mail['id']]);
}
/** Staff access only, invoked AFTER canonical brvtal_admin_require(). */
function brvtal_mail_history_list(PDO $pdo, array $filters, int $adminId): array {
    if ($adminId<1) throw new RuntimeException('MAIL_ADMIN_REQUIRED');
    $where=[];$values=[];
    foreach (['event_id','buyer_id','order_id','ticket_id'] as $key) {
        if (isset($filters[$key])) {
            $value=filter_var($filters[$key],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if ($value===false) throw new InvalidArgumentException('MAIL_INVALID_FILTER');
            $where[]=$key.'=?';$values[]=$value;
        }
    }
    if (!$where) throw new InvalidArgumentException('MAIL_FILTER_REQUIRED');
    $limit=filter_var($filters['limit'] ?? 25,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100]]);
    $cursor=filter_var($filters['before_id'] ?? PHP_INT_MAX,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if ($limit===false || $cursor===false) throw new InvalidArgumentException('MAIL_INVALID_PAGE');
    $sql='SELECT id,message_key,template_key,recipient_hint,event_id,buyer_id,order_id,ticket_id,
      actor_kind,resend_email_id,delivery_state,state_at,created_at,redacted_at
      FROM ticket_mail_history WHERE id<? AND '.implode(' AND ',$where).' ORDER BY id DESC LIMIT '.(int)$limit;
    $stmt=$pdo->prepare($sql);$stmt->execute([$cursor,...$values]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
/** Redact the minimised recipient on retention expiry; keep status and ids. */
function brvtal_mail_history_redact_expired(PDO $pdo): int {
    $stmt=$pdo->prepare('UPDATE ticket_mail_history SET recipient_hint=NULL,recipient_digest=NULL,
      redacted_at=UTC_TIMESTAMP() WHERE redacted_at IS NULL AND retention_until<UTC_TIMESTAMP()');
    $stmt->execute();return $stmt->rowCount();
}
