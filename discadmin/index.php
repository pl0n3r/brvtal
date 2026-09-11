<?php
declare(strict_types=1);

/*
 * BRVTAL DISCADMIN UI shell wrapper.
 * The original, production-tested admin shell lives in index-core.php unchanged.
 * This wrapper only exposes integrated release/security/navigation metadata in the UI.
 */
require_once __DIR__ . '/../config/version.php';

ob_start();
require __DIR__ . '/index-core.php';
$html = ob_get_clean();

$version = htmlspecialchars(BRVTAL_APP_VERSION, ENT_QUOTES, 'UTF-8');
$build = htmlspecialchars(BRVTAL_APP_BUILD, ENT_QUOTES, 'UTF-8');
$env = htmlspecialchars(BRVTAL_APP_ENV, ENT_QUOTES, 'UTF-8');

$ui = <<<'HTML'
<style id="brvtal-admin-release-style">
/* Keep the DISCADMIN navigation visible while the main workspace scrolls. */
.side{position:sticky!important;top:0!important;height:100vh!important;align-self:start!important;z-index:20}
.brvtal-admin-release{margin-top:12px;padding-top:10px;border-top:1px solid #202326;color:#fff;font:9px/1.55 monospace;letter-spacing:.75px;text-transform:uppercase}
.brvtal-admin-release strong{display:block;color:#fff;font-size:10px;margin-bottom:3px}
.brvtal-admin-release span{display:block}
.brvtal-admin-release .version{color:#fff;font-size:11px;font-weight:800;letter-spacing:1px}
.brvtal-admin-release .build{color:#8d949a;font-size:8px}
@media(max-width:850px){.side{position:sticky!important;top:0!important;height:auto!important;z-index:20}}
</style>
<script>
(function(){
  'use strict';
  const RELEASE = window.BRVTAL_RELEASE || {};
  function install(){
    const side=document.querySelector('.side');
    if(!side) return;
    const nav=side.querySelector('.nav');
    if(nav){
      const buttons=[...nav.querySelectorAll('button')];
      const hasCore=buttons.some(b=>b.textContent.trim().toUpperCase()==='CONTENT CORE');
      if(!hasCore){
        const settings=buttons.find(b=>b.textContent.trim().toUpperCase()==='SETTINGS');
        if(settings){
          const b=document.createElement('button'); b.type='button'; b.textContent='CONTENT CORE';
          b.onclick=()=>location.href='/discadmin/content-core-entry.php'; settings.before(b);
        }
      }
      const hasSecurity=[...nav.querySelectorAll('button')].some(b=>b.textContent.trim().toUpperCase()==='SECURITY / 2FA');
      if(!hasSecurity){
        const b=document.createElement('button'); b.type='button'; b.textContent='SECURITY / 2FA';
        b.onclick=()=>location.href='/discadmin/totp-status.php'; nav.appendChild(b);
      }
    }
    const foot=side.querySelector('.sidefoot');
    if(!foot || foot.querySelector('[data-brvtal-release]')) return;
    const box=document.createElement('div'); box.className='brvtal-admin-release'; box.dataset.brvtalRelease='1';
    box.innerHTML='<strong>BRVTAL DISCADMIN</strong><span class="version">v'+RELEASE.version+' · '+RELEASE.env+'</span><span class="build">BUILD '+RELEASE.build+'</span>';
    foot.appendChild(box);
  }
  window.BRVTAL_INSTALL_ADMIN_META=install;
  new MutationObserver(install).observe(document.documentElement,{childList:true,subtree:true});
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',install,{once:true}); else install();
})();
</script>
HTML;

$releaseScript = '<script>window.BRVTAL_RELEASE={version:' . json_encode(BRVTAL_APP_VERSION) . ',build:' . json_encode(BRVTAL_APP_BUILD) . ',env:' . json_encode(BRVTAL_APP_ENV) . '};</script>';
$html = str_replace('</body>', $releaseScript . $ui . '</body>', $html);
echo $html;
