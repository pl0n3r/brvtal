<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/ticketing_wompi_webhook.php';

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    require_once __DIR__ . '/../../config/bootstrap.php';
    header('Cache-Control: no-store');
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
        json_response(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405,['Allow'=>'POST']);
    }
    if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
        json_response(['ok'=>false,'error'=>'HTTPS_REQUIRED'],403);
    }
    if (!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')
        || (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 65536) {
        json_response(['ok'=>false,'error'=>'WEBHOOK_INVALID'],400);
    }
    $db = null;
    try {
        $settings = brvtal_wompi_hook_config();
        if (!in_array($settings['mode'], ['sandbox','production'], true)) throw new DomainException('WEBHOOK_DISABLED');
        $raw = file_get_contents('php://input', false, null, 0, 65537);
        $signed = brvtal_wompi_hook_verify((string)$raw,
            (string)($_SERVER['HTTP_X_EVENT_CHECKSUM'] ?? ''), $settings);
        // Wompi signs status/amount/id, not necessarily reference/currency.
        $provider = brvtal_wompi_hook_lookup($settings, $signed['id']);
        $db = db();
        $db->beginTransaction();
        brvtal_wompi_hook_apply($db, $signed, $provider);
        $db->commit();
        json_response(['ok'=>true],200);
    } catch (DomainException $e) {
        if ($db instanceof PDO && $db->inTransaction()) $db->rollBack();
        $code = $e->getMessage();
        // Only a controlled error code is logged, never body, emails or credentials.
        if (!preg_match('/^WEBHOOK_[A-Z_]+$/D',$code)) $code = 'WEBHOOK_INVALID';
        error_log('[WOMPI_WEBHOOK] ' . $code);
        json_response(['ok'=>false,'error'=>$code],
            $code === 'WEBHOOK_DISABLED' ? 503 : 403);
    } catch (Throwable) {
        if ($db instanceof PDO && $db->inTransaction()) $db->rollBack();
        error_log('[WOMPI_WEBHOOK] WEBHOOK_UNAVAILABLE');
        json_response(['ok'=>false,'error'=>'WEBHOOK_UNAVAILABLE'],503);
    }
}
