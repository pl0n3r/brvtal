/* BRVTAL Media Library + picker. The canonical DISCADMIN shell remains owner of navigation. */
window.BRVTALMediaLibrary = (() => {
  'use strict';

  const ENDPOINT = '/api/media-library.php';
  const store = {root:null,items:[],selected:null,engine:null,csrf:'',loading:false};
  let pickerObserver;
  let toastTimer = 0;
  let selectionLoadId = 0;
  let selectionController = null;

  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
  const bytes = n => {
    n = Number(n || 0);
    if (!n) return '0 B';
    const units = ['B','KB','MB','GB']; let i = 0;
    while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
    return (i === 0 ? Math.round(n) : n.toFixed(n >= 10 ? 1 : 2)) + ' ' + units[i];
  };

  function normalizeMediaPath(value) {
    let path = String(value ?? '').trim();
    if (!path) return '';
    if (/^(https?:|data:|blob:)/i.test(path)) return path;
    path = path.replace(/\\/g, '/');
    const uploadIndex = path.indexOf('/uploads/');
    if (uploadIndex > -1) path = path.slice(uploadIndex);
    if (path.startsWith('uploads/')) path = '/' + path;
    if (path && !path.startsWith('/')) path = '/' + path;
    return path;
  }

  function mediaUrl(value) {
    return normalizeMediaPath(value);
  }

  const monthKey = item => String(item.created_at || '').slice(0,7) || 'UNKNOWN';
  const monthLabel = key => {
    if (!/^\d{4}-\d{2}$/.test(key)) return 'UNKNOWN DATE';
    const d = new Date(key + '-01T00:00:00');
    return d.toLocaleDateString('en-US',{year:'numeric',month:'long'}).toUpperCase();
  };
  const original = item => mediaUrl(item?.file_path || '');
  const preview = item => mediaUrl(item?.engine?.variants?.square?.path || item?.file_path || '');

  function toast(kind, message, options = {}) {
    const text = String(message || '').trim();
    if (!text) return;
    let stack = document.getElementById('brvtal-toast-stack');
    if (!stack) {
      stack = document.createElement('div');
      stack.id = 'brvtal-toast-stack';
      stack.className = 'brvtal-toast-stack';
      document.body.appendChild(stack);
    }
    const node = document.createElement('div');
    node.className = 'brvtal-toast ' + (kind || 'info');
    node.setAttribute('role', kind === 'error' ? 'alert' : 'status');
    node.innerHTML = `<button type="button" aria-label="Close">×</button><strong>${esc(kind || 'info')}</strong><span>${esc(text)}</span>`;
    node.querySelector('button').addEventListener('click', () => node.remove());
    stack.appendChild(node);
    const ttl = Number(options.timeout || (kind === 'error' ? 0 : kind === 'processing' ? 1800 : 3600));
    if (ttl > 0) window.setTimeout(() => node.remove(), ttl);
  }

  function notify(kind, message, options = {}) {
    toast(kind, message, options);
  }

  function actionLabel(path, method) {
    const p = String(path || '').replace(/^\/+/, '');
    if (p.startsWith('auth')) return null;
    if (method === 'DELETE') return ['Deleting record…','Record deleted.','Could not delete record'];
    if (p.includes('lineup')) return ['Saving lineup…','Lineup saved.','Could not save lineup'];
    if (p.startsWith('settings')) return ['Saving setting…','Setting saved.','Could not save setting'];
    if (p.startsWith('events')) return ['Saving event…','Event saved.','Could not save event'];
    if (p.startsWith('artists')) return ['Saving artist…','Artist saved.','Could not save artist'];
    if (p.startsWith('sets')) return ['Saving set…','Set saved.','Could not save set'];
    if (p.startsWith('media')) return ['Saving media…','Media saved.','Could not save media'];
    if (p.startsWith('pages')) return ['Saving page…','Page saved.','Could not save page'];
    return ['Processing change…','Change saved.','Could not complete change'];
  }

  function installGlobalFeedback() {
    if (window.__BRVTAL_GLOBAL_FEEDBACK__) return;
    window.__BRVTAL_GLOBAL_FEEDBACK__ = true;
    window.BRVTALNotify = notify;

    const installReqPatch = () => {
      if (typeof window.req !== 'function' || window.req.__brvtalFeedback) return;
      const originalReq = window.req;
      const patched = async function(path, options = {}) {
        const method = String(options.method || 'GET').toUpperCase();
        const mutating = ['POST','PUT','PATCH','DELETE'].includes(method);
        const labels = mutating ? actionLabel(path, method) : null;
        if (labels) notify('processing', labels[0]);
        try {
          const result = await originalReq.apply(this, arguments);
          if (labels) notify('success', labels[1]);
          return result;
        } catch (error) {
          if (labels) notify('error', `${labels[2]} · ${error.message || error}`, {timeout:0});
          throw error;
        }
      };
      patched.__brvtalFeedback = true;
      window.req = patched;
    };

    const installShowPatch = () => {
      if (typeof window.show !== 'function' || window.show.__brvtalFeedback) return;
      const originalShow = window.show;
      const patchedShow = function(message) {
        notify('error', message, {timeout:0});
        return originalShow.apply(this, arguments);
      };
      patchedShow.__brvtalFeedback = true;
      window.show = patchedShow;
    };

    const installThumbPatch = () => {
      if (typeof window.thumb !== 'function' || window.thumb.__brvtalMedia) return;
      const patchedThumb = function(src, alt = '', big = false) {
        const url = mediaUrl(src);
        if (!url) return `<div class="thumbph${big ? ' lg' : ''}">IMG</div>`;
        return `<img class="thumb${big ? ' lg' : ''}" src="${esc(url)}" alt="${esc(alt || '')}" loading="lazy" onerror="window.BRVTALMediaLibrary&&window.BRVTALMediaLibrary.handleThumbError(this)">`;
      };
      patchedThumb.__brvtalMedia = true;
      window.thumb = patchedThumb;
    };

    installReqPatch(); installShowPatch(); installThumbPatch();
    window.setTimeout(() => { installReqPatch(); installShowPatch(); installThumbPatch(); }, 0);
    window.setTimeout(() => { installReqPatch(); installShowPatch(); installThumbPatch(); }, 250);
  }

  function handleThumbError(img) {
    const ph = document.createElement('div');
    ph.className = (img.className || 'thumb').replace(/\bthumb\b/, 'thumbph') || 'thumbph';
    ph.textContent = 'IMG';
    img.replaceWith(ph);
  }

  function handleImageError(img) {
    const fallback = img.dataset.fallback;
    if (fallback && img.src !== new URL(fallback, window.location.origin).href) {
      delete img.dataset.fallback;
      img.src = fallback;
      return;
    }
    const ph = document.createElement('span');
    ph.className = 'media-kind';
    ph.textContent = 'IMG';
    img.replaceWith(ph);
  }

  function imageMarkup(item, fit = 'cover') {
    const p = preview(item);
    const f = original(item);
    if (!p) return `<span class="media-kind">${esc(String(item?.type || 'FILE').toUpperCase())}</span>`;
    return `<img src="${esc(p)}" data-fallback="${esc(f)}" alt="${esc(item?.alt_text || item?.title || '')}" loading="lazy" style="object-fit:${fit}" onerror="window.BRVTALMediaLibrary&&window.BRVTALMediaLibrary.handleImageError(this)">`;
  }

  async function getCsrf() {
    if (store.csrf) return store.csrf;
    try {
      if (typeof csrf !== 'undefined' && csrf) { store.csrf = csrf; return store.csrf; }
    } catch (_) {}
    const r = await fetch('/api/auth',{credentials:'same-origin',cache:'no-store'});
    const j = await r.json();
    if (!r.ok || !j.authenticated || !j.csrf) throw new Error('AUTH_REQUIRED');
    store.csrf = j.csrf;
    return store.csrf;
  }

  async function request(url = '', options = {}) {
    const headers = {...(options.headers || {})};
    if (options.method && options.method !== 'GET') headers['X-CSRF-Token'] = await getCsrf();
    const r = await fetch(ENDPOINT + url,{credentials:'same-origin',cache:'no-store',...options,headers});
    const j = await r.json().catch(() => ({ok:false,error:'INVALID_RESPONSE'}));
    if (!r.ok || j.ok === false) {
      const error = new Error(j.error || ('HTTP_' + r.status));
      error.status = r.status; error.payload = j;
      throw error;
    }
    return j;
  }

  function status(message, kind = '') {
    const el = store.root?.querySelector('#media-status');
    if (el) {
      el.textContent = message || '';
      el.className = 'media-status' + (kind ? ' ' + kind : '');
    }
    if (kind === 'ok') notify('success', message);
    if (kind === 'err') notify('error', message, {timeout:0});
  }

  function card(item, active = false) {
    const visual = item.type === 'image' ? imageMarkup(item) : `<span class="media-kind">${esc(String(item.type || 'FILE').toUpperCase())}</span>`;
    const dims = item.dimensions ? `${item.dimensions.width}×${item.dimensions.height}` : '';
    const warning = Array.isArray(item.warnings) && item.warnings.length
      ? `<span class="media-warning">${esc(item.warnings.join(' · '))}</span>` : '';
    return `<button class="media-card${active ? ' active' : ''}" type="button" data-media-id="${Number(item.id)}">
      <span class="media-preview">${visual}</span>
      <span class="media-card-body"><span class="media-card-title">${esc(item.title || 'Untitled')}</span>
      <span class="media-card-meta">${esc(dims || item.mime_type || item.type || '')} · ${esc(bytes(item.file_size))}</span>${warning}</span>
    </button>`;
  }

  function filteredItems() {
    if (!store.root) return store.items;
    const q = (store.root.querySelector('#media-search')?.value || '').trim().toLowerCase();
    const type = store.root.querySelector('#media-type-filter')?.value || '';
    const month = store.root.querySelector('#media-month-filter')?.value || '';
    return store.items.filter(item => {
      if (type && item.type !== type) return false;
      if (month && monthKey(item) !== month) return false;
      if (!q) return true;
      return [item.title,item.file_path,item.alt_text,item.mime_type,item.type].join(' ').toLowerCase().includes(q);
    });
  }

  function populateMonths() {
    if (!store.root) return;
    const select = store.root.querySelector('#media-month-filter');
    if (!select) return;
    const previous = select.value;
    const months = [...new Set(store.items.map(monthKey))].sort((a, b) => b.localeCompare(a));
    select.innerHTML = '<option value="">ALL DATES</option>' + months.map(m => `<option value="${esc(m)}">${esc(monthLabel(m))}</option>`).join('');
    if (months.includes(previous)) select.value = previous;
  }

  function renderGrid() {
    if (!store.root) return;
    const grid = store.root.querySelector('#media-grid');
    const summary = store.root.querySelector('#media-summary');
    if (!grid) return;
    const items = filteredItems();
    if (summary) summary.textContent = `${items.length} OF ${store.items.length} ASSETS · ORIGINALS PRESERVED`;
    if (!items.length) {
      grid.innerHTML = '<div class="empty" style="grid-column:1/-1">No media matches this view.</div>';
      return;
    }
    const groups = new Map();
    items.forEach(item => {
      const key = monthKey(item);
      if (!groups.has(key)) groups.set(key,[]);
      groups.get(key).push(item);
    });
    grid.innerHTML = [...groups.entries()].map(([key,rows]) =>
      `<div class="media-month-heading">${esc(monthLabel(key))}<span>${rows.length}</span></div>` +
      rows.map(item => card(item, store.selected && Number(store.selected.id) === Number(item.id))).join('')
    ).join('');
    grid.querySelectorAll('[data-media-id]').forEach(btn => btn.addEventListener('click', () => select(Number(btn.dataset.mediaId), {reveal:true})));
  }

  function renderInspector(item) {
    if (!store.root) return;
    const box = store.root.querySelector('#media-inspector');
    if (!box) return;
    if (!item) { box.innerHTML = '<div class="media-inspector-empty">SELECT AN ASSET</div>'; return; }
    const visual = item.type === 'image' ? imageMarkup(item, 'contain') : `<span class="media-kind">${esc(String(item.type || 'FILE').toUpperCase())}</span>`;
    const dims = item.dimensions ? `${item.dimensions.width}×${item.dimensions.height}` : '—';
    const engine = item.type === 'image'
      ? (item.engine?.status === 'ready'
          ? `MEDIA ENGINE READY · ${Object.keys(item.engine.variants || {}).length} VARIANTS`
          : `ORIGINAL PRESERVED · ${esc(item.engine?.reason || 'NO GENERATED VARIANTS')}`)
      : 'ORIGINAL ASSET';
    const usage = Array.isArray(item.usage) ? item.usage : [];
    const usageHtml = usage.length
      ? usage.map(ref => `<div class="media-usage-item"><b>${esc(ref.resource)}</b> · ${esc(ref.title || ('#' + ref.id))}<div class="meta">${esc(ref.field)}</div></div>`).join('')
      : '<div class="media-usage-safe">NOT CURRENTLY REFERENCED · SAFE TO DELETE</div>';
    const focal = item.engine?.focal_point || {x:.5,y:.5};
    const variants = item.engine?.variants || {};
    const quality = item.quality || {grade:'unknown',contexts:{}};
    const contextPreviews = item.type === 'image' ? ['square','card','hero'].map(name => {
      const definition = quality.contexts?.[name] || {};
      const src = mediaUrl(variants[name]?.path || original(item));
      return `<div class="media-context ${definition.ready ? 'ready' : 'limited'}"><div class="media-context-image ${name}"><img src="${esc(src)}" alt="" style="object-position:${Number(focal.x)*100}% ${Number(focal.y)*100}%"></div><b>${esc(definition.label || name)}</b><span>${definition.ready ? 'READY' : `NEEDS ${definition.width || '?'}×${definition.height || '?'}`}</span></div>`;
    }).join('') : '';
    box.innerHTML = `<div class="media-inspector-preview">${visual}</div><div class="media-inspector-body">
      <h3>${esc(item.title || 'Untitled')}</h3><div class="media-inspector-path">${esc(original(item) || item.file_path)}</div>
      <div class="media-inspector-grid"><div class="media-fact"><span>TYPE</span><b>${esc(item.type)}</b></div><div class="media-fact"><span>SIZE</span><b>${esc(bytes(item.file_size))}</b></div><div class="media-fact"><span>DIMENSIONS</span><b>${esc(dims)}</b></div><div class="media-fact"><span>STATUS</span><b>${esc(item.status)}</b></div></div>
      <label for="media-edit-title">TITLE</label><input id="media-edit-title" value="${esc(item.title || '')}">
      <label for="media-edit-alt">ALT TEXT</label><input id="media-edit-alt" value="${esc(item.alt_text || '')}">
      <label for="media-edit-status">PUBLICATION</label><select id="media-edit-status"><option value="published" ${item.status==='published'?'selected':''}>PUBLISHED</option><option value="draft" ${item.status==='draft'?'selected':''}>DRAFT</option></select>
      <div class="media-engine">${engine}</div>
      ${item.type === 'image' ? `<div class="media-crop"><div class="media-crop-head"><b>FOCAL POINT / CROP</b><span>QUALITY ${esc(String(quality.grade).toUpperCase())}</span></div><div class="media-contexts">${contextPreviews}</div><label for="media-focal-x">HORIZONTAL FOCUS <output id="media-focal-x-value">${Math.round(Number(focal.x)*100)}%</output></label><input id="media-focal-x" type="range" min="0" max="100" value="${Math.round(Number(focal.x)*100)}"><label for="media-focal-y">VERTICAL FOCUS <output id="media-focal-y-value">${Math.round(Number(focal.y)*100)}%</output></label><input id="media-focal-y" type="range" min="0" max="100" value="${Math.round(Number(focal.y)*100)}"><button class="btn red" id="media-transform" type="button">SAVE FOCUS + REGENERATE</button><p>The original stays untouched. Crops are regenerated for each delivery context.</p></div>` : ''}
      <div class="media-usage"><h4>USED BY / ${usage.length}</h4>${usageHtml}</div>
      <div class="media-inspector-actions"><button class="btn" id="media-save" type="button">SAVE</button><button class="btn ghost" id="media-copy" type="button">COPY PATH</button><button class="btn ghost" id="media-delete" type="button" ${usage.length?'disabled title="This asset is in use"':''}>DELETE</button></div>
    </div>`;
    box.querySelector('#media-save')?.addEventListener('click', saveSelected);
    ['x','y'].forEach(axis => box.querySelector('#media-focal-' + axis)?.addEventListener('input', updateCropPreview));
    box.querySelector('#media-transform')?.addEventListener('click', transformSelected);
    box.querySelector('#media-copy')?.addEventListener('click', async () => {
      try { await navigator.clipboard.writeText(original(item) || item.file_path || ''); status('Media path copied.', 'ok'); } catch (_) { status('Could not copy media path.', 'err'); }
    });
    box.querySelector('#media-delete')?.addEventListener('click', deleteSelected);
  }

  function updateCropPreview() {
    if (!store.root) return;
    const x = Number(store.root.querySelector('#media-focal-x')?.value || 50);
    const y = Number(store.root.querySelector('#media-focal-y')?.value || 50);
    const xo = store.root.querySelector('#media-focal-x-value'); const yo = store.root.querySelector('#media-focal-y-value');
    if (xo) xo.value = x + '%'; if (yo) yo.value = y + '%';
    store.root.querySelectorAll('.media-context-image img').forEach(img => { img.style.objectPosition = `${x}% ${y}%`; });
  }

  async function transformSelected() {
    if (!store.selected || !store.root) return;
    const x = Number(store.root.querySelector('#media-focal-x')?.value || 50) / 100;
    const y = Number(store.root.querySelector('#media-focal-y')?.value || 50) / 100;
    try {
      status('Regenerating context crops…');
      const j = await request('?action=transform&id=' + encodeURIComponent(store.selected.id), {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({x,y})});
      store.selected = j.data; renderInspector(store.selected); renderGrid(); status('Focal point saved and variants regenerated.', 'ok');
    } catch (e) { status('Could not regenerate variants: ' + e.message, 'err'); }
  }

  async function select(id, options = {}) {
    const loadId = ++selectionLoadId;
    selectionController?.abort();
    const controller = new AbortController();
    selectionController = controller;
    try {
      status('Loading asset…');
      const j = await request('?action=detail&id=' + encodeURIComponent(id), {signal:controller.signal});
      if (loadId !== selectionLoadId) return;
      store.selected = j.data;
      renderGrid(); renderInspector(store.selected); status('');
      if (options.reveal && window.matchMedia('(max-width: 1050px)').matches) {
        store.root?.querySelector('#media-inspector')?.scrollIntoView({behavior:'auto',block:'start'});
      }
    } catch (e) {
      if (e?.name === 'AbortError' || loadId !== selectionLoadId) return;
      status('Unable to load asset: ' + e.message, 'err');
    } finally {
      if (loadId === selectionLoadId) selectionController = null;
    }
  }

  async function refresh(selectId = null) {
    if (store.loading) return;
    store.loading = true;
    try {
      status('Loading media library…');
      const j = await request('?action=list');
      store.items = Array.isArray(j.data) ? j.data : [];
      store.engine = j.engine || null;
      populateMonths(); renderGrid();
      if (selectId) await select(selectId); else if (store.selected) {
        const still = store.items.some(x => Number(x.id) === Number(store.selected.id));
        if (still) await select(store.selected.id); else { store.selected = null; renderInspector(null); }
      }
      status(store.engine?.gd && store.engine?.webp ? 'MEDIA ENGINE ONLINE' : 'MEDIA ENGINE: ORIGINALS ONLY ON THIS PHP RUNTIME', store.engine?.gd && store.engine?.webp ? 'ok' : '');
    } catch (e) { status('Media library failed: ' + e.message, 'err'); }
    finally { store.loading = false; }
  }

  async function uploadFiles(files) {
    const list = [...(files || [])];
    if (!list.length) return;
    for (const file of list) {
      try {
        notify('processing', 'Uploading ' + file.name + '…');
        status('Uploading ' + file.name + '…');
        const fd = new FormData(); fd.append('file',file); fd.append('title',file.name.replace(/\.[^.]+$/,''));
        const j = await request('?action=upload',{method:'POST',body:fd});
        await refresh(j.data?.id || null);
        status('Uploaded ' + file.name + '.', 'ok');
      } catch (e) { status('Upload failed: ' + (e.payload?.error || e.message), 'err'); }
    }
  }

  async function saveSelected() {
    if (!store.selected || !store.root) return;
    const payload = {
      title: store.root.querySelector('#media-edit-title')?.value || '',
      alt_text: store.root.querySelector('#media-edit-alt')?.value || '',
      status: store.root.querySelector('#media-edit-status')?.value || 'published'
    };
    try {
      notify('processing', 'Saving media metadata…');
      status('Saving media metadata…');
      const j = await request('?action=update&id=' + encodeURIComponent(store.selected.id),{method:'PUT',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
      store.selected = j.data;
      const idx = store.items.findIndex(x => Number(x.id) === Number(j.data.id));
      if (idx >= 0) store.items[idx] = {...store.items[idx],...j.data};
      renderGrid(); renderInspector(store.selected); status('Media metadata saved.', 'ok');
    } catch (e) { status('Could not save media: ' + e.message, 'err'); }
  }

  async function deleteSelected() {
    if (!store.selected) return;
    if (!confirm('Delete this media asset and its generated local variants?')) return;
    try {
      notify('processing', 'Checking references and deleting media…');
      status('Checking references and deleting…');
      await request('?id=' + encodeURIComponent(store.selected.id),{method:'DELETE'});
      const old = store.selected.id; store.selected = null;
      store.items = store.items.filter(x => Number(x.id) !== Number(old));
      renderGrid(); renderInspector(null); status('Media deleted.', 'ok');
    } catch (e) {
      if (e.status === 409 && e.payload?.usage) {
        store.selected = {...store.selected,usage:e.payload.usage,usage_count:e.payload.usage_count}; renderInspector(store.selected);
        status('Delete blocked: this media is currently in use.', 'err');
      } else status('Could not delete media: ' + e.message, 'err');
    }
  }

  function externalRegistrationModal() {
    const overlay = document.createElement('div'); overlay.className = 'brvtal-media-picker';
    overlay.innerHTML = `<div class="brvtal-media-picker-box"><div class="brvtal-media-picker-head"><h3>REGISTER EXTERNAL MEDIA</h3><button class="btn ghost" type="button" data-close>CLOSE</button></div>
      <form class="media-register-form"><label>TITLE<input name="title" required></label><label>FILE URL / UPLOAD PATH<input name="file_path" placeholder="https://… or /uploads/…" required></label><label>TYPE<select name="type"><option>image</option><option>video</option><option>audio</option><option>document</option></select></label><label>MIME TYPE<input name="mime_type" placeholder="image/jpeg"></label><label>ALT TEXT<input name="alt_text"></label><div class="media-register-actions"><button class="btn red" type="submit">REGISTER</button></div></form></div>`;
    document.body.appendChild(overlay);
    const close = () => overlay.remove(); overlay.querySelector('[data-close]').onclick = close;
    overlay.addEventListener('click',e => { if (e.target === overlay) close(); });
    overlay.querySelector('form').addEventListener('submit', async e => {
      e.preventDefault(); const data = Object.fromEntries(new FormData(e.currentTarget).entries());
      data.file_path = normalizeMediaPath(data.file_path);
      try {
        notify('processing', 'Registering external media…');
        const j = await request('?action=register',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)});
        close(); await refresh(j.data?.id || null); status('External media registered.', 'ok');
      }
      catch (err) { status('Could not register media: ' + err.message, 'err'); }
    });
  }

  function updateInputPreview(input, item) {
    const holder = input.closest('.thumbcell') || input.parentElement;
    if (!holder) return;
    const url = original(item) || preview(item);
    if (!url) return;
    let img = holder.querySelector('img');
    if (!img) {
      const old = holder.querySelector('.thumbph,.avatar');
      img = document.createElement('img');
      img.className = old?.className?.replace('thumbph','thumb') || 'thumb';
      if (old) old.replaceWith(img); else holder.insertBefore(img, holder.firstChild);
    }
    img.src = url;
    img.alt = item.alt_text || item.title || '';
    img.loading = 'lazy';
    img.onerror = () => handleThumbError(img);
  }

  async function openPicker(input, options = {}) {
    let items = store.items;
    if (!items.length) {
      try { const j = await request('?action=list'); items = Array.isArray(j.data) ? j.data : []; store.items = items; }
      catch (e) { notify('error', 'Could not load Media Library.', {timeout:0}); return; }
    }
    if (options.imagesOnly !== false) items = items.filter(x => x.type === 'image');
    const overlay = document.createElement('div'); overlay.className = 'brvtal-media-picker';
    overlay.innerHTML = `<div class="brvtal-media-picker-box"><div class="brvtal-media-picker-head"><div><h3>SELECT MEDIA</h3><div class="meta">Reusable asset library</div></div><button class="btn ghost" type="button" data-close>CLOSE</button></div><input class="search" data-search placeholder="Search media…" style="margin-bottom:12px;width:100%"><div class="brvtal-media-picker-grid"></div></div>`;
    document.body.appendChild(overlay);
    const grid = overlay.querySelector('.brvtal-media-picker-grid');
    const draw = q => {
      const term = (q || '').toLowerCase(); const rows = items.filter(x => !term || [x.title,x.file_path,x.alt_text].join(' ').toLowerCase().includes(term));
      grid.innerHTML = rows.map(x => card(x,false)).join('') || '<div class="empty">No assets found.</div>';
      grid.querySelectorAll('[data-media-id]').forEach(btn => btn.onclick = () => {
        const item = rows.find(x => Number(x.id) === Number(btn.dataset.mediaId)); if (!item) return;
        input.value = original(item) || mediaUrl(item.file_path) || '';
        input.dispatchEvent(new Event('input',{bubbles:true})); input.dispatchEvent(new Event('change',{bubbles:true}));
        updateInputPreview(input, item);
        overlay.remove();
        notify('warning', 'Media selected. Press SAVE to persist this record.', {timeout:5200});
      });
    };
    draw(''); overlay.querySelector('[data-search]').oninput = e => draw(e.target.value);
    overlay.querySelector('[data-close]').onclick = () => overlay.remove();
    overlay.addEventListener('click',e => { if (e.target === overlay) overlay.remove(); });
  }

  function decoratePickerInputs(root = document) {
    root.querySelectorAll('#f_cover_image,#f_photo,#e_cover_image,#e_ticket_qr').forEach(input => {
      if (input.dataset.mediaPickerBound === '1') return;
      input.dataset.mediaPickerBound = '1';
      input.value = normalizeMediaPath(input.value);
      const button = document.createElement('button'); button.type = 'button'; button.className = 'media-picker-btn'; button.textContent = 'SELECT MEDIA';
      button.addEventListener('click',() => openPicker(input,{imagesOnly:true}));
      input.after(button);
    });
  }

  function startPickerObserver() {
    if (pickerObserver) return;
    pickerObserver = new MutationObserver(mutations => {
      mutations.forEach(m => m.addedNodes.forEach(node => { if (node.nodeType === 1) decoratePickerInputs(node); }));
      decoratePickerInputs(document);
    });
    pickerObserver.observe(document.documentElement,{childList:true,subtree:true});
    decoratePickerInputs(document);
  }

  function mount(root) {
    store.root = root; store.selected = null;
    const search = root.querySelector('#media-search'); const type = root.querySelector('#media-type-filter'); const month = root.querySelector('#media-month-filter');
    search?.addEventListener('input',renderGrid); type?.addEventListener('change',renderGrid); month?.addEventListener('change',renderGrid);
    const input = root.querySelector('#media-file');
    root.querySelector('#media-upload')?.addEventListener('click',() => input?.click());
    input?.addEventListener('change',() => { uploadFiles(input.files); input.value=''; });
    root.querySelector('#media-register')?.addEventListener('click',externalRegistrationModal);
    const dz = root.querySelector('#media-dropzone');
    ['dragenter','dragover'].forEach(name => dz?.addEventListener(name,e => { e.preventDefault(); dz.classList.add('drag'); }));
    ['dragleave','drop'].forEach(name => dz?.addEventListener(name,e => { e.preventDefault(); dz.classList.remove('drag'); }));
    dz?.addEventListener('drop',e => uploadFiles(e.dataTransfer?.files));
    dz?.addEventListener('keydown',e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input?.click(); } });
    startPickerObserver(); refresh();
  }

  installGlobalFeedback();
  startPickerObserver();
  return {mount,refresh,openPicker,decoratePickerInputs,notify,mediaUrl,handleImageError,handleThumbError};
})();
