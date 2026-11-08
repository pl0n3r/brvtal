<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/instagram_profile_photo.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}
$username = strtolower(trim((string)($_GET['username'] ?? '')));
if (!preg_match('/^[a-z0-9._]{1,30}$/D', $username) || !brvtalIgConfiguredFor($username)) {
    header('Cache-Control: no-store');
    http_response_code(404);
    exit;
}
$cached = brvtalIgCacheRefresh($username);
if ($cached === null) {
    header('Cache-Control: no-store');
    http_response_code(404);
    exit;
}
$etag = '"' . $cached['meta']['sha256'] . '"';
header('ETag: ' . $etag);
header('Cache-Control: public, max-age=3600, stale-while-revalidate=86400');
header('Content-Type: ' . $cached['meta']['mime']);
header('Content-Length: ' . (string)filesize($cached['path']));
if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}
readfile($cached['path']);
