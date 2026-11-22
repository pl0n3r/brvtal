<?php
declare(strict_types=1);

require_once __DIR__ . '/ticketing_staff_permissions.php';
require_once __DIR__ . '/ticketing_inventory.php';

/** Off by default; the test escape hatch is limited to disposable MariaDB. */
function brvtalCourtesyEnabled(PDO $db): bool {
    // A CI/disposable DB alone never activates courtesy checks in legacy scans.
    // Explicit opt-in is required even in the synthetic test environment.
    if (getenv('BRVTAL_COURTESY_ENABLED') !== '1') return false;
    if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') return true;
    $name = (string)$db->query('SELECT DATABASE()')->fetchColumn();
    return preg_match('/^brvtal_test[A-Za-z0-9_]*$/D', $name) === 1;
}

function brvtalCourtesyValidate(array $input): array {
    $allowed = ['action','event_id','ticket_type_id','recipient','category','reason','idempotency_key','invite_id'];
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
    if (!preg_match('/^[a-z0-9._@+-]{3,100}$/D',$input['recipient'])
        || !in_array($input['category'],['artist','staff','press','partner'],true)
        || strlen($input['reason'])<5 || strlen($input['reason'])>180
        || preg_match('/[\\x00-\\x1f@]|https?:|(?:\\d[\\s().-]*){7,}/i',$input['reason'])
        || !preg_match('/^[A-Za-z0-9_-]{20,64}$/D',$input['idempotency_key']))
        throw new InvalidArgumentException('INVALID_INPUT');
    return $input;
}

