<?php
declare(strict_types=1);

ob_start();
require __DIR__ . '/index-core.php';
$html = (string)ob_get_clean();

$build = defined('BRVTAL_APP_BUILD') ? rawurlencode((string)BRVTAL_APP_BUILD) : '';
$healthScript = '<script src="/discadmin/content-health.js' . ($build !== '' ? '?v=' . $build : '') . '"></script>';

if (str_contains($html, '</body>')) {
    $html = str_replace('</body>', $healthScript . '</body>', $html);
} else {
    $html .= $healthScript;
}

echo $html;
