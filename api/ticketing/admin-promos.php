<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/admin_auth.php';
require_once __DIR__.'/../../config/ticketing_staff_permissions.php';
require_once __DIR__.'/../../config/ticketing_promoters.php';
require_once __DIR__.'/../../config/public_visibility.php';
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
brvtal_admin_require();
$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if (!in_array($method,['GET','POST'],true)) {
    json_response(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405,['Allow'=>'GET, POST']);
}
if ($method==='POST') brvtal_admin_require_csrf();
try {
    $db=db();
    $q=$db->prepare('SELECT staff_role FROM admins WHERE id=? AND is_active=1 LIMIT 1');
    $q->execute([(int)($_SESSION['admin_id']??0)]);
    if (!brvtalTicketingCan((string)($q->fetchColumn()?:''),'settings')) {
        json_response(['ok'=>false,'error'=>'FORBIDDEN'],403);
    }
    if ($method==='GET' && ($_GET['catalog']??'')==='1') {
        // #1964: pickers instead of typed IDs. Only published events and their ticket names.
        // #2022: every selling state (tickets_available, last_tickets…), not only 'published'.
        $active=brvtal_public_event_statuses()['active'];
        $eventsQ=$db->prepare('SELECT id,title,slug,event_date FROM events WHERE status IN ('
            .implode(',',array_fill(0,count($active),'?')).')
            ORDER BY event_date IS NULL, event_date DESC, id DESC LIMIT 60');
        $eventsQ->execute($active);
        $events=$eventsQ->fetchAll(PDO::FETCH_ASSOC);
        $types=[];
        if ($events) {
            $ids=array_map(static fn(array $row): int => (int)$row['id'], $events);
            $q=$db->prepare('SELECT id,event_id,name FROM event_ticket_types WHERE event_id IN ('
                .implode(',',array_fill(0,count($ids),'?')).') ORDER BY sort_order,id');
            $q->execute($ids);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $types[(int)$row['event_id']][]=['id'=>(int)$row['id'],'name'=>(string)$row['name']];
            }
        }
        json_response(['ok'=>true,'events'=>array_map(static fn(array $row): array => [
            'id'=>(int)$row['id'],'title'=>(string)$row['title'],'slug'=>(string)$row['slug'],'date'=>$row['event_date'],
            'ticket_types'=>$types[(int)$row['id']]??[]],$events)]);
    }
    // #2204: aliases and aggregate amounts only; all promoter operations remain synthetic.
    if ($method==='GET' && ($_GET['promoters']??'')==='1') {
        brvtalPromoSynthetic($db);
        if (!array_key_exists('promoter_id',$_GET)) {
            $rows=$db->query('SELECT id,promoter_key,active FROM promoters ORDER BY id DESC LIMIT 101')->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows)>100) throw new LengthException('ATTRIBUTION_RANGE_TOO_LARGE');
            json_response(['ok'=>true,'promoters'=>$rows,'synthetic_only'=>true]);
        }
        $id=filter_var($_GET['promoter_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if ($id===false) throw new InvalidArgumentException();
        $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        $db->beginTransaction();
        try {
            $q=$db->prepare('SELECT id,promoter_key,active FROM promoters WHERE id=?');
            $q->execute([$id]);
            $promoter=$q->fetch(PDO::FETCH_ASSOC);
            if (!$promoter) throw new DomainException('PROMOTER_UNAVAILABLE');
            $sales=array_column(brvtalPromoterSalesSnapshot($db,$id),null,'code');
            $q=$db->prepare('SELECT id,code,active FROM promoter_codes WHERE promoter_id=? ORDER BY id');
            $q->execute([$id]);
            $codes=[];
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $codes[]=$row+$sales[$row['code']];
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        json_response(['ok'=>true,'promoter'=>$promoter,'codes'=>$codes,'synthetic_only'=>true]);
    }
    if ($method==='GET') {
        $event=filter_var($_GET['event_id']??null,FILTER_VALIDATE_INT);
        if (!$event || $event<1) throw new InvalidArgumentException();
        $q=$db->prepare('SELECT id,event_id,ticket_type_id,kind,value_cop,
            starts_at_utc,ends_at_utc,max_uses,buyer_limit,uses_count,active,campaign_key
            FROM ticket_promo_rules WHERE event_id=? ORDER BY id DESC LIMIT 50');
        $q->execute([$event]);
        // Never return a full digest or plaintext coupon to the admin list.
        json_response(['ok'=>true,'rules'=>$q->fetchAll(PDO::FETCH_ASSOC),
            'synthetic_only'=>true,'checkout_activation'=>false]);
    }
    if (!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),
            'application/json') || (int)($_SERVER['CONTENT_LENGTH']??0)>4096) {
        json_response(['ok'=>false,'error'=>'INVALID_CONTENT_TYPE'],415);
    }
    $raw=file_get_contents('php://input',false,null,0,4097);
    if (!is_string($raw)||strlen($raw)>4096) throw new InvalidArgumentException();
    $body=json_decode($raw,true,12,JSON_THROW_ON_ERROR);
    if (!is_array($body)) throw new InvalidArgumentException();
    $action=$body['action']??null; unset($body['action']);
    if ($action==='create_promoter') {
        brvtalPromoSynthetic($db);
        if (count($body)!==2 || !is_string($body['promoter_key']??null) || !is_string($body['code']??null)) {
            throw new InvalidArgumentException();
        }
        $db->beginTransaction();
        try {
            $id=brvtalPromoterCreate($db,$body['promoter_key']);
            brvtalPromoterCreateCode($db,$id,$body['code']);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            if ($e instanceof PDOException && (int)($e->errorInfo[1]??0)===1062) {
                throw new DomainException('PROMO_ALREADY_EXISTS');
            }
            throw $e;
        }
        json_response(['ok'=>true,'id'=>$id,'synthetic_only'=>true],201);
    }
    if ($action==='set_promoter_active' || $action==='set_referral_active') {
        brvtalPromoSynthetic($db);
        $codeAction=$action==='set_referral_active';
        if (count($body)!==($codeAction?3:2) || !is_int($body['id']??null) || $body['id']<1
            || !is_bool($body['active']??null)
            || ($codeAction && (!is_int($body['code_id']??null) || $body['code_id']<1))) {
            throw new InvalidArgumentException();
        }
        if ($codeAction) {
            $q=$db->prepare('UPDATE promoter_codes SET active=? WHERE id=? AND promoter_id=?');
            $q->execute([(int)$body['active'],$body['code_id'],$body['id']]);
        } else {
            $q=$db->prepare('UPDATE promoters SET active=? WHERE id=?');
            $q->execute([(int)$body['active'],$body['id']]);
        }
        // Read the scoped row after an idempotent toggle; no paid history changes.
        $q=$codeAction
            ? $db->prepare('SELECT active FROM promoter_codes WHERE id=? AND promoter_id=?')
            : $db->prepare('SELECT active FROM promoters WHERE id=?');
        $q->execute($codeAction?[$body['code_id'],$body['id']]:[$body['id']]);
        if ($q->fetchColumn()===false) throw new DomainException('PROMOTER_UNAVAILABLE');
        json_response(['ok'=>true,'synthetic_only'=>true]);
    }
    if ($action==='create') {
        $id=brvtalPromoCreate($db,$body);
        json_response(['ok'=>true,'id'=>$id,'synthetic_only'=>true],201);
    }
    if ($action==='deactivate' && array_keys($body)===['id'] && is_int($body['id'])) {
        brvtalPromoDeactivate($db,$body['id']);
        json_response(['ok'=>true,'synthetic_only'=>true]);
    }
    throw new InvalidArgumentException();
} catch (InvalidArgumentException|JsonException) {
    json_response(['ok'=>false,'error'=>'INVALID_PROMO_INPUT'],422);
} catch (DomainException $e) {
    $code=$e->getMessage();
    $status=$code==='PROMOS_NOT_ENABLED'?423:
        ($code==='PROMO_ALREADY_EXISTS'?409:($code==='ATTRIBUTION_EVIDENCE_UNAVAILABLE'?503:404));
    json_response(['ok'=>false,'error'=>$code],$status);
} catch (Throwable) {
    json_response(['ok'=>false,'error'=>'PROMOS_UNAVAILABLE'],503);
}
