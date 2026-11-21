<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/buyer_auth.php';
require_once __DIR__ . '/../../config/buyer_account.php';

header('Cache-Control: private, no-store, max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'; frame-ancestors \'none\'');
$method=strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method,['GET','POST'],true)) {
    json_response(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405,['Allow'=>'GET, POST']);
}
try {
    brvtalBuyerSessionStart();
    $buyer=(int)($_SESSION['buyer_id'] ?? 0);
    if ($buyer<1) json_response(['ok'=>false,'error'=>'AUTH_REQUIRED'],401);
    $db=db();
    if (!brvtalBuyerAccountAuthorized($db,$buyer)) {
        json_response(['ok'=>false,'error'=>'AUTH_REQUIRED'],401);
    }
    if ($method==='GET') {
        $filters=brvtalBuyerAccountFilters($_GET);
        json_response(['ok'=>true,'data'=>brvtalBuyerAccountOrders($db,$buyer,$filters)]);
    }
    if (!brvtalBuyerCsrfValid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
        json_response(['ok'=>false,'error'=>'CSRF_INVALID'],403);
    }
    $body=input_json();
    if (array_diff(array_keys($body),['action','ticket_id','token'])
        || ($body['action'] ?? null)!=='qr'
        || !is_string($body['ticket_id'] ?? null)
        || !is_string($body['token'] ?? null)) {
        json_response(['ok'=>false,'error'=>'INVALID_REQUEST'],422);
    }
    $download=brvtalBuyerAccountQr($db,$buyer,$body['ticket_id'],$body['token']);
    json_response(['ok'=>true,'qr'=>$download['content'],'filename'=>$download['filename']]);
} catch (InvalidArgumentException) {
    json_response(['ok'=>false,'error'=>'INVALID_FILTER'],422);
} catch (DomainException) {
    // Uniform error for another buyer, revoked, used, refunded, expired or invalid token.
    json_response(['ok'=>false,'error'=>'TICKET_UNAVAILABLE'],404);
} catch (Throwable) {
    json_response(['ok'=>false,'error'=>'ACCOUNT_UNAVAILABLE'],503);
}
