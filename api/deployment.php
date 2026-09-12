<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/deployment.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    json_response(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'], 405);
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
json_response([
    'ok' => true,
    'data' => [
        'commit' => brvtal_deployment_sha(),
        'short_commit' => brvtal_deployment_short_sha(),
        'source' => brvtal_deployment_source(),
        'version' => BRVTAL_APP_VERSION,
        'environment' => BRVTAL_APP_ENV,
        'release_date' => BRVTAL_RELEASE_DATE,
    ],
]);
