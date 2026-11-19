<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/ticketing_wompi_client.php';

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    require_once __DIR__ . '/../../config/bootstrap.php';
    require_once __DIR__ . '/../../config/buyer_auth.php';
    header('Cache-Control: no-store');
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
        json_response(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405,['Allow'=>'POST']);
    }
    if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
        json_response(['ok'=>false,'error'=>'HTTPS_REQUIRED'],403);
    }
    try {
        $settings = brvtal_ticket_wompi_config();
    } catch (DomainException) {
        json_response(['ok'=>false,'error'=>'CHECKOUT_DISABLED'],503);
    }
    brvtalBuyerSessionStart();
    $buyerId = (int)($_SESSION['buyer_id'] ?? 0);
    if ($buyerId < 1) json_response(['ok'=>false,'error'=>'AUTH_REQUIRED'],401);
    if (!brvtalBuyerCsrfValid((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
        json_response(['ok'=>false,'error'=>'CSRF'],419);
    }
    // Strict JSON whitelist: no buyer-supplied amount, return URL, card data or status.
    $input = input_json();
    if (array_diff(array_keys($input), ['order_id','idempotency_key','method'])
        || !is_int($input['order_id'] ?? null)
        || !is_string($input['idempotency_key'] ?? null)
        || !is_string($input['method'] ?? null)) {
        json_response(['ok'=>false,'error'=>'INVALID_REQUEST'],422);
    }
    $db = null;
    try {
        $db = db();
        $db->beginTransaction();
        $payload = brvtal_ticket_wompi_prepare(
            $db, $buyerId, $input['order_id'], $input['idempotency_key'], $input['method'], $settings
        );
        $db->commit();
        json_response(['ok'=>true,'checkout'=>$payload],200);
    } catch (InvalidArgumentException) {
        if ($db instanceof PDO && $db->inTransaction()) $db->rollBack();
        json_response(['ok'=>false,'error'=>'INVALID_REQUEST'],422);
    } catch (DomainException $e) {
        if ($db instanceof PDO && $db->inTransaction()) $db->rollBack();
        $code = $e->getMessage();
        $status = $code === 'WOMPI_ORDER_NOT_FOUND' ? 404 : 409;
        json_response(['ok'=>false,'error'=>$code],$status);
    } catch (Throwable) {
        if ($db instanceof PDO && $db->inTransaction()) $db->rollBack();
        json_response(['ok'=>false,'error'=>'CHECKOUT_UNAVAILABLE'],503);
    }
}
