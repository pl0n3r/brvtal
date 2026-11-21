<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/buyer_auth.php';
require_once __DIR__ . '/../../config/ticketing_waitlist.php';

header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405,['Allow'=>'POST']);
}
if (getenv('BRVTAL_WAITLIST_TESTING') !== '1'
    && (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off')) {
    json_response(['ok'=>false,'error'=>'HTTPS_REQUIRED'],403);
}
try {
    $body = input_json();
    if (!is_array($body) || !is_string($body['action'] ?? null)) {
        json_response(['ok'=>false,'error'=>'INVALID_REQUEST'],422);
    }
    if ($body['action'] === 'unsubscribe' && count($body) === 2) {
        // Opaque link token: unsubscribe only, no account, seat or payment authority.
        brvtal_waitlist_unsubscribe(db(),(string)($body['token'] ?? ''));
        json_response(['ok'=>true,'status'=>'unsubscribe_processed'],200);
    }
    if ($body['action'] !== 'subscribe' || count($body) !== 5) {
        json_response(['ok'=>false,'error'=>'INVALID_REQUEST'],422);
    }
    brvtalBuyerSessionStart();
    $buyerId=(int)($_SESSION['buyer_id'] ?? 0);
    if ($buyerId<1) json_response(['ok'=>false,'error'=>'AUTH_REQUIRED'],401);
    if (!brvtalBuyerCsrfValid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
        json_response(['ok'=>false,'error'=>'CSRF'],403);
    }
    if (!is_int($body['event_id'] ?? null) || !is_int($body['ticket_type_id'] ?? null)
        || !is_string($body['consent'] ?? null) || !is_string($body['website'] ?? null)) {
        json_response(['ok'=>false,'error'=>'INVALID_REQUEST'],422);
    }
    $state=brvtal_waitlist_subscribe(db(),$body['event_id'],$body['ticket_type_id'],
        $buyerId,$body['consent'],$body['website']);
    json_response(['ok'=>true,'status'=>$state,'payment_created'=>false,'ticket_created'=>false],200);
} catch (InvalidArgumentException) {
    json_response(['ok'=>false,'error'=>'INVALID_REQUEST'],422);
} catch (DomainException $e) {
    $code=$e->getMessage();
    json_response(['ok'=>false,'error'=>in_array($code,
        ['WAITLIST_DISABLED','WAITLIST_RATE_LIMIT','WAITLIST_VERIFIED_BUYER_REQUIRED'],true)
        ? $code : 'WAITLIST_UNAVAILABLE'],409);
} catch (Throwable) {
    json_response(['ok'=>false,'error'=>'WAITLIST_UNAVAILABLE'],503);
}
