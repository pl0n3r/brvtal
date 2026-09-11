<?php
declare(strict_types=1);

/*
 * BRVTAL DISCADMIN UI shell wrapper.
 * Keeps the production-tested index-core.php shell intact and mounts
 * Content Core / Security as first-class workspaces inside the same shell.
 */
require_once __DIR__ . '/../config/version.php';

ob_start();
require __DIR__ . '/index-core.php';
$html = ob_get_clean();

$ui = <<<'HTML'
<style id="brvtal-admin-integrated-modules">
.side{position:sticky!important;top:0!important;height:100vh!important;align-self:start!important;z-index:20}
.brvtal-admin-release{margin-top:12px;padding-top:10px;border-top:1px solid #202326;color:#fff;font:9px/1.55 monospace;letter-spacing:.75px;text-transform:uppercase}
.brvtal-admin-release strong{display:block;color:#fff;font-size:10px;margin-bottom:3px}
.brvtal-admin-release .version{display:block;color:#fff;font-size:11px;font-weight:800;letter-spacing:1px}
.brvtal-admin-release .build{display:block;color:#8d949a;font-size:8px}
.main.brvtal-module-active{padding-top:0!important}
.brvtal-module-loading{padding:32px 0;color:#777;font:10px monospace;letter-spacing:1.5px;text-transform:uppercase}
.brvtal-module-error{margin-top:20px;padding:16px;border:1px solid #5a2428;color:#ff7777;background:#11090a;font:11px monospace}
.brvtal-module-host{min-height:calc(100vh - 48px)}
.brvtal-module-host>.wrap{max-width:none!important;margin:0!important;padding:0!important}
.brvtal-module-host .module-shell{max-width:none!important}
@media(max-width:850px){.side{position:sticky!important;top:0!important;height:auto!important;z-index:20}.main.brvtal-module-active{padding-top:0!important}}
</style>
<script>
(function(){
  'use strict';
  const RELEASE = {
    version: <?= json_encode(BRVTAL_APP_VERSION) ?>,
    build: <?= json_encode(BRVTAL_APP_BUILD) ?>,
    env: <?= json_encode(BRVTAL_APP_ENV) ?>
  };
  const MODULES = {
    'CONTENT CORE': '/discadmin/content-core.php',
    'SECURITY / 2FA': '/discadmin/totp-status.php'
  };
  let loading=false;

  function install(){
    const side=document.querySelector('.side');
    if(!side) return;
    const nav=side.querySelector('.nav');
    if(!nav) return;
    const settings=[...nav.querySelectorAll('button')].find(b=>b.textContent.trim().toUpperCase()==='SETTINGS');
    Object.entries(MODULES).forEach(([label,url])=>{
      let b=[...nav.querySelectorAll('button')].find(x=>x.textContent.trim().toUpperCase()===label);
      if(!b){
        b=document.createElement('button');
        b.type='button';
        b.textContent=label;
        b.dataset.brvtalModule=url;
        if(label==='CONTENT CORE' && settings) settings.before(b); else nav.appendChild(b);
      }
      if(b.dataset.brvtalBound!=='1'){
        b.dataset.brvtalBound='1';
        b.dataset.brvtalModule=url;
        b.addEventListener('click',function(){loadModule(url,label);});
      }
    });
    const foot=side.querySelector('.sidefoot');
    if(foot && !foot.querySelector('[data-brvtal-release]')){
      const box=document.createElement('div');
      box.className='brvtal-admin-release';
      box.dataset.brvtalRelease='1';
      box.innerHTML='<strong>BRVTAL DISCADMIN</strong><span class="version">v'+RELEASE.version+' · '+RELEASE.env+'</span><span class="build">BUILD '+RELEASE.build+'</span>';
      foot.appendChild(box);
    }
  }

  function copyStyles(doc,label){
    const id='brvtal-module-style-'+label.toLowerCase().replace(/[^a-z0-9]+/g,'-');
    if(document.getElementById(id)) return;
    const style=document.createElement('style');
    style.id=id;
    style.textContent=[...doc.querySelectorAll('head style')].map(x=>x.textContent).join('\n');
    if(style.textContent) document.head.appendChild(style);
  }

  function runScripts(root){
    root.querySelectorAll('script').forEach(old=>{
      const s=document.createElement('script');
      [...old.attributes].forEach(a=>s.setAttribute(a.name,a.value));
      s.textContent=old.textContent;
      old.replaceWith(s);
    });
  }

  async function loadModule(url,label){
    if(loading) return;
    const main=document.querySelector('.main');
    if(!main) return;
    loading=true;
    main.classList.add('brvtal-module-active');
    main.innerHTML='<div class="brvtal-module-host"><div class="brvtal-module-loading">LOADING / '+label+'</div></div>';
    try{
      const response=await fetch(url,{credentials:'same-origin',headers:{'X-BRVTAL-ADMIN-FRAGMENT':'1','X-Requested-With':'XMLHttpRequest'}});
      if(!response.ok) throw new Error('HTTP '+response.status);
      const text=await response.text();
      const doc=new DOMParser().parseFromString(text,'text/html');
      if(/<title>.*500|Fatal error|Uncaught Error/i.test(text)) throw new Error('MODULE RETURNED SERVER ERROR');
      copyStyles(doc,label);
      const source=doc.querySelector('.wrap') || doc.body;
      const host=document.createElement('div');
      host.className='brvtal-module-host';
      [...source.childNodes].forEach(n=>host.appendChild(document.importNode(n,true)));
      main.innerHTML='';
      main.appendChild(host);
      runScripts(host);
      install();
    }catch(err){
      main.innerHTML='<div class="brvtal-module-error">MODULE LOAD FAILED / '+String(err.message||err).replace(/[&<>]/g,'')+'</div>';
    }finally{loading=false}
  }

  window.BRVTAL_LOAD_ADMIN_MODULE=loadModule;
  window.BRVTAL_INSTALL_ADMIN_META=install;
  install();
})();
</script>
HTML;

$html = str_replace('</body>', $ui . '</body>', $html);
echo $html;
