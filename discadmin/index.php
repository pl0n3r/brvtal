<?php
declare(strict_types=1);

/*
 * BRVTAL DISCADMIN UI shell wrapper.
 * The original, production-tested admin shell lives in index-core.php unchanged.
 * Integrated modules are loaded into the existing .main workspace so DISCADMIN
 * remains one application with one persistent navigation shell.
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
.side{position:sticky!important;top:0!important;height:100vh!important;align-self:start!important;z-index:20}
.brvtal-admin-release{margin-top:12px;padding-top:10px;border-top:1px solid #202326;color:#fff;font:9px/1.55 monospace;letter-spacing:.75px;text-transform:uppercase}
.brvtal-admin-release strong{display:block;color:#fff;font-size:10px;margin-bottom:3px}
.brvtal-admin-release span{display:block}.brvtal-admin-release .version{color:#fff;font-size:11px;font-weight:800;letter-spacing:1px}.brvtal-admin-release .build{color:#8d949a;font-size:8px}
.brvtal-module-loading{padding:40px 0;color:#777;font:10px monospace;letter-spacing:1.5px;text-transform:uppercase}
.brvtal-module-error{margin-top:20px;padding:14px;border:1px solid #5a2428;color:#ff7777;background:#11090a;font:11px monospace}
@media(max-width:850px){.side{position:sticky!important;top:0!important;height:auto!important;z-index:20}}
</style>
<script>
(function(){
  'use strict';
  const RELEASE = window.BRVTAL_RELEASE || {};
  const MODULES = {
    'CONTENT CORE': '/discadmin/content-core.php',
    'SECURITY / 2FA': '/discadmin/totp-status.php'
  };
  let moduleLoading = false;

  function installRelease(){
    const side=document.querySelector('.side');
    if(!side) return;
    const nav=side.querySelector('.nav');
    if(nav){
      const buttons=[...nav.querySelectorAll('button')];
      Object.entries(MODULES).forEach(([label,url])=>{
        if(buttons.some(b=>b.textContent.trim().toUpperCase()===label)) return;
        const b=document.createElement('button');
        b.type='button'; b.textContent=label; b.dataset.brvtalModule=url;
        b.addEventListener('click',()=>loadModule(url,label));
        const settings=buttons.find(x=>x.textContent.trim().toUpperCase()==='SETTINGS');
        if(settings && label==='CONTENT CORE') settings.before(b); else nav.appendChild(b);
      });
      nav.querySelectorAll('button[data-brvtal-module]').forEach(b=>{
        if(b.dataset.brvtalBound==='1') return;
        b.dataset.brvtalBound='1';
        b.addEventListener('click',()=>loadModule(b.dataset.brvtalModule,b.textContent.trim()));
      });
    }
    const foot=side.querySelector('.sidefoot');
    if(!foot || foot.querySelector('[data-brvtal-release]')) return;
    const box=document.createElement('div'); box.className='brvtal-admin-release'; box.dataset.brvtalRelease='1';
    box.innerHTML='<strong>BRVTAL DISCADMIN</strong><span class="version">v'+RELEASE.version+' · '+RELEASE.env+'</span><span class="build">BUILD '+RELEASE.build+'</span>';
    foot.appendChild(box);
  }

  function executeScripts(root){
    root.querySelectorAll('script').forEach(old=>{
      const s=document.createElement('script');
      [...old.attributes].forEach(a=>s.setAttribute(a.name,a.value));
      s.textContent=old.textContent;
      old.replaceWith(s);
    });
  }

  async function loadModule(url,label){
    if(moduleLoading) return;
    const main=document.querySelector('.main');
    if(!main) return;
    moduleLoading=true;
    main.dataset.brvtalShellModule=label;
    main.innerHTML='<div class="brvtal-module-loading">LOADING / '+label+'</div>';
    try{
      const response=await fetch(url,{credentials:'same-origin',headers:{'X-BRVTAL-ADMIN-FRAGMENT':'1'}});
      if(!response.ok) throw new Error('HTTP '+response.status);
      const text=await response.text();
      const doc=new DOMParser().parseFromString(text,'text/html');
      const fragment=doc.querySelector('.wrap') || doc.body;
      doc.head.querySelectorAll('style').forEach(style=>{
        const id='brvtal-module-style-'+label.toLowerCase().replace(/[^a-z0-9]+/g,'-');
        if(!document.getElementById(id)){
          const copy=document.createElement('style'); copy.id=id; copy.textContent=style.textContent; document.head.appendChild(copy);
        }
      });
      main.innerHTML='';
      [...fragment.childNodes].forEach(node=>main.appendChild(document.importNode(node,true)));
      executeScripts(main);
      installRelease();
    }catch(error){
      main.innerHTML='<div class="brvtal-module-error">MODULE LOAD FAILED / '+String(error.message||error).replace(/[&<>]/g,'')+'</div>';
    }finally{moduleLoading=false}
  }

  window.BRVTAL_INSTALL_ADMIN_META=installRelease;
  window.BRVTAL_LOAD_ADMIN_MODULE=loadModule;
  new MutationObserver(installRelease).observe(document.documentElement,{childList:true,subtree:true});
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',installRelease,{once:true}); else installRelease();
})();
</script>
HTML;

$releaseScript = '<script>window.BRVTAL_RELEASE={version:' . json_encode(BRVTAL_APP_VERSION) . ',build:' . json_encode(BRVTAL_APP_BUILD) . ',env:' . json_encode(BRVTAL_APP_ENV) . '};</script>';
$html = str_replace('</body>', $releaseScript . $ui . '</body>', $html);
echo $html;
