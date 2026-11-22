(() => {
  'use strict';

  const V2 = { tab:'general', pickerTarget:null, lastFocus:null, securityLoadToken:0, integrationLoadToken:0, integrationWritable:false, integrationItems:[] };
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
    const safeId = esc(id);
    const helpMarkup = help ? '<small>' + esc(help) + '</small>' : '';
    return `<label class="sv2-field"><span>${esc(label)}</span><input id="sv2_${safeId}" data-testid="settings-field-${safeId}" type="${esc(type)}" value="${esc(value || '')}" autocomplete="off">${helpMarkup}</label>`;
  }

  function read(id) {
    return document.getElementById('sv2_' + id)?.value?.trim() || '';
  }

  // Settings accepts only filesystem-backed CMS paths; do not permit a
  // scheme, parameters, Unicode separators or client-supplied traversal.
  function safeCmsMediaPath(value) {
    if (typeof value !== 'string' || value.length > 320) return false;
    const prefix = '/uploads/media/';
    if (value.slice(0, prefix.length) !== prefix) return false;
    const relative = value.substring(prefix.length);
    if (!relative || relative.indexOf('..') >= 0 || /[^-\w/.]/.test(relative)) return false;
    const filename = relative.substring(relative.lastIndexOf('/') + 1);
    if (!/^[\w-]+\.(?:webp|png|jpe?g|gif)$/i.test(filename)) return false;
    const folders = relative.substring(0, relative.lastIndexOf('/'));
    return !folders || folders.split('/').every(folder => folder !== '' && /^[\w-]+$/.test(folder));
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
      ['integrations','INTEGRACIONES'],
      ['advanced','ADVANCED'],
    ];
    return defs.map(([id,label],index) => `<button type="button" class="${V2.tab===id?'active':''}" data-settings-tab="${id}" data-testid="settings-tab-${id}"><span>0${index+1}</span>${label}</button>`).join('');
  }

  function generalPane() {
    const site = jsonValue('site');
    const locale = String(site.default_locale || 'es').toUpperCase();
    const locales = Array.isArray(site.available_locales) ? site.available_locales.map(x => String(x).toUpperCase()).join(' / ') : 'ES / EN';
    return `<section class="sv2-pane ${V2.tab==='general'?'active':''}" data-settings-pane="general">
      <header class="sv2-section-head"><div><span>01 / GENERAL</span><h3>Site identity</h3></div><p>Global identity values used by the public runtime. Visual treatment belongs in Theme Studio.</p></header>
      <div class="sv2-grid two">${text('site_name','Public site name',site.name || 'BRVTAL','Used by public name bindings and document fallback.')}${text('site_tagline','Tagline',site.tagline || 'RAVE TILL GRAVE','Global editorial tagline; its typography/placement remains theme-owned.')}</div>
      <div class="sv2-grid two">${text('site_description','Hero description',site.description || '','Site description shown on public home; leave blank for the default home copy.')}${text('site_hero_manifesto_es','Hero manifesto · ES',site.hero_manifesto_es || '','Leave blank for owner v2 Spanish copy.')}</div>
      <div class="sv2-grid two">${text('site_hero_manifesto_en','Hero manifesto · EN',site.hero_manifesto_en || '','Leave blank for owner v2 English copy.')}</div>
      <div class="sv2-grid two">${text('site_hero_cta_es','Hero button · ES',site.hero_cta_es || '','Leave blank for EXPLORA BRVTAL.')}${text('site_hero_cta_en','Hero button · EN',site.hero_cta_en || '','Leave blank for EXPLORE BRVTAL.')}</div>
      <div class="sv2-grid two">${text('site_hero_eyebrow_es','Hero eyebrow · ES',site.hero_eyebrow_es || '','Leave blank for the owner v2 editorial categories.')}${text('site_hero_eyebrow_en','Hero eyebrow · EN',site.hero_eyebrow_en || '','Leave blank for the owner v2 editorial categories.')}</div>
      <header class="sv2-section-head"><div><span>CONCEPT 05 / MEDIA</span><h3>Editorial image assignments</h3></div><p>Choose published assets from Media Library; Git sources are never URLs.</p></header>
      <div class="sv2-grid two"><label class="sv2-field"><span>HERO FACE</span><input id="sv2_c05_media_hero_face" data-testid="settings-field-c05_media_hero_face" readonly type="text" value="${esc(site.c05_media_hero_face?.url || '')}" data-media-id="${esc(site.c05_media_hero_face?.id || '')}" placeholder="Choose published media"><button type="button" data-settings-media-picker="c05_media_hero_face">SELECT MEDIA</button><button type="button" data-settings-clear-media="c05_media_hero_face">CLEAR</button></label>
        <label class="sv2-field"><span>CROWD</span><input id="sv2_c05_media_crowd" data-testid="settings-field-c05_media_crowd" readonly type="text" value="${esc(site.c05_media_crowd?.url || '')}" data-media-id="${esc(site.c05_media_crowd?.id || '')}" placeholder="Choose published media"><button type="button" data-settings-media-picker="c05_media_crowd">SELECT MEDIA</button><button type="button" data-settings-clear-media="c05_media_crowd">CLEAR</button></label>
        <label class="sv2-field"><span>ARTISTS</span><input id="sv2_c05_media_artists" data-testid="settings-field-c05_media_artists" readonly type="text" value="${esc(site.c05_media_artists?.url || '')}" data-media-id="${esc(site.c05_media_artists?.id || '')}" placeholder="Choose published media"><button type="button" data-settings-media-picker="c05_media_artists">SELECT MEDIA</button><button type="button" data-settings-clear-media="c05_media_artists">CLEAR</button></label>
        <label class="sv2-field"><span>NIGHTS</span><input id="sv2_c05_media_nights" data-testid="settings-field-c05_media_nights" readonly type="text" value="${esc(site.c05_media_nights?.url || '')}" data-media-id="${esc(site.c05_media_nights?.id || '')}" placeholder="Choose published media"><button type="button" data-settings-media-picker="c05_media_nights">SELECT MEDIA</button><button type="button" data-settings-clear-media="c05_media_nights">CLEAR</button></label>
        <label class="sv2-field"><span>MEMORIES</span><input id="sv2_c05_media_memories" data-testid="settings-field-c05_media_memories" readonly type="text" value="${esc(site.c05_media_memories?.url || '')}" data-media-id="${esc(site.c05_media_memories?.id || '')}" placeholder="Choose published media"><button type="button" data-settings-media-picker="c05_media_memories">SELECT MEDIA</button><button type="button" data-settings-clear-media="c05_media_memories">CLEAR</button></label></div>
      <div class="sv2-context-grid">
        <article><span>CANONICAL ORIGIN</span><strong>www.brvtal.com.co</strong><p>Environment-owned. Not editable here.</p></article>
        <article><span>LANGUAGE POLICY</span><strong>${esc(locale)} · ${esc(locales)}</strong><p>Spanish is canonical/default and ES/EN availability is governed by <code>settings.site</code>. The public selector remains a separate delivery step.</p></article>
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
    const indexnow = jsonValue('indexnow');
    const indexNowKey = String(indexnow.key || '').trim();
    const keyLocation = String(indexnow.key_location || '/indexnow-key.txt').trim();
    const endpoint = String(indexnow.endpoint || INDEXNOW_ENDPOINTS[0][0]).trim();
    const keyValid = /^[A-Za-z0-9-]{8,128}$/.test(indexNowKey);
    const keyLocationValid = /^\/indexnow-[A-Za-z0-9][A-Za-z0-9-]{0,90}\.txt$/.test(keyLocation);
    const endpointValid = INDEXNOW_ENDPOINTS.some(([value]) => value === endpoint);
    const indexNowEnabled = Boolean(indexnow.enabled) && keyValid && keyLocationValid && endpointValid;
    const endpointOptions = INDEXNOW_ENDPOINTS.map(([value,label]) => {
      const selected = value === endpoint ? 'selected' : '';
      return `<option value="${esc(value)}" ${selected}>${esc(label)}</option>`;
    }).join('');
    const verificationUrl = `https://www.brvtal.com.co${keyLocationValid ? keyLocation : '/indexnow-key.txt'}`;

    return `<section class="sv2-pane ${V2.tab==='seo'?'active':''}" data-settings-pane="seo">
      <header class="sv2-section-head"><div><span>03 / SEO</span><h3>Search discovery</h3></div><p>Search metadata has one canonical editor. Settings keeps only indexing infrastructure so Home metadata cannot drift from the rest of the public site.</p></header>
      <div class="sv2-context-grid">
        <article class="sv2-tool-card"><span>CANONICAL EDITOR</span><strong>WEBSITE → SEO</strong><p>Home, Contact, Pages, Events, Artists, Sets, Releases and Blog share one server-rendered source of truth.</p><button type="button" class="btn red" data-settings-open="seo">OPEN SEO WORKSPACE</button></article>
        <article><span>METADATA AUTHORITY</span><strong>SERVER-RENDERED</strong><p>Title, description, canonical, Open Graph and Twitter/X are derived before HTML reaches crawlers.</p></article>
        <article><span>AUTO / MANUAL</span><strong>REVERSIBLE</strong><p>Clear an explicit override in the SEO workspace to return that field to its live editorial fallback.</p></article>
      </div>
      <div class="sv2-integration">
        <div class="sv2-section-head"><div><span>INDEXNOW</span><h3>Search-engine change notifications</h3></div><p>Notify participating search engines only when public URLs are created, updated, unpublished or removed. No scheduled full-site resubmission.</p></div>
        <div class="sv2-grid two">
          <label class="sv2-field"><span>IndexNow</span><select id="sv2_indexnow_enabled" data-testid="indexnow-enabled"><option value="0" ${indexNowEnabled?'':'selected'}>DISABLED</option><option value="1" ${indexNowEnabled?'selected':''}>ENABLED</option></select><small>Enable after saving a valid IndexNow configuration.</small></label>
          ${text('indexnow_key','IndexNow key',indexNowKey,'Allowed: A–Z, a–z, 0–9 and hyphens; 8–128 characters.')}
          ${text('indexnow_key_location','Key location',keyLocation,'Root-level IndexNow key file path, for example /indexnow-key.txt.')}
          <label class="sv2-field"><span>Submission endpoint</span><select id="sv2_indexnow_endpoint" data-testid="indexnow-endpoint">${endpointOptions}</select><small>Official participating endpoint. Global IndexNow is the default.</small></label>
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
        <div class="sv2-actions"><button type="button" class="btn red" data-settings-save="indexnow" data-testid="indexnow-save">SAVE INDEXNOW</button></div>
      </div>
    </section>`;
  }

  function analyticsPane() {
    const analytics = jsonValue('analytics');
    const hasCanonicalGtm = Object.prototype.hasOwnProperty.call(analytics,'gtm_id');
    const legacyGtm = analytics.google_tag_manager || analytics.tag_manager || analytics.gtm || '';
    const gtmId = String(hasCanonicalGtm ? analytics.gtm_id : legacyGtm).trim().toUpperCase();
    const connected = /^GTM-[A-Z0-9]{4,20}$/.test(gtmId);
    const privacyName = String(row('privacy.controller_name')?.setting_value || '').trim();
    const privacyEmail = String(row('privacy.controller_email')?.setting_value || '').trim();
    const privacyPhone = String(row('privacy.controller_phone')?.setting_value || '').trim();
    const privacyResponse = String(row('privacy.response_time')?.setting_value || '').trim();
    const privacyDomicile = String(row('privacy.controller_domicile')?.setting_value || '').trim();
    const privacyAddress = String(row('privacy.controller_address')?.setting_value || '').trim();
    const privacyArea = String(row('privacy.rights_contact_area')?.setting_value || '').trim();
    const privacyConsultation = String(row('privacy.consultation_time')?.setting_value || '').trim();
    const privacyClaim = String(row('privacy.claim_time')?.setting_value || '').trim();
    const privacyConfigured = [privacyName,privacyEmail,privacyResponse].filter(Boolean).length;
    const privacyAdditionalConfigured = [privacyPhone,privacyDomicile,privacyAddress,privacyArea,privacyConsultation,privacyClaim].filter(Boolean).length;
    return `<section class="sv2-pane ${V2.tab==='analytics'?'active':''}" data-settings-pane="analytics">
      <header class="sv2-section-head"><div><span>04 / ANALYTICS & PRIVACY</span><h3>Tag delivery</h3></div><p>Google Tag Manager is the single public tag-delivery layer and loads automatically on every public page.</p></header>
      <div class="sv2-grid two">${text('gtm_id','Google Tag Manager Container ID',gtmId,'Format: GTM-XXXXXXX. GA4, pixels and other optional tracking tags are managed inside GTM.')}
        <div class="sv2-readonly"><span>INTEGRATION STATUS</span><strong>${connected?'CONNECTED':'NOT CONFIGURED'}</strong><p>${connected?'The validated container is ready for immediate public loading.':'Save a valid GTM container ID to enable public tag delivery.'}</p></div>
      </div>
      <div class="sv2-context-grid"><article><span>LOAD POLICY</span><strong>IMMEDIATE / ALL PUBLIC PAGES</strong><p>BRVTAL requests GTM automatically. Analytics storage starts granted; advertising storage, user data and personalization remain denied by the BRVTAL bootstrap.</p></article><article><span>DIRECT GA4</span><strong>RETIRED</strong><p>BRVTAL no longer loads GA4 directly. Configure GA4 and other optional tags inside Google Tag Manager.</p></article><article><span>PUBLIC API</span><strong>PRIVATE CONFIG</strong><p>The GTM container setting is read server-side and is not included in <code>api/public.php</code>.</p></article></div>
      <div class="sv2-actions"><button type="button" class="btn red" data-settings-save="analytics">SAVE TAG MANAGER</button></div>
      <header class="sv2-section-head"><div><span>PRIVACY RIGHTS</span><h3>Public controller information</h3></div><p>These fields appear on the public /privacy page. Enter the legally verified details; blank fields remain marked as pending. This does not replace professional legal review.</p></header>
      <div class="sv2-grid two">
        ${text('privacy_controller_name','Data controller / responsible entity',privacyName,'Legal name of the person or organization (up to 160 characters).')}
        ${text('privacy_controller_email','Privacy contact email',privacyEmail,'A valid email address for privacy requests (up to 254 characters).','email')}
        ${text('privacy_controller_phone','Public data controller phone',privacyPhone,'Only the verified public phone number, max 40 characters.')}
        ${text('privacy_response_time','Legacy general response deadline',privacyResponse,'Compatibility field: consultation and claim periods are configured separately below.')}
        ${text('privacy_controller_domicile','Legal domicile',privacyDomicile,'Verified city and country of the controller (max 160 characters).')}
        ${text('privacy_controller_address','Contact address',privacyAddress,'Verified correspondence address (max 254 characters).')}
        ${text('privacy_rights_contact_area','Area handling data rights',privacyArea,'Unit handling access, rectification, erasure and complaints (max 160 characters).')}
        ${text('privacy_consultation_time','Consultation response period',privacyConsultation,'Requires legal review: Law 1581/2012, article 14.')}
        ${text('privacy_claim_time','Claim response period',privacyClaim,'Requires legal review: Law 1581/2012, article 15.')}
        <div class="sv2-readonly"><span>ADDITIONAL LEGAL DETAILS</span><strong>${privacyAdditionalConfigured}/6 CONFIGURED</strong><p>Final legal approval is still required even when all fields have values.</p></div>
        <div class="sv2-readonly" data-privacy-config-status data-testid="privacy-config-status"><span>PUBLIC PRIVACY STATUS</span><strong>${privacyConfigured === 3 ? '3/3 CONFIGURED' : `${privacyConfigured}/3 PENDING`}</strong><p>${privacyConfigured === 3 ? 'Configuration values are present. Legal review is still required.' : 'The public privacy page displays placeholders for each missing field.'}</p></div>
      </div>
      <div class="sv2-actions"><button type="button" class="btn red" data-settings-save="privacy" data-testid="privacy-save">SAVE PRIVACY DETAILS</button></div>
    </section>`;
  }

  function requestedSettingsTab() {
    const requested = new URLSearchParams(location.search).get('settings');
    return ['general','social','seo','analytics','integrations','advanced'].includes(requested) ? requested : '';
  }

    const INTEGRATION_PROVIDERS = [{"id":"resend","name":"Resend","description":"Envío de correos y confirmaciones de entradas.","fields":[{"key":"BRVTAL_TICKET_MAIL_API_KEY","label":"Clave de API","help":"Empieza por re_…","example":"re_…","kind":"password"},{"key":"BRVTAL_TICKET_MAIL_DOMAIN","label":"Dominio de envío","help":"Dominio verificado para enviar correos.","example":"correo.ejemplo.co","kind":"text"},{"key":"BRVTAL_TICKET_MAIL_FROM","label":"Remitente","help":"Correo que verán las personas al recibir un mensaje.","example":"entradas@ejemplo.co","kind":"email"},{"key":"BRVTAL_TICKET_MAIL_WEBHOOK_SECRET","label":"Secreto del webhook","help":"Clave de firma para verificar los eventos recibidos.","example":"whsec_…","kind":"password"}]},{"id":"wompi","name":"Wompi sandbox","description":"Pagos de prueba; estas claves no activan cobros reales.","fields":[{"key":"BRVTAL_TICKET_WOMPI_PUBLIC_KEY","label":"Clave pública","help":"Clave de pruebas para la integración.","example":"pub_test_…","kind":"password"},{"key":"BRVTAL_TICKET_WOMPI_PRIVATE_KEY","label":"Clave privada","help":"Guárdala de forma privada. No se mostrará otra vez.","example":"prv_test_…","kind":"password"}]},{"id":"smtp","name":"SMTP","description":"Servidor alternativo para el envío de correos.","fields":[{"key":"BRVTAL_SMTP_HOST","label":"Servidor","help":"Dirección del servidor de correo.","example":"smtp.ejemplo.co","kind":"text"},{"key":"BRVTAL_SMTP_USER","label":"Usuario","help":"Identificador de la cuenta de correo.","example":"cuenta@ejemplo.co","kind":"text"},{"key":"BRVTAL_SMTP_PASSWORD","label":"Contraseña","help":"Contraseña del servidor. No se mostrará después.","example":"Introduce una nueva contraseña","kind":"password"},{"key":"BRVTAL_MAIL_FROM","label":"Remitente","help":"Correo desde el que se envían los mensajes.","example":"hola@ejemplo.co","kind":"email"}]},{"id":"ga4","name":"Google Analytics 4","description":"Identidad de servicio para consultar métricas.","fields":[{"key":"BRVTAL_GA4_PROPERTY_ID","label":"ID de propiedad","help":"Solo los números del identificador de GA4.","example":"123456789","kind":"text"},{"key":"BRVTAL_GA4_CLIENT_EMAIL","label":"Correo de servicio","help":"Dirección de la cuenta de servicio.","example":"cuenta@proyecto.iam.gserviceaccount.com","kind":"email"},{"key":"BRVTAL_GA4_PRIVATE_KEY","label":"Clave privada (PEM)","help":"Pega el bloque PEM completo, con saltos de línea.","example":"-----BEGIN PRIVATE KEY-----","kind":"multiline"}]},{"id":"sentry","name":"Sentry","description":"Diagnóstico de errores del servidor.","fields":[{"key":"BRVTAL_SENTRY_DSN","label":"Dirección de conexión (DSN)","help":"Dirección HTTPS de tu proyecto.","example":"https://…@sentry.io/…","kind":"password"}]}];
  
  function integrationState(provider,items){
   const stored=provider.fields.some(field=>items.some(item=>item.key===field.key && item.stored===true));
   if(!stored)return "Falta";
   return provider.id==="wompi" ? "Modo pruebas" : "Configurado";
  }
  
  function integrationField(field,items,writable){
   const item=items.find(row=>row.key===field.key);
   const stored=item?.stored===true;
   const id="sv2-secret-"+field.key.toLowerCase().replaceAll("_","-");
   const disabled=writable?"":"disabled";
   const input=field.kind==="multiline"
     ? `<textarea id="${id}" rows="5" data-secret-input data-secret-key="${esc(field.key)}" autocomplete="off" spellcheck="false" placeholder="${esc(field.example)}" ${disabled}></textarea>`
     : `<input id="${id}" data-secret-input data-secret-key="${esc(field.key)}" type="${esc(field.kind)}" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="${esc(field.example)}" ${disabled}>`;
   const updated=item?.updated_at ? "Actualizado: "+esc(item.updated_at) : "Sin valor guardado";
   return `<div class="sv2-secret-field" data-secret-row="${esc(field.key)}">
       <label class="sv2-secret-label" for="${id}">${esc(field.label)}</label>
       ${input}
       <small class="sv2-secret-help">${esc(field.help)}</small>
       <div class="sv2-secret-field-meta">
         <small data-secret-stored>${stored?"Guardada · "+updated:updated}</small>
         <button type="button" class="sv2-secret-delete" data-secret-delete="${esc(field.key)}" aria-label="Eliminar ${esc(field.label)}" ${stored?"":"hidden"} ${writable?"":"disabled"}>Eliminar</button>
       </div>
     </div>`;
  }
  
  function mailAllowlistField(provider,items,writable){
    if(provider.id!=="resend")return "";
    const item=items.find(row=>row.key==="BRVTAL_TICKET_MAIL_TEST_RECIPIENTS");
    const configured=item?.stored===true;
    return `<div class="sv2-secret-field">
      <label class="sv2-secret-label" for="sv2-mail-test-recipients">Destinatarios permitidos para pruebas</label>
      <textarea id="sv2-mail-test-recipients" rows="2" data-mail-allowlist
        data-secret-key="BRVTAL_TICKET_MAIL_TEST_RECIPIENTS" autocomplete="off"
        autocapitalize="off" spellcheck="false"
        placeholder="qa@ejemplo.co, pruebas@ejemplo.co" ${writable?"":"disabled"}></textarea>
      <small class="sv2-secret-help">Correos separados por comas. La lista se guarda cifrada y nunca se vuelve a mostrar.</small>
      <small data-mail-allowlist-status>${configured?"Lista guardada":"Lista no configurada"}</small>
    </div>`;
  }

  function integrationCard(provider,items,writable){
   const state=integrationState(provider,items);
   const timestamps=provider.fields.map(field=>items.find(item=>item.key===field.key)?.updated_at).filter(Boolean).sort((a,b)=>a.localeCompare(b));
   const updated=timestamps.length?"Actualizado: "+timestamps.at(-1):"Sin actualizaciones";
   return `<article class="sv2-secret-card" data-integration-provider="${esc(provider.id)}">
     <header class="sv2-secret-card-head">
       <div class="sv2-secret-card-info"><h4>${esc(provider.name)}</h4><p>${esc(provider.description)}</p></div>
       <div class="sv2-secret-card-meta"><span class="sv2-secret-badge ${state==="Falta"?"is-missing":"is-configured"}" data-provider-state>${esc(state)}</span><small data-provider-updated>${esc(updated)}</small></div>
     </header>
     <div class="sv2-secret-fields">${provider.fields.map(field=>integrationField(field,items,writable)).join("")}</div>
     ${provider.id==="resend"?`<div class="sv2-secret-field">
       <label class="sv2-secret-label" for="sv2-mail-test-to">Enviar prueba a</label>
       <input id="sv2-mail-test-to" type="email" data-mail-test-to autocomplete="email" inputmode="email"
         autocapitalize="off" spellcheck="false" placeholder="tu@correo.co" ${writable?"":"disabled"}>
       <small class="sv2-secret-help">Envía un correo real marcado «[PRUEBA]» con la configuración guardada. Funciona en cualquier modo.</small>
     </div>`:""}
     ${mailAllowlistField(provider,items,writable)}
     <footer class="sv2-secret-card-footer">
       <p role="status" aria-live="polite" data-secret-feedback>Los campos vacíos no se modificarán.</p>
       ${provider.id==="resend"?`<button type="button" class="btn ghost" data-mail-test-send ${writable?"":"disabled"}>Enviar correo de prueba</button>`:""}
       <button type="button" class="btn red sv2-secret-save" data-secret-provider-save="${esc(provider.id)}" ${writable?"":"disabled"}>Guardar</button>
     </footer>
   </article>`;
  }
  
  function integrationsPane(){
   return `<section class="sv2-pane ${V2.tab==="integrations"?"active":""}" data-settings-pane="integrations">
     <header class="sv2-section-head"><div><h3>Integraciones</h3></div><p>Guarda y administra las credenciales sin mostrar las claves existentes. Cada proveedor se configura por separado.</p></header>
     <div class="sv2-integrations-host" data-settings-integrations aria-live="polite">
       <p class="sv2-secret-loading">Cargando integraciones…</p>
     </div>
   </section>`;
  }
  
  function integrationError(error){
   const raw=String(error?.message||"");
   const known={
     AUTH_REQUIRED:"La sesión de administrador venció. Vuelve a iniciar sesión.",
     FORBIDDEN:"Tu cuenta no tiene permiso para cambiar estas claves.",
     CSRF:"La sesión cambió. Actualiza Settings e inténtalo de nuevo.",
     SECRET_WRITE_DISABLED:"La escritura está desactivada en el servidor.",
     SECRET_FORMAT_INVALID:"El formato no es válido. Revisa el ejemplo del campo.",
     SECRET_KEY_NOT_ALLOWED:"Esta clave no se puede configurar.",
     SECRET_STORE_UNAVAILABLE:"El almacenamiento no está disponible. Inténtalo de nuevo.",
     TEST_RECIPIENT_REQUIRED:"Escribe el correo al que quieres enviar la prueba.",
     TEST_RECIPIENT_INVALID:"Ese correo no es válido. Revísalo e inténtalo de nuevo.",
     MAIL_NOT_CONFIGURED:"Guarda primero la clave de API, el dominio y el remitente de Resend.",
     MAIL_TEST_RATE_LIMITED:"Solo se puede enviar un correo de prueba por minuto.",
     MAIL_TEST_NOT_ACCEPTED:"Resend no aceptó el correo. Revisa la clave, el dominio verificado y el remitente.",
     MAIL_TEST_UNAVAILABLE:"El correo de prueba no está disponible. Revisa la configuración.",
     HTTP_401:"Debes iniciar sesión como administrador.",
     HTTP_403:"No tienes autorización para esta operación."
   };
   return known[raw]||"No se pudo completar la operación. Inténtalo de nuevo.";
  }
  
  async function loadIntegrations(force=false){
   const host=document.querySelector("[data-settings-integrations]");
   if(!host || V2.tab!=="integrations")return;
   if(!force && host.dataset.integrationLoaded==="1")return;
   const token=++V2.integrationLoadToken;
   delete host.dataset.integrationLoaded;
   host.innerHTML='<p class="sv2-secret-loading">Cargando integraciones…</p>';
   try{
     const response=await fetch("/api/admin-integration-secrets.php",{credentials:"same-origin",cache:"no-store"});
     const payload=await response.json().catch(()=>({}));
     if(!response.ok || payload.ok!==true || !Array.isArray(payload.items)){
       throw new Error(String(payload.error||"HTTP_"+response.status));
     }
     if(token!==V2.integrationLoadToken || !host.isConnected)return;
     const writable=payload.write_enabled===true && payload.master_key_configured===true;
     V2.integrationWritable=writable;
     V2.integrationItems=payload.items.map(item=>({
       key:String(item.key||""),stored:item.stored===true,updated_at:String(item.updated_at||"")
     }));
     host.innerHTML=(writable?"":'<p class="sv2-secret-locked" role="note">La escritura de claves está bloqueada en el servidor. Puedes consultar el estado, pero no modificarlas.</p>')+
       '<div class="sv2-secret-cards">'+INTEGRATION_PROVIDERS.map(provider=>integrationCard(provider,V2.integrationItems,writable)).join("")+"</div>"+
       '<p class="sv2-secret-disclaimer">Guardar una clave no activa envíos, cobros reales ni publicación del sitio.</p>';
     host.dataset.integrationLoaded="1";
   }catch(error){
     if(token!==V2.integrationLoadToken || !host.isConnected)return;
     host.innerHTML='<div class="sv2-secret-error"><p>'+esc(integrationError(error))+'</p><button type="button" class="btn ghost" data-secret-retry>Reintentar</button></div>';
   }
  }
  
  async function integrationCsrf(){
   if(window.csrf)return String(window.csrf);
   const response=await fetch("/api/index.php/auth",{credentials:"same-origin",cache:"no-store"});
   const data=await response.json().catch(()=>({}));
   if(!response.ok || !data.authenticated || !data.csrf)throw new Error("AUTH_REQUIRED");
   window.csrf=data.csrf;
   return String(data.csrf);
  }
  
  async function integrationMutation(method,key,value){
   const csrf=await integrationCsrf();
   const response=await fetch("/api/admin-integration-secrets.php",{
     method,credentials:"same-origin",cache:"no-store",
     headers:{"Content-Type":"application/json","X-CSRF-Token":csrf},
     body:JSON.stringify(method==="POST"?{key,value}:{key})
   });
   const payload=await response.json().catch(()=>({}));
   if(!response.ok || payload.ok!==true || !Array.isArray(payload.items)){
     const error=String(payload.error||"HTTP_"+response.status);
     if(["CSRF","AUTH_REQUIRED","HTTP_401","HTTP_403"].includes(error))delete window.csrf;
     throw new Error(error);
   }
   // Only status metadata, never values or ciphertext, may leave this function.
   return payload.items.map(item=>({key:String(item.key||""),stored:item.stored===true,updated_at:String(item.updated_at||"")}));
  }
  
  function updateIntegrationCard(card,items){
   const provider=INTEGRATION_PROVIDERS.find(p=>p.id===card.dataset.integrationProvider);
   if(!provider)return;
   V2.integrationItems=items;
   const state=integrationState(provider,items);
   const badge=card.querySelector("[data-provider-state]");
   if(badge){badge.textContent=state;badge.classList.toggle("is-missing",state==="Falta");badge.classList.toggle("is-configured",state!=="Falta");}
   const times=provider.fields.map(f=>items.find(x=>x.key===f.key)?.updated_at).filter(Boolean).sort((a,b)=>a.localeCompare(b));
   const meta=card.querySelector("[data-provider-updated]");
   if(meta)meta.textContent=times.length?"Actualizado: "+times.at(-1):"Sin actualizaciones";
   const allowlist=items.find(item=>item.key==="BRVTAL_TICKET_MAIL_TEST_RECIPIENTS");
   const allowStatus=card.querySelector("[data-mail-allowlist-status]");
   if(allowStatus)allowStatus.textContent=allowlist?.stored===true?"Lista guardada":"Lista no configurada";
   provider.fields.forEach(field=>{
     const row=Array.from(card.querySelectorAll("[data-secret-row]")).find(el=>el.dataset.secretRow===field.key);
     if(!row)return;
     const item=items.find(x=>x.key===field.key);
     const saved=item?.stored===true;
     const small=row.querySelector("[data-secret-stored]");
     if(small)small.textContent=saved?("Guardada · Actualizado: "+String(item.updated_at||"fecha no disponible")):"Sin valor guardado";
     const del=row.querySelector("[data-secret-delete]");
     if(del){del.hidden=!saved;del.disabled=!V2.integrationWritable;}
   });
  }
  
  function integrationBusy(card,busy){
   card.querySelectorAll("button, input, textarea").forEach(el=>{el.disabled=busy || !V2.integrationWritable;});
   card.setAttribute("aria-busy",busy?"true":"false");
  }
  
  async function saveIntegrationProvider(id){
   const card=Array.from(document.querySelectorAll("[data-integration-provider]")).find(el=>el.dataset.integrationProvider===id);
   if(!card || !V2.integrationWritable || card.getAttribute("aria-busy")==="true")return;
   const fields=Array.from(card.querySelectorAll("[data-secret-input], [data-mail-allowlist]")).filter(x=>x.value.trim().length>0);
   const feedback=card.querySelector("[data-secret-feedback]");
   if(!feedback)return;
   if(!fields.length){feedback.textContent="Escribe al menos un valor. Los campos vacíos se conservan.";return;}
   integrationBusy(card,true);
   feedback.textContent="Guardando…";
   let saved=0;
   try{
     for(const field of fields){
       const result=await integrationMutation("POST",field.dataset.secretKey,field.value);
       field.value="";
       updateIntegrationCard(card,result);
       saved++;
     }
     feedback.textContent=saved===1?"Clave guardada. Los demás valores no se modificaron.":`${saved} claves guardadas. Los demás valores no se modificaron.`;
   }catch(error){
     feedback.textContent=(saved?`${saved} claves guardadas. `:"")+integrationError(error);
   }finally{integrationBusy(card,false);}
  }
  
  async function sendIntegrationTestMail(){
    const card=document.querySelector('[data-integration-provider="resend"]');
    if(!card || !V2.integrationWritable || card.getAttribute("aria-busy")==="true")return;
    const feedback=card.querySelector("[data-secret-feedback]");
    if(!feedback)return;
    integrationBusy(card,true);
    feedback.textContent="Solicitando correo de prueba…";
    try{
      const csrf=await integrationCsrf();
      const response=await fetch("/api/admin-integration-test-mail.php",{
        method:"POST",credentials:"same-origin",cache:"no-store",
        headers:{"Content-Type":"application/json","X-CSRF-Token":csrf},
        body:JSON.stringify({email:String(card.querySelector("[data-mail-test-to]")?.value||"").trim()})
      });
      const payload=await response.json().catch(()=>({}));
      if(!response.ok || payload.ok!==true || payload.state!=="accepted"){
        throw new Error(String(payload.error||"MAIL_TEST_NOT_ACCEPTED"));
      }
      feedback.textContent="Correo de prueba enviado. Revisa la bandeja de entrada (y el spam).";
    }catch(error){
      feedback.textContent=integrationError(error);
    }finally{
      integrationBusy(card,false);
    }
  }

  async function deleteIntegrationField(key){
   const row=Array.from(document.querySelectorAll("[data-secret-row]")).find(el=>el.dataset.secretRow===key);
   const card=row?.closest("[data-integration-provider]");
   if(!row || !card || !V2.integrationWritable || card.getAttribute("aria-busy")==="true")return;
   const field=INTEGRATION_PROVIDERS.flatMap(p=>p.fields).find(f=>f.key===key);
   const label=field?.label||"este valor";
   if(!window.confirm(`¿Eliminar ${label}? Tendrás que introducirlo de nuevo para recuperarlo.`))return;
   const feedback=card.querySelector("[data-secret-feedback]");
   integrationBusy(card,true);
   if(feedback)feedback.textContent="Eliminando…";
   try{
     const items=await integrationMutation("DELETE",key);
     updateIntegrationCard(card,items);
     if(feedback)feedback.textContent=`${label}: valor eliminado.`;
   }catch(error){if(feedback)feedback.textContent=integrationError(error);}
   finally{integrationBusy(card,false);}
  }

  function securityHost() {
    return document.querySelector('[data-settings-security-host]');
  }

  async function loadSecurity(force = false) {
    const host = securityHost();
    if (!host || V2.tab !== 'advanced') return;
    if (!force && host.dataset.securityLoaded === '1') return;

    const token = ++V2.securityLoadToken;
    delete host.dataset.securityLoaded;
    const loading = document.createElement('p');
    loading.className = 'sv2-security-state';
    loading.textContent = 'Loading account security…';
    host.replaceChildren(loading);

    try {
      const response = await fetch('/discadmin/totp-api.php?action=status', {
        method:'POST',
        credentials:'same-origin',
        cache:'no-store',
        headers:{
          'Content-Type':'application/json',
          'X-CSRF-Token':String(window.csrf || '')
        },
        body:'{}'
      });
      const payload = await response.json().catch(() => ({ok:false,error:'INVALID_RESPONSE'}));
      if (!response.ok || !payload.ok) {
        throw new Error(response.status === 401 ? 'AUTH_REQUIRED' : (payload.error || 'SECURITY_LOAD_FAILED'));
      }
      if (token !== V2.securityLoadToken || !host.isConnected) return;

      const rendered = window.BRVTALSecurity?.render?.(host, {
        enabled:payload.enabled === true,
        confirmed:payload.confirmed === true,
        email:String(payload.email || '')
      }, String(window.csrf || ''));
      if (!rendered) throw new Error('SECURITY_RENDER_UNAVAILABLE');
      host.dataset.securityLoaded = '1';
    } catch (error) {
      if (token !== V2.securityLoadToken || !host.isConnected) return;
      const box = document.createElement('div');
      box.className = 'sv2-security-error';
      const title = document.createElement('strong');
      title.textContent = 'Security controls unavailable.';
      const detail = document.createElement('span');
      detail.textContent = 'Retry without leaving Settings.';
      const retry = document.createElement('button');
      retry.type = 'button';
      retry.className = 'btn ghost';
      retry.dataset.settingsSecurityRetry = '1';
      retry.textContent = 'RETRY';
      box.append(title, detail, retry);
      host.replaceChildren(box);
      feedback('error',error?.message === 'AUTH_REQUIRED' ? 'Your admin session needs to be refreshed.' : 'Security / 2FA could not be loaded.');
    }
  }

  function advancedPane() {
    return `<section class="sv2-pane ${V2.tab==='advanced'?'active':''}" data-settings-pane="advanced">
      <header class="sv2-section-head"><div><span>06 / ADVANCED</span><h3>Security & technical tools</h3></div><p>Real configuration only. Internal settings records stay behind validated interfaces instead of being exposed as a raw editor.</p></header>
      <div class="sv2-context-grid sv2-advanced-tools">
        <article class="sv2-tool-card"><span>VISUAL SYSTEM</span><strong>THEME STUDIO</strong><p>Public palette, typography, navigation and effects.</p><button type="button" class="btn ghost" data-settings-open="theme">OPEN THEME STUDIO</button></article>
        <article class="sv2-tool-card"><span>OPERATIONS</span><strong>SYSTEM STATUS</strong><p>Runtime, database, storage, backups and operational diagnostics.</p><button type="button" class="btn ghost" data-settings-open="system">OPEN SYSTEM STATUS</button></article>
      </div>
      <div class="sv2-security-shell">
        <header class="sv2-section-head"><div><span>ACCOUNT SECURITY</span><h3>Two-factor authentication</h3></div><p>Configure 2FA for the signed-in administrator without leaving Settings. Enrollment and disable actions keep the existing CSRF/auth boundaries.</p></header>
        <div class="sv2-security-host" data-settings-security-host data-testid="settings-security-host" aria-live="polite"><p class="sv2-security-state">Loading account security…</p></div>
      </div>
    </section>`;
  }
  function settingsScreen() {
    const requested = requestedSettingsTab();
    if (requested) V2.tab = requested;
    const markup = `<div class="settings-v2" data-settings-v2 data-testid="settings-v2-root">
      <header class="sv2-hero"><div><span>BRVTAL CMS / CONFIGURATION</span><h2>SETTINGS</h2><p>Global behavior, integrations and access to specialized configuration tools.</p></div></header>
      <div class="sv2-layout"><aside class="sv2-tabs" aria-label="Settings sections">${tabs()}</aside><div class="sv2-editor">${generalPane()}${socialPane()}${seoPane()}${analyticsPane()}${integrationsPane()}${advancedPane()}</div></div>
    </div>`;
    queueMicrotask(() => { if (V2.tab === 'advanced') loadSecurity().catch(() => {}); });
    queueMicrotask(() => { if (V2.tab === 'integrations') loadIntegrations().catch(() => {}); });
    return markup;
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
    const description = read('site_description');
    if (!name) throw new Error('Public site name is required.');
    if (description.length > 240 || /[<>]/.test(description)
      || /[\u0000-\u001F\u007F]/.test(description)) {
      throw new Error('Hero description must be plain text (max 240 characters).');
    }
    const heroManifestoEs = read('site_hero_manifesto_es');
    const heroManifestoEn = read('site_hero_manifesto_en');
    for (const value of [heroManifestoEs, heroManifestoEn]) {
      if (value.length > 64 || /[<>]/.test(value) || /[\u0000-\u001F\u007F]/.test(value)) {
        throw new Error('Hero manifesto must be plain text (max 64 characters).');
      }
    }
    const heroCtaEs = read('site_hero_cta_es');
    const heroCtaEn = read('site_hero_cta_en');
    const heroEyebrowEs = read('site_hero_eyebrow_es');
    const heroEyebrowEn = read('site_hero_eyebrow_en');
    const safeCopy = (value, limit) => value.length <= limit
      && !/[<>]/.test(value) && !/[\u0000-\u001F\u007F]/.test(value);
    if (![heroCtaEs,heroCtaEn].every(value => safeCopy(value, 48))
      || ![heroEyebrowEs,heroEyebrowEn].every(value => safeCopy(value, 80))) {
      throw new Error('Hero CTA/eyebrow must be plain text (max 48/80 characters).');
    }
    const assignedMedia = {};
    for (const key of ['hero_face','crowd','artists','nights','memories']) {
      const input = document.getElementById('sv2_c05_media_' + key);
      const url = String(input?.value || '').trim();
      const id = Number(input?.dataset.mediaId || 0);
      if (url && (!Number.isSafeInteger(id) || id < 1
        || !safeCmsMediaPath(url))) {
        throw new Error('Select a published Media Library image.');
      }
      assignedMedia['c05_media_' + key] = url ? {id, url, status:'published'} : null;
    }
    await persistJson('site',{
      ...assignedMedia,
      name,tagline,description,
      hero_manifesto_es:heroManifestoEs,hero_manifesto_en:heroManifestoEn,
      hero_cta_es:heroCtaEs,hero_cta_en:heroCtaEn,
      hero_eyebrow_es:heroEyebrowEs,hero_eyebrow_en:heroEyebrowEn,
    });
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
    if (!/^\/indexnow-[A-Za-z0-9][A-Za-z0-9-]{0,90}\.txt$/.test(keyLocation)) {
      throw new Error('Key location must be a root-level indexnow-*.txt path such as /indexnow-key.txt.');
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

  function validPrivacyText(value,limit) {
    return value.length <= limit && !/[<>\u0000-\u001F\u007F]/.test(value);
  }

  function validPrivacyEmail(value) {
    const at = value.indexOf('@');
    if (at < 1 || at !== value.lastIndexOf('@') || at >= value.length - 1) return false;
    const domain = value.slice(at + 1);
    if (!domain.includes('.') || domain.startsWith('.') || domain.endsWith('.')) return false;
    return ![...value].some(character => /\s/.test(character));
  }

  function validPrivacyPhone(value) {
    if (!value) return true;
    const digits = (value.match(/[0-9]/g) || []).length;
    return /^\+?[0-9][0-9() .-]*$/.test(value) && value.length <= 40 && digits >= 7 && digits <= 20;
  }

  async function persistPrivacyFields(fields,index=0) {
    if (index >= fields.length) return;
    const [key,value] = fields[index];
    if (String(row(key)?.setting_value ?? '').trim() !== value) {
      await req('/settings', {
        method:'POST',
        body:JSON.stringify({setting_key:key,setting_value:value,is_json:0})
      });
    }
    return persistPrivacyFields(fields,index + 1);
  }

  async function savePrivacy() {
    const fields = [
      ['privacy.controller_name',read('privacy_controller_name'),160],
      ['privacy.controller_email',read('privacy_controller_email'),254],
      ['privacy.controller_phone',read('privacy_controller_phone'),40],
      ['privacy.response_time',read('privacy_response_time'),160],
      ['privacy.controller_domicile',read('privacy_controller_domicile'),160],
      ['privacy.controller_address',read('privacy_controller_address'),254],
      ['privacy.rights_contact_area',read('privacy_rights_contact_area'),160],
      ['privacy.consultation_time',read('privacy_consultation_time'),160],
      ['privacy.claim_time',read('privacy_claim_time'),160]
    ];
    for (const [key,value,limit] of fields) {
      if (!validPrivacyText(value,limit)) {
        throw new Error('Privacy fields must contain plain text within the allowed length.');
      }
      if (key === 'privacy.controller_email' && value
        && !validPrivacyEmail(value)) {
        throw new Error('A valid privacy contact email is required.');
      }
    }
    const phone = fields.find(([key]) => key === 'privacy.controller_phone')[1];
    if (!validPrivacyPhone(phone)) {
      throw new Error('A valid public contact phone is required (7–20 digits).');
    }
    await persistPrivacyFields(fields);
    const response = await req('/settings');
    state.rows = response.data || [];
    render();
    requestAnimationFrame(() => activate(V2.tab));
  }

  const saveHandlers = {
    general:saveGeneral,
    social:saveSocial,
    indexnow:saveIndexNow,
    analytics:saveAnalytics,
    privacy:savePrivacy
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
    V2.tab = ['general','social','seo','analytics','integrations','advanced'].includes(id) ? id : 'general';
    const root = document.querySelector('[data-settings-v2]');
    if (!root) return;
    root.querySelectorAll('[data-settings-tab]').forEach(button => button.classList.toggle('active',button.dataset.settingsTab===V2.tab));
    root.querySelectorAll('[data-settings-pane]').forEach(pane => pane.classList.toggle('active',pane.dataset.settingsPane===V2.tab));
    if (V2.tab === 'advanced') loadSecurity().catch(() => {});
    if (V2.tab === 'integrations') loadIntegrations().catch(() => {});
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
    const target = trigger?.dataset.settingsMediaPicker || 'seo_share';
    const images = media.filter(item => item.type === 'image'
      && (target === 'seo_share' || item.status === 'published')
      && (target === 'seo_share' || safeCmsMediaPath(String(item.file_path || ''))));
    const overlay = document.createElement('div');
    overlay.id = 'sv2-media-picker';
    overlay.className = 'sv2-picker-overlay';
    overlay.innerHTML = `<div class="sv2-picker" role="dialog" aria-modal="true" aria-labelledby="sv2-picker-title"><header><div><span>MEDIA LIBRARY</span><h3 id="sv2-picker-title">Choose share image</h3></div><button type="button" data-sv2-close aria-label="Close media picker">×</button></header><label><span>SEARCH</span><input type="search" placeholder="Title or path"></label><div class="sv2-picker-grid">${images.map(item => `<button type="button" data-sv2-media="${esc(item.file_path || '')}" data-sv2-search="${esc((item.title || '')+' '+(item.file_path || ''))}">${item.file_path?`<img src="${esc(item.file_path)}" alt="" loading="lazy">`:''}<strong>${esc(item.title || 'Untitled')}</strong><code>${esc(item.file_path || '')}</code></button>`).join('') || '<p>No image assets available.</p>'}</div></div>`;
    document.body.appendChild(overlay);
    const search = overlay.querySelector('input[type=search]');
    overlay.querySelector('[data-sv2-close]')?.addEventListener('click',closePicker);
    overlay.addEventListener('click',event => { if (event.target===overlay) closePicker(); });
    overlay.querySelectorAll('[data-sv2-media]').forEach(button => button.addEventListener('click',()=>{
      const path = button.dataset.sv2Media || '';
      if (target === 'seo_share') updateAsset(path);
      else {
        const input = document.getElementById('sv2_' + target);
        const item = images.find(row => String(row.file_path) === path);
        if (input && item) {
          input.value = path;
          input.dataset.mediaId = String(item.id);
          input.dispatchEvent(new Event('change', {bubbles:true}));
        }
      }
      closePicker();
    }));
    search?.addEventListener('input',()=>{const q=search.value.toLowerCase();overlay.querySelectorAll('[data-sv2-search]').forEach(card=>{card.hidden=Boolean(q)&&!String(card.dataset.sv2Search||'').toLowerCase().includes(q);});});
    overlay.addEventListener('keydown',event=>{if(event.key==='Escape'){event.preventDefault();closePicker();}});
    search?.focus();
  }

  function clearEditorialMedia(button) {
    const input = document.getElementById('sv2_' + button.dataset.settingsClearMedia);
    if (input) { input.value = ''; delete input.dataset.mediaId; }
  }

  function handleIntegrationClick(event) {
    if(event.target.closest('[data-mail-test-send]')){void sendIntegrationTestMail();return true;}
    const save=event.target.closest('[data-secret-provider-save]');
    if(save){void saveIntegrationProvider(save.dataset.secretProviderSave);return true;}
    const remove=event.target.closest('[data-secret-delete]');
    if(remove){void deleteIntegrationField(remove.dataset.secretDelete);return true;}
    if(event.target.closest('[data-secret-retry]')){void loadIntegrations(true);return true;}
    return false;
  }

  function bind() {
    document.addEventListener('click', event => {
      if(handleIntegrationClick(event))return;
      const tab = event.target.closest('[data-settings-tab]');
      if (tab) { activate(tab.dataset.settingsTab); return; }
      const saveButton = event.target.closest('[data-settings-save]');
      if (saveButton) { save(saveButton.dataset.settingsSave).catch(error => feedback('error', error?.message || 'Settings could not be saved.')); return; }
      const destination = event.target.closest('[data-settings-open]');
      if (destination) {
        const section = destination.dataset.settingsOpen || '';
        if (section === 'system') {
          if (typeof globalThis.tech === 'function') globalThis.tech('system');
          else globalThis.go?.('system');
        } else if (section) globalThis.go?.(section);
        return;
      }
      if (event.target.closest('[data-settings-security-retry]')) { loadSecurity(true).catch(error => feedback('error', error?.message || 'Security settings could not be loaded.')); return; }
      const picker = event.target.closest('[data-settings-media-picker]');
      if (picker) { openPicker(picker).catch(error => feedback('error', error?.message || 'Media picker could not be opened.')); return; }
      const clear = event.target.closest('[data-settings-clear-media]');
      if (clear) clearEditorialMedia(clear);
      if (event.target.closest('[data-settings-clear-asset]')) updateAsset('');
    });
  }

  window.settingsHome = settingsScreen;
  window.openSettingByKey = key => {
    if (key === 'seo') {
      globalThis.go?.('seo');
      return;
    }
    const map = {site:'general',social:'social',indexnow:'seo',analytics:'analytics',integrations:'integrations'};
    if (map[key]) activate(map[key]);
    else legacyOpenSettingByKey?.(key);
  };
  window.BRVTALSettingsV2 = { activate, openRaw:key=>legacyOpenSettingByKey?.(key), jsonValue, reloadSecurity:()=>loadSecurity(true), reloadIntegrations:()=>loadIntegrations(true) };
  bind();
})();
