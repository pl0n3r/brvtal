(() => {
  'use strict';

  const endpoint = '/api/public-preview.php';
  const previewableLegacyTypes = new Set(['events','artists','sets','pages']);

  const value = id => document.getElementById(id)?.value?.trim?.() ?? '';
  const numberValue = id => {
    const parsed = Number(value(id));
    return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
  };

  async function csrfToken() {
    try {
      if (typeof csrf !== 'undefined' && csrf) return csrf;
    } catch (_) {}
    const response = await fetch('/api/index.php/auth', {
      credentials:'same-origin',
      cache:'no-store'
    });
    const json = await response.json().catch(() => ({}));
    if (!response.ok || !json.authenticated || !json.csrf) {
      throw new Error('AUTH_REQUIRED');
    }
    return json.csrf;
  }

  async function create(type, payload) {
    const response = await fetch(endpoint, {
      method:'POST',
      credentials:'same-origin',
      cache:'no-store',
      headers:{
        'Content-Type':'application/json',
        'X-CSRF-Token':await csrfToken()
      },
      body:JSON.stringify({type,payload})
    });
    const json = await response.json().catch(() => ({ok:false,error:'INVALID_RESPONSE'}));
    if (!response.ok || json.ok === false || !json.data?.url) {
      throw new Error(json.error || 'PREVIEW_FAILED');
    }
    return json.data;
  }

  function previewWindow() {
    const tab = window.open('about:blank', '_blank');
    if (!tab) return null;
    try { tab.opener = null; } catch (_) {}
    tab.document.write(
      '<!doctype html><title>BRVTAL Preview</title>'
      + '<body style="margin:0;background:#050505;color:#f4f1e8;'
      + 'font:14px/1.5 ui-monospace,monospace;display:grid;place-items:center;min-height:100vh">'
      + '<b>BUILDING PRIVATE PUBLIC PREVIEW…</b></body>'
    );
    return tab;
  }

  async function open(type, payload) {
    const tab = previewWindow();
    try {
      const data = await create(type, payload);
      if (tab) {
        tab.location.replace(data.url);
      } else {
        window.location.assign(data.url);
      }
      return data;
    } catch (error) {
      if (tab && !tab.closed) tab.close();
      window.BRVTALFeedback?.error?.(
        String(error?.message || 'PREVIEW_FAILED').replaceAll('_',' '),
        'public-preview'
      );
      throw error;
    }
  }

  function bindButton(button, type, provider) {
    if (!button) return;
    button.hidden = false;
    button.disabled = false;
    button.onclick = async () => {
      button.disabled = true;
      const original = button.textContent;
      button.textContent = 'BUILDING PREVIEW…';
      try {
        await open(type, provider());
      } finally {
        if (button.isConnected) {
          button.disabled = false;
          button.textContent = original;
        }
      }
    };
  }

  function hideButton(button) {
    if (!button) return;
    button.hidden = true;
    button.onclick = null;
  }

  function legacyPayload(type, record = {}) {
    const id = Number(record?.id || 0);
    if (type === 'events') {
      return {
        id,
        title:value('f_title'),
        slug:value('f_slug'),
        event_date:value('f_event_date').replace('T',' '),
        status:value('f_status') || 'draft',
        venue:value('f_venue'),
        city:value('f_city'),
        description:value('f_description'),
        cover_image:value('f_cover_image'),
        accent:value('f_accent'),
        ticket_url:value('f_ticket_url')
      };
    }
    if (type === 'artists') {
      return {
        id,
        name:value('f_name'),
        slug:value('f_slug'),
        bio:value('f_bio'),
        photo:value('f_photo'),
        status:value('f_status') || 'draft',
        instagram_url:value('f_instagram_url'),
        soundcloud_url:value('f_soundcloud_url'),
        website_url:value('f_website_url')
      };
    }
    if (type === 'sets') {
      return {
        id,
        title:value('f_title'),
        slug:value('f_slug'),
        platform:value('f_platform'),
        external_url:value('f_external_url'),
        embed_url:value('f_embed_url'),
        cover_image:value('f_cover_image'),
        artist_id:numberValue('f_artist_id'),
        event_id:numberValue('f_event_id'),
        status:value('f_status') || 'draft',
        description:value('f_description')
      };
    }
    if (type === 'pages') {
      return {
        id,
        title:value('f_title'),
        slug:value('f_slug'),
        locale:value('f_locale') || 'en',
        status:value('f_status') || 'draft',
        content_json:value('f_content_json'),
        seo_title:value('f_seo_title'),
        seo_description:value('f_seo_description')
      };
    }
    return {};
  }

  function bindLegacy(type, record = {}) {
    const button = document.getElementById('previewBtn');
    if (!previewableLegacyTypes.has(type)) {
      hideButton(button);
      return;
    }
    bindButton(button, type, () => legacyPayload(type, record));
  }

  window.BRVTALPublicPreview = {
    create,
    open,
    bindButton,
    bindLegacy,
    legacyPayload
  };
})();