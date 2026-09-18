(() => {
  'use strict';

  const V2 = { tab:'general', pickerTarget:null, lastFocus:null };
  const INDEXNOW_ENDPOINTS = [
    ['https://api.indexnow.org/indexnow','IndexNow Global'],
    ['https://indexnow.amazonbot.amazon/indexnow','Amazon'],
    ['https://www.bing.com/indexnow','Bing'],
    ['https://searchadvisor.naver.com/indexnow','Naver'],
    ['https://search.seznam.cz/indexnow','Seznam.cz'],
    ['https://yandex.com/indexnow','Yandex'],
    ['https://indexnow.yep.com/indexnow','Yep'],
  ];
  const legacyOpenSettingByKey = window.openSettingByKey;

  const esc = value => String(value ?? '')
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;').replace(/'/g,'&#039;');

  function row(key) {
    return (state.rows || []).find(item => String(item.setting_key || '') === key) || null;
  }

  function jsonValue(key) {
    const record = row(key);
    if (!record) return {};
    const raw = record.setting_value;
    if (raw && typeof raw === 'object') return { ...raw };
    try {
      const parsed = JSON.parse(String(raw || '{}'));
      return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
    } catch (_) {
      return {};
    }
  }

  function text(id,label,value,help='',type='text') {
    return `<label class="sv2-field"><span>${esc(label)}</span><input id="sv2_${esc(id)}" type="${esc(type)}" value="${esc(value || '')}" autocomplete="off">${help ? `<small>${esc(help)}</small>` : ''}</label>`;
  }

  function read(id) {
    return document.getElementById('sv2_' + id)?.value?.trim() || '';
  }

  function validHttpUrl(value) {
    if (!value) return true;
    try { return /^https?:$/.test(new URL(value, location.href).protocol); }
    catch (_) { return false; }
  }

  function settingSummary(key) {
    const record = row(key);
    if (!record) return 'NOT CONFIGURED';
    const value = String(record.setting_value ?? '').trim();
    if (!value) return 'CONFIGURED / EMPTY';
    if (Number(record.is_json) === 1) {
      try {
        const parsed = JSON.parse(value);
        return parsed && typeof parsed === 'object' ? `${Object.keys(parsed).length} FIELDS` : 'JSON';
      } catch (_) { return 'INVALID JSON'; }
    }
    return 'TEXT';
  }

  function tabs() {
    const defs = [
      ['general','GENERAL'],
      ['social','SOCIAL & CONTACT'],
      ['seo','SEO'],
      ['analytics','ANALYTICS & PRIVACY'],
      ['advanced','ADVANCED'],
    ];
    return defs.map(([id,label],index) => `<button type="button" class="${V2.tab===id?'active':''}" data-settings-tab="${id}"><span>0${index+1}</span>${label}</button>`).join('');
  }

  function generalPane() {
    const site = jsonValue('site');
    const locale = String(site.default_locale || 'es').toUpperCase();
    const locales = Array.isArray(site.available_locales) ? site.available_locales.map(x => String(x).toUpperCase()).join(' / ') : 'ES / EN';
    return `<section class="sv2-pane ${V2.tab==='general'?'active':''}" data-settings-pane="general">
      <header class="sv2-section-head"><div><span>01 / GENERAL</span><h3>Site identity</h3></div><p>Global identity values used by the public runtime. Visual treatment belongs in Theme Studio.</p></header>
      <div class="sv2-grid two">${text('site_name','Public site name',site.name || 'BRVTAL','Used by public name bindings and document fallback.')}${text('site_tagline','Tagline',site.tagline || 'RAVE TILL GRAVE','Global editorial tagline; its typography/placement remains theme-owned.')}</div>
      <div class="sv2-context-grid">
        <article><span>CANONICAL ORIGIN</span><strong>www.brvtal.com.co</strong><p>Environment-owned. Not editable here.</p></article>
        <article><span>LANGUAGE POLICY</span><strong>${esc(locale)} · ${esc(locales)}</strong><p>Stored values are visible for context, but public ES/EN behavior remains reserved for #212 and is not presented as a live control yet.</p></article>
        <article><span>STATUS</span><strong>LIVE</strong><p><code>settings.site</code> is consumed by the public runtime.</p></article>
      </div>
      <div class="sv2-actions"><button type="button" class="btn red" data-settings-save="general">SAVE GENERAL</button></div>
    </section>`;
  }

  function socialPane() {
    const social = jsonValue('social');
    return `<section class="sv2-pane ${V2.tab==='social'?'active':''}" data-settings-pane="social">
      <header class="sv2-section-head"><div><span>02 / SOCIAL & CONTACT</span><h3>Public destinations</h3></div><p>Only public HTTP/HTTPS destinations. Empty values hide optional links where supported.</p></header>
      <div class="sv2-grid two">
        ${text('instagram','Instagram',social.instagram || social.instagram_url || '','https://instagram.com/...','url')}
        ${text('soundcloud','SoundCloud',social.soundcloud || social.soundcloud_url || '','https://soundcloud.com/...','url')}
        ${text('youtube','YouTube',social.youtube || social.youtube_url || '','https://youtube.com/...','url')}
        ${text('spotify','Spotify',social.spotify || social.spotify_url || '','https://open.spotify.com/...','url')}
        ${text('website','Website / external hub',social.website || social.website_url || '','Optional public destination.','url')}
      </div>
      <div class="sv2-actions"><button type="button" class="btn red" data-settings-save="social">SAVE SOCIAL</button></div>
    </section>`;
  }

  function seoPane() {
    const seo = jsonValue('seo');
    const indexnow = jsonValue('indexnow');
    const share = seo.share_image || seo.og_image || '';
    const indexNowKey = String(indexnow.key || '').trim();
    const keyLocation = String(indexnow.key_location || '/indexnow-key.txt').trim();
    const endpoint = String(indexnow.endpoint || INDEXNOW_ENDPOINTS[0][0]).trim();
    const keyValid = /^[A-Za-z0-9-]{8,128}$/.test(indexNowKey);
    const keyLocationValid = /^\/[A-Za-z0-9][A-Za-z0-9-]{0,100}\.txt$/.test(keyLocation);
    const endpointValid = INDEXNOW_ENDPOINTS.some(([value]) => value === endpoint);
    const indexNowEnabled = Boolean(indexnow.enabled) && keyValid && keyLocationValid && endpointValid;
    const endpointOptions = INDEXNOW_ENDPOINTS.map(([value,label]) => {
      const selected = value === endpoint ? 'selected' : '';
      return `<option value="${esc(value)}" ${selected}>${esc(label)}</option>`;
    }).join('');
    const verificationUrl = `https://www.brvtal.com.co${keyLocationValid ? keyLocation : '/indexnow-key.txt'}`;

    return `<section class="sv2-pane ${V2.tab==='seo'?'active':''}" data-settings-pane="seo">
      <header class="sv2-section-head"><div><span>03 / SEO</span><h3>Canonical site defaults</h3></div><p>Server-owned defaults for public documents plus event-driven indexing controls. Entity-specific SEO fields remain authoritative for Events, Artists, Sets, Releases, Blog and Pages.</p></header>
      <div class="sv2-grid">${text('seo_title','Default Home title',seo.site_title || 'BRVTAL — Rave till Grave','Server-rendered Home title fallback.')}
        <label class="sv2-field"><span>Default Home description</span><textarea id="sv2_seo_description" maxlength="320">${esc(seo.description || 'BRVTAL — Rave till Grave. Underground electronic music, experiences and events from Colombia.')}</textarea><small>Used server-side for the Home meta/OG description.</small></label>
      </div>
      <div class="sv2-asset" data-settings-asset>
        <div><span>DEFAULT SHARE IMAGE</span><strong>OG / SOCIAL PREVIEW</strong><small>Choose an existing image from Media or enter a safe public path/URL.</small><code data-settings-asset-path>${esc(share || 'Not selected')}</code></div>
        <div class="sv2-asset-preview">${share ? `<img src="${esc(share)}" alt="Share image preview" loading="lazy">` : '<span>NO ASSET</span>'}</div>
        <input id="sv2_seo_share" value="${esc(share)}" placeholder="/uploads/... or https://...">
        <div class="sv2-asset-actions"><button type="button" class="btn ghost" data-settings-media-picker>CHOOSE FROM MEDIA</button><button type="button" class="btn ghost" data-settings-clear-asset>CLEAR</button></div>
      </div>
      <div class="sv2-integration">
        <div class="sv2-section-head"><div><span>INDEXNOW</span><h3>Search-engine change notifications</h3></div><p>Notify participating search engines only when public URLs are created, updated, unpublished or removed. No scheduled full-site resubmission.</p></div>
        <div class="sv2-grid two">
          <label class="sv2-field"><span>IndexNow</span><select id="sv2_indexnow_enabled"><option value="0" ${indexNowEnabled?'':'selected'}>DISABLED</option><option value="1" ${indexNowEnabled?'selected':''}>ENABLED</option></select><small>Enable after saving a valid IndexNow configuration.</small></label>
          ${text('indexnow_key','IndexNow key',indexNowKey,'Allowed: A–Z, a–z, 0–9 and hyphens; 8–128 characters.')}
          ${text('indexnow_key_location','Key location',keyLocation,'Root-level UTF-8 key file path, for example /indexnow-key.txt.')}
          <label class="sv2-field"><span>Submission endpoint</span><select id="sv2_indexnow_endpoint">${endpointOptions}</select><small>Official participating endpoint. Global IndexNow is the default.</small></label>
        </div>
        <div class="sv2-context-grid">
          <article><span>STATUS</span><strong>${indexNowEnabled?'CONNECTED':'NOT CONFIGURED'}</strong><p>Submissions are queued after successful editorial commits and never make a save fail.</p></article>
          <article><span>CANONICAL HOST</span><strong>www.brvtal.com.co</strong><p>Derived automatically and intentionally not editable.</p></article>
          <article><span>KEY VERIFICATION</span><strong>${esc(verificationUrl)}</strong><p>The response is generated dynamically; no live key is committed to Git.</p></article>
        </div>
        <div class="sv2-context-grid">
          <article><span>SUBMISSION POLICY</span><strong>EVENT-DRIVEN</strong><p>Changed canonical URLs are deduplicated per request; sitemap remains the full-site catch-up signal.</p></article>
          <article><span>URL LIST</span><strong>AUTOMATIC</strong><p>Only URLs affected by the successful public mutation are submitted.</p></article>
          <article><span>BATCH LIMIT</span><strong>10,000 URLS</strong><p>Protocol maximum; BRVTAL normally sends much smaller change sets.</p></article>
        </div>
        <div class="sv2-actions"><button type="button" class="btn red" data-settings-save="indexnow">SAVE INDEXNOW</button></div>
      </div>
      <div class="sv2-actions"><button type="button" class="btn red" data-settings-save="seo">SAVE SEO</button></div>
    </section>`;
  }

  function analyticsPane() {
    const analytics = jsonValue('analytics');
    const hasCanonicalGtm = Object.prototype.hasOwnProperty.call(analytics,'gtm_id');
    const legacyGtm = analytics.google_tag_manager || analytics.tag_manager || analytics.gtm || '';
    const gtmId = String(hasCanonicalGtm ? analytics.gtm_id : legacyGtm).trim().toUpperCase();
    const connected = /^GTM-[A-Z0-9]{4,20}$/.test(gtmId);
    return `<section class="sv2-pane ${V2.tab==='analytics'?'active':''}" data-settings-pane="analytics">
      <header class="sv2-section-head"><div><span>04 / ANALYTICS & PRIVACY</span><h3>Tag delivery</h3></div><p>Google Tag Manager is the single public tag-delivery layer and loads automatically on every public page.</p></header>
      <div class="sv2-grid two">${text('gtm_id','Google Tag Manager Container ID',gtmId,'Format: GTM-XXXXXXX. GA4, pixels and other optional tracking tags are managed inside GTM.')}
        <div class="sv2-readonly"><span>INTEGRATION STATUS</span><strong>${connected?'CONNECTED':'NOT CONFIGURED'}</strong><p>${connected?'The validated container is ready for immediate public loading.':'Save a valid GTM container ID to enable public tag delivery.'}</p></div>
      </div>
      <div class="sv2-context-grid"><article><span>LOAD POLICY</span><strong>IMMEDIATE / ALL PUBLIC PAGES</strong><p>BRVTAL requests GTM automatically. Analytics storage starts granted; advertising storage, user data and personalization remain denied by the BRVTAL bootstrap.</p></article><article><span>DIRECT GA4</span><strong>RETIRED</strong><p>BRVTAL no longer loads GA4 directly. Configure GA4 and other optional tags inside Google Tag Manager.</p></article><article><span>PUBLIC API</span><strong>PRIVATE CONFIG</strong><p>The GTM container setting is read server-side and is not included in <code>api/public.php</code>.</p></article></div>
      <div class="sv2-actions"><button type="button" class="btn red" data-settings-save="analytics">SAVE TAG MANAGER</button></div>
    </section>`;
  }

  function advancedPane(rows) {
    const safeRows = rows || [];
    const cards = safeRows.map(record => {
      const key = String(record.setting_key || '');
      const isTheme = key === 'theme.active' || key.startsWith('theme.');
      let role = 'ADVANCED';
      if (key === 'appearance') role = 'LEGACY';
      else if (isTheme) role = 'THEME STUDIO';
      else if (['site','social','seo','analytics','indexnow'].includes(key)) role = 'TYPED + RAW';
      let preview = String(record.setting_value ?? '');
      if (preview.length > 180) preview = preview.slice(0,177) + '…';
      const action = key === 'theme.active'
        ? '<button type="button" class="iconbtn" data-settings-theme-studio>OPEN THEME STUDIO</button>'
        : `<button type="button" class="iconbtn" data-settings-raw="${esc(key)}">RAW EDIT</button>`;
      return `<article class="sv2-raw-row"><div><span>${esc(role)}</span><strong>${esc(key)}</strong><code>${esc(preview || 'EMPTY')}</code></div>${action}</article>`;
    }).join('');
    return `<section class="sv2-pane ${V2.tab==='advanced'?'active':''}" data-settings-pane="advanced">
      <header class="sv2-section-head"><div><span>05 / ADVANCED</span><h3>Compatibility & raw records</h3></div><p>Escape hatch for recovery, unknown keys and legacy values. Primary configuration belongs in the typed screens or Theme Studio.</p></header>
      <div class="sv2-status-map">
        <article><span>LIVE / TYPED</span><strong>SITE · SOCIAL · SEO / INDEXNOW · ANALYTICS</strong><p>Normal editing happens through the dedicated controls above.</p></article>
        <article><span>THEME-OWNED</span><strong>THEME.*</strong><p>Use Theme Studio for public visual identity, palette, typography, navigation and effects.</p></article>
        <article><span>LEGACY</span><strong>APPEARANCE</strong><p>Preserved for compatibility. Theme Studio is the primary visual authority.</p></article>
      </div>
      <div class="sv2-advanced-head"><div><strong>RAW SETTINGS</strong><span>${safeRows.length} RECORDS</span></div><button type="button" class="btn ghost" data-settings-new-raw>+ NEW ADVANCED SETTING</button></div>
      <div class="sv2-raw-list">${cards || '<p class="sv2-empty">No settings records.</p>'}</div>
    </section>`;
  }

  function settingsScreen(rows) {
    const allRows = rows || [];
    return `<div class="settings-v2" data-settings-v2>
      <header class="sv2-hero"><div><span>BRVTAL CMS / CONFIGURATION</span><h2>SETTINGS</h2><p>Global behavior and integrations. Visual theme controls live in Theme Studio; raw JSON is an advanced escape hatch, not the normal workflow.</p></div><div class="sv2-hero-status"><span>CONTROL PLANE</span><strong>${allRows.length}</strong><small>CONFIG RECORDS</small></div></header>
      <div class="sv2-layout"><aside class="sv2-tabs" aria-label="Settings sections">${tabs()}</aside><div class="sv2-editor">${generalPane()}${socialPane()}${seoPane()}${analyticsPane()}${advancedPane(allRows)}</div></div>
    </div>`;
  }

  async function persistJson(key, patch, removeKeys = [], refresh = true) {
    const current = jsonValue(key);
    const next = { ...current, ...patch };
    removeKeys.forEach(removeKey => delete next[removeKey]);
    if (JSON.stringify(current) !== JSON.stringify(next)) {
      await req('/settings', {method:'POST', body:JSON.stringify({setting_key:key, setting_value:JSON.stringify(next), is_json:1})});
    }
    if (!refresh) return;
    const response = await req('/settings');
    state.rows = response.data || [];
    render();
    requestAnimationFrame(() => activate(V2.tab));
  }

  function feedback(kind,message) {
    const api = window.BRVTALFeedback;
    if (api?.[kind]) api[kind](message,'settings-v2');
  }

  async function saveGeneral() {
    const name = read('site_name');
    const tagline = read('site_tagline');
    if (!name) throw new Error('Public site name is required.');
    await persistJson('site',{name,tagline});
  }

  async function saveSocial() {
    const patch = {};
    for (const key of ['instagram','soundcloud','youtube','spotify','website']) {
      const value = read(key);
      if (!validHttpUrl(value)) throw new Error(`${key.toUpperCase()} must be an HTTP/HTTPS URL.`);
      patch[key] = value;
    }
    await persistJson('social',patch);
  }

  async function saveSeo() {
    const share = read('seo_share');
    if (share && !validHttpUrl(share) && !share.startsWith('/')) {
      throw new Error('Share image must be an HTTP/HTTPS URL or an absolute public path.');
    }
    await persistJson('seo',{
      site_title:read('seo_title'),
      description:read('seo_description').slice(0,320),
      share_image:share
    });
  }

  async function saveIndexNow() {
    const indexNowKey = read('indexnow_key');
    const keyLocation = read('indexnow_key_location');
    const endpoint = read('indexnow_endpoint');
    const indexNowEnabled = read('indexnow_enabled') === '1';

    if (indexNowKey && !/^[A-Za-z0-9-]{8,128}$/.test(indexNowKey)) {
      throw new Error('IndexNow key must be 8–128 characters using only letters, numbers and hyphens.');
    }
    if (indexNowEnabled && !indexNowKey) {
      throw new Error('IndexNow key is required before enabling the integration.');
    }
    if (!/^\/[A-Za-z0-9][A-Za-z0-9-]{0,100}\.txt$/.test(keyLocation)) {
      throw new Error('Key location must be a root-level .txt path such as /indexnow-key.txt.');
    }
    if (!INDEXNOW_ENDPOINTS.some(([value]) => value === endpoint)) {
      throw new Error('Select an official IndexNow endpoint.');
    }

    await persistJson('indexnow',{
      enabled:indexNowEnabled,
      key:indexNowKey,
      key_location:keyLocation,
      endpoint
    });
  }

  async function saveAnalytics() {
    const gtm = read('gtm_id').toUpperCase();
    if (gtm && !/^GTM-[A-Z0-9]{4,20}$/.test(gtm)) {
      throw new Error('Google Tag Manager ID must use the GTM-XXXXXXX format.');
    }
    await persistJson('analytics',{gtm_id:gtm},['ga4_id','google','measurement_id','google_tag_manager','tag_manager','gtm']);
  }

  const saveHandlers = {
    general:saveGeneral,
    social:saveSocial,
    seo:saveSeo,
    indexnow:saveIndexNow,
    analytics:saveAnalytics
  };

  async function save(section) {
    try {
      feedback('progress','Saving Settings…');
      const handler = saveHandlers[section];
      if (handler) await handler();
      feedback('success','Settings saved.');
    } catch (error) {
      feedback('error',error?.message || 'Settings save failed.');
      if (!window.BRVTALFeedback) alert(error?.message || 'Settings save failed.');
    }
  }

  function activate(id) {
    V2.tab = ['general','social','seo','analytics','advanced'].includes(id) ? id : 'general';
    const root = document.querySelector('[data-settings-v2]');
    if (!root) return;
    root.querySelectorAll('[data-settings-tab]').forEach(button => button.classList.toggle('active',button.dataset.settingsTab===V2.tab));
    root.querySelectorAll('[data-settings-pane]').forEach(pane => pane.classList.toggle('active',pane.dataset.settingsPane===V2.tab));
  }

  function updateAsset(path) {
    const input = document.getElementById('sv2_seo_share');
    if (!input) return;
    input.value = String(path || '');
    const card = input.closest('[data-settings-asset]');
    const code = card?.querySelector('[data-settings-asset-path]');
    const preview = card?.querySelector('.sv2-asset-preview');
    if (code) code.textContent = path || 'Not selected';
    if (preview) preview.innerHTML = path ? `<img src="${esc(path)}" alt="Share image preview" loading="lazy">` : '<span>NO ASSET</span>';
  }

  function closePicker() {
    document.getElementById('sv2-media-picker')?.remove();
    V2.lastFocus?.focus?.();
    V2.lastFocus = null;
  }

  async function openPicker(trigger) {
    closePicker();
    V2.lastFocus = trigger || document.activeElement;
    let media = [];
    try { media = (await req('/media')).data || []; }
    catch (error) { feedback('error','Media Library could not be loaded.'); return; }
    const images = media.filter(item => item.type === 'image');
    const overlay = document.createElement('div');
    overlay.id = 'sv2-media-picker';
    overlay.className = 'sv2-picker-overlay';
    overlay.innerHTML = `<div class="sv2-picker" role="dialog" aria-modal="true" aria-labelledby="sv2-picker-title"><header><div><span>MEDIA LIBRARY</span><h3 id="sv2-picker-title">Choose share image</h3></div><button type="button" data-sv2-close aria-label="Close media picker">×</button></header><label><span>SEARCH</span><input type="search" placeholder="Title or path"></label><div class="sv2-picker-grid">${images.map(item => `<button type="button" data-sv2-media="${esc(item.file_path || '')}" data-sv2-search="${esc((item.title || '')+' '+(item.file_path || ''))}">${item.file_path?`<img src="${esc(item.file_path)}" alt="" loading="lazy">`:''}<strong>${esc(item.title || 'Untitled')}</strong><code>${esc(item.file_path || '')}</code></button>`).join('') || '<p>No image assets available.</p>'}</div></div>`;
    document.body.appendChild(overlay);
    const search = overlay.querySelector('input[type=search]');
    overlay.querySelector('[data-sv2-close]')?.addEventListener('click',closePicker);
    overlay.addEventListener('click',event => { if (event.target===overlay) closePicker(); });
    overlay.querySelectorAll('[data-sv2-media]').forEach(button => button.addEventListener('click',()=>{updateAsset(button.dataset.sv2Media || '');closePicker();}));
    search?.addEventListener('input',()=>{const q=search.value.toLowerCase();overlay.querySelectorAll('[data-sv2-search]').forEach(card=>{card.hidden=Boolean(q)&&!String(card.dataset.sv2Search||'').toLowerCase().includes(q);});});
    overlay.addEventListener('keydown',event=>{if(event.key==='Escape'){event.preventDefault();closePicker();}});
    search?.focus();
  }

  function bind() {
    document.addEventListener('click', event => {
      const tab = event.target.closest('[data-settings-tab]');
      if (tab) { activate(tab.dataset.settingsTab); return; }
      const saveButton = event.target.closest('[data-settings-save]');
      if (saveButton) { save(saveButton.dataset.settingsSave); return; }
      const themeStudio = event.target.closest('[data-settings-theme-studio]');
      if (themeStudio) { globalThis.go?.('theme'); return; }
      const raw = event.target.closest('[data-settings-raw]');
      if (raw) { legacyOpenSettingByKey?.(raw.dataset.settingsRaw); return; }
      if (event.target.closest('[data-settings-new-raw]')) { window.openModal?.('settings'); return; }
      const picker = event.target.closest('[data-settings-media-picker]');
      if (picker) { openPicker(picker); return; }
      if (event.target.closest('[data-settings-clear-asset]')) updateAsset('');
    });
  }

  window.settingsHome = settingsScreen;
  window.openSettingByKey = key => {
    const map = {site:'general',social:'social',seo:'seo',indexnow:'seo',analytics:'analytics'};
    if (map[key]) activate(map[key]);
    else legacyOpenSettingByKey?.(key);
  };
  window.BRVTALSettingsV2 = { activate, openRaw:key=>legacyOpenSettingByKey?.(key), jsonValue };
  bind();
})();
