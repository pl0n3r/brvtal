<?php
declare(strict_types=1);

// T05 is an internal transaction library. The future authenticated checkout
// owner (T07) must explicitly bind buyer ownership and CSRF before exposing a
// write endpoint. Never accept a public order_id as authorization.
require_once __DIR__ . '/../../config/ticketing_inventory.php';

if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string)$_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['error'=>'INVENTORY_ENDPOINT_NOT_ENABLED'], JSON_THROW_ON_ERROR);
    exit;
}
