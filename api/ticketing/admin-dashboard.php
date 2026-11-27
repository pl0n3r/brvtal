<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/admin_auth.php';
require_once __DIR__.'/../../config/ticketing_dashboard.php';
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
brvtal_admin_require();
if (strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='GET') {
    json_response(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405,['Allow'=>'GET']);
}
try {
    $db=db();
    $q=$db->prepare('SELECT staff_role FROM admins WHERE id=? AND is_active=1 LIMIT 1');
    $q->execute([(int)($_SESSION['admin_id']??0)]);
    $report=brvtalDashboardReport($db,$_GET,(string)($q->fetchColumn()?:''));
    json_response(['ok'=>true,'dashboard'=>$report]);
} catch (DomainException) {
    json_response(['ok'=>false,'error'=>'FORBIDDEN'],403);
} catch (InvalidArgumentException) {
    json_response(['ok'=>false,'error'=>'INVALID_FILTER'],422);
} catch (LengthException) {
    json_response(['ok'=>false,'error'=>'RANGE_TOO_LARGE'],422);
} catch (Throwable) {
    json_response(['ok'=>false,'error'=>'DASHBOARD_UNAVAILABLE'],503);
}
