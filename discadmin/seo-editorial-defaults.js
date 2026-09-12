(() => {
  'use strict';

  const nativeFetch = window.fetch.bind(window);
  const AUTO_DESCRIPTION_LIMIT = 160;

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

  window.BRVTALSEODefaults = { plainText, truncate, withDefaults, limit:AUTO_DESCRIPTION_LIMIT };
})();
