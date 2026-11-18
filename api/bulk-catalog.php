<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_auth.php';
require_once __DIR__ . '/bulk-actions-lib.php';

brvtal_admin_require();

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    json_response(
        ['ok'=>false, 'error'=>'METHOD_NOT_ALLOWED'],
        405,
        ['Allow'=>'GET', 'Cache-Control'=>'no-store']
    );
}

try {
    $query = brvtalBulkCatalogNormalizeQuery($_GET);
    $data = brvtalBulkCatalogFetch(db(), $query);
    json_response(
        ['ok'=>true, 'data'=>$data],
        200,
        ['Cache-Control'=>'no-store']
    );
} catch (InvalidArgumentException $e) {
    json_response(
        ['ok'=>false, 'error'=>$e->getMessage()],
        422,
        ['Cache-Control'=>'no-store']
    );
} catch (Throwable $e) {
    if (function_exists('brvtal_log')) {
        brvtal_log('ADMIN_BULK_CATALOG_ERROR', 'Bulk catalog query failed', [
            'class'=>$e::class,
            'resource'=>is_string($_GET['resource'] ?? null) ? (string)$_GET['resource'] : '',
        ]);
    }
    json_response(
        ['ok'=>false, 'error'=>'BULK_CATALOG_UNAVAILABLE'],
        503,
        ['Cache-Control'=>'no-store']
    );
}
