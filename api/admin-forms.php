<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_auth.php';
require_once __DIR__ . '/../config/forms_admin.php';

brvtal_admin_require();
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
$method = $_SERVER['REQUEST_METHOD'] ?? '';
try {
    $pdo = db();
    brvtalFormsAdminRequire($pdo, (int)($_SESSION['admin_id'] ?? 0));
    if (!in_array($method, ['GET', 'POST'], true)) {
        json_response(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED'], 405, ['Allow' => 'GET, POST']);
    }
    $settings = $GLOBALS['config'] ?? [];
    if ($method === 'POST') {
        brvtal_admin_require_csrf((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        if ($_GET || strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
            throw new InvalidArgumentException('INVALID_ACTION');
        }
        $raw = file_get_contents('php://input', false, null, 0, 8193);
        if (!is_string($raw) || strlen($raw) > 8192) throw new InvalidArgumentException('INVALID_ACTION');
        $input = json_decode($raw, true, 16);
        if (!is_array($input)) throw new InvalidArgumentException('INVALID_ACTION');
        brvtalFormsManagementInput($input);
        if (!is_array($settings) || !brvtalFormsAdminEnabled($settings)
            || ($settings['forms']['admin_manage_enabled'] ?? null) !== true) {
            json_response(['ok' => false, 'error' => 'FORMS_ADMIN_UNAVAILABLE'], 503);
        }
        json_response(['ok' => true, 'data' => brvtalFormsAdminAction($pdo, (int)$_SESSION['admin_id'], $input)]);
    }
    if (!is_array($settings) || !brvtalFormsAdminEnabled($settings)) {
        json_response(['ok' => false, 'error' => 'FORMS_ADMIN_UNAVAILABLE'], 503);
    }
    json_response(['ok' => true, 'data' => brvtalFormsAdminRead($pdo, $_GET)]);
} catch (InvalidArgumentException) {
    json_response(['ok' => false, 'error' => $method === 'POST' ? 'INVALID_ACTION' : 'INVALID_FILTER'], 422);
} catch (DomainException $e) {
    [$error, $status] = match ($e->getMessage()) {
        'FORBIDDEN' => ['FORBIDDEN', 403],
        'STALE_REVISION' => ['STALE_REVISION', 409],
        'INVALID_TRANSITION' => ['INVALID_TRANSITION', 409],
        'ENTRY_UNAVAILABLE' => ['ENTRY_UNAVAILABLE', 409],
        default => ['ENTRY_NOT_FOUND', 404],
    };
    json_response(['ok' => false, 'error' => $error], $status);
} catch (Throwable) {
    // No query, response data, cursor or exception text in logs.
    json_response(['ok' => false, 'error' => 'FORMS_ADMIN_UNAVAILABLE'], 503);
}
