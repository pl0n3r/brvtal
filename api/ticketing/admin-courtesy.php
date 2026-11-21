<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/admin_auth.php';
require_once __DIR__ . '/../../config/ticketing_courtesy.php';
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
brvtal_admin_require();
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') {
    json_response(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405,['Allow'=>'POST']);
}
if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS']==='off') {
    json_response(['ok'=>false,'error'=>'HTTPS_REQUIRED'],403);
}
brvtal_admin_require_csrf();
if (!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/json')
    || (int)($_SERVER['CONTENT_LENGTH']??0)>2048) {
    json_response(['ok'=>false,'error'=>'INVALID_INPUT'],422);
}
try {
    $raw=file_get_contents('php://input',false,null,0,2049);
    if (!is_string($raw) || strlen($raw)>2048) throw new InvalidArgumentException();
    $payload=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
    if (!is_array($payload) || array_is_list($payload)) throw new InvalidArgumentException();
    $data=brvtalCourtesyValidate($payload);
    $db=db();
    $actor=(int)($_SESSION['admin_id']??0);
    $q=$db->prepare('SELECT staff_role FROM admins WHERE id=? AND is_active=1 LIMIT 1');
    $q->execute([$actor]); $role=(string)($q->fetchColumn()?:'');
    $result=$data['action']==='issue'
        ?brvtalCourtesyIssue($db,$actor,$role,$data)
        :brvtalCourtesyAction($db,$actor,$role,$data['action'],$data['invite_id']);
    json_response(['ok'=>true,'data'=>$result]);
} catch (InvalidArgumentException|JsonException) {
    json_response(['ok'=>false,'error'=>'INVALID_INPUT'],422);
} catch (DomainException $error) {
    $code=$error->getMessage();
    $status=match($code) {
        'FORBIDDEN'=>403,'COURTESY_DISABLED'=>423,
        'INVITATION_NOT_FOUND'=>404,
        'DUPLICATE_INVITATION','IDEMPOTENCY_CONFLICT','COURTESY_CAPACITY','INVITATION_NOT_ACTIVE'=>409,
        default=>422,
    };
    json_response(['ok'=>false,'error'=>$code],$status);
} catch (Throwable) {
    json_response(['ok'=>false,'error'=>'COURTESY_UNAVAILABLE'],503);
}
