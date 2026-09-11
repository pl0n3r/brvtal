/* The canonical shell owns navigation; modules own only their workspace. */
window.BRVTALAdminModules = (() => {
  const modules = {
    'content-core': {url:'/discadmin/content-core.php', mount:root=>BRVTALContentCore.mount(root)},
    security: {url:'/discadmin/totp-status.php', mount:root=>BRVTALSecurity.mount(root)}
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
      modules[section].mount(host.firstElementChild);
    } catch(error) {
      if(controller.signal.aborted || !host.isConnected) return;
      host.replaceChildren();
      const message=document.createElement('p');message.className='error';
      message.textContent='Unable to load this module: '+error.message;
      const retry=document.createElement('button');retry.className='btn';retry.textContent='RETRY';
      retry.onclick=()=>load(section);host.append(message,retry);
    } finally {if(pending===controller) pending=null;}
  }
  return {load,cancel,initialSection};
})();
