<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/public_seo.php';

$type = trim((string)($_GET['type'] ?? ''));
$slug = trim((string)($_GET['slug'] ?? ''));
$entity = null;
if ($type !== '' || $slug !== '') {
    $entity = brvtal_public_seo_entity(db(), $type, $slug);
    if (!$entity) {
        http_response_code(404);
        header('X-Robots-Tag: noindex, follow');
    }
}

$seo = brvtal_public_seo_document($entity, brvtal_public_base_url($config));
$html = (string)file_get_contents(__DIR__ . '/index.html');
$html = preg_replace('/<title>.*?<\/title>/s', '<title>' . htmlspecialchars($seo['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</title>', $html, 1) ?? $html;
$html = preg_replace('/<meta name="description" content="[^"]*">/', '<meta name="description" content="' . htmlspecialchars($seo['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">', $html, 1) ?? $html;
$html = str_replace('</head>', '  ' . brvtal_public_seo_tags($seo) . "\n</head>", $html);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=60, stale-while-revalidate=300');
echo $html;
