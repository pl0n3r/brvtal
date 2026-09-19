<?php
declare(strict_types=1);

ob_start();
require __DIR__ . '/index-core.php';
$html = (string)ob_get_clean();

$runtimeDeclaration = "const API='../api/index.php';let csrf='';let state=";
$explicitRuntimeDeclaration = "const API='../api/index.php';window.csrf='';window.state=";
$html = str_replace($runtimeDeclaration, $explicitRuntimeDeclaration, $html, $runtimeReplacementCount);
if ($runtimeReplacementCount !== 1) {
    throw new RuntimeException('DISCADMIN runtime declaration could not be made explicit');
}

$phpRuntime = htmlspecialchars(PHP_VERSION, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$html = preg_replace(
    '~<div class="techcard"><h3>PHP</h3><div class="techvalue">[^<]*</div></div>~',
    '<div class="techcard" data-runtime-card="php"><h3>PHP</h3><div class="techvalue">' . $phpRuntime . '</div></div>',
    $html,
    1
) ?? $html;

$assetVersion = function_exists('brvtal_deployment_short_sha')
    ? rawurlencode(brvtal_deployment_short_sha())
    : (defined('BRVTAL_APP_BUILD') ? rawurlencode((string)BRVTAL_APP_BUILD) : '');
$suffix = $assetVersion !== '' ? '?v=' . $assetVersion : '';
$appearanceBoot = '<script>(function(){try{var k="brvtal.discadmin.appearance",m=localStorage.getItem(k);if(!/^(dark|light|glass)$/.test(m||""))m="dark";document.documentElement.dataset.discadminAppearance=m;document.documentElement.style.colorScheme=m==="light"?"light":"dark"}catch(e){document.documentElement.dataset.discadminAppearance="dark"}})();</script>'
    . '<link rel="stylesheet" href="/discadmin/admin-appearance.css' . $suffix . '">';
$enhancements = '<link rel="stylesheet" href="/discadmin/system-status-v2.css' . $suffix . '">'
    . '<link rel="stylesheet" href="/discadmin/backups.css' . $suffix . '">'
    . '<link rel="stylesheet" href="/discadmin/admin-shell.css' . $suffix . '">'
    . '<link rel="stylesheet" href="/discadmin/admin-record-lists.css' . $suffix . '">'
    . '<link rel="stylesheet" href="/discadmin/admin-form-dialogs.css' . $suffix . '">'
    . '<link rel="stylesheet" href="/discadmin/admin-color-field.css' . $suffix . '">'
    . '<link rel="stylesheet" href="/discadmin/admin-information-architecture.css' . $suffix . '">'
    . '<link rel="stylesheet" href="/discadmin/dashboard-v2.css' . $suffix . '">'
    . '<link rel="stylesheet" href="/discadmin/hero-slider.css' . $suffix . '">'
    . '<link rel="stylesheet" href="/discadmin/hero-slider-v2.css' . $suffix . '" data-hero-v2="1">'
    . '<link rel="stylesheet" href="/discadmin/settings-v2.css' . $suffix . '" data-settings-v2="1">'
    . '<link rel="stylesheet" href="/discadmin/theme-studio-v2.css' . $suffix . '" data-theme-studio-v2="1">'
    . '<link rel="stylesheet" href="/discadmin/theme-studio-configuration.css' . $suffix . '" data-theme-config="1">'
    . '<link rel="stylesheet" href="/discadmin/theme-studio-reliability.css' . $suffix . '" data-theme-studio-reliability="1">'
    . '<link rel="stylesheet" href="/discadmin/memories.css' . $suffix . '" data-memories-admin="1">'
    . '<script src="/discadmin/admin-auth-boundary.js' . $suffix . '"></script>'
    . '<script src="/discadmin/content-core-nav.js' . $suffix . '"></script>'
    . '<script src="/discadmin/admin-color-field.js' . $suffix . '"></script>'
    . '<script src="/discadmin/event-workflow.js' . $suffix . '"></script>'
    . '<script src="/discadmin/content-health.js' . $suffix . '"></script>'
    . '<script src="/discadmin/seo-editorial-defaults.js' . $suffix . '"></script>'
    . '<script src="/discadmin/seo-metadata.js' . $suffix . '"></script>'
    . '<script src="/discadmin/event-workflow-seo.js' . $suffix . '"></script>'
    . '<script src="/discadmin/global-search.js' . $suffix . '"></script>'
    . '<script src="/discadmin/bulk-actions.js' . $suffix . '"></script>'
    . '<script src="/discadmin/admin-activity.js' . $suffix . '"></script>'
    . '<script src="/discadmin/system-status-v2.js' . $suffix . '"></script>'
    . '<script src="/discadmin/system-status-storage.js' . $suffix . '"></script>'
    . '<script src="/discadmin/backups.js' . $suffix . '"></script>'
    . '<script src="/discadmin/admin-shell.js' . $suffix . '"></script>'
    . '<script src="/discadmin/admin-modal-accessibility.js' . $suffix . '"></script>'
    . '<script src="/discadmin/admin-record-lists.js' . $suffix . '"></script>'
    . '<script src="/discadmin/admin-form-dialogs.js' . $suffix . '"></script>'
    . '<script src="/discadmin/pages-publication-contract.js' . $suffix . '"></script>'
    . '<script src="/discadmin/set-publication-contract.js' . $suffix . '"></script>'
    . '<script src="/discadmin/admin-reliability.js' . $suffix . '"></script>'
    . '<script src="/discadmin/hero-slider-state-bridge.js' . $suffix . '"></script>'
    . '<script src="/discadmin/hero-slider.js' . $suffix . '"></script>'
    . '<script src="/discadmin/hero-slider-accessibility.js' . $suffix . '"></script>'
    . '<script src="/discadmin/admin-appearance.js' . $suffix . '"></script>'
    . '<script src="/discadmin/admin-information-architecture.js' . $suffix . '"></script>'
    . '<script src="/discadmin/admin-route-aliases.js' . $suffix . '"></script>'
    . '<script src="/discadmin/settings-v2.js' . $suffix . '"></script>'
    . '<script src="/discadmin/theme-studio-v2.js' . $suffix . '"></script>'
    . '<script src="/discadmin/theme-studio-configuration.js' . $suffix . '"></script>'
    . '<script src="/discadmin/theme-studio-reliability.js' . $suffix . '"></script>'
    . '<script src="/discadmin/memories.js' . $suffix . '" data-memories-admin="1"></script>'
    . '<script src="/discadmin/dashboard-v2.js' . $suffix . '"></script>';

if (str_contains($html, '</head>')) {
    $html = str_replace('</head>', $appearanceBoot . '</head>', $html);
} else {
    $html = $appearanceBoot . $html;
}

if (str_contains($html, '</body>')) {
    $html = str_replace('</body>', $enhancements . '</body>', $html);
} else {
    $html .= $enhancements;
}

echo $html;
