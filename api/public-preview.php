<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_auth.php';
require_once __DIR__ . '/../config/public_preview.php';

brvtal_admin_require();
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

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
    $snapshot = brvtal_public_preview_snapshot(
        strtolower(trim((string)($input['type'] ?? ''))),
        $input['payload']
    );
    $stored = brvtal_public_preview_store($snapshot);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'data' => $stored], JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
