<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/public-media-delivery.php';

function brvtal_public_image_delivery_json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=60, stale-while-revalidate=300');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        header('Allow: GET');
        brvtal_public_image_delivery_json(['error' => 'METHOD_NOT_ALLOWED'], 405);
    }

    $rows = db()->query(
        "SELECT file_path
         FROM media
         WHERE status='published' AND type='image'
         ORDER BY id DESC"
    )->fetchAll();

    $paths = array_map(static fn(array $row): string => (string)($row['file_path'] ?? ''), $rows);
    $map = brvtal_public_media_delivery_map($paths);
    $etag = '"' . sha1(json_encode($map, JSON_UNESCAPED_SLASHES)) . '"';
    header('ETag: ' . $etag);

    if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        exit;
    }

    brvtal_public_image_delivery_json($map);
} catch (Throwable $e) {
    if (function_exists('brvtal_log')) {
        brvtal_log('PUBLIC_IMAGE_DELIVERY_ERROR', 'Public image delivery failure', [
            'class' => get_class($e),
            'message' => $e->getMessage(),
        ]);
    }

    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'INTERNAL_ERROR'], JSON_UNESCAPED_SLASHES);
    exit;
}
