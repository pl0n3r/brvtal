<?php
declare(strict_types=1);

/**
 * POST /api/media-import-url.php  {"url": "https://…"}
 *
 * Authenticated, CSRF-protected proxy that downloads one image from a pasted
 * URL and returns its validated bytes. DISCADMIN then uploads those bytes with
 * the normal Media flow (api/media-library.php), which keeps deduplication,
 * variants and the activity log in a single place.
 */
require_once __DIR__ . '/../config/admin_auth.php';
require_once __DIR__ . '/../config/media.php';
require_once __DIR__ . '/../config/media_url_import.php';

brvtal_admin_require();
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    header('Allow: POST');
    brvtal_media_json_response(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED'], 405);
}
brvtal_admin_require_csrf();
brvtal_admin_release_session();

$input = json_decode((string)file_get_contents('php://input'), true);
$url = is_array($input) ? (string)($input['url'] ?? '') : '';

try {
    $image = brvtal_media_url_import_fetch($url);
} catch (BrvtalMediaUrlImportError $e) {
    brvtal_media_json_response([
        'ok' => false,
        'error' => $e->errorCode,
        'message' => brvtal_media_url_import_message($e->errorCode),
    ], $e->httpStatus);
}

header('Content-Type: ' . $image['mime']);
header('Content-Length: ' . strlen($image['bytes']));
header('Content-Disposition: inline; filename="importada.' . $image['extension'] . '"');
header("Content-Security-Policy: default-src 'none'; sandbox");
echo $image['bytes'];
