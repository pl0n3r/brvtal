<?php
declare(strict_types=1);

require_once __DIR__ . '/ticketing_staff_permissions.php';
require_once __DIR__ . '/ticketing_inventory.php';
require_once __DIR__ . '/ticketing_mail_outbox.php';
require_once __DIR__ . '/ticketing_mail_mode.php';
require_once __DIR__ . '/ticketing_issue.php';

/** The additive schema, rather than a production escape hatch, owns readiness. */
function brvtalCourtesyEnabled(PDO $db): bool {
    $q=$db->query("SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ticket_courtesy_invites' LIMIT 1");
    return $q->fetchColumn()!==false;
}

function brvtalCourtesyValidate(array $input): array {
    $allowed=['action','event_id','ticket_type_id','recipient','category','reason','idempotency_key','invite_id'];
    if (array_diff(array_keys($input),$allowed)) throw new InvalidArgumentException('INVALID_INPUT');
    $action=$input['action']??null;
    if (!in_array($action,['issue','revoke','resend'],true)) throw new InvalidArgumentException('INVALID_ACTION');
    if ($action!=='issue') {
        if (array_diff(array_keys($input),['action','invite_id'])
            || !is_int($input['invite_id']??null) || $input['invite_id']<1)
            throw new InvalidArgumentException('INVALID_INPUT');
        return $input;
    }
    if (count($input)!==7 || !is_int($input['event_id']) || $input['event_id']<1
        || !is_int($input['ticket_type_id']) || $input['ticket_type_id']<1)
        throw new InvalidArgumentException('INVALID_INPUT');
    foreach (['recipient','category','reason','idempotency_key'] as $field)
        if (!is_string($input[$field]??null)) throw new InvalidArgumentException('INVALID_INPUT');
    $input['recipient']=strtolower(trim($input['recipient']));
    $input['reason']=trim($input['reason']);
    if (strlen($input['recipient'])>190 || !filter_var($input['recipient'],FILTER_VALIDATE_EMAIL)
        || !in_array($input['category'],['artist','staff','press','partner'],true)
        || strlen($input['reason'])<5 || strlen($input['reason'])>180
        || preg_match('/[\x00-\x1f@]|https?:|(?:\d[\s().-]*){7,}/i',$input['reason'])
        || !preg_match('/^[A-Za-z0-9_-]{20,64}$/D',$input['idempotency_key']))
        throw new InvalidArgumentException('INVALID_INPUT');
    return $input;
}

/**
 * The QR bearer is encrypted in credential storage and the sealed outbox;
 * never return it to DISCADMIN or persist it as plaintext. The existing CLI mail worker owns
 * provider delivery and its test/live gates.
 */
