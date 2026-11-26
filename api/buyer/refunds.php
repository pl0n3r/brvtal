<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/bootstrap.php';
require_once __DIR__.'/../../config/buyer_auth.php';
require_once __DIR__.'/../../config/buyer_account.php';
require_once __DIR__.'/../../config/ticketing_refunds.php';
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
try {
    brvtalBuyerSessionStart();
    $buyer=(int)($_SESSION['buyer_id']??0);
    if ($buyer<1) json_response(['ok'=>false,'error'=>'AUTH_REQUIRED'],401);
    $db=db();
    if (!brvtalBuyerAccountAuthorized($db,$buyer)) json_response(['ok'=>false,'error'=>'AUTH_REQUIRED'],401);
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    // GET and POST both re-evaluate the authenticated checkout-order scope
    // in the refund service; no buyer-supplied order grant is accepted.
    if($method==='GET') json_response(['ok'=>true,'data'=>brvtalRefundBuyerList($db,$buyer)]);
    if($method!=='POST') json_response(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405,['Allow'=>'GET, POST']);
    if(!brvtalBuyerCsrfValid($_SERVER['HTTP_X_CSRF_TOKEN']??null)) json_response(['ok'=>false,'error'=>'CSRF_INVALID'],403);
    $input=input_json();
    if(!is_array($input)||array_is_list($input)) json_response(['ok'=>false,'error'=>'REFUND_INVALID'],422);
    json_response(['ok'=>true,'data'=>brvtalRefundRequest($db,$buyer,$input)],201);
} catch(InvalidArgumentException) {
    json_response(['ok'=>false,'error'=>'REFUND_INVALID'],422);
} catch(DomainException $e) {
    $error=$e->getMessage();
    $status=$error==='REFUND_SANDBOX_ONLY'?423:($error==='IDEMPOTENCY_CONFLICT'?409:403);
    json_response(['ok'=>false,'error'=>$error],$status);
} catch(Throwable) {
    json_response(['ok'=>false,'error'=>'REFUNDS_UNAVAILABLE'],503);
}
