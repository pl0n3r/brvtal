<?php
declare(strict_types=1);

/**
 * #1887: DISCADMIN-only mail template administration. No delivery endpoints.
 * GET is read-only; every POST (including preview) requires session + CSRF.
 */
require_once __DIR__ . '/../config/admin_auth.php';
require_once __DIR__ . '/../config/mail_template_admin_api.php';

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
brvtal_admin_require();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    json_response(['ok'=>false, 'error'=>'METHOD_NOT_ALLOWED'], 405, ['Allow'=>'GET, POST']);
}
if ($method === 'POST') {
    brvtal_admin_require_csrf();
}
try {
    $pdo = db();
    $actorId = (int)($_SESSION['admin_id'] ?? 0);
    $roleQuery = $pdo->prepare('SELECT staff_role FROM admins WHERE id=? AND is_active=1 LIMIT 1');
    $roleQuery->execute([$actorId]);
    $role = (string)($roleQuery->fetchColumn() ?: '');
    // All branch decisions below are enforced server-side, including preview.
    brvtalMailAdminRequire($role, false);
    if ($method === 'GET') {
        $request = brvtalMailAdminParseGet($_GET);
    } else {
        if (!preg_match('~^application/json(?:\s*;|\s*$)~i', (string)($_SERVER['CONTENT_TYPE'] ?? ''))) {
            json_response(['ok'=>false, 'error'=>'INVALID_CONTENT_TYPE'], 415);
        }
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 12288) {
            json_response(['ok'=>false, 'error'=>'PAYLOAD_TOO_LARGE'], 413);
        }
        $raw = file_get_contents('php://input', false, null, 0, 12289);
        if (!is_string($raw) || strlen($raw) > 12288) {
            json_response(['ok'=>false, 'error'=>'PAYLOAD_TOO_LARGE'], 413);
        }
        $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded)) {
            json_response(['ok'=>false, 'error'=>'INVALID_PAYLOAD'], 422);
        }
        $request = brvtalMailAdminParsePost($decoded);
    }
    $result = brvtalMailAdminDispatch($pdo, $role, $actorId, $method, $request);
    json_response(['ok'=>true, 'data'=>$result, 'mail_sent'=>false]);
} catch (InvalidArgumentException | JsonException) {
    json_response(['ok'=>false, 'error'=>'INVALID_PAYLOAD'], 422);
} catch (DomainException $error) {
    $code = $error->getMessage();
    $status = match ($code) {
        'FORBIDDEN' => 403,
        'MAIL_TEMPLATE_CONFLICT' => 409,
        'MAIL_TEMPLATE_NOT_FOUND', 'MAIL_TEMPLATE_VERSION_UNKNOWN' => 404,
        default => 422,
    };
    // Only predefined, non-sensitive codes are allowed in responses.
    $safe = in_array($code, ['FORBIDDEN', 'MAIL_TEMPLATE_CONFLICT',
        'MAIL_TEMPLATE_NOT_FOUND', 'MAIL_TEMPLATE_VERSION_UNKNOWN'], true)
        ? $code : 'INVALID_PAYLOAD';
    json_response(['ok'=>false, 'error'=>$safe], $status);
} catch (Throwable) {
    // Never log request bodies, document copy, encrypted data or exception text.
    json_response(['ok'=>false, 'error'=>'MAIL_TEMPLATES_UNAVAILABLE'], 503);
}
