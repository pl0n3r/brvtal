<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_auth.php';
require_once __DIR__ . '/../config/integration_secrets_store.php';
require_once __DIR__ . '/../config/ticketing_staff_permissions.php';

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
brvtal_admin_require();

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET','POST','DELETE'], true)) {
    json_response(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'], 405, ['Allow'=>'GET, POST, DELETE']);
}

try {
    $pdo = db();
    $actorId = (int)($_SESSION['admin_id'] ?? 0);
    $actor = $pdo->prepare('SELECT staff_role FROM admins WHERE id=? AND is_active=1 LIMIT 1');
    $actor->execute([$actorId]);
    if (!brvtalTicketingCan((string)($actor->fetchColumn() ?: ''), 'settings')) {
        json_response(['ok'=>false,'error'=>'FORBIDDEN'], 403);
    }
    $writeEnabled = brvtalIntegrationSecretWriteEnabled();
    $masterAvailable = brvtalIntegrationSecretMasterKey() !== null;
    if ($method === 'GET') {
        json_response(['ok'=>true,'write_enabled'=>$writeEnabled && $masterAvailable,
            'master_key_configured'=>$masterAvailable, 'items'=>brvtalIntegrationSecretStatus($pdo)]);
    }

    // Normalize rejected write CSRF to 403 for the documented API contract.
    $providedCsrf = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $expectedCsrf = brvtal_admin_csrf_token();
    if ($providedCsrf === '' || $expectedCsrf === '' || !hash_equals($expectedCsrf, $providedCsrf)) {
        json_response(['ok'=>false,'error'=>'CSRF'], 403);
    }
    brvtal_admin_require_csrf($providedCsrf);
    if (!$writeEnabled || !$masterAvailable) {
        json_response(['ok'=>false,'error'=>'SECRET_WRITE_DISABLED'], 403);
    }
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192
        || !str_contains(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
        json_response(['ok'=>false,'error'=>'INVALID_CONTENT_TYPE'], 415);
    }
    $body = file_get_contents('php://input', false, null, 0, 8193);
    if (!is_string($body) || strlen($body) > 8192) {
        json_response(['ok'=>false,'error'=>'PAYLOAD_TOO_LARGE'], 413);
    }
    $input = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($input) || array_is_list($input) || !is_string($input['key'] ?? null)) {
        json_response(['ok'=>false,'error'=>'INVALID_PAYLOAD'], 422);
    }
    $key = $input['key'];
    $value = $method === 'POST' ? ($input['value'] ?? null) : null;
    brvtalIntegrationSecretMutate($pdo, $method, $key, is_string($value) ? $value : null, $actorId);
    json_response(['ok'=>true,'items'=>brvtalIntegrationSecretStatus($pdo)]);
} catch (InvalidArgumentException | JsonException) {
    json_response(['ok'=>false,'error'=>'SECRET_FORMAT_INVALID'], 422);
} catch (DomainException) {
    json_response(['ok'=>false,'error'=>'SECRET_WRITE_DISABLED'], 403);
} catch (Throwable $e) {
    // Neither exception messages nor request bodies may reach telemetry.
    brvtal_log('INTEGRATION_SECRET_ERROR', 'Safe integration secret operation failed',
        ['class'=>$e::class]);
    json_response(['ok'=>false,'error'=>'SECRET_STORE_UNAVAILABLE'], 503);
}
