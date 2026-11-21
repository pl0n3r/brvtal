<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/admin_auth.php';
require_once __DIR__ . '/../../config/ticketing_staff_permissions.php';
require_once __DIR__ . '/../../config/ticketing_waitlist.php';

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
brvtal_admin_require();
try {
    $db=db();
    $q=$db->prepare('SELECT staff_role FROM admins WHERE id=? AND is_active=1');
    $q->execute([(int)($_SESSION['admin_id'] ?? 0)]);
    $role=(string)$q->fetchColumn();
    if (!brvtalTicketingCan($role,'buyers')) {
        json_response(['ok'=>false,'error'=>'FORBIDDEN'],403);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        $raw=(string)($_GET['event_id'] ?? '');
        if (!preg_match('/^[1-9][0-9]{0,9}$/D',$raw)) {
            json_response(['ok'=>false,'error'=>'INVALID_EVENT'],422);
        }
        $eventId=(int)$raw;
        // Order is explicit: first consented joins first, no preferential hidden filters.
        $q=$db->prepare('SELECT id,ticket_type_id,buyer_id,status,
            consent_at_utc,created_at_utc,offer_expires_at_utc
            FROM ticket_waitlist_entries WHERE event_id=?
            ORDER BY created_at_utc,id LIMIT 100');
        $q->execute([$eventId]);
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        $q=$db->prepare('SELECT status,COUNT(*) AS total
            FROM ticket_waitlist_entries WHERE event_id=? GROUP BY status');
        $q->execute([$eventId]);
        json_response(['ok'=>true,'rows'=>$rows,'counts'=>$q->fetchAll(PDO::FETCH_ASSOC),
            'order'=>'consent_timestamp_then_id','no_payment_or_ticket'=>true]);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        json_response(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405,['Allow'=>'GET, POST']);
    }
    brvtal_admin_require_csrf();
    if (!brvtalTicketingCan($role,'mail') || !brvtal_waitlist_offers_enabled()) {
        json_response(['ok'=>false,'error'=>'FORBIDDEN'],403);
    }
    $body=input_json();
    if (!is_array($body) || count($body)!==3 || ($body['action'] ?? '')!=='offer'
        || !is_int($body['event_id'] ?? null) || !is_int($body['ticket_type_id'] ?? null)) {
        json_response(['ok'=>false,'error'=>'INVALID_REQUEST'],422);
    }
    $offer=brvtal_waitlist_offer($db,$body['event_id'],$body['ticket_type_id']);
    json_response(['ok'=>true,'offered'=>$offer!==null,'offer'=>$offer]);
} catch (InvalidArgumentException) {
    json_response(['ok'=>false,'error'=>'INVALID_REQUEST'],422);
} catch (DomainException $e) {
    json_response(['ok'=>false,'error'=>$e->getMessage()==='WAITLIST_DISABLED'
        ? 'WAITLIST_DISABLED' : 'WAITLIST_UNAVAILABLE'],409);
} catch (Throwable) {
    json_response(['ok'=>false,'error'=>'WAITLIST_UNAVAILABLE'],503);
}
