<?php
declare(strict_types=1);

ob_start();
require __DIR__ . '/index-core.php';
$html = (string)ob_get_clean();

$build = defined('BRVTAL_APP_BUILD') ? rawurlencode((string)BRVTAL_APP_BUILD) : '';
$suffix = $build !== '' ? '?v=' . $build : '';
$enhancements = '<script src="/discadmin/content-health.js' . $suffix . '"></script>'
    . '<script src="/discadmin/seo-metadata.js' . $suffix . '"></script>'
    . '<script src="/discadmin/global-search.js' . $suffix . '"></script>';

if (str_contains($html, '</body>')) {
    $html = str_replace('</body>', $enhancements . '</body>', $html);
} else {
    $html .= $enhancements;
}

echo $html;
