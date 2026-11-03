<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/deployment.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    json_response(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'], 405);
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
// Preserve exact release verification; never publish environment/cache internals.
$identity = brvtalDeploymentPublicData();
$publicIdentity = array_intersect_key(
    $identity,
    array_fill_keys(['version', 'commit', 'source', 'exact'], true)
);
json_response([
    'ok' => true,
    'data' => $publicIdentity,
]);
