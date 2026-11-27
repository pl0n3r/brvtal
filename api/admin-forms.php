<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_auth.php';
require_once __DIR__ . '/../config/forms_admin.php';

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
brvtal_admin_require();
try {
    $pdo = db();
    brvtalFormsAdminRequire($pdo, (int)($_SESSION['admin_id'] ?? 0));
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        json_response(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED'], 405, ['Allow' => 'GET']);
    }
    $settings = $GLOBALS['config'] ?? [];
    if (!is_array($settings) || !brvtalFormsAdminEnabled($settings)) {
        json_response(['ok' => false, 'error' => 'FORMS_ADMIN_UNAVAILABLE'], 503);
    }
    json_response(['ok' => true, 'data' => brvtalFormsAdminRead($pdo, $_GET)]);
} catch (InvalidArgumentException) {
    json_response(['ok' => false, 'error' => 'INVALID_FILTER'], 422);
} catch (DomainException $e) {
    $forbidden = $e->getMessage() === 'FORBIDDEN';
    json_response(['ok' => false, 'error' => $forbidden?'FORBIDDEN':'ENTRY_NOT_FOUND'], $forbidden?403:404);
} catch (Throwable) {
    // No query, response data, cursor or exception text in logs.
    json_response(['ok' => false, 'error' => 'FORMS_ADMIN_UNAVAILABLE'], 503);
}
