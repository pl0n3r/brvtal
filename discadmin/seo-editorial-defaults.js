(() => {
  'use strict';

  const AUTO_DESCRIPTION_LIMIT = 160;
  const SEO_TRAILING_CHARS = new Set([' ', ',', '.', ';', ':', '-']);
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

  function trimSeoSuffix(value) {
    let end = value.length;
    while (end > 0 && SEO_TRAILING_CHARS.has(value[end - 1])) end -= 1;
    return value.slice(0, end);
  }

  function truncate(value, limit) {
    const text = plainText(value);
    if (!text || text.length <= limit) return text;
    let cut = text.slice(0, limit).trimEnd();
    const lastSpace = cut.lastIndexOf(' ');
    const boundary = lastSpace >= 0 ? cut.slice(0, lastSpace) : '';
    if (boundary.length >= Math.floor(limit * 0.65)) cut = boundary;
    return trimSeoSuffix(cut);
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

  function ensureMode(input) {
    if (!input || input.dataset.seoMode) return;
    input.dataset.seoMode = String(input.value || '').trim() === '' ? 'auto' : 'manual';
  }

  function setAutomaticFallback(input, value) {
    if (input?.dataset.seoMode !== 'auto') return;
    if (!Object.hasOwn(input.dataset,'seoOriginalPlaceholder')) {
      input.dataset.seoOriginalPlaceholder = input.getAttribute('placeholder') || '';
    }
    const fallback = String(value || '');
    const nextPlaceholder = fallback || input.dataset.seoOriginalPlaceholder || '';
    const changed = input.value !== ''
      || input.dataset.seoFallback !== fallback
      || input.getAttribute('placeholder') !== nextPlaceholder;
    input.value = '';
    input.dataset.seoFallback = fallback;
    input.setAttribute('placeholder',nextPlaceholder);
    if (changed) input.dispatchEvent(new Event('input',{bubbles:true}));
  }

  function persistableValue(input) {
    if (!input) return '';
    if (input.dataset.seoMode === 'auto') return '';
    return String(input.value || '').trim();
  }

  function effectiveValue(input, fallback = '') {
    const authored = String(input?.value || '').trim();
    if (authored) return authored;
    return String(input?.dataset?.seoFallback || fallback || '').trim();
  }

  function syncSeoSection(section) {
    if (!section?.isConnected) return;
    const config = editorConfig(section);
    if (!config?.seoTitle || !config?.seoDescription) return;
    const automatic = autoValues(config);
    ensureMode(config.seoTitle);
    ensureMode(config.seoDescription);
    setAutomaticFallback(config.seoTitle, automatic.title);
    setAutomaticFallback(config.seoDescription, automatic.description);
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
    if (String(target.value || '').trim() === '') {
      target.dataset.seoMode = 'auto';
      setAutomaticFallback(target, isTitle ? automatic.title : automatic.description);
    } else {
      target.dataset.seoMode = 'manual';
    }
  }

  document.addEventListener('input',handleSeoInput,true);
  const observer = new MutationObserver(scheduleDedupe);
  observer.observe(document.documentElement,{childList:true,subtree:true});
  dedupeSeoEditors();

  window.BRVTALSEODefaults = {
    plainText,
    truncate,
    persistableValue,
    effectiveValue,
    dedupe:dedupeSeoEditors,
    sync:syncSeoEditors,
    limit:AUTO_DESCRIPTION_LIMIT,
  };
})();
