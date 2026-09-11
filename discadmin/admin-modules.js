/* The canonical shell owns navigation; modules own only their workspace. */
window.BRVTALAdminModules = (() => {
  'use strict';

  const currentScript = document.currentScript;
  const build = (() => {
    try { return new URL(currentScript?.src || location.href).searchParams.get('v') || ''; }
    catch (_) { return ''; }
  })();
  const versioned = path => path + (build ? '?v=' + encodeURIComponent(build) : '');

  function ensureStyle(id, href) {
    if (document.getElementById(id)) return;
    const link = document.createElement('link'); link.id = id; link.rel = 'stylesheet'; link.href = versioned(href); document.head.appendChild(link);
  }
  function ensureScript(id, src) {
    const existing = document.getElementById(id);
    if (existing) return existing.dataset.ready === '1' ? Promise.resolve() : new Promise((resolve,reject) => { existing.addEventListener('load',resolve,{once:true}); existing.addEventListener('error',reject,{once:true}); });
    return new Promise((resolve,reject) => {
      const script = document.createElement('script'); script.id = id; script.src = versioned(src); script.defer = true;
      script.addEventListener('load',() => { script.dataset.ready='1'; resolve(); },{once:true});
      script.addEventListener('error',reject,{once:true}); document.head.appendChild(script);
    });
  }

  ensureStyle('brvtal-media-library-style','/discadmin/media-library.css');
  const mediaReady = ensureScript('brvtal-media-library-script','/discadmin/media-library.js');

  const modules = {
    'content-core': {url:'/discadmin/content-core.php', mount:root=>BRVTALContentCore.mount(root)},
    security: {url:'/discadmin/totp-status.php', mount:root=>BRVTALSecurity.mount(root)},
    media: {url:'/discadmin/media-library.php', mount:async root=>{await mediaReady; BRVTALMediaLibrary.mount(root)}}
  };
  let pending;

  function cancel() { if (pending) pending.abort(); pending=null; }
  function initialSection() {
    const section=new URLSearchParams(location.search).get('module');
    return modules[section] ? section : 'dashboard';
  }
  async function load(section) {
    cancel();
    const controller=new AbortController();pending=controller;
    const host=document.getElementById('admin-module-host');
    if (!host || !modules[section]) return;
    try {
      const response=await fetch(modules[section].url, {
        credentials:'same-origin', cache:'no-store', signal:controller.signal,
        headers:{'X-BRVTAL-ADMIN-FRAGMENT':'1'}
      });
      if (response.status===401 || response.redirected) {
        state.authed=false;render();return;
      }
      if (!response.ok) throw Error('HTTP '+response.status);
      const doc=new DOMParser().parseFromString(await response.text(),'text/html');
      if(controller.signal.aborted || !host.isConnected) return;
      const fragment=doc.querySelector('[data-admin-module="'+section+'"]');
      if(!fragment) throw Error('Invalid module response');
      host.replaceChildren(document.importNode(fragment,true));
      await modules[section].mount(host.firstElementChild);
    } catch(error) {
      if(controller.signal.aborted || !host.isConnected) return;
      host.replaceChildren();
      const message=document.createElement('p');message.className='error';
      message.textContent='Unable to load this module: '+error.message;
      const retry=document.createElement('button');retry.className='btn';retry.textContent='RETRY';
      retry.onclick=()=>load(section);host.append(message,retry);
    } finally {if(pending===controller) pending=null;}
  }

  function prepareModuleWorkspace(section) {
    state.section=section;
    render();
    const main=document.querySelector('.main');
    if(!main) return null;
    [...main.children].forEach((child,index)=>{if(index>0) child.remove();});
    const host=document.createElement('div'); host.id='admin-module-host'; host.setAttribute('aria-live','polite'); host.textContent='LOADING…'; main.appendChild(host);
    return host;
  }

  const originalGo=window.go;
  window.go=async function(section) {
    if(section==='media') {
      prepareModuleWorkspace('media');
      await mediaReady;
      await load('media');
      return;
    }
    return originalGo(section);
  };

  const originalOpenModal=window.openModal;
  window.openModal=function(type,id=null) {
    if(type==='media' && !id) { window.go('media'); return; }
    return originalOpenModal(type,id);
  };

  return {load,cancel,initialSection};
})();
