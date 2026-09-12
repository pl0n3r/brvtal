/* The canonical shell owns navigation; modules own only their workspace. */
window.BRVTALAdminModules = (() => {
  'use strict';

  const currentScript = document.currentScript;
  const build = (() => {
    try { return new URL(currentScript?.src || location.href).searchParams.get('v') || ''; }
    catch (_) { return ''; }
  })();
  const versioned = path => path + (build ? '?v=' + encodeURIComponent(build) : '');
  const nativeFetch = window.fetch.bind(window);

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

  const Feedback = (() => {
    let stack = null;
    const keyed = new Map();

    function ensureStack() {
      if (stack?.isConnected) return stack;
      stack = document.getElementById('brvtal-feedback-stack');
      if (!stack) {
        stack = document.createElement('div');
        stack.id = 'brvtal-feedback-stack';
        stack.className = 'brvtal-feedback-stack';
        stack.setAttribute('aria-live','polite');
        stack.setAttribute('aria-atomic','false');
        document.body.appendChild(stack);
      }
      return stack;
    }

    function dismiss(toast) {
      if (!toast) return;
      const key = toast.dataset.feedbackKey || '';
      if (key && keyed.get(key) === toast) keyed.delete(key);
      toast.classList.add('leaving');
      setTimeout(() => toast.remove(), 180);
    }

    function show(message, kind = 'info', options = {}) {
      const host = ensureStack();
      const key = String(options.key || '');
      if (key && keyed.has(key)) dismiss(keyed.get(key));

      const toast = document.createElement('div');
      toast.className = 'brvtal-feedback ' + kind;
      if (key) toast.dataset.feedbackKey = key;
      toast.setAttribute('role', kind === 'error' ? 'alert' : 'status');
      toast.innerHTML = `<span class="brvtal-feedback-dot" aria-hidden="true"></span><div class="brvtal-feedback-copy"><strong>${kind === 'progress' ? 'PROCESSING' : kind === 'success' ? 'DONE' : kind === 'error' ? 'ERROR' : 'NOTICE'}</strong><span></span></div><button type="button" aria-label="Dismiss notification">×</button>`;
      toast.querySelector('.brvtal-feedback-copy span').textContent = String(message || '');
      toast.querySelector('button').addEventListener('click', () => dismiss(toast));
      host.appendChild(toast);
      if (key) keyed.set(key, toast);
      requestAnimationFrame(() => toast.classList.add('show'));

      const persistent = options.persistent ?? (kind === 'error' || kind === 'progress');
      const timeout = Number(options.timeout ?? (kind === 'success' ? 3400 : 4200));
      if (!persistent) setTimeout(() => dismiss(toast), timeout);
      return toast;
    }

    return {
      show,
      progress:(message,key='mutation') => show(message,'progress',{key,persistent:true}),
      success:(message,key='mutation') => show(message,'success',{key,persistent:false}),
      error:(message,key='mutation') => show(message,'error',{key,persistent:true}),
      info:(message,key='notice') => show(message,'info',{key,persistent:false})
    };
  })();
  window.BRVTALFeedback = Feedback;

  function normalizeMediaPath(value) {
    const raw = String(value || '').trim();
    if (!raw || /^(?:data:|blob:|https?:\/\/)/i.test(raw) || raw.startsWith('/')) return raw;
    return '/' + raw.replace(/^\.?\//,'').replace(/^\/+/,'');
  }

  let mediaMapPromise = null;
  async function getMediaMap(force = false) {
    if (!force && mediaMapPromise) return mediaMapPromise;
    mediaMapPromise = (async () => {
      try {
        const r = await nativeFetch('/api/media-library.php?action=list',{credentials:'same-origin',cache:'no-store'});
        const j = await r.json();
        const map = new Map();
        (Array.isArray(j.data) ? j.data : []).forEach(item => map.set(Number(item.id), item));
        return map;
      } catch (_) {
        return new Map();
      }
    })();
    return mediaMapPromise;
  }

  async function getAdminCsrf() {
    try {
      if (typeof csrf !== 'undefined' && csrf) return csrf;
    } catch (_) {}
    const r = await nativeFetch('/api/index.php/auth',{credentials:'same-origin',cache:'no-store'});
    const j = await r.json().catch(() => ({}));
    if (!r.ok || !j.authenticated || !j.csrf) throw new Error('AUTH_REQUIRED');
    return j.csrf;
  }

  const mediaPermissions = {
    pending:null,
    async repair() {
      if (this.pending) return this.pending;
      this.pending = (async () => {
        const token = await getAdminCsrf();
        const r = await nativeFetch('/api/media-permissions.php',{
          method:'POST',
          credentials:'same-origin',
          cache:'no-store',
          headers:{'X-CSRF-Token':token}
        });
        const j = await r.json().catch(() => ({ok:false,error:'INVALID_RESPONSE'}));
        if (!r.ok || j.ok === false) throw new Error(j.error || ('HTTP_' + r.status));
        mediaMapPromise = null;
        return j;
      })().finally(() => { this.pending = null; });
      return this.pending;
    }
  };
  window.BRVTALMediaPermissions = mediaPermissions;

  function mutationLabels(url, method) {
    const action = (url.searchParams.get('action') || '').toLowerCase();
    const path = url.pathname.toLowerCase();
    if (path.includes('media-library.php') && action === 'upload') return ['Uploading media…','Media uploaded.'];
    if (path.includes('media-library.php') && action === 'register') return ['Registering media…','Media registered.'];
    if (path.includes('media-library.php') && action === 'update') return ['Saving media metadata…','Media metadata saved.'];
    if (path.includes('media-library.php') && method === 'DELETE') return ['Deleting media…','Media deleted.'];
    if (path.includes('releases.php') && method === 'DELETE') return ['Deleting release…','Release deleted.'];
    if (path.includes('releases.php')) return ['Saving release…','Release saved.'];
    if (path.includes('blog.php') && method === 'DELETE') return ['Deleting blog post…','Blog post deleted.'];
    if (path.includes('blog.php')) return ['Saving blog post…','Blog post saved.'];
    if (path.includes('totp-api.php')) return ['Updating security…','Security updated.'];
    if (method === 'DELETE') return ['Deleting…','Deleted.'];
    if (path.includes('/settings')) return ['Saving settings…','Settings saved.'];
    return ['Saving changes…','Changes saved.'];
  }

  function readableError(payload, status) {
    const code = String(payload?.message || payload?.error || ('HTTP_' + status));
    return code.replace(/_/g,' ').toLowerCase().replace(/^\w/, c => c.toUpperCase());
  }

  window.fetch = async function(input, init = {}) {
    const request = input instanceof Request ? input : null;
    const method = String(init.method || request?.method || 'GET').toUpperCase();
    let url;
    try { url = new URL(request?.url || String(input), location.href); }
    catch (_) { return nativeFetch(input, init); }

    const sameOrigin = url.origin === location.origin;
    const mutating = sameOrigin && ['POST','PUT','PATCH','DELETE'].includes(method);
    const authRequest = /\/(?:api\/index\.php\/)?auth(?:$|[/?])/.test(url.pathname + url.search);
    const track = mutating && !authRequest && !url.pathname.endsWith('/media-permissions.php');
    const [working, done] = mutationLabels(url, method);
    if (track) Feedback.progress(working,'mutation');

    try {
      const response = await nativeFetch(input, init);
      let payload = null;
      if (track) {
        try { payload = await response.clone().json(); } catch (_) {}
        if (response.ok && payload?.ok !== false) {
          if (url.pathname.endsWith('/media-library.php') && (url.searchParams.get('action') || '') === 'upload') {
            try { await mediaPermissions.repair(); } catch (e) { Feedback.error('Media uploaded, but public thumbnail permissions could not be repaired: ' + e.message,'media-permissions'); }
          }
          Feedback.success(done,'mutation');
        } else {
          Feedback.error(readableError(payload,response.status),'mutation');
        }
      }
      return response;
    } catch (error) {
      if (track) Feedback.error(error?.message || 'Network error','mutation');
      throw error;
    }
  };

  function placeholderFor(img) {
    const div = document.createElement('div');
    div.className = img.classList.contains('lg') ? 'thumb lg' : (img.classList.contains('avatar') ? 'avatar thumbph' : 'thumbph');
    div.textContent = 'NO IMG';
    img.replaceWith(div);
  }

  document.addEventListener('error', event => {
    const img = event.target;
    if (!(img instanceof HTMLImageElement)) return;

    const raw = img.getAttribute('src') || '';
    const normalized = normalizeMediaPath(raw);
    if (!img.dataset.brvtalNormalized && normalized && normalized !== raw) {
      event.stopImmediatePropagation();
      event.stopPropagation();
      img.dataset.brvtalNormalized = '1';
      img.src = normalized;
      return;
    }

    const directFallback = normalizeMediaPath(img.dataset.fallbackSrc || '');
    const inspectorFallback = normalizeMediaPath(img.closest('.media-inspector')?.querySelector('.media-inspector-path')?.textContent || '');
    if ((directFallback || inspectorFallback) && !img.dataset.brvtalFallback) {
      event.stopImmediatePropagation();
      event.stopPropagation();
      img.dataset.brvtalFallback = '1';
      img.src = directFallback || inspectorFallback;
      return;
    }

    const card = img.closest('[data-media-id]');
    if (card && !img.dataset.brvtalLookup) {
      event.stopImmediatePropagation();
      event.stopPropagation();
      img.dataset.brvtalLookup = '1';
      getMediaMap().then(map => {
        const item = map.get(Number(card.dataset.mediaId));
        const fallback = normalizeMediaPath(item?.file_path || '');
        if (fallback && fallback !== raw) {
          img.dataset.brvtalFallback = '1';
          img.src = fallback;
        } else {
          placeholderFor(img);
        }
      });
      return;
    }

    event.stopImmediatePropagation();
    event.stopPropagation();
    placeholderFor(img);
  }, true);

  function syncMediaFieldPreview(input) {
    if (!(input instanceof HTMLInputElement)) return;
    if (!['f_cover_image','f_photo','e_cover_image','e_ticket_qr','blog_cover_image'].includes(input.id)) return;
    const holder = input.closest('.thumbcell');
    if (!holder) return;
    const src = normalizeMediaPath(input.value);
    let img = holder.querySelector('img');
    if (!src) {
      if (img) placeholderFor(img);
      return;
    }
    if (!img) {
      const previous = holder.querySelector('.thumbph,div.thumb');
      img = document.createElement('img');
      img.className = previous?.classList.contains('lg') ? 'thumb lg' : 'thumb';
      img.alt = 'Selected media';
      img.loading = 'lazy';
      if (previous) previous.replaceWith(img); else holder.prepend(img);
    }
    img.dataset.brvtalNormalized = '1';
    img.src = src;
  }
  document.addEventListener('input',e => syncMediaFieldPreview(e.target),true);
  document.addEventListener('change',e => syncMediaFieldPreview(e.target),true);

  let artistThumbMapPromise = null;
  async function getArtistThumbMap(force = false) {
    if (!force && artistThumbMapPromise) return artistThumbMapPromise;
    artistThumbMapPromise = (async () => {
      try {
        const r = await nativeFetch('/api/index.php/artists',{credentials:'same-origin',cache:'no-store'});
        const j = await r.json();
        const map = new Map();
        (Array.isArray(j.data) ? j.data : []).forEach(artist => map.set(String(artist.name || '').trim().toLowerCase(), artist.photo || ''));
        return map;
      } catch (_) {
        return new Map();
      }
    })();
    return artistThumbMapPromise;
  }

  async function hydrateContentCoreThumbs(scope = document) {
    const placeholders = [...scope.querySelectorAll?.('[data-admin-module="content-core"] .artist .ph') || []];
    if (!placeholders.length) return;
    const map = await getArtistThumbMap();
    placeholders.forEach(ph => {
      if (!ph.isConnected) return;
      const name = ph.closest('.artist')?.querySelector('.grow b')?.textContent?.trim().toLowerCase() || '';
      const photo = normalizeMediaPath(map.get(name) || '');
      if (!photo) return;
      const img = document.createElement('img');
      img.src = photo;
      img.alt = ph.closest('.artist')?.querySelector('.grow b')?.textContent?.trim() || 'Artist';
      img.loading = 'lazy';
      ph.replaceWith(img);
    });
  }

  let hydrateTimer = null;
  const contentCoreThumbObserver = new MutationObserver(mutations => {
    if (!mutations.some(m => m.addedNodes.length)) return;
    clearTimeout(hydrateTimer);
    hydrateTimer = setTimeout(() => hydrateContentCoreThumbs(document), 20);
  });
  contentCoreThumbObserver.observe(document.documentElement,{childList:true,subtree:true});

  function fieldValue(id) {
    return document.getElementById('f_' + id)?.value ?? '';
  }
  function formPayload(type) {
    if (type === 'events') return {
      title:fieldValue('title'), slug:fieldValue('slug'),
      event_date:fieldValue('event_date') ? fieldValue('event_date').replace('T',' ') : null,
      venue:fieldValue('venue'), city:fieldValue('city'), description:fieldValue('description'),
      skin:fieldValue('skin'), accent:fieldValue('accent'), cover_image:fieldValue('cover_image'),
      ticket_url:fieldValue('ticket_url'), status:fieldValue('status') || 'draft',
      sort_order:Number(fieldValue('sort_order') || 0)
    };
    if (type === 'artists') return {
      name:fieldValue('name'), slug:fieldValue('slug'), bio:fieldValue('bio'), photo:fieldValue('photo'),
      instagram_url:fieldValue('instagram_url'), soundcloud_url:fieldValue('soundcloud_url'),
      website_url:fieldValue('website_url'), status:fieldValue('status') || 'draft',
      sort_order:Number(fieldValue('sort_order') || 0)
    };
    if (type === 'sets') return {
      title:fieldValue('title'), slug:fieldValue('slug'), platform:fieldValue('platform') || 'soundcloud',
      external_url:fieldValue('external_url'), embed_url:fieldValue('embed_url'), cover_image:fieldValue('cover_image'),
      artist_id:fieldValue('artist_id') ? Number(fieldValue('artist_id')) : null,
      event_id:fieldValue('event_id') ? Number(fieldValue('event_id')) : null,
      status:fieldValue('status') || 'draft', sort_order:Number(fieldValue('sort_order') || 0),
      description:fieldValue('description')
    };
    if (type === 'media') return {
      title:fieldValue('title'), type:fieldValue('type') || 'image', file_path:fieldValue('file_path'),
      mime_type:fieldValue('mime_type'), file_size:Number(fieldValue('file_size') || 0),
      alt_text:fieldValue('alt_text'), status:fieldValue('status') || 'published'
    };
    if (type === 'pages') return {
      title:fieldValue('title'), slug:fieldValue('slug'), locale:fieldValue('locale') || 'en',
      content_json:fieldValue('content_json'), seo_title:fieldValue('seo_title'),
      seo_description:fieldValue('seo_description'), status:fieldValue('status') || 'draft'
    };
    if (type === 'settings') return {
      setting_key:fieldValue('setting_key'), setting_value:fieldValue('setting_value'),
      is_json:Number(fieldValue('is_json') || 0)
    };
    return {};
  }

  window.save = async function(type, id = null) {
    const saveBtn = document.getElementById('saveBtn');
    const previousLabel = saveBtn?.textContent || 'SAVE';
    if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = 'SAVING…'; }
    const payload = formPayload(type);
    try {
      if (typeof req !== 'function') throw new Error('ADMIN_REQUEST_UNAVAILABLE');
      if (type === 'settings') {
        await req('/settings',{method:'POST',body:JSON.stringify(payload)});
      } else {
        const path = '/' + type + (id !== null && id !== '' ? '/' + encodeURIComponent(id) : '');
        await req(path,{method:id !== null && id !== '' ? 'PUT' : 'POST',body:JSON.stringify(payload)});
      }
      if (typeof closeModal === 'function') closeModal();
      if (typeof go === 'function') await go(type);
    } catch (error) {
      if (typeof show === 'function') show(error?.message || 'SAVE_FAILED');
      if (!document.querySelector('.brvtal-feedback.error')) Feedback.error(readableError({error:error?.message || 'SAVE_FAILED'},500),'mutation');
    } finally {
      if (saveBtn?.isConnected) { saveBtn.disabled = false; saveBtn.textContent = previousLabel; }
    }
  };

  ensureStyle('brvtal-media-library-style','/discadmin/media-library.css');
  ensureStyle('brvtal-releases-style','/discadmin/releases.css');
  ensureStyle('brvtal-blog-style','/discadmin/blog.css');
  const mediaReady = ensureScript('brvtal-media-library-script','/discadmin/media-library.js');
  const releasesReady = ensureScript('brvtal-releases-script','/discadmin/releases.js');
  const blogReady = ensureScript('brvtal-blog-script','/discadmin/blog.js');

  const modules = {
    'content-core': {url:'/discadmin/content-core.php', mount:root=>BRVTALContentCore.mount(root)},
    security: {url:'/discadmin/totp-status.php', mount:root=>BRVTALSecurity.mount(root)},
    media: {url:'/discadmin/media-library.php', mount:async root=>{
      await mediaReady;
      try { await mediaPermissions.repair(); }
      catch (e) { Feedback.error('Media thumbnail access check failed: ' + e.message,'media-permissions'); }
      BRVTALMediaLibrary.mount(root);
    }},
    releases: {url:'/discadmin/releases.php', mount:async root=>{
      await Promise.all([mediaReady,releasesReady]);
      BRVTALReleases.mount(root);
    }},
    blog: {url:'/discadmin/blog.php', mount:async root=>{
      await Promise.all([mediaReady,blogReady]);
      BRVTALBlog.mount(root);
    }}
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
      if (section === 'content-core') hydrateContentCoreThumbs(host);
    } catch(error) {
      if(controller.signal.aborted || !host.isConnected) return;
      host.replaceChildren();
      const message=document.createElement('p');message.className='error';
      message.textContent='Unable to load this module: '+error.message;
      const retry=document.createElement('button');retry.className='btn';retry.textContent='RETRY';
      retry.onclick=()=>load(section);host.append(message,retry);
      Feedback.error('Unable to load ' + section + ': ' + error.message,'module-load');
    } finally {if(pending===controller) pending=null;}
  }

  function ensureDynamicNavigation() {
    const nav = document.querySelector('.nav');
    if (!nav) return;
    const definitions = [
      ['releases','RELEASES'],
      ['blog','BLOG']
    ];
    definitions.forEach(([section,label]) => {
      let button = nav.querySelector(`[data-admin-nav="${section}"]`);
      if (!button) {
        button = document.createElement('button');
        button.type = 'button';
        button.dataset.adminNav = section;
        button.textContent = label;
        button.addEventListener('click',() => window.go(section));
        nav.appendChild(button);
      }
      try { button.classList.toggle('active', typeof state !== 'undefined' && state.section === section); }
      catch (_) { button.classList.remove('active'); }
    });
  }

  let navTimer = null;
  const navObserver = new MutationObserver(mutations => {
    if (!mutations.some(m => m.addedNodes.length || m.removedNodes.length)) return;
    clearTimeout(navTimer);
    navTimer = setTimeout(ensureDynamicNavigation, 10);
  });
  navObserver.observe(document.documentElement,{childList:true,subtree:true});
  setTimeout(ensureDynamicNavigation,0);

  function prepareModuleWorkspace(section) {
    state.section=section;
    render();
    ensureDynamicNavigation();
    const main=document.querySelector('.main');
    if(!main) return null;
    [...main.children].forEach((child,index)=>{if(index>0) child.remove();});
    const host=document.createElement('div'); host.id='admin-module-host'; host.setAttribute('aria-live','polite'); host.textContent='LOADING…'; main.appendChild(host);
    return host;
  }

  const originalGo=window.go;
  window.go=async function(section) {
    if(section==='media' || section==='releases' || section==='blog') {
      prepareModuleWorkspace(section);
      if (section === 'media') await mediaReady;
      if (section === 'releases') await Promise.all([mediaReady,releasesReady]);
      if (section === 'blog') await Promise.all([mediaReady,blogReady]);
      await load(section);
      ensureDynamicNavigation();
      return;
    }
    const result = await originalGo(section);
    ensureDynamicNavigation();
    return result;
  };

  const originalOpenModal=window.openModal;
  window.openModal=function(type,id=null) {
    if(type==='media' && !id) { window.go('media'); return; }
    if(type==='releases' && !id) { window.go('releases'); return; }
    if(type==='blog' && !id) { window.go('blog'); return; }
    return originalOpenModal(type,id);
  };

  const originalRestoreSession = window.restoreSession;
  if (typeof originalRestoreSession === 'function') {
    window.restoreSession = async function(...args) {
      try { await mediaPermissions.repair(); }
      catch (e) { if (e?.message !== 'AUTH_REQUIRED') Feedback.error('Media thumbnail access check failed: ' + e.message,'media-permissions'); }
      const result = await originalRestoreSession.apply(this,args);
      if (result) hydrateContentCoreThumbs(document);
      ensureDynamicNavigation();
      return result;
    };
  }

  const originalLogin = window.login;
  if (typeof originalLogin === 'function') {
    window.login = async function(...args) {
      const result = await originalLogin.apply(this,args);
      try {
        if (typeof state !== 'undefined' && state.authed) {
          await mediaPermissions.repair();
          artistThumbMapPromise = null;
          if (state.section && typeof window.go === 'function') await window.go(state.section);
        }
      } catch (e) {
        Feedback.error('Login succeeded, but media thumbnail access could not be refreshed: ' + e.message,'media-permissions');
      }
      ensureDynamicNavigation();
      return result;
    };
  }

  return {load,cancel,initialSection,feedback:Feedback,repairMediaPermissions:()=>mediaPermissions.repair()};
})();