<?php
declare(strict_types=1);

ob_start();
require __DIR__ . '/index-core.php';
$html = (string)ob_get_clean();

$legacyRestoreBootstrap = '<script>restoreSession();</script>';
$html = str_replace($legacyRestoreBootstrap, '', $html, $restoreBootstrapCount);
if ($restoreBootstrapCount !== 1) {
    throw new RuntimeException('DISCADMIN session restore bootstrap could not be deferred');
}

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
    ? rawurlencode(brvtalDeploymentCacheKey())
    : (defined('BRVTAL_APP_BUILD') ? rawurlencode((string)BRVTAL_APP_BUILD) : '');
$suffix = $assetVersion !== '' ? '?v=' . $assetVersion : '';
$appearanceBoot = '<script>(function(){try{var k="brvtal.discadmin.appearance",m=localStorage.getItem(k);if(!/^(dark|light|glass)$/.test(m||""))m="dark";document.documentElement.dataset.discadminAppearance=m;document.documentElement.style.colorScheme=m==="light"?"light":"dark"}catch(e){document.documentElement.dataset.discadminAppearance="dark"}})();</script>'
    . '<link rel="stylesheet" href="/discadmin/admin-appearance.css' . $suffix . '">';
$enhancements = '<link rel="stylesheet" href="/discadmin/system-status-v2.css' . $suffix . '">'
    . '<link rel="stylesheet" href="/discadmin/backups.css' . $suffix . '">'
    . '<link rel="stylesheet" href="/discadmin/admin-shell.css' . $suffix . '">'
    . '<link rel="stylesheet" href="/discadmin/admin-record-lists.css' . $suffix . '">'
    . '<link rel="stylesheet" href="/discadmin/admin-data-grid.css' . $suffix . '" data-admin-data-grid-style="1">'
    . '<link rel="stylesheet" href="/discadmin/content-ordering.css' . $suffix . '" data-content-ordering-style="1">'
    . '<link rel="stylesheet" href="/discadmin/legacy-editor-drafts.css'
    . $suffix . '" data-legacy-editor-drafts-style="1">'
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
    . '<link rel="stylesheet" href="/discadmin/admin-design-system.css' . $suffix . '" data-admin-design-system="1">'
    . '<link rel="stylesheet" href="/discadmin/admin-frecuencia.css' . $suffix . '" data-admin-frecuencia="1">'
    . '<script data-admin-frecuencia-accent="1">(function(){let lastRoot=null,inFlight=null;const FALLBACK="#b8ff00";function hex(value){if(typeof value!=="string"||!/^#[a-f\d]{3}(?:[a-f\d]{3})?$/i.test(value))return null;let x=value.slice(1).toLowerCase();if(x.length===3)x=[...x].map(c=>c+c).join("");return "#"+x}function ink(color){const p=[0,2,4].map(n=>parseInt(color.slice(1+n,3+n),16)/255).map(v=>v<=.04045?v/12.92:Math.pow((v+.055)/1.055,2.4));const L=.2126*p[0]+.7152*p[1]+.0722*p[2];return (L+.05)/.05>=1.05/(L+.05)?"#000000":"#ffffff"}async function sync(){const current=document.getElementById("brvtal-dashboard-v2");if(!current||!window.state?.authed||window.state.section!=="dashboard")return false;if(inFlight)return inFlight;if(lastRoot===current)return true;lastRoot=current;inFlight=(async function(){let color=FALLBACK;try{const result=await req("/settings");const rows=Array.isArray(result?.data)?result.data:[];const active=rows.find(row=>row.setting_key==="theme.active");const slug=String(active?.setting_value||"").trim();if(/^[a-z0-9_-]{1,64}$/i.test(slug)){const record=rows.find(row=>row.setting_key==="theme."+slug);const theme=typeof record?.setting_value==="string"?JSON.parse(record.setting_value):record?.setting_value;color=hex(theme?.colors?.accent)||FALLBACK}}catch(e){color=FALLBACK}if(!window.state?.authed||window.state.section!=="dashboard"||document.getElementById("brvtal-dashboard-v2")!==current)return false;document.documentElement.style.setProperty("--admin-signal",color);document.documentElement.style.setProperty("--admin-signal-ink",ink(color));return true})().finally(function(){inFlight=null});return inFlight}const watcher=new MutationObserver(function(){const root=document.getElementById("brvtal-dashboard-v2");if(root&&root!==lastRoot&&window.state?.authed)sync()});watcher.observe(document.documentElement,{childList:true,subtree:true});document.addEventListener("click",function(event){if(event.target.closest("[data-admin-nav=dashboard]")){lastRoot=null;queueMicrotask(sync)}});window.BRVTALFrecuenciaAccent={sync,ink};})();</script>'
    . '<script src="/discadmin/admin-auth-boundary.js' . $suffix . '"></script>'
    . '<script src="/discadmin/content-core-nav.js' . $suffix . '"></script>'
    . '<script src="/discadmin/admin-color-field.js' . $suffix . '"></script>'
    . '<script src="/discadmin/event-workflow.js' . $suffix . '"></script>'
    . '<script src="/discadmin/content-health.js' . $suffix . '"></script>'
    . '<script src="/discadmin/seo-editorial-defaults.js' . $suffix . '"></script>'
    . '<script src="/discadmin/seo-metadata.js' . $suffix . '"></script>'
    . '<script src="/discadmin/event-workflow-seo.js' . $suffix . '"></script>'
    . '<script src="/discadmin/global-search.js' . $suffix . '"></script>'
    . '<script src="/discadmin/admin-data-grid.js' . $suffix . '" data-admin-data-grid="1"></script>'
    . '<script src="/discadmin/bulk-actions.js' . $suffix . '"></script>'
    . '<script src="/discadmin/admin-activity.js' . $suffix . '"></script>'
    . '<script src="/discadmin/system-status-v2.js' . $suffix . '"></script>'
    . '<script src="/discadmin/system-status-storage.js' . $suffix . '"></script>'
    . '<script src="/discadmin/backups.js' . $suffix . '"></script>'
    . '<script src="/discadmin/admin-shell.js' . $suffix . '"></script>'
    . '<script src="/discadmin/admin-modal-accessibility.js' . $suffix . '"></script>'
    . '<script src="/discadmin/admin-record-lists.js' . $suffix . '"></script>'
    . '<script src="/discadmin/content-ordering.js' . $suffix . '" data-content-ordering-script="1"></script>'
    . '<script src="/discadmin/editor-drafts.js' . $suffix . '" data-editor-drafts-script="1"></script>'
    . '<script src="/discadmin/admin-unsaved-changes.js' . $suffix . '" data-unsaved-changes-script="1"></script>'
    . '<script src="/discadmin/legacy-editor-drafts.js' . $suffix . '" data-legacy-editor-drafts-script="1"></script>'
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
    . '<script src="/discadmin/dashboard-v2.js' . $suffix . '"></script>'
    . '<script data-admin-session-restore="1">restoreSession().then(function(){window.BRVTALFrecuenciaAccent.sync()});</script>';

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
