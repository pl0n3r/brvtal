<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/admin_auth.php';
require_once __DIR__ . '/../../config/ticketing_admin_courtesy.php';

header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
if (!brvtal_admin_cookie_secure()) {
    json_response(['ok'=>false,'error'=>'HTTPS_REQUIRED'], 403);
}
brvtal_admin_require();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    json_response(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'], 405, ['Allow'=>'GET']);
}
try {
    $data = brvtalCourtesyAdminRead(db(), (int)($_SESSION['admin_id'] ?? 0), $_GET);
    json_response(['ok'=>true,'data'=>$data,'read_only'=>true]);
} catch (InvalidArgumentException) {
    json_response(['ok'=>false,'error'=>'INVALID_FILTER'], 422);
} catch (DomainException) {
    json_response(['ok'=>false,'error'=>'FORBIDDEN'], 403);
} catch (Throwable) {
    json_response(['ok'=>false,'error'=>'COURTESY_UNAVAILABLE'], 503);
}
