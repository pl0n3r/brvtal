(() => {
  'use strict';

  const SEO_ENDPOINT = '/api/seo-metadata.php';
  const nativeFetch = window.fetch.bind(window);
  let csrfCache = '';
  let decorateTimer = null;

  function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
  }

  function ensureStyle() {
    if (document.getElementById('brvtal-seo-metadata-style')) return;
    const style = document.createElement('style');
    style.id = 'brvtal-seo-metadata-style';
    style.textContent = `
      .brvtal-seo-section{margin-top:18px;border-top:1px solid #2b3034;padding-top:16px}
      .brvtal-seo-section .seo-kicker{font:800 8px/1.2 monospace;letter-spacing:1.6px;color:#7f878d;margin-bottom:5px}
      .brvtal-seo-section .seo-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;margin-bottom:12px}
      .brvtal-seo-section .seo-head strong{font-size:13px;letter-spacing:.02em}
      .brvtal-seo-section .seo-help{font-size:9px;line-height:1.45;color:#747c82;max-width:520px}
      .brvtal-seo-grid{display:grid;grid-template-columns:1fr;gap:10px}
      .brvtal-seo-grid label{display:block;font:800 8px/1.2 monospace;letter-spacing:1.2px;color:#858d93;margin-bottom:6px}
      .brvtal-seo-grid input,.brvtal-seo-grid textarea{box-sizing:border-box;width:100%;background:#050606;border:1px solid #34393e;color:#f4f5f6;padding:11px;font:11px/1.45 Arial,Helvetica,sans-serif}
      .brvtal-seo-grid textarea{min-height:78px;resize:vertical}
      .brvtal-seo-counter{display:block;text-align:right;font:700 8px/1 monospace;color:#737b82;margin-top:5px}
      .brvtal-seo-counter.warn{color:#ffd166}.brvtal-seo-counter.bad{color:#ff5566}
      .brvtal-search-preview{margin-top:12px;border:1px solid #252a2e;background:#070808;padding:13px;max-width:680px}
      .brvtal-search-preview .label{font:800 8px/1 monospace;letter-spacing:1.4px;color:#747c82;margin-bottom:10px}
      .brvtal-search-preview .url{font-size:10px;color:#49d98a;margin-bottom:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      .brvtal-search-preview .title{font-size:16px;color:#8ab4f8;margin-bottom:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      .brvtal-search-preview .description{font-size:10px;line-height:1.45;color:#b7bdc2;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
      @media(max-width:650px){.brvtal-seo-section .seo-head{display:block}.brvtal-seo-section .seo-help{margin-top:7px}}
    `;
    document.head.appendChild(style);
  }

  function normalizeTitle(value, fallback = '') {
    const text = String(value || '').trim();
    return text || String(fallback || '').trim();
  }

  function sectionMarkup(ids, values = {}, context = {}) {
    const title = values.seo_title || '';
    const description = values.seo_description || '';
    const slug = context.slug || '';
    const fallbackTitle = context.title || 'BRVTAL';
    return `<section class="brvtal-seo-section" data-seo-editor="${esc(context.resource || '')}">
      <div class="seo-kicker">SEO / SEARCH DISCOVERY</div>
      <div class="seo-head"><strong>SEARCH METADATA</strong><span class="seo-help">Optional. Leave blank to let the public frontend fall back to the content title and description. Saving never changes publication status.</span></div>
      <div class="brvtal-seo-grid">
        <div><label for="${esc(ids.title)}">SEO TITLE</label><input id="${esc(ids.title)}" maxlength="190" value="${esc(title)}" placeholder="${esc(fallbackTitle)}"><span class="brvtal-seo-counter" data-seo-count="title">${String(title).length}/60 recommended</span></div>
        <div><label for="${esc(ids.description)}">SEO DESCRIPTION</label><textarea id="${esc(ids.description)}" maxlength="320" placeholder="Short search-result description…">${esc(description)}</textarea><span class="brvtal-seo-counter" data-seo-count="description">${String(description).length}/160 recommended</span></div>
      </div>
      <div class="brvtal-search-preview"><div class="label">SEARCH PREVIEW</div><div class="url" data-seo-preview="url">brvtal.com.co/${esc(context.resource || 'content')}/${esc(slug || 'slug')}</div><div class="title" data-seo-preview="title">${esc(normalizeTitle(title, fallbackTitle))}</div><div class="description" data-seo-preview="description">${esc(description || 'Add a concise SEO description to preview how this content can appear in search results.')}</div></div>
    </section>`;
  }

  function bindPreview(section, context = {}) {
    if (!section || section.dataset.seoBound === '1') return;
    section.dataset.seoBound = '1';
    const titleInput = section.querySelector('input[id$="seo_title"]');
    const descriptionInput = section.querySelector('textarea[id$="seo_description"]');
    const titlePreview = section.querySelector('[data-seo-preview="title"]');
    const descriptionPreview = section.querySelector('[data-seo-preview="description"]');
    const titleCounter = section.querySelector('[data-seo-count="title"]');
    const descriptionCounter = section.querySelector('[data-seo-count="description"]');
    const update = () => {
      const title = titleInput?.value?.trim() || context.title || 'BRVTAL';
      const description = descriptionInput?.value?.trim() || 'Add a concise SEO description to preview how this content can appear in search results.';
      if (titlePreview) titlePreview.textContent = title;
      if (descriptionPreview) descriptionPreview.textContent = description;
      if (titleCounter) {
        const n = titleInput?.value?.length || 0;
        titleCounter.textContent = `${n}/60 recommended`;
        titleCounter.className = 'brvtal-seo-counter' + (n > 70 ? ' bad' : n > 60 ? ' warn' : '');
      }
      if (descriptionCounter) {
        const n = descriptionInput?.value?.length || 0;
        descriptionCounter.textContent = `${n}/160 recommended`;
        descriptionCounter.className = 'brvtal-seo-counter' + (n > 180 ? ' bad' : n > 160 ? ' warn' : '');
      }
    };
    titleInput?.addEventListener('input', update);
    descriptionInput?.addEventListener('input', update);
    update();
  }

  function legacyRecord(resource) {
    try {
      if (typeof state === 'undefined') return null;
      const id = Number(state.editing || 0);
      if (!id || !Array.isArray(state.rows)) return null;
      return state.rows.find(row => Number(row.id) === id) || null;
    } catch (_) { return null; }
  }

  function decorateLegacyModal() {
    const modal = document.getElementById('modal');
    const content = document.getElementById('mcontent');
    if (!modal?.classList.contains('open') || !content || content.querySelector('[data-seo-editor="legacy"]')) return;
    let resource = '';
    try { resource = ['events','artists','sets'].includes(state?.section) ? state.section : ''; } catch (_) {}
    if (!resource) return;
    const record = legacyRecord(resource) || {};
    const title = resource === 'artists' ? (document.getElementById('f_name')?.value || record.name || '') : (document.getElementById('f_title')?.value || record.title || '');
    const slug = document.getElementById('f_slug')?.value || record.slug || '';
    const wrapper = document.createElement('div');
    wrapper.innerHTML = sectionMarkup(
      {title:'f_seo_title',description:'f_seo_description'},
      record,
      {resource,title,slug}
    );
    const section = wrapper.firstElementChild;
    section.dataset.seoEditor = 'legacy';
    content.appendChild(section);
    bindPreview(section,{title});
  }

  async function fetchList(url) {
    const response = await nativeFetch(url,{credentials:'same-origin',cache:'no-store'});
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || payload?.ok === false) return [];
    return Array.isArray(payload.data) ? payload.data : [];
  }

  async function decorateContentCore() {
    const modal = document.getElementById('eventModal');
    const firstStep = modal?.querySelector('.step-content[data-content="1"]');
    if (!modal?.classList.contains('open') || !firstStep || firstStep.querySelector('[data-seo-editor="content-core"]')) return;
    const title = document.getElementById('e_title')?.value || '';
    const slug = document.getElementById('e_slug')?.value || '';
    let record = {};
    if (slug || title) {
      const rows = await fetchList('/api/index.php/events');
      record = rows.find(row => (slug && row.slug === slug) || (!slug && title && row.title === title)) || {};
    }
    const wrapper = document.createElement('div');
    wrapper.innerHTML = sectionMarkup(
      {title:'e_seo_title',description:'e_seo_description'},
      record,
      {resource:'events',title:title || record.title || '',slug:slug || record.slug || ''}
    );
    const section = wrapper.firstElementChild;
    section.dataset.seoEditor = 'content-core';
    firstStep.appendChild(section);
    bindPreview(section,{title:title || record.title || ''});
  }

  async function decorateRelease() {
    const titleInput = document.getElementById('release_title');
    const content = document.getElementById('mcontent');
    if (!titleInput || !content || content.querySelector('[data-seo-editor="release"]')) return;
    const title = titleInput.value || '';
    const slug = document.getElementById('release_slug')?.value || '';
    let record = {};
    if (slug || title) {
      const rows = await fetchList('/api/releases.php');
      record = rows.find(row => (slug && row.slug === slug) || (!slug && title && row.title === title)) || {};
    }
    const form = content.querySelector('.form') || content;
    const wrapper = document.createElement('div');
    wrapper.innerHTML = sectionMarkup(
      {title:'release_seo_title',description:'release_seo_description'},
      record,
      {resource:'releases',title:title || record.title || '',slug:slug || record.slug || ''}
    );
    const section = wrapper.firstElementChild;
    section.dataset.seoEditor = 'release';
    form.appendChild(section);
    bindPreview(section,{title:title || record.title || ''});
  }

  function scheduleDecorate() {
    clearTimeout(decorateTimer);
    decorateTimer = setTimeout(() => {
      decorateLegacyModal();
      decorateContentCore().catch(() => {});
      decorateRelease().catch(() => {});
    }, 20);
  }

  function metadataFor(resource) {
    if (resource === 'releases') return {
      seo_title: document.getElementById('release_seo_title')?.value?.trim() || '',
      seo_description: document.getElementById('release_seo_description')?.value?.trim() || '',
    };
    if (resource === 'events' && document.getElementById('eventModal')?.classList.contains('open')) return {
      seo_title: document.getElementById('e_seo_title')?.value?.trim() || '',
      seo_description: document.getElementById('e_seo_description')?.value?.trim() || '',
    };
    return {
      seo_title: document.getElementById('f_seo_title')?.value?.trim() || '',
      seo_description: document.getElementById('f_seo_description')?.value?.trim() || '',
    };
  }

  function targetFor(url, method) {
    if (!['POST','PUT'].includes(method)) return null;
    if (url.pathname.endsWith('/api/releases.php')) return {resource:'releases',queryId:Number(url.searchParams.get('id') || 0)};
    const match = url.pathname.match(/\/api\/index\.php\/(events|artists|sets)(?:\/(\d+))?\/?$/);
    if (!match) return null;
    return {resource:match[1],queryId:Number(match[2] || 0)};
  }

  async function csrfToken(init = {}) {
    const headers = new Headers(init.headers || {});
    const supplied = headers.get('X-CSRF-Token');
    if (supplied) return supplied;
    if (csrfCache) return csrfCache;
    try { if (typeof csrf !== 'undefined' && csrf) { csrfCache = csrf; return csrfCache; } } catch (_) {}
    const r = await nativeFetch('/api/index.php/auth',{credentials:'same-origin',cache:'no-store'});
    const j = await r.json().catch(() => ({}));
    if (!r.ok || !j.authenticated || !j.csrf) throw new Error('AUTH_REQUIRED');
    csrfCache = j.csrf;
    return csrfCache;
  }

  async function persistMetadata(resource, id, metadata, init = {}) {
    if (!id) return;
    const token = await csrfToken(init);
    const response = await nativeFetch(`${SEO_ENDPOINT}?resource=${encodeURIComponent(resource)}&id=${encodeURIComponent(id)}`,{
      method:'PUT',
      credentials:'same-origin',
      cache:'no-store',
      headers:{'Content-Type':'application/json','X-CSRF-Token':token},
      body:JSON.stringify(metadata),
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || payload?.ok === false) throw new Error(payload?.error || ('HTTP_' + response.status));
  }

  window.fetch = async function(input, init = {}) {
    const request = input instanceof Request ? input : null;
    const method = String(init.method || request?.method || 'GET').toUpperCase();
    let url;
    try { url = new URL(request?.url || String(input), location.href); }
    catch (_) { return nativeFetch(input, init); }
    const target = url.origin === location.origin ? targetFor(url, method) : null;
    const metadata = target ? metadataFor(target.resource) : null;

    const response = await nativeFetch(input, init);
    if (!target || !response.ok) return response;

    let payload = {};
    try { payload = await response.clone().json(); } catch (_) {}
    if (payload?.ok === false) return response;
    const id = target.queryId || Number(payload?.id || payload?.data?.id || 0);
    if (!id) return response;

    try {
      await persistMetadata(target.resource, id, metadata, init);
      window.BRVTALFeedback?.success?.('SEO metadata saved.','seo-metadata');
    } catch (error) {
      window.BRVTALFeedback?.error?.('Content saved, but SEO metadata could not be saved: ' + (error?.message || error),'seo-metadata');
    }
    return response;
  };

  const observer = new MutationObserver(scheduleDecorate);
  observer.observe(document.documentElement,{childList:true,subtree:true,attributes:true,attributeFilter:['class']});
  ensureStyle();
  scheduleDecorate();
  window.BRVTALSEOMetadata = {decorate:scheduleDecorate,persist:persistMetadata};
})();
