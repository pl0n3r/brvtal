<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/admin_auth.php';
require_once __DIR__ . '/../../config/ticketing_recovery.php';
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
brvtal_admin_require();
try {
    $db = db();
    $actor = (int)($_SESSION['admin_id'] ?? 0);
    $stmt = $db->prepare('SELECT staff_role FROM admins WHERE id=? AND is_active=1');
    $stmt->execute([$actor]);
    $role = (string)($stmt->fetchColumn() ?: '');
    if ($actor<1 || !brvtalTicketingCan($role,'mail')
        || !brvtalTicketingCan($role,'buyers')) {
        json_response(['ok'=>false,'error'=>'FORBIDDEN'],403);
    }
    if (!brvtalRecoverySynthetic($db)) {
        json_response(['ok'=>false,'error'=>'SYNTHETIC_ONLY'],423);
    }
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'GET') {
        json_response(['ok'=>true,'data'=>brvtalRecoveryMetrics($db),
            'mode'=>'synthetic','mail_sent'=>false]);
    }
    if ($method !== 'POST') {
        json_response(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405,['Allow'=>'GET, POST']);
    }
    brvtal_admin_require_csrf();
    if (!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')
        || (int)($_SERVER['CONTENT_LENGTH'] ?? 0)>2048) {
        json_response(['ok'=>false,'error'=>'INVALID_CONTENT_TYPE'],415);
    }
    $payload = file_get_contents('php://input',false,null,0,2049);
    if (!is_string($payload) || strlen($payload)>2048) {
        json_response(['ok'=>false,'error'=>'INVALID_PAYLOAD'],413);
    }
    $input = json_decode($payload,true,8,JSON_THROW_ON_ERROR);
    if (!is_array($input) || array_is_list($input)) {
        json_response(['ok'=>false,'error'=>'INVALID_PAYLOAD'],422);
    }
    $preview = brvtalRecoveryPlan($db,$actor,$role,$input);
    json_response(['ok'=>true,'data'=>$preview,'mail_sent'=>false]);
} catch (InvalidArgumentException | JsonException) {
    json_response(['ok'=>false,'error'=>'INVALID_PAYLOAD'],422);
} catch (DomainException $e) {
    $code=$e->getMessage();
    json_response(['ok'=>false,'error'=>$code],$code==='RECOVERY_KEY_CONFLICT'?409:403);
} catch (Throwable) {
    json_response(['ok'=>false,'error'=>'RECOVERY_UNAVAILABLE'],503);
}
