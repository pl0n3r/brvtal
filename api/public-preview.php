<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_auth.php';
require_once __DIR__ . '/../config/public_preview.php';

brvtal_admin_require();
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header('Cross-Origin-Resource-Policy: same-origin');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED']);
    exit;
}

brvtal_admin_require_csrf();
$length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($length > 131072) {
    http_response_code(413);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'PREVIEW_PAYLOAD_TOO_LARGE']);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode((string)$raw, true);
if (!is_array($input) || !is_array($input['payload'] ?? null)) {
    http_response_code(422);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'INVALID_PREVIEW_PAYLOAD']);
    exit;
}

try {
    $snapshot = brvtalPublicPreviewSnapshot(
        strtolower(trim((string)($input['type'] ?? ''))),
        $input['payload']
    );
    $stored = brvtalPublicPreviewStore($snapshot);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'data' => $stored], JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    if (function_exists('brvtal_log')) {
        brvtal_log('PUBLIC_PREVIEW_ERROR', 'Private preview creation failed', ['class' => $e::class]);
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'PREVIEW_INTERNAL_ERROR']);
}