function brvtalCourtesyQueue(
    PDO $db,int $invite,string $email,string $public,string $secret,int $revision
): int {
    if (!$db->inTransaction()) throw new RuntimeException('COURTESY_BUSY');
    $testing=brvtal_secret_value('BRVTAL_TICKET_MAIL_TESTING')==='1';
    $mail=brvtal_ticket_mail_status();
    if (!$testing && (!$mail['ready']
        || ($mail['mode']==='test' && !brvtal_ticket_test_recipient_allowed($email)))) {
        throw new DomainException('MAIL_NOT_READY');
    }
    if (strlen($public)!==16 || strlen($secret)!==32 || $invite<1 || $revision<0) {
        throw new DomainException('COURTESY_DELIVERY_INVALID');
    }
    $token=rtrim(strtr(base64_encode($secret),'+/','-_'),'=');
    $url='https://www.brvtal.com.co/mi-cuenta/boleta#BRVTAL:T1:'.bin2hex($public).':'.$token;
    $text="Tienes una cortesía para BRVTAL.\n\nAbre el enlace para mostrar tu QR de entrada:\n"
        .$url."\n\nEste enlace es personal. No lo compartas.\n\nBRVTAL · Mensaje transaccional.";
    $link=htmlspecialchars($url,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $html='<!doctype html><html lang="es"><meta charset="utf-8">'
        .'<meta name="viewport" content="width=device-width,initial-scale=1">'
        .'<body style="margin:0;background:#111;color:#fff;font:16px/1.6 Arial,sans-serif">'
        .'<main style="max-width:620px;margin:auto;padding:32px 20px">'
        .'<h1 style="font-size:26px">Tu cortesía BRVTAL</h1>'
        .'<p>Tienes una entrada de cortesía. Abre este enlace para mostrar tu QR al ingresar.</p>'
        .'<p><a style="display:inline-block;min-height:44px;padding:12px 18px;'
        .'background:#fff;color:#111;text-decoration:underline" href="'.$link.'">Mostrar mi QR</a></p>'
        .'<p>El enlace es personal. No lo compartas.</p></main></body></html>';
    return brvtal_ticket_mail_enqueue($db,"courtesy:{$invite}:{$revision}",
        "courtesy:{$invite}",$email,'Tu cortesía BRVTAL',
        brvtalBuyerMailSeal($text),brvtalBuyerMailSeal($html,50000),'courtesy',1);
}

/** Honor canonical active states; sold_out still requires physical courtesy stock. */
function brvtalCourtesyEventEligible(array $event, ?DateTimeImmutable $now = null): bool
{
    $clock = brvtal_ticket_inventory_now($now)
        ->setTimezone(new DateTimeZone('America/Bogota'));
    $tz = new DateTimeZone('America/Bogota');
    foreach (['event_date', 'published_at'] as $field) {
        $raw = trim((string)($event[$field] ?? ''));
        if ($field === 'event_date' && $raw === '') {
            return false;
        }
        if ($raw === '') {
            continue;
        }
        try {
            $date = new DateTimeImmutable($raw, $tz);
        } catch (Throwable) {
            return false;
        }
        if ($field === 'event_date' && $date <= $clock) {
            return false;
        }
        // DATE_ATOM preserves Bogota wall-clock meaning for the canonical policy.
        $event[$field] = $date->format(DATE_ATOM);
    }
    return brvtal_public_event_allows_ticketing($event, $clock);
}

/** A courtesy consumes inventory, not payment or income. */
function brvtalCourtesyIssue(PDO $db,int $actor,string $role,array $data): array {
    if ($actor<1 || !brvtalTicketingCan($role,'courtesy')) throw new DomainException('FORBIDDEN');
    if (!brvtalCourtesyEnabled($db)) throw new DomainException('COURTESY_SCHEMA_MISSING');
    if ($db->inTransaction()) throw new RuntimeException('COURTESY_BUSY');
    $event=$data['event_id']; $type=$data['ticket_type_id'];
    $recipient=hash('sha256',$data['recipient']);
    $request=hash('sha256',$actor.'|'.$data['idempotency_key']);
    $db->beginTransaction();
    try {
        // The event mutex serializes paid holds and courtesy reservations.
        $q=$db->prepare('SELECT status,event_date,published_at FROM events WHERE id=? FOR UPDATE');
        $q->execute([$event]);
        $eventRow = $q->fetch(PDO::FETCH_ASSOC);
        if (!$eventRow || !brvtalCourtesyEventEligible($eventRow))
            throw new DomainException('EVENT_CLOSED');
        $q=$db->prepare('SELECT id,request_digest,ticket_type_id,category,reason,status FROM ticket_courtesy_invites
            WHERE event_id=? AND recipient_digest=? FOR UPDATE');
        $q->execute([$event,$recipient]); $existing=$q->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if (!hash_equals($existing['request_digest'],$request)) throw new DomainException('DUPLICATE_INVITATION');
            if ((int)$existing['ticket_type_id']!==$type
                || $existing['category']!==$data['category'] || $existing['reason']!==$data['reason'])
                throw new DomainException('IDEMPOTENCY_CONFLICT');
            $q=$db->prepare("SELECT 1 FROM ticket_mail_outbox
                WHERE related_ref=? AND state IN ('queued','retry','sending','accepted') LIMIT 1");
            $q->execute(['courtesy:'.$existing['id']]);
            $queued=$existing['status']==='issued' && $q->fetchColumn()!==false;
            $db->commit();
            return ['invite_id'=>(int)$existing['id'],'replayed'=>true,
                'queued'=>$queued,'needs_resend'=>!$queued,'sent'=>false,'qr'=>null];
        }
        $q=$db->prepare('SELECT id FROM ticket_courtesy_invites WHERE request_digest=?');
        $q->execute([$request]);
        if ($q->fetchColumn()!==false) throw new DomainException('IDEMPOTENCY_CONFLICT');
        $q=$db->prepare('SELECT t.status,i.capacity,i.courtesy_capacity,e.capacity AS event_capacity
            FROM event_ticket_types t JOIN ticket_inventory_types i ON i.ticket_type_id=t.id AND i.event_id=t.event_id
            JOIN ticket_inventory_events e ON e.event_id=t.event_id WHERE t.id=? AND t.event_id=? FOR UPDATE');
        $q->execute([$type,$event]); $limits=$q->fetch(PDO::FETCH_ASSOC);
        if (!$limits || $limits['status']!=='active') throw new DomainException('CAPACITY_NOT_CONFIGURED');
        $utc=gmdate('Y-m-d H:i:s');
        $all=brvtal_ticket_inventory_usage($db,$event,0,$utc);
        $perType=brvtal_ticket_inventory_usage($db,$event,$type,$utc);
        if ($all['sold']+$all['held']+$all['courtesy']+1>(int)$limits['event_capacity']
            || $perType['sold']+$perType['held']+$perType['courtesy']+1>(int)$limits['capacity']
            || $perType['courtesy']+1>(int)$limits['courtesy_capacity'])
            throw new DomainException('COURTESY_CAPACITY');

        // Reuse an existing buyer by email to honor the unique buyer email key.
        $q=$db->prepare('SELECT id,status FROM ticket_buyers WHERE email=? FOR UPDATE');
        $q->execute([$data['recipient']]); $buyerRow=$q->fetch(PDO::FETCH_ASSOC);
        if ($buyerRow && $buyerRow['status']==='blocked') throw new DomainException('RECIPIENT_BLOCKED');
        if ($buyerRow) {
            $buyer=(int)$buyerRow['id'];
        } else {
            $db->prepare('INSERT INTO ticket_buyers(public_id,email,status) VALUES (?,?,?)')
                ->execute([random_bytes(16),$data['recipient'],'guest']);
            $buyer=(int)$db->lastInsertId();
        }
        // Internal draft at COP 0: never Wompi-approved or revenue-bearing.
        $db->prepare("INSERT INTO ticket_orders(public_id,buyer_id,event_id,checkout_key_hash,status,currency,total_cop)
            VALUES (?,?,?,?, 'draft','COP',0)")
            ->execute([random_bytes(16),$buyer,$event,hash('sha256',random_bytes(32))]);
        $order=(int)$db->lastInsertId();
        $db->prepare("INSERT INTO ticket_order_items(order_id,event_id,ticket_type_id,ticket_name_snapshot,
            quantity,unit_price_cop,subtotal_cop,currency) VALUES (?,?,?,'Cortesía',1,0,0,'COP')")
            ->execute([$order,$event,$type]);
        $item=(int)$db->lastInsertId();
        $db->prepare("INSERT INTO ticket_inventory_holds(order_id,event_id,ticket_type_id,quantity,kind,status,expires_at_utc)
            VALUES (?,?,?,1,'courtesy','consumed',DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 1 DAY))")
            ->execute([$order,$event,$type]);
        $public=random_bytes(16); $secret=random_bytes(32);
        $db->prepare('INSERT INTO ticket_entries(public_id,order_item_id,event_id,ticket_type_id,unit_number,issued_at_utc)
            VALUES (?,?,?,?,1,UTC_TIMESTAMP(6))')->execute([$public,$item,$event,$type]);
        $entry=(int)$db->lastInsertId();
        $db->prepare("INSERT INTO ticket_entry_credentials(entry_id,version,token_hash,token_encrypted,created_at_utc)
            VALUES (?,1,?,?,UTC_TIMESTAMP(6))")->execute([
                $entry,hash('sha256',$secret,true),brvtal_ticket_issue_seal_token($secret,$public)]);
        $db->prepare('INSERT INTO ticket_courtesy_invites
            (event_id,ticket_type_id,order_id,entry_id,category,recipient_digest,request_digest,reason,authorized_by)
            VALUES (?,?,?,?,?,?,?,?,?)')->execute([$event,$type,$order,$entry,$data['category'],
                $recipient,$request,$data['reason'],$actor]);
        $invite=(int)$db->lastInsertId();
        brvtalCourtesyQueue($db,$invite,$data['recipient'],$public,$secret,0);
        $db->commit();
        return ['invite_id'=>$invite,'replayed'=>false,'queued'=>true,'sent'=>false,'qr'=>null];
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        if ($error instanceof PDOException) throw new RuntimeException('COURTESY_STORAGE_UNAVAILABLE',0,$error);
        throw $error;
    }
}

/** Revoke the real credential; resend rotates it and queues a new sealed link. */
function brvtalCourtesyAction(PDO $db,int $actor,string $role,string $action,int $invite): array {
    if ($actor<1 || !brvtalTicketingCan($role,'courtesy')) throw new DomainException('FORBIDDEN');
    if (!brvtalCourtesyEnabled($db)) throw new DomainException('COURTESY_SCHEMA_MISSING');
    if ($db->inTransaction()) throw new RuntimeException('COURTESY_BUSY');
    $q=$db->prepare('SELECT event_id FROM ticket_courtesy_invites WHERE id=?');
    $q->execute([$invite]); $event=(int)$q->fetchColumn();
    if ($event<1) throw new DomainException('INVITATION_NOT_FOUND');
    $db->beginTransaction();
    try {
        $q=$db->prepare('SELECT id FROM events WHERE id=? FOR UPDATE'); $q->execute([$event]);
        if ($q->fetchColumn()===false) throw new DomainException('INVITATION_NOT_FOUND');
        $q=$db->prepare('SELECT c.order_id,c.entry_id,c.ticket_type_id,c.status,c.resend_requests,
            e.status AS entry_status,e.public_id,b.email FROM ticket_courtesy_invites c
            JOIN ticket_entries e ON e.id=c.entry_id JOIN ticket_orders o ON o.id=c.order_id
            JOIN ticket_buyers b ON b.id=o.buyer_id WHERE c.id=? AND c.event_id=? FOR UPDATE');
        $q->execute([$invite,$event]); $row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['status']!=='issued' || $row['entry_status']!=='issued')
            throw new DomainException('INVITATION_NOT_ACTIVE');
        if ($action==='revoke') {
            $db->prepare("UPDATE ticket_entries SET status='revoked',revoked_at_utc=UTC_TIMESTAMP(6)
                WHERE id=? AND status='issued'")->execute([(int)$row['entry_id']]);
            $db->prepare("UPDATE ticket_entry_credentials SET status='revoked',revoked_at_utc=UTC_TIMESTAMP(6)
                WHERE entry_id=? AND status='active'")->execute([(int)$row['entry_id']]);
            $db->prepare("UPDATE ticket_inventory_holds SET status='released' WHERE order_id=?
                AND ticket_type_id=? AND kind='courtesy' AND status='consumed'")
                ->execute([(int)$row['order_id'],(int)$row['ticket_type_id']]);
            $db->prepare("UPDATE ticket_courtesy_invites SET status='revoked',revoked_by=?,
                revoked_at_utc=UTC_TIMESTAMP(6) WHERE id=? AND status='issued'")->execute([$actor,$invite]);
            // Already accepted mail cannot be recalled; a queued email must not leave.
            $db->prepare("UPDATE ticket_mail_outbox SET state='failed',last_error_code='courtesy_revoked'
                WHERE related_ref=? AND state IN ('queued','retry')")->execute(["courtesy:{$invite}"]);
        } elseif ($action==='resend') {
            $revision=(int)$row['resend_requests']+1;
            if ($revision>100 || !filter_var((string)$row['email'],FILTER_VALIDATE_EMAIL))
                throw new DomainException('RESEND_LIMIT');
            $q=$db->prepare("SELECT version FROM ticket_entry_credentials
                WHERE entry_id=? AND status='active' FOR UPDATE");
            $q->execute([(int)$row['entry_id']]); $version=$q->fetchColumn();
            if ($version===false) throw new DomainException('INVITATION_NOT_ACTIVE');
            $db->prepare("UPDATE ticket_entry_credentials SET status='revoked',revoked_at_utc=UTC_TIMESTAMP(6)
                WHERE entry_id=? AND status='active'")->execute([(int)$row['entry_id']]);
            $secret=random_bytes(32);
            $db->prepare("INSERT INTO ticket_entry_credentials
                (entry_id,version,token_hash,token_encrypted,created_at_utc) VALUES (?,?,?,?,UTC_TIMESTAMP(6))")
                ->execute([(int)$row['entry_id'],(int)$version+1,hash('sha256',$secret,true),
                    brvtal_ticket_issue_seal_token($secret,(string)$row['public_id'])]);
            $db->prepare("UPDATE ticket_mail_outbox SET state='failed',last_error_code='courtesy_superseded'
                WHERE related_ref=? AND state IN ('queued','retry')")->execute(["courtesy:{$invite}"]);
            brvtalCourtesyQueue($db,$invite,(string)$row['email'],(string)$row['public_id'],$secret,$revision);
            $db->prepare("UPDATE ticket_courtesy_invites SET resend_requests=? WHERE id=?")
                ->execute([$revision,$invite]);
        } else throw new InvalidArgumentException('INVALID_ACTION');
        $db->commit();
        return ['invite_id'=>$invite,'status'=>$action==='revoke'?'revoked':'queued',
            'queued'=>$action==='resend','sent'=>false];
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        if ($error instanceof PDOException) throw new RuntimeException('COURTESY_STORAGE_UNAVAILABLE',0,$error);
        throw $error;
    }
}