/** T25: one reserved seat per recipient; paid revenue remains untouched. */
function brvtalCourtesyIssue(PDO $db,int $actor,string $role,array $data): array {
    if ($actor<1 || !brvtalTicketingCan($role,'courtesy')) throw new DomainException('FORBIDDEN');
    if (!brvtalCourtesyEnabled($db)) throw new DomainException('COURTESY_DISABLED');
    if ($db->inTransaction()) throw new RuntimeException('COURTESY_BUSY');
    $event=$data['event_id']; $type=$data['ticket_type_id'];
    $recipient=hash('sha256',$data['recipient']);
    $request=hash('sha256',$actor.'|'.$data['idempotency_key']);
    $db->beginTransaction();
    try {
        // The event mutex matches T05 and serializes paid/courtesy budgets.
        $q=$db->prepare('SELECT status FROM events WHERE id=? FOR UPDATE'); $q->execute([$event]);
        $eventStatus=$q->fetchColumn();
        if (!in_array($eventStatus,['active','published'],true)) throw new DomainException('EVENT_CLOSED');
        $q=$db->prepare('SELECT id,request_digest,ticket_type_id,category,reason FROM ticket_courtesy_invites
            WHERE event_id=? AND recipient_digest=? FOR UPDATE');
        $q->execute([$event,$recipient]); $existing=$q->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if (!hash_equals($existing['request_digest'],$request)) throw new DomainException('DUPLICATE_INVITATION');
            if ((int)$existing['ticket_type_id']!==$type
                || $existing['category']!==$data['category']
                || $existing['reason']!==$data['reason'])
                throw new DomainException('IDEMPOTENCY_CONFLICT');
            $db->commit();
            return ['invite_id'=>(int)$existing['id'],'replayed'=>true,'qr'=>null,'sent'=>false];
        }
        $q=$db->prepare('SELECT id FROM ticket_courtesy_invites WHERE request_digest=?');
        $q->execute([$request]);
        if ($q->fetchColumn()!==false) throw new DomainException('IDEMPOTENCY_CONFLICT');
        $q=$db->prepare('SELECT t.status, i.capacity,i.courtesy_capacity,e.capacity AS event_capacity
            FROM event_ticket_types t
            JOIN ticket_inventory_types i ON i.ticket_type_id=t.id AND i.event_id=t.event_id
            JOIN ticket_inventory_events e ON e.event_id=t.event_id
            WHERE t.id=? AND t.event_id=? FOR UPDATE');
        $q->execute([$type,$event]); $limits=$q->fetch(PDO::FETCH_ASSOC);
        if (!$limits || $limits['status']!=='active') throw new DomainException('CAPACITY_NOT_CONFIGURED');

        $utc=gmdate('Y-m-d H:i:s');
        $all=brvtal_ticket_inventory_usage($db,$event,0,$utc);
        $perType=brvtal_ticket_inventory_usage($db,$event,$type,$utc);
        if ($all['sold']+$all['held']+$all['courtesy']+1>(int)$limits['event_capacity']
            || $perType['sold']+$perType['held']+$perType['courtesy']+1>(int)$limits['capacity']
            || $perType['courtesy']+1>(int)$limits['courtesy_capacity'])
            throw new DomainException('COURTESY_CAPACITY');

        // Internal draft at COP 0; NEVER paid, webhook-approved or revenue-bearing.
        $db->prepare('INSERT INTO ticket_buyers(public_id,email,status) VALUES (?,NULL,?)')
            ->execute([random_bytes(16),'guest']);
        $buyer=(int)$db->lastInsertId();
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
        $db->prepare("INSERT INTO ticket_entry_credentials(entry_id,version,token_hash,created_at_utc)
            VALUES (?,1,?,UTC_TIMESTAMP(6))")->execute([$entry,hash('sha256',$secret,true)]);
        $db->prepare('INSERT INTO ticket_courtesy_invites
            (event_id,ticket_type_id,order_id,entry_id,category,recipient_digest,request_digest,reason,authorized_by)
            VALUES (?,?,?,?,?,?,?,?,?)')->execute([$event,$type,$order,$entry,$data['category'],
                $recipient,$request,$data['reason'],$actor]);
        $invite=(int)$db->lastInsertId();
        $db->commit();
        return ['invite_id'=>$invite,'replayed'=>false,'sent'=>false,
            'qr'=>'BRVTAL:T1:'.bin2hex($public).':'.rtrim(strtr(base64_encode($secret),'+/','-_'),'=')];
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        if ($error instanceof PDOException) throw new RuntimeException('COURTESY_STORAGE_UNAVAILABLE',0,$error);
        throw $error;
    }
}

/** Revocation and resend intent are audit-only; no Resend provider is called. */
function brvtalCourtesyAction(PDO $db,int $actor,string $role,string $action,int $invite): array {
    if ($actor<1 || !brvtalTicketingCan($role,'courtesy')) throw new DomainException('FORBIDDEN');
    if (!brvtalCourtesyEnabled($db)) throw new DomainException('COURTESY_DISABLED');
    if ($db->inTransaction()) throw new RuntimeException('COURTESY_BUSY');
    $q=$db->prepare('SELECT event_id FROM ticket_courtesy_invites WHERE id=?');
    $q->execute([$invite]); $event=(int)$q->fetchColumn();
    if ($event<1) throw new DomainException('INVITATION_NOT_FOUND');
    $db->beginTransaction();
    try {
        $q=$db->prepare('SELECT id FROM events WHERE id=? FOR UPDATE'); $q->execute([$event]);
        if ($q->fetchColumn()===false) throw new DomainException('INVITATION_NOT_FOUND');
        $q=$db->prepare('SELECT order_id,entry_id,ticket_type_id,status
            FROM ticket_courtesy_invites WHERE id=? AND event_id=? FOR UPDATE');
        $q->execute([$invite,$event]); $row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['status']!=='issued') throw new DomainException('INVITATION_NOT_ACTIVE');
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
        } elseif ($action==='resend') {
            // Record intention; plaintext QR is intentionally unrecoverable.
            $record=$db->prepare("UPDATE ticket_courtesy_invites SET resend_requests=resend_requests+1
                WHERE id=? AND status='issued' AND resend_requests<100");
            $record->execute([$invite]);
            if ($record->rowCount()!==1) throw new DomainException('RESEND_LIMIT');
        } else throw new InvalidArgumentException('INVALID_ACTION');
        $db->commit();
        return ['invite_id'=>$invite,'status'=>$action==='revoke'?'revoked':'logged_only','sent'=>false];
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        if ($error instanceof PDOException) throw new RuntimeException('COURTESY_STORAGE_UNAVAILABLE',0,$error);
        throw $error;
    }
}
