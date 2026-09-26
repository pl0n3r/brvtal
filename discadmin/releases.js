window.BRVTALReleases = (() => {
  'use strict';

  const endpoint = '/api/releases.php';
  const store = { root:null, releases:[], artists:[], loading:false };

  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
  const normalizeMediaPath = value => {
    const raw = String(value || '').trim();
    if (!raw || /^(?:data:|blob:|https?:\/\/)/i.test(raw) || raw.startsWith('/')) return raw;
    return '/' + raw.replace(/^\.?\//,'').replace(/^\/+/,'');
  };
  const slugify = value => String(value || '')
    .normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase()
    .replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'');

  function setStatus(message = '', kind = '') {
    const el = store.root?.querySelector('#release-status');
    if (!el) return;
    el.textContent = message;
    el.className = 'releases-status' + (kind ? ' ' + kind : '');
  }

  async function csrfToken() {
    if (window.BRVTALAdminAuthBoundary?.csrfToken) {
      return window.BRVTALAdminAuthBoundary.csrfToken();
    }
    try { if (typeof csrf !== 'undefined' && csrf) return csrf; } catch (_) {}
    throw new Error('AUTH_REQUIRED');
  }

  async function request(query = '', options = {}) {
    const opts = {...options,credentials:'same-origin',cache:'no-store'};
    const method = String(opts.method || 'GET').toUpperCase();
    opts.headers = {...(opts.headers || {})};
    if (['POST','PUT','PATCH','DELETE'].includes(method)) {
      opts.headers['X-CSRF-Token'] = await csrfToken();
    }
    const r = await fetch(endpoint + query, opts);
    const j = await r.json().catch(() => ({ok:false,error:'INVALID_RESPONSE'}));
    if (!r.ok || j.ok === false) {
      const error = new Error(j.error || ('HTTP_' + r.status));
      error.status = r.status;
      error.payload = j;
      throw error;
    }
    return j;
  }

  function artwork(record) {
    const src = normalizeMediaPath(record?.artwork || '');
    return src
      ? `<img class="release-art" src="${esc(src)}" alt="${esc(record?.title || 'Release artwork')}" loading="lazy">`
      : '<div class="release-art-ph">NO ART</div>';
  }

  function artistNames(record) {
    const names = (record?.artists || []).map(a => a.name).filter(Boolean);
    return names.length ? names.join(' · ') : 'NO ARTIST LINKED';
  }

  function visibleRows() {
    if (!store.root) return [];
    const q = (store.root.querySelector('#release-search')?.value || '').trim().toLowerCase();
    const status = store.root.querySelector('#release-status-filter')?.value || '';
    return store.releases.filter(release => {
      if (status && release.status !== status) return false;
      if (!q) return true;
      const haystack = [release.title,release.catalog_number,release.release_type,artistNames(release)].join(' ').toLowerCase();
      return haystack.includes(q);
    });
  }

  function renderMetrics() {
    if (!store.root) return;
    const total = store.releases.length;
    const published = store.releases.filter(x => x.status === 'published').length;
    const drafts = store.releases.filter(x => x.status === 'draft').length;
    const featured = store.releases.filter(x => Number(x.featured) === 1).length;
    const values = { 'release-total':total, 'release-published':published, 'release-drafts':drafts, 'release-featured':featured };
    Object.entries(values).forEach(([id,value]) => { const el=store.root.querySelector('#'+id); if(el) el.textContent=String(value); });
  }

  function orderingAvailable() {
    if (!store.root) return false;
    return !(store.root.querySelector('#release-search')?.value || '').trim()
      && !(store.root.querySelector('#release-status-filter')?.value || '');
  }

  function render() {
    if (!store.root) return;
    renderMetrics();
    const grid = store.root.querySelector('#release-grid');
    if (!grid) return;
    const rows = visibleRows();
    if (window.BRVTALDataGrid?.render?.('releases',grid,rows,{
      allRows:store.releases,
      orderingEnabled:orderingAvailable()
    })) return;
    if (!rows.length) {
      grid.innerHTML = '<div class="releases-empty">NO RELEASES MATCH THIS VIEW</div>';
      return;
    }
    grid.innerHTML = rows.map(release => {
      const date = release.release_date || 'DATE TBD';
      const catalog = release.catalog_number || 'NO CATALOG #';
      const type = String(release.release_type || 'single').toUpperCase();
      return `<article class="release-row" data-release-id="${Number(release.id)}">
        <div>${artwork(release)}</div>
        <div><div class="release-title">${esc(release.title)}</div><div class="release-meta">${esc(catalog)} · ${esc(type)}</div></div>
        <div class="release-artists">${esc(artistNames(release))}</div>
        <div class="release-date">${esc(date)}</div>
        <div class="release-actions"><button class="iconbtn" type="button" data-edit>EDIT</button><button class="iconbtn" type="button" data-delete>DELETE</button></div>
      </article>`;
    }).join('');
    grid.querySelectorAll('[data-release-id]').forEach(row => {
      const id = Number(row.dataset.releaseId);
      row.querySelector('[data-edit]')?.addEventListener('click',() => openEditor(id));
      row.querySelector('[data-delete]')?.addEventListener('click',() => remove(id));
    });
  }

  async function loadArtists() {
    const r = await fetch('/api/index.php/artists',{credentials:'same-origin',cache:'no-store'});
    const j = await r.json().catch(() => ({}));
    if (!r.ok || j.ok === false) throw new Error(j.error || 'ARTISTS_LOAD_FAILED');
    store.artists = Array.isArray(j.data) ? j.data : [];
  }

  async function refresh() {
    if (store.loading) return;
    store.loading = true;
    try {
      setStatus('Loading releases…');
      const [releaseResult] = await Promise.all([request(''), loadArtists()]);
      store.releases = Array.isArray(releaseResult.data) ? releaseResult.data : [];
      render();
      setStatus('CATALOG READY', 'ok');
    } catch (error) {
      if (error?.message === 'RELEASES_SCHEMA_MISSING') {
        const grid = store.root?.querySelector('#release-grid');
        if (grid) grid.innerHTML = '<div class="release-schema-note"><b>RELEASES DATABASE MIGRATION REQUIRED</b><br>Run database/migration_releases_01.sql before using this module.</div>';
        setStatus('RELEASES_SCHEMA_MISSING', 'err');
      } else {
        setStatus('Unable to load releases: ' + (error?.message || 'UNKNOWN_ERROR'), 'err');
      }
    } finally {
      store.loading = false;
    }
  }

  function input(id) { return document.getElementById(id); }
  function value(id) { return input(id)?.value?.trim?.() ?? ''; }

  function artistEditor(record) {
    const linked = new Map((record?.artists || []).map(a => [Number(a.artist_id), a]));
    if (!store.artists.length) return '<div class="helper">No artists available.</div>';
    return store.artists.map(artist => {
      const current = linked.get(Number(artist.id));
      return `<div class="release-artist-item">
        <input id="release_artist_${Number(artist.id)}" type="checkbox" data-release-artist="${Number(artist.id)}" aria-label="Link ${esc(artist.name)} to release" ${current?'checked':''}>
        <span><label for="release_artist_${Number(artist.id)}"><b>${esc(artist.name)}</b><span class="meta">${esc(artist.slug || '')}</span></label></span>
        <input type="text" data-release-role="${Number(artist.id)}" value="${esc(current?.role || 'Primary')}" placeholder="Role" aria-label="Role for ${esc(artist.name)}">
      </div>`;
    }).join('');
  }

  function openEditor(id = null) {
    const record = id ? store.releases.find(x => Number(x.id) === Number(id)) : null;
    const r = record || {};
    const modal = document.getElementById('modal');
    const content = document.getElementById('mcontent');
    const title = document.getElementById('mtitle');
    const notice = document.getElementById('notice');
    const saveButton = document.getElementById('saveBtn');
    if (!modal || !content || !title || !saveButton) return;

    title.textContent = id ? 'EDIT RELEASE' : 'NEW RELEASE';
    if (notice) notice.className = 'notice';
    content.innerHTML = `<div class="form">
      <div class="section"><div class="sectionhead"><strong>RELEASE DATA</strong><span class="helper">Label catalog metadata</span></div><div class="grid2">
        <div class="field"><label for="release_title">Title *</label><input id="release_title" value="${esc(r.title || '')}"></div>
        <div class="field"><label for="release_slug">Slug *</label><input id="release_slug" value="${esc(r.slug || '')}"></div>
        <div class="field"><label for="release_type">Type</label><select id="release_type"><option value="single" ${r.release_type==='single'||!r.release_type?'selected':''}>Single</option><option value="ep" ${r.release_type==='ep'?'selected':''}>EP</option><option value="album" ${r.release_type==='album'?'selected':''}>Album</option><option value="compilation" ${r.release_type==='compilation'?'selected':''}>Compilation</option><option value="other" ${r.release_type==='other'?'selected':''}>Other</option></select></div>
        <div class="field"><label for="release_catalog">Catalog number</label><input id="release_catalog" value="${esc(r.catalog_number || '')}" placeholder="BRVTAL001"></div>
        <div class="field"><label for="release_date">Release date</label><input id="release_date" type="date" value="${esc(r.release_date || '')}"></div>
        <div class="field"><label for="release_status_field">Status</label><select id="release_status_field"><option value="draft" ${r.status==='draft'||!r.status?'selected':''}>Draft</option><option value="published" ${r.status==='published'?'selected':''}>Published</option><option value="archived" ${r.status==='archived'?'selected':''}>Archived</option></select></div>
        <div class="field full"><label for="release_artwork">Artwork</label><div class="release-editor-artwork thumbcell">${r.artwork?`<img class="thumb lg" src="${esc(normalizeMediaPath(r.artwork))}" alt="${esc(r.title || 'Artwork')}">`:'<div class="thumb lg">NO IMAGE</div>'}<div><input id="release_artwork" value="${esc(normalizeMediaPath(r.artwork || ''))}"><button class="media-picker-btn" id="release-artwork-picker" type="button">SELECT MEDIA</button></div></div></div>
        <div class="field full"><label for="release_description">Description</label><textarea id="release_description">${esc(r.description || '')}</textarea></div>
        <label class="release-featured full"><input id="release_featured" type="checkbox" ${Number(r.featured)===1?'checked':''}><span><b>FEATURED RELEASE</b><span class="meta">Highlight this release in public surfaces.</span></span></label>
        <div class="helper full">Display order is managed visually from the Releases list.</div>
      </div></div>
      <div class="section"><div class="sectionhead"><strong>ARTISTS</strong><span class="helper">Link existing BRVTAL artist profiles</span></div><div class="release-artist-list">${artistEditor(r)}</div></div>
      <div class="section"><div class="sectionhead"><strong>PLATFORMS</strong><span class="helper">Public listening / purchase links</span></div><div class="release-platform-grid">
        <div class="field"><label for="release_spotify">Spotify</label><input id="release_spotify" value="${esc(r.spotify_url || '')}"></div>
        <div class="field"><label for="release_soundcloud">SoundCloud</label><input id="release_soundcloud" value="${esc(r.soundcloud_url || '')}"></div>
        <div class="field"><label for="release_bandcamp">Bandcamp</label><input id="release_bandcamp" value="${esc(r.bandcamp_url || '')}"></div>
        <div class="field"><label for="release_youtube">YouTube</label><input id="release_youtube" value="${esc(r.youtube_url || '')}"></div>
        <div class="field"><label for="release_beatport">Beatport</label><input id="release_beatport" value="${esc(r.beatport_url || '')}"></div>
      </div></div>
    </div>`;

    const titleInput = input('release_title');
    const slugInput = input('release_slug');
    let slugTouched = Boolean(r.slug);
    slugInput?.addEventListener('input',() => { slugTouched = true; });
    titleInput?.addEventListener('input',() => { if (!slugTouched && slugInput) slugInput.value = slugify(titleInput.value); });

    input('release_artwork')?.addEventListener('input',e => {
      const img = e.target.closest('.thumbcell')?.querySelector('img');
      if (img) img.src = normalizeMediaPath(e.target.value);
    });
    document.getElementById('release-artwork-picker')?.addEventListener('click',() => {
      const artworkInput = input('release_artwork');
      if (artworkInput && window.BRVTALMediaLibrary?.openPicker) {
        window.BRVTALMediaLibrary.openPicker(artworkInput,{imagesOnly:true});
      }
    });

    saveButton.onclick = () => save(id);
    window.BRVTALPublicPreview?.bindButton(
      document.getElementById('previewBtn'),
      'releases',
      () => {
        const current = id ? store.releases.find(item => Number(item.id) === Number(id)) : null;
        const sortOrder = current
          ? Number(current.sort_order || 0)
          : store.releases.reduce((max,item) => Math.max(max,Number(item.sort_order ?? -1)), -1) + 1;
        return {id:Number(id||0),...payload(sortOrder)};
      }
    );
    modal.classList.add('open');
    void window.BRVTALLegacyDrafts?.bind?.('releases',id,r);
  }

  function payload(sortOrder = 0) {
    const artists = [...document.querySelectorAll('[data-release-artist]:checked')].map((checkbox,index) => {
      const artistId = Number(checkbox.dataset.releaseArtist);
      return {
        artist_id: artistId,
        role: document.querySelector(`[data-release-role="${artistId}"]`)?.value?.trim() || 'Primary',
        sort_order: index,
      };
    });
    return {
      title:value('release_title'),
      slug:value('release_slug'),
      release_type:value('release_type'),
      catalog_number:value('release_catalog'),
      release_date:value('release_date'),
      description:value('release_description'),
      artwork:normalizeMediaPath(value('release_artwork')),
      spotify_url:value('release_spotify'),
      soundcloud_url:value('release_soundcloud'),
      bandcamp_url:value('release_bandcamp'),
      youtube_url:value('release_youtube'),
      beatport_url:value('release_beatport'),
      status:value('release_status_field') || 'draft',
      featured:input('release_featured')?.checked ? 1 : 0,
      sort_order:Number(sortOrder),
      artists,
    };
  }

  function releaseSortOrder(id) {
    const current = id ? store.releases.find(record => Number(record.id) === Number(id)) : null;
    if (current) return Number(current.sort_order || 0);
    return store.releases.reduce(
      (max,record) => Math.max(max,Number(record.sort_order ?? -1)),
      -1
    ) + 1;
  }

  function releasePayloadForSave(id) {
    const data = payload(releaseSortOrder(id));
    if (!data.title) throw new Error('TITLE_REQUIRED');
    if (!data.slug) data.slug = slugify(data.title);
    return data;
  }

  function rememberSavedRelease(record) {
    const savedId = Number(record?.id || 0);
    if (!savedId) return;
    const index = store.releases.findIndex(item => Number(item.id) === savedId);
    if (index >= 0) store.releases[index] = record;
    else store.releases.push(record);
  }

  async function finishReleaseSave(id,data,result,button) {
    const draftState = await window.BRVTALLegacyDrafts?.serverSaved?.({
      type:'releases',
      id,
      payload:data,
      result
    });
    if (!draftState?.keepOpen) return false;

    rememberSavedRelease(result?.data);
    saveButtonForRelease(button,Number(draftState.id || id || 0));
    setStatus('Server save completed; newer edits remain in the local draft.', 'ok');
    return true;
  }

  async function save(id = null) {
    const button = document.getElementById('saveBtn');
    if (button) { button.disabled = true; button.textContent = 'SAVING…'; }
    try {
      const data = releasePayloadForSave(id);
      const result = await request(id ? '?id=' + encodeURIComponent(id) : '', {
        method:id ? 'PUT' : 'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify(data),
      });
      if (await finishReleaseSave(id,data,result,button)) return;
      if (typeof closeModal === 'function') closeModal(true);
      await refresh();
      setStatus('Release saved.', 'ok');
    } catch (error) {
      await window.BRVTALLegacyDrafts?.saveFailed?.({type:'releases',id});
      setStatus('Could not save release: ' + (error?.message || 'UNKNOWN_ERROR'), 'err');
      window.BRVTALFeedback?.error?.((error?.message || 'Release save failed').replace(/_/g,' '),'release-save');
    } finally {
      if (button?.isConnected) { button.disabled = false; button.textContent = 'GUARDAR'; }
    }
  }

  function saveButtonForRelease(button, id) {
    if (!button?.isConnected) return;
    button.onclick = () => save(id);
  }

  async function remove(id) {
    const release = store.releases.find(x => Number(x.id) === Number(id));
    if (!confirm(`Delete release “${release?.title || id}”?`)) return;
    try {
      await request('?id=' + encodeURIComponent(id),{method:'DELETE'});
      store.releases = store.releases.filter(x => Number(x.id) !== Number(id));
      render();
      setStatus('Release deleted.', 'ok');
    } catch (error) {
      setStatus('Could not delete release: ' + (error?.message || 'UNKNOWN_ERROR'), 'err');
    }
  }

  window.addEventListener('brvtal:seo-partial-resolved', event => {
    if (event.detail?.resource !== 'releases') return;
    if (!document.getElementById('release_title')) return;
    if (typeof closeModal === 'function') closeModal(true);
    refresh()
      .then(() => setStatus('Release saved.', 'ok'))
      .catch(error => setStatus('SEO recovered, but releases could not refresh: ' + (error?.message || 'UNKNOWN_ERROR'), 'err'));
  });

  window.addEventListener('brvtal:content-order-changed', event => {
    if (event.detail?.resource !== 'releases' || !Array.isArray(event.detail.ids)) return;
    store.releases = window.BRVTALContentOrdering?.applyOrder?.(store.releases,event.detail.ids) || store.releases;
  });

  function mount(root) {
    store.root = root;
    root.querySelector('#release-new')?.addEventListener('click',() => openEditor(null));
    root.querySelector('#release-search')?.addEventListener('input',render);
    root.querySelector('#release-status-filter')?.addEventListener('change',render);
    refresh();
  }

  return { mount, refresh, openEditor, save, remove, payload, normalizeMediaPath };
})();
