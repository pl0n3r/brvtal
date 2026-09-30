<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_auth.php';
require_once __DIR__ . '/../config/admin_development_signals.php';

brvtal_admin_require();

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    json_response(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'], 405, ['Allow'=>'GET','Cache-Control'=>'no-store']);
}

$data = brvtalAdminDevelopmentSignals();
json_response(['ok'=>true,'data'=>$data], 200, ['Cache-Control'=>'no-store']);
