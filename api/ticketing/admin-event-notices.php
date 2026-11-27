<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/admin_auth.php';
require_once __DIR__.'/../../config/ticketing_event_notice_audience.php';
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
if (!brvtal_admin_cookie_secure()) json_response(['ok'=>false,'error'=>'HTTPS_REQUIRED'],403);
if (getenv('BRVTAL_INTEGRATION_TESTS')!=='1') json_response(['ok'=>false,'error'=>'NOTICE_SANDBOX_ONLY'],423);
brvtal_admin_require();
if (($_SERVER['REQUEST_METHOD']??'GET')!=='GET') {
    json_response(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405,['Allow'=>'GET']);
}
try {
    $event=brvtalNoticeEventId($_GET);
    $db=db();
    $q=$db->prepare('SELECT staff_role FROM admins WHERE id=? AND is_active=1 LIMIT 1');
    $q->execute([(int)($_SESSION['admin_id']??0)]);
    $role=(string)($q->fetchColumn()?:'');
    $preview=brvtalNoticePreview($db,$event,$role);
    json_response(['ok'=>true,'data'=>$preview,'read_only'=>true]);
} catch (InvalidArgumentException) {
    json_response(['ok'=>false,'error'=>'INVALID_FILTER'],422);
} catch (DomainException $error) {
    $code=$error->getMessage()==='NOTICE_SANDBOX_ONLY'?'NOTICE_SANDBOX_ONLY':'FORBIDDEN';
    json_response(['ok'=>false,'error'=>$code],$code==='NOTICE_SANDBOX_ONLY'?423:403);
} catch (Throwable) {
    json_response(['ok'=>false,'error'=>'NOTICE_UNAVAILABLE'],503);
}
