<?php
declare(strict_types=1);

require_once __DIR__.'/../config/bootstrap.php';
require_once __DIR__.'/../config/public_abuse_guard.php';
require_once __DIR__.'/../config/form_entries.php';

header('Cache-Control: no-store');
$method = (string)($_SERVER['REQUEST_METHOD'] ?? '');
if (!in_array($method, ['GET','POST'], true)) {
    header('Allow: GET, POST');
    json_response(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405);
}
$origin = rtrim((string)($config['app']['base_url'] ?? ''),'/');
$sentOrigin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
$site = (string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off'
    || !preg_match('~\Ahttps://[a-z0-9.-]+(?::[0-9]{1,5})?\z~D',$origin)
    || $origin !== 'https://'.strtolower((string)($_SERVER['HTTP_HOST'] ?? ''))
    || ($site !== '' && $site !== 'same-origin')
    || ($sentOrigin !== $origin && !($method === 'GET' && $sentOrigin === '' && $site === 'same-origin'))) {
    json_response(['ok'=>false,'error'=>'ORIGIN_REQUIRED'],403);
}
if (!brvtalFormsCaptureEnabled($config)) json_response(['ok'=>false,'error'=>'FORM_UNAVAILABLE'],503);
$id = $_GET['public_id'] ?? null;
$v = $_GET['version'] ?? null;
$key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;
$validId = is_string($id) && preg_match('/\A[a-f0-9]{32}\z/D',$id);
try {
    $rate = brvtalFormsRate($config,$validId ? $id : 'invalid');
    if (!$rate['allowed']) {
        json_response(['ok'=>false,'error'=>$rate['error']],$rate['error']==='RATE_LIMITED'?429:503,
            ['Retry-After'=>(string)$rate['retry_after']]);
    }
    if (!$validId || !is_string($v) || !preg_match('/\A[1-9][0-9]{0,9}\z/D',$v) || (int)$v>4294967295
        || !is_string($key) || !preg_match('/\A[a-f0-9]{32}\z/D',$key)) {
        json_response(['ok'=>false,'error'=>'FORM_REQUEST_INVALID'],422);
    }
    $version = (int)$v;
    $context = $id.'|'.$version.'|'.$key;
    if ($method === 'GET') {
        brvtalFormsPublished(db(),$config,$id,$version);
        json_response(['ok'=>true,'data'=>brvtalFormsChallenge($config,$context)]);
    }
    if (strtolower(trim(explode(';',(string)($_SERVER['CONTENT_TYPE'] ?? ''))[0])) !== 'application/json') {
        json_response(['ok'=>false,'error'=>'FORM_CONTENT_TYPE'],415);
    }
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0)>65536) json_response(['ok'=>false,'error'=>'PAYLOAD_TOO_LARGE'],413);
    $raw = file_get_contents('php://input',false,null,0,65537);
    if (!is_string($raw) || strlen($raw)>65536) json_response(['ok'=>false,'error'=>'PAYLOAD_TOO_LARGE'],413);
    if (($_SERVER['HTTP_X_FORM_HONEYPOT'] ?? '') !== ''
        || !brvtalFormsVerify($config,$context,$_SERVER['HTTP_X_FORM_CAPTCHA_TOKEN'] ?? null,
            $_SERVER['HTTP_X_FORM_CAPTCHA_ANSWER'] ?? null)) json_response(['ok'=>false,'error'=>'FORM_CHALLENGE_INVALID'],422);
    brvtalFormsSubmit(db(),$config,$id,$version,$key,$raw);
    json_response(['ok'=>true,'message'=>'FORM_RECEIVED']);
} catch (DomainException $e) {
    json_response(['ok'=>false,'error'=>$e->getMessage()==='FORM_VERSION_CONFLICT'?'FORM_VERSION_CONFLICT':'FORM_INTENT_CONFLICT'],409);
} catch (InvalidArgumentException) {
    json_response(['ok'=>false,'error'=>'FORM_PAYLOAD_INVALID'],422);
} catch (Throwable) {
    json_response(['ok'=>false,'error'=>'FORM_UNAVAILABLE'],503);
}
