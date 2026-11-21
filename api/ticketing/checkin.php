<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/admin_auth.php';
require_once __DIR__ . '/../../config/ticketing_staff_permissions.php';
require_once __DIR__ . '/../../config/ticketing_checkin.php';
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
brvtal_admin_require();
if (strtoupper((string)($_SERVER['REQUEST_METHOD']??''))!=='POST') {
    json_response(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405,['Allow'=>'POST']);
}
if (empty($_SERVER['HTTPS'])||$_SERVER['HTTPS']==='off') {
    json_response(['ok'=>false,'error'=>'HTTPS_REQUIRED'],403);
}
brvtal_admin_require_csrf();
$raw=file_get_contents('php://input',false,null,0,2049);
if (!is_string($raw)||strlen($raw)>2048
    ||!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/json')) {
    json_response(['ok'=>false,'error'=>'CHECKIN_INVALID_INPUT'],422);
}
try {
    $body=json_decode($raw,true,12,JSON_THROW_ON_ERROR);
    if (!is_array($body)) throw new InvalidArgumentException();
    $data=brvtalTicketCheckinInput($body);
} catch (Throwable) {
    json_response(['ok'=>false,'error'=>'CHECKIN_INVALID_INPUT'],422);
}
try {
    $pdo=db();
    $operator=(int)($_SESSION['admin_id']??0);
    $stmt=$pdo->prepare('SELECT staff_role FROM admins WHERE id=? AND is_active=1 LIMIT 1');
    $stmt->execute([$operator]);
    if (!brvtalTicketingCan((string)($stmt->fetchColumn()?:''),'door')) {
        json_response(['ok'=>false,'error'=>'FORBIDDEN'],403);
    }
    brvtalTicketCheckinRate($operator,(string)($_SERVER['REMOTE_ADDR']??''));
    $result=brvtalTicketCheckinConsume($pdo,$data['event_id'],$operator,$data['device_id'],$data['qr']);
    json_response(['ok'=>$result['ok'],'reason'=>$result['reason']],$result['ok']?200:409);
} catch (DomainException $e) {
    json_response(['ok'=>false,'error'=>$e->getMessage()==='CHECKIN_RATE_LIMITED'
        ?'RATE_LIMITED':'CHECKIN_REJECTED'],$e->getMessage()==='CHECKIN_RATE_LIMITED'?429:422);
} catch (Throwable) {
    // No SQL, token, payment detail or buyer identity is ever returned.
    json_response(['ok'=>false,'error'=>'CHECKIN_UNAVAILABLE'],503);
}
