(() => {
  'use strict';

  const nativeFetch = window.fetch.bind(window);

  function seoOverrideValue(id) {
    const input = document.getElementById(id);
    const shared = window.BRVTALSEODefaults?.persistableValue;
    return typeof shared === 'function' ? shared(input) : String(input?.value || '').trim();
  }

  window.fetch = async function(input, init = {}) {
    const request = input instanceof Request ? input : null;
    let url;
    try { url = new URL(request?.url || String(input), location.href); }
    catch (_) { return nativeFetch(input, init); }

    const method = String(init.method || request?.method || 'GET').toUpperCase();
    if (url.origin !== location.origin || url.pathname !== '/api/event-workflow.php' || method !== 'POST' || typeof init.body !== 'string') {
      return nativeFetch(input, init);
    }

    try {
      const payload = JSON.parse(init.body);
      if (payload && typeof payload === 'object' && payload.event && typeof payload.event === 'object') {
        payload.event.seo_title = seoOverrideValue('e_seo_title');
        payload.event.seo_description = seoOverrideValue('e_seo_description');
        init = {...init, body:JSON.stringify(payload)};
      }
    } catch (_) {
      // Preserve the original request body; the server remains the validation authority.
    }

    return nativeFetch(input, init);
  };
})();
