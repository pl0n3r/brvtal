(() => {
  'use strict';

  const nativeFetch = window.fetch.bind(window);
  const AUTO_DESCRIPTION_LIMIT = 160;
  let dedupeTimer = null;

  function plainText(value) {
    if (value == null || typeof value === 'boolean') return '';
    if (typeof value === 'number') return String(value);
    if (Array.isArray(value)) return value.map(plainText).filter(Boolean).join(' ').replace(/\s+/g,' ').trim();
    if (typeof value === 'object') {
      const ignored = new Set(['id','type','slug','url','href','src','image','cover_image','photo','file_path','platform','status','locale','class','style']);
      return Object.entries(value)
        .filter(([key]) => !ignored.has(String(key).toLowerCase()))
        .map(([,item]) => plainText(item))
        .filter(Boolean)
        .join(' ')
        .replace(/\s+/g,' ')
        .trim();
    }

    const raw = String(value).trim();
    if (!raw) return '';
    try {
      const parsed = JSON.parse(raw);
      if (parsed && typeof parsed === 'object') return plainText(parsed);
    } catch (_) {}

    const holder = document.createElement('div');
    holder.innerHTML = raw;
    return String(holder.textContent || holder.innerText || '').replace(/\s+/g,' ').trim();
  }

  function truncate(value, limit) {
    const text = plainText(value);
    if (!text || text.length <= limit) return text;
    let cut = text.slice(0, limit).trimEnd();
    const boundary = cut.replace(/\s+\S*$/, '');
    if (boundary.length >= Math.floor(limit * 0.65)) cut = boundary;
    return cut.replace(/[\s,.;:-]+$/g,'');
  }

  function withDefaults(payload, kind) {
    const next = {...payload};
    const title = String(next.title || '').trim();
    if (!String(next.seo_title || '').trim() && title) next.seo_title = truncate(title, 190);

    if (!String(next.seo_description || '').trim()) {
      const source = kind === 'blog'
        ? (String(next.excerpt || '').trim() || next.body || '')
        : (next.content_json || '');
      const description = truncate(source, AUTO_DESCRIPTION_LIMIT);
      if (description) next.seo_description = description;
    }
    return next;
  }

  function targetKind(url, method) {
    if (!['POST','PUT'].includes(method) || url.origin !== location.origin) return '';
    if (url.pathname.endsWith('/api/blog.php')) return 'blog';
    if (/\/api\/index\.php\/pages(?:\/\d+)?\/?$/.test(url.pathname)) return 'page';
    return '';
  }

  function editorConfig(section) {
    const editor = section?.dataset?.seoEditor || '';
    if (editor === 'content-core') {
      return {
        sourceTitle:document.getElementById('e_title'),
        sourceDescription:document.getElementById('e_description'),
        seoTitle:document.getElementById('e_seo_title'),
        seoDescription:document.getElementById('e_seo_description'),
      };
    }
    if (editor === 'release') {
      return {
        sourceTitle:document.getElementById('release_title'),
        sourceDescription:document.getElementById('release_description'),
        seoTitle:document.getElementById('release_seo_title'),
        seoDescription:document.getElementById('release_seo_description'),
      };
    }
    if (editor === 'legacy') {
      const artistMode = Boolean(document.getElementById('f_name'));
      return {
        sourceTitle:document.getElementById(artistMode ? 'f_name' : 'f_title'),
        sourceDescription:document.getElementById(artistMode ? 'f_bio' : 'f_description'),
        seoTitle:document.getElementById('f_seo_title'),
        seoDescription:document.getElementById('f_seo_description'),
      };
    }
    return null;
  }

  function autoValues(config) {
    return {
      title:truncate(config?.sourceTitle?.value || '', 190),
      description:truncate(config?.sourceDescription?.value || '', AUTO_DESCRIPTION_LIMIT),
    };
  }

  function ensureMode(input, automaticValue) {
    if (!input || input.dataset.seoMode) return;
    const current = String(input.value || '').trim();
    input.dataset.seoMode = current === '' || current === automaticValue ? 'auto' : 'manual';
  }

  function setAutomaticValue(input, value) {
    if (!input || input.dataset.seoMode !== 'auto' || input.value === value) return;
    input.value = value;
    input.dispatchEvent(new Event('input',{bubbles:true}));
  }

  function syncSeoSection(section) {
    if (!section?.isConnected) return;
    const config = editorConfig(section);
    if (!config?.seoTitle || !config?.seoDescription) return;
    const automatic = autoValues(config);
    ensureMode(config.seoTitle, automatic.title);
    ensureMode(config.seoDescription, automatic.description);
    setAutomaticValue(config.seoTitle, automatic.title);
    setAutomaticValue(config.seoDescription, automatic.description);
  }

  function syncSeoEditors() {
    document.querySelectorAll('[data-seo-editor]').forEach(syncSeoSection);
  }

  function dedupeSeoEditors() {
    ['legacy','content-core','release'].forEach(editor => {
      const sections = [...document.querySelectorAll(`[data-seo-editor="${editor}"]`)];
      sections.slice(1).forEach(section => section.remove());
    });
    syncSeoEditors();
  }

  function scheduleDedupe() {
    clearTimeout(dedupeTimer);
    dedupeTimer = setTimeout(dedupeSeoEditors, 0);
  }

  function isEditorialSource(target) {
    return target instanceof HTMLElement && [
      'e_title','e_description',
      'f_title','f_description','f_name','f_bio',
      'release_title','release_description',
    ].includes(target.id);
  }

  function handleSeoInput(event) {
    const target = event.target;
    if (!(target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement)) return;

    if (isEditorialSource(target)) {
      syncSeoEditors();
      return;
    }

    if (!event.isTrusted || !/(?:^|_)seo_(?:title|description)$/.test(target.id)) return;
    const section = target.closest('[data-seo-editor]');
    const config = editorConfig(section);
    if (!config) return;
    const automatic = autoValues(config);
    const isTitle = target === config.seoTitle;
    const fallback = isTitle ? automatic.title : automatic.description;
    if (String(target.value || '').trim() === '') {
      target.dataset.seoMode = 'auto';
      setAutomaticValue(target, fallback);
    } else {
      target.dataset.seoMode = 'manual';
    }
  }

  window.fetch = function(input, init = {}) {
    const request = input instanceof Request ? input : null;
    const method = String(init.method || request?.method || 'GET').toUpperCase();
    let url;
    try { url = new URL(request?.url || String(input), location.href); }
    catch (_) { return nativeFetch(input, init); }

    const kind = targetKind(url, method);
    if (!kind || typeof init.body !== 'string') return nativeFetch(input, init);

    let payload;
    try { payload = JSON.parse(init.body); }
    catch (_) { return nativeFetch(input, init); }
    if (!payload || typeof payload !== 'object' || Array.isArray(payload)) return nativeFetch(input, init);

    return nativeFetch(input, {...init, body:JSON.stringify(withDefaults(payload, kind))});
  };

  document.addEventListener('input',handleSeoInput,true);
  const observer = new MutationObserver(scheduleDedupe);
  observer.observe(document.documentElement,{childList:true,subtree:true});
  dedupeSeoEditors();

  window.BRVTALSEODefaults = {
    plainText,
    truncate,
    withDefaults,
    dedupe:dedupeSeoEditors,
    sync:syncSeoEditors,
    limit:AUTO_DESCRIPTION_LIMIT,
  };
})();
