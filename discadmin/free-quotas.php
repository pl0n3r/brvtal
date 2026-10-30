<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_auth.php';
brvtal_admin_require();
require_once __DIR__ . '/../config/free_quotas.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

echo json_encode(
    ['ok' => true, 'data' => brvtalFreeQuotaSnapshot()],
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
