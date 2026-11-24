<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/public_order_status.php';

// #1958: purchase confirmation page data, by opaque order token, without a session.
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    json_response(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED'], 405, ['Allow' => 'GET']);
}
$token = (string)($_GET['t'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/D', $token)) {
    json_response(['ok' => false, 'error' => 'ORDER_NOT_FOUND'], 404);
}
try {
    $order = brvtal_public_order_status(db(), $token);
} catch (Throwable) {
    json_response(['ok' => false, 'error' => 'ORDER_UNAVAILABLE'], 503);
}
if ($order === null) {
    json_response(['ok' => false, 'error' => 'ORDER_NOT_FOUND'], 404);
}
json_response(['ok' => true, 'order' => $order], 200);
