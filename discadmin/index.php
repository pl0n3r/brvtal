<?php
declare(strict_types=1);

ob_start();
require __DIR__ . '/index-core.php';
$html = (string)ob_get_clean();

$assetVersion = function_exists('brvtal_deployment_short_sha')
    ? rawurlencode(brvtal_deployment_short_sha())
    : (defined('BRVTAL_APP_BUILD') ? rawurlencode((string)BRVTAL_APP_BUILD) : '');
$suffix = $assetVersion !== '' ? '?v=' . $assetVersion : '';
$enhancements = '<link rel="stylesheet" href="/discadmin/system-status-v2.css' . $suffix . '">'
    . '<link rel="stylesheet" href="/discadmin/backups.css' . $suffix . '">'
    . '<script src="/discadmin/content-core-nav.js' . $suffix . '"></script>'
    . '<script src="/discadmin/content-health.js' . $suffix . '"></script>'
    . '<script src="/discadmin/seo-editorial-defaults.js' . $suffix . '"></script>'
    . '<script src="/discadmin/seo-metadata.js' . $suffix . '"></script>'
    . '<script src="/discadmin/global-search.js' . $suffix . '"></script>'
    . '<script src="/discadmin/bulk-actions.js' . $suffix . '"></script>'
    . '<script src="/discadmin/admin-activity.js' . $suffix . '"></script>'
    . '<script src="/discadmin/system-status-v2.js' . $suffix . '"></script>'
    . '<script src="/discadmin/system-status-storage.js' . $suffix . '"></script>'
    . '<script src="/discadmin/backups.js' . $suffix . '"></script>';

if (str_contains($html, '</body>')) {
    $html = str_replace('</body>', $enhancements . '</body>', $html);
} else {
    $html .= $enhancements;
}

echo $html;
