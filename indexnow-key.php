<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/indexnow.php';

$setting = brvtalIndexNowSetting(db());
$requestPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
$validRoute = $requestPath === $setting['key_location']
    || (PHP_SAPI === 'cli-server' && $requestPath === '/indexnow-key.php');
if (!$validRoute) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "NOT_FOUND\n";
    exit;
}
if (!$setting['enabled'] || $setting['key'] === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "INDEXNOW_NOT_CONFIGURED\n";
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo $setting['key'];
