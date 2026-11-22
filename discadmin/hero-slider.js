(() => {
  'use strict';

  const KEY = 'home.hero.slider';
  const API = '../api/index.php';
  const SETTINGS_READ_PATH = '/settings?key=' + encodeURIComponent(KEY);
  const MEDIA_PICKER_PATH = '/media?view=hero-picker';
  const MAX_SLIDES = 20;
  const MAX_LAYERS = 12;
  const SETTINGS_READ_ATTEMPTS = 2;
  const SETTINGS_RETRY_DELAY_MS = 120;
  const DRAFT_SCOPE = 'hero-slider';
  const DRAFT_ID = KEY;
  const DRAFT_DEBOUNCE_MS = 650;
  const layerTypes = ['text','image','logo','cta'];
  const layerTypeLabel = type => ({text:'TEXTO',image:'IMAGEN',logo:'LOGO',cta:'BOTÓN'})[type] || String(type).toUpperCase();
  const animations = ['none','fade','slide-up','slide-left','zoom'];
  const defaultConfig = () => ({enabled:false,autoplay:true,interval:7000,slides:[]});
  let config = defaultConfig();
  let media = [];
  let mediaReady = false;
  let mediaLoadError = '';
  let loadRevision = 0;
  let selectedId = '';
  let selectedLayerId = '';
  let previewMode = 'desktop';
  let originalGo = null;
  let cleanConfigSnapshot = null;
  let heroDraftTimer = null;
  let heroSaveInFlight = false;
  let heroDraftBaseRevision = '';
  let heroDraftRecovery = null;
  let heroDraftState = {label:'Guardado en el servidor',kind:'server'};
  let lastLoadDiagnostics = {status:'idle',reason:'',revision:0,settingsAttempts:0,hostRecovered:false,error:'',failureClass:'',httpStatus:0,code:'',authRevalidated:false,authRevalidationAttempted:0,authRevalidationSucceeded:0,authRevalidationFailed:0};

  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
  let uidSequence = 0;
  const uid = prefix => {
    const stamp = Date.now().toString(36);
    if (globalThis.crypto?.getRandomValues) {
      const entropy = new Uint32Array(1);
      globalThis.crypto.getRandomValues(entropy);
      return `${prefix}-${stamp}-${entropy[0].toString(36)}`;
    }
    uidSequence += 1;
    return `${prefix}-${stamp}-${uidSequence.toString(36)}`;
  };
  const asBool = value => value === true || value === 1 || value === '1' || value === 'true';
  const clamp = (n,min,max) => Math.max(min,Math.min(max,Number(n) || 0));

  function injectStyles() {
    if (document.querySelector('link[data-hero-v2]')) return;
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = 'hero-slider-v2.css';
    link.dataset.heroV2 = '1';
    document.head.appendChild(link);
  }

  function normalizeLayer(input = {}) {
    const type = layerTypes.includes(input.type) ? input.type : 'text';
    return {
      id: String(input.id || uid('layer')),
      type,
      name: String(input.name || (type === 'cta' ? 'Botón' : type === 'image' ? 'Imagen' : type === 'logo' ? 'Logo' : 'Texto')),
      text: String(input.text || ''),
      src: String(input.src || ''),
      mobileSrc: String(input.mobileSrc || ''),
      url: String(input.url || ''),
      x: clamp(input.x ?? 12,0,100),
      y: clamp(input.y ?? 50,0,100),
      width: clamp(input.width ?? (type === 'text' ? 56 : 28),5,100),
      mobileX: input.mobileX === '' || input.mobileX == null ? null : clamp(input.mobileX,0,100),
      mobileY: input.mobileY === '' || input.mobileY == null ? null : clamp(input.mobileY,0,100),
      mobileWidth: input.mobileWidth === '' || input.mobileWidth == null ? null : clamp(input.mobileWidth,5,100),
      hiddenMobile: asBool(input.hiddenMobile),
      animation: animations.includes(input.animation) ? input.animation : 'fade',
      delay: clamp(input.delay ?? 0,0,10000),
      duration: clamp(input.duration ?? 650,100,5000),
      align: ['left','center','right'].includes(input.align) ? input.align : 'left'
    };
  }

  function normalizeSlide(input = {}) {
    return {
      id: String(input.id || uid('slide')),
      enabled: input.enabled !== false,
      name: String(input.name || input.title || 'Nueva diapositiva'),
      mediaType: input.mediaType === 'video' ? 'video' : 'image',
      desktopSrc: String(input.desktopSrc || ''),
      mobileSrc: String(input.mobileSrc || ''),
      poster: String(input.poster || ''),
      kicker: String(input.kicker || ''),
      title: String(input.title || ''),
      body: String(input.body || ''),
      ctaLabel: String(input.ctaLabel || ''),
      ctaUrl: String(input.ctaUrl || ''),
      contentAlign: ['left','center','right'].includes(input.contentAlign) ? input.contentAlign : 'left',
      overlay: clamp(input.overlay ?? 35,0,85),
      transition: ['fade','slide','zoom'].includes(input.transition) ? input.transition : 'fade',
      layers: Array.isArray(input.layers) ? input.layers.slice(0,MAX_LAYERS).map(normalizeLayer) : []
    };
  }

  function normalizeConfig(input) {
    const next = input && typeof input === 'object' ? input : {};
    return {
      enabled: asBool(next.enabled),
      autoplay: next.autoplay !== false,
      interval: clamp(next.interval || 7000,2500,30000),
      slides: Array.isArray(next.slides) ? next.slides.slice(0,MAX_SLIDES).map(normalizeSlide) : []
    };
  }

  function mediaRecordForPath(path) {
    const reference = String(path || '').trim();
    return media.find(item => String(item.file_path || '') === reference) || null;
  }

  function mediaReferenceError(value, expectedType, required, label) {
    const reference = String(value || '').trim();
    if (!reference) return required ? `${label}: selecciona un archivo para activar esta diapositiva.` : '';
    if (/^https:\/\//i.test(reference)) return '';
    if (!reference.startsWith('/uploads/') || reference.includes('..')) {
      return `${label}: usa un medio de la Biblioteca o un enlace HTTPS válido.`;
    }
    const item = mediaRecordForPath(reference);
    if (!item) return ''; // Picker reads are bounded; the server remains authoritative at save time.
    if (String(item.type || '') !== expectedType) {
      return `${label}: tipo incorrecto (se requiere ${expectedType === 'video' ? 'vídeo' : 'imagen'}).`;
    }
    return '';
  }

  function firstMediaError(checks) {
    for (const [value, type, required, fieldLabel] of checks) {
      const error = mediaReferenceError(value, type, required, fieldLabel);
      if (error) return error;
    }
    return '';
  }

  function layerMediaError(slide, label) {
    const layers = Array.isArray(slide.layers) ? slide.layers : [];
    for (let layerIndex = 0; layerIndex < layers.length; layerIndex += 1) {
      const layer = layers[layerIndex];
      if (!['image','logo'].includes(layer.type)) continue;
      const layerName = layer.name || `Layer ${layerIndex + 1}`;
      const layerLabel = `${label} · capa ${layerIndex + 1} (${layerName})`;
      const error = firstMediaError([
        [layer.src, 'image', false, `${layerLabel}: archivo de escritorio`],
        [layer.mobileSrc, 'image', false, `${layerLabel}: archivo móvil`],
      ]);
      if (error) return error;
    }
    return '';
  }

  function slideMediaError(slide, slideIndex) {
    if (slide.enabled === false) return '';
    const label = `Diapositiva ${slideIndex + 1} (${slide.name || 'sin nombre'})`;
    const expectedType = slide.mediaType === 'video' ? 'video' : 'image';
    const checks = [
      [slide.desktopSrc, expectedType, true, `${label}: archivo de escritorio`],
      [slide.mobileSrc, expectedType, false, `${label}: archivo móvil`],
    ];
    if (expectedType === 'video') {
      checks.push([slide.poster, 'image', false, `${label}: póster de vídeo`]);
    }
    return firstMediaError(checks) || layerMediaError(slide, label);
  }

  function configMediaError(payload) {
    for (let slideIndex = 0; slideIndex < payload.slides.length; slideIndex += 1) {
      const error = slideMediaError(payload.slides[slideIndex], slideIndex);
      if (error) return error;
    }
    return '';
  }

  async function request(path, options = {}) {
    if (typeof window.req === 'function') return window.req(path, options);
    const headers = {'Content-Type':'application/json', ...(options.headers || {})};
    if (window.csrf) headers['X-CSRF-Token'] = window.csrf;
    const response = await fetch(API + path,{...options,headers,credentials:'same-origin'});
    const json = await response.json().catch(() => ({}));
    if (!response.ok) {
      const field = json.field ? ` · ${json.field}` : '';
      const code = String(json.error || 'REQUEST_FAILED');
      const error = new Error(code + field);
      error.code = code;
      error.status = response.status;
      throw error;
    }
    return json;
  }

  function safeSettingsError(error) {
    const message = String(error?.message || 'SETTINGS_READ_FAILED');
    const code = String(error?.code || message.split(' · ')[0] || 'SETTINGS_READ_FAILED');
    const status = Number(error?.status || 0);
    if (status === 401 || ['AUTH_REQUIRED','INVALID_CREDENTIALS'].includes(code)) {
      return { failureClass:'auth', httpStatus:status || 401, code };
    }
    if (status === 429 || code === 'RATE_LIMITED') {
      return { failureClass:'rate-limit', httpStatus:status || 429, code };
    }
    if (status >= 500 || ['SETTINGS_UNAVAILABLE','NETWORK_ERROR','FETCH_FAILED'].includes(code) || error?.name === 'TypeError') {
      return { failureClass:status >= 500 ? 'application-transient' : 'transport', httpStatus:status, code };
    }
    return { failureClass:'application', httpStatus:status, code };
  }

  function retryableSettingsReadError(error) {
    const failure = safeSettingsError(error);
    return failure.failureClass === 'transport' || failure.failureClass === 'application-transient';
  }

  function authRevalidationDiagnostics() {
    const current = window.BRVTALAdminAuthBoundary?.diagnostics?.() || {};
    return {
      attempted:Number(current.revalidationAttempted || 0),
      succeeded:Number(current.revalidationSucceeded || 0),
      failed:Number(current.revalidationFailed || 0),
    };
  }

  function applyAuthRevalidationDelta(diagnostics, before) {
    const current = authRevalidationDiagnostics();
    diagnostics.authRevalidationAttempted = Math.max(0,current.attempted - before.attempted);
    diagnostics.authRevalidationSucceeded = Math.max(0,current.succeeded - before.succeeded);
    diagnostics.authRevalidationFailed = Math.max(0,current.failed - before.failed);
    diagnostics.authRevalidated = diagnostics.authRevalidationAttempted > 0;
  }

  async function readSettingsWithRetry(diagnostics) {
    let lastError = null;
    const authBefore = authRevalidationDiagnostics();
    for (let attempt = 1; attempt <= SETTINGS_READ_ATTEMPTS; attempt += 1) {
      diagnostics.settingsAttempts = attempt;
      try {
        const response = await request(SETTINGS_READ_PATH,{cache:'no-store'});
        applyAuthRevalidationDelta(diagnostics,authBefore);
        return response;
      } catch (error) {
        lastError = error;
        const failure = safeSettingsError(error);
        diagnostics.failureClass = failure.failureClass;
        diagnostics.httpStatus = failure.httpStatus;
        diagnostics.code = failure.code;
        applyAuthRevalidationDelta(diagnostics,authBefore);
        if (attempt >= SETTINGS_READ_ATTEMPTS || !retryableSettingsReadError(error)) break;
        await new Promise(resolve => setTimeout(resolve, SETTINGS_RETRY_DELAY_MS));
      }
    }
    throw lastError || new Error('SETTINGS_READ_FAILED');
  }

  const root = () => document.getElementById('hero-slider-root');

  function recoverWorkspaceHost(revision) {
    if (revision !== loadRevision
      || window.state?.section !== 'hero-slider'
      || window.state?.authed === false) return null;
    return root() || prepareWorkspace();
  }

  function configSnapshot(value = config) {
    return JSON.stringify(normalizeConfig(value));
  }

  function markConfigClean(value = config) {
    cleanConfigSnapshot = configSnapshot(value);
  }

  function hasUnsavedChanges() {
    return cleanConfigSnapshot !== null && configSnapshot() !== cleanConfigSnapshot;
  }

  function draftApi() {
    const api = window.BRVTALDrafts;
    return api && typeof api.save === 'function' && typeof api.load === 'function' ? api : null;
  }

  function canonicalRevisionValue(value) {
    if (value === undefined) return 'null';
    if (Array.isArray(value)) return '[' + value.map(canonicalRevisionValue).join(',') + ']';
    if (value && typeof value === 'object') {
      return '{' + Object.keys(value).sort((a, b) => a.localeCompare(b)).map(key =>
        JSON.stringify(key) + ':' + canonicalRevisionValue(value[key])
      ).join(',') + '}';
    }
    return JSON.stringify(value) ?? 'null';
  }

  async function configRevision(value = defaultConfig()) {
    const snapshot = canonicalRevisionValue(value ?? defaultConfig());
    if (!globalThis.crypto?.subtle || typeof TextEncoder !== 'function') return snapshot;
    const digest = await globalThis.crypto.subtle.digest('SHA-256', new TextEncoder().encode(snapshot));
    return [...new Uint8Array(digest)].map(byte => byte.toString(16).padStart(2,'0')).join('');
  }

  function setHeroDraftState(label, kind = 'unsaved') {
    heroDraftState = {label:String(label || ''),kind:String(kind || 'unsaved')};
    const node = root()?.querySelector('[data-hero-draft-state]');
    if (!node) return;
    node.textContent = heroDraftState.label;
    node.dataset.kind = heroDraftState.kind;
  }

  function clearHeroDraftTimer() {
    if (heroDraftTimer !== null) window.clearTimeout(heroDraftTimer);
    heroDraftTimer = null;
  }

  function heroDraftStatusMarkup() {
    return `<div class="hero-draft-status" role="status" aria-live="polite"><span data-hero-draft-state data-kind="${esc(heroDraftState.kind)}">${esc(heroDraftState.label)}</span><small>Borrador local de recuperación. Publica con el botón Guardar.</small></div>`;
  }

  function heroDraftConflict() {
    const api = draftApi();
    return Boolean(heroDraftRecovery && api && !api.sameRevision(heroDraftRecovery, heroDraftBaseRevision));
  }

  function heroDraftRecoveryMarkup() {
    if (!heroDraftRecovery) return '';
    const conflict = heroDraftConflict();
    const savedAt = heroDraftRecovery.saved_at ? new Date(heroDraftRecovery.saved_at).toLocaleString('es-CO') : 'hace poco';
    return `<div class="hero-draft-recovery" data-hero-draft-recovery data-conflict="${conflict ? '1' : '0'}" role="region" aria-label="Recuperar borrador local de banners"><div><strong>BORRADOR LOCAL DISPONIBLE${conflict ? ' · EL SERVIDOR CAMBIÓ' : ''}</strong><small>Guardado ${esc(savedAt)}. Recuperarlo solo modifica este editor; nada se publica hasta pulsar Guardar.</small></div><div class="hero-draft-recovery-actions"><button type="button" class="btn ghost" data-hero-draft-discard>DESCARTAR</button><button type="button" class="btn ghost" data-hero-draft-restore>RECUPERAR</button></div></div>`;
  }

  async function persistHeroDraft() {
    clearHeroDraftTimer();
    const api = draftApi();
    if (!api) {
      setHeroDraftState('Error al guardar · recuperación local no disponible', 'error');
      return false;
    }
    const payload = normalizeConfig(config);
    const submittedSnapshot = configSnapshot(payload);
    if (cleanConfigSnapshot !== null && submittedSnapshot === cleanConfigSnapshot) {
      setHeroDraftState('Guardado en el servidor', 'server');
      return true;
    }
    setHeroDraftState('Guardando', 'saving');
    try {
      await api.save(DRAFT_SCOPE, DRAFT_ID, {
        base_revision: heroDraftBaseRevision,
        data: payload
      });
      if (configSnapshot() !== submittedSnapshot) {
        setHeroDraftState('Sin guardar', 'unsaved');
        heroDraftTimer = window.setTimeout(() => { void persistHeroDraft(); }, DRAFT_DEBOUNCE_MS);
      } else {
        setHeroDraftState('Borrador guardado localmente', 'saved');
      }
      return true;
    } catch (error) {
      if (error?.name === 'AbortError') return false;
      setHeroDraftState('Error al guardar · mantén abierto el editor', 'error');
      return false;
    }
  }

  function markHeroDraftDirty() {
    setHeroDraftState('Sin guardar', 'unsaved');
    clearHeroDraftTimer();
    if (heroSaveInFlight) return;
    heroDraftTimer = window.setTimeout(() => { void persistHeroDraft(); }, DRAFT_DEBOUNCE_MS);
  }

  async function loadHeroDraftRecovery(serverRevisionSource = config) {
    clearHeroDraftTimer();
    heroDraftRecovery = null;
    setHeroDraftState('Guardado en el servidor', 'server');
    heroDraftBaseRevision = await configRevision(serverRevisionSource);
    const api = draftApi();
    if (!api) return;
    const draft = await api.load(DRAFT_SCOPE, DRAFT_ID);
    if (!draft) return;
    if (configSnapshot(draft.data) === configSnapshot(config)) {
      try { await api.remove(DRAFT_SCOPE, DRAFT_ID); } catch (_) {}
      return;
    }
    heroDraftRecovery = draft;
    setHeroDraftState('Borrador guardado localmente', 'saved');
  }

  function normalizeSelection() {
    if (!config.slides.some(slide => slide.id === selectedId)) selectedId = config.slides[0]?.id || '';
    const slide = selectedSlide();
    if (!slide?.layers.some(layer => layer.id === selectedLayerId)) selectedLayerId = slide?.layers[0]?.id || '';
  }

  function restoreHeroDraft() {
    if (!heroDraftRecovery) return;
    config = normalizeConfig(heroDraftRecovery.data);
    heroDraftRecovery = null;
    normalizeSelection();
    setHeroDraftState('Borrador guardado localmente', 'saved');
    renderManager();
  }

  async function discardHeroDraft() {
    clearHeroDraftTimer();
    const api = draftApi();
    if (api) {
      try { await api.remove(DRAFT_SCOPE, DRAFT_ID); } catch (_) {}
    }
    heroDraftRecovery = null;
    setHeroDraftState('Guardado en el servidor', 'server');
    renderManager();
  }

  function bannersWorkspaceActive() {
    return window.state?.section === 'hero-slider' || Boolean(root());
  }

  function requestNavigation(section) {
    if (!bannersWorkspaceActive() || !hasUnsavedChanges()) return true;
    const action = section === 'hero-slider' ? 'recargar Banners' : 'salir de Banners';
    return window.confirm(`Tienes cambios sin guardar en Banners. ¿Quieres descartarlos y ${action}?`);
  }

  function commitNavigation(section) {
    if (!section || cleanConfigSnapshot === null) return;
    markConfigClean();
  }

  function handleBeforeUnload(event) {
    if (!bannersWorkspaceActive() || !hasUnsavedChanges()) return;
    event.preventDefault();
    event.returnValue = '';
  }

  const selectedSlide = () => config.slides.find(slide => slide.id === selectedId) || config.slides[0] || null;
  const selectedLayer = () => selectedSlide()?.layers.find(layer => layer.id === selectedLayerId) || null;

  function ensureNav() {
    const nav = document.querySelector('.nav');
    if (!nav) return;
    let button = nav.querySelector('[data-admin-nav="hero-slider"]');
    if (!button) {
      button = document.createElement('button');
      button.type = 'button';
      button.dataset.adminNav = 'hero-slider';
      button.textContent = 'BANNERS DE PORTADA';
      button.addEventListener('click', () => window.go('hero-slider'));
      const dashboard = [...nav.querySelectorAll('button')].find(node => node.textContent.trim().toUpperCase() === 'DASHBOARD');
      if (dashboard?.nextSibling) nav.insertBefore(button,dashboard.nextSibling); else nav.prepend(button);
    }
    button.classList.toggle('active',window.state?.section === 'hero-slider');
  }

  function prepareWorkspace() {
    window.state.section = 'hero-slider';
    window.render();
    ensureNav();
    const main = document.querySelector('.main');
    if (!main) return null;
    const top = main.querySelector('.top');
    if (top) {
      top.querySelector('.eyebrow') && (top.querySelector('.eyebrow').textContent = 'HOME / EXPERIENCE');
      top.querySelector('h1') && (top.querySelector('h1').textContent = 'BANNERS DE PORTADA');
    }
    [...main.children].forEach(child => { if (child !== top) child.remove(); });
    const host = document.createElement('div');
    host.id = 'hero-slider-root';
    main.appendChild(host);
    return host;
  }

  function mediaSaveLabel() {
    if (mediaReady) return 'SAVE';
    return mediaLoadError ? 'MEDIA UNAVAILABLE' : 'MEDIA LOADING…';
  }

  function syncMediaControls(host) {
    if (!host) return;
    host.querySelectorAll('[data-media-picker],[data-layer-media]').forEach(button => { button.disabled = !mediaReady; });
    const saveButton = host.querySelector('[data-save-slider]');
    if (saveButton) {
      saveButton.disabled = !mediaReady;
      saveButton.textContent = mediaSaveLabel();
    }
  }

  function applyMediaLoad(result, revision) {
    if (revision !== loadRevision || !root()) return;
    if (result.ok) {
      media = Array.isArray(result.response?.data) ? result.response.data : [];
      mediaReady = true;
      mediaLoadError = '';
    } else {
      media = [];
      mediaReady = false;
      mediaLoadError = String(result.error?.message || 'REQUEST_FAILED');
    }
    syncMediaControls(root());
  }

  function currentHeroLoad(revision) {
    return revision === loadRevision && window.state?.section === 'hero-slider' && Boolean(root());
  }

  function ignoreMediaPromise(mediaPromise) {
    if (mediaPromise === null) return;
    void mediaPromise.then(() => {});
  }

  async function load() {
    const host = root();
    if (!host) {
      lastLoadDiagnostics = {status:'failed',reason:'host-missing-before-load',revision:loadRevision,settingsAttempts:0,hostRecovered:false,error:'',failureClass:'application',httpStatus:0,code:'HOST_MISSING',authRevalidated:false,authRevalidationAttempted:0,authRevalidationSucceeded:0,authRevalidationFailed:0};
      return false;
    }
    const revision = ++loadRevision;
    const diagnostics = {status:'loading',reason:'',revision,settingsAttempts:0,hostRecovered:false,error:'',failureClass:'',httpStatus:0,code:'',authRevalidated:false,authRevalidationAttempted:0,authRevalidationSucceeded:0,authRevalidationFailed:0};
    lastLoadDiagnostics = diagnostics;
    host.innerHTML = '<div class="hero-slider-loading">CARGANDO BANNERS…</div>';
    media = [];
    mediaReady = false;
    mediaLoadError = '';
    let mediaPromise = null;

    try {
      const settingsResponse = await readSettingsWithRetry(diagnostics);
      if (revision !== loadRevision) {
        diagnostics.status = 'aborted';
        diagnostics.reason = 'stale-revision';
        return false;
      }
      let activeHost = root();
      if (!activeHost) {
        activeHost = recoverWorkspaceHost(revision);
        diagnostics.hostRecovered = Boolean(activeHost);
        if (!activeHost) {
          diagnostics.status = 'aborted';
          diagnostics.reason = 'host-missing-after-settings';
          return false;
        }
      }
      const settings = Array.isArray(settingsResponse.data) ? settingsResponse.data : [];
      const record = settings.find(item => item.setting_key === KEY);
      let stored = record?.setting_value || null;
      if (typeof stored === 'string') { try { stored = JSON.parse(stored); } catch (_) { stored = null; } }
      const serverRevisionSource = stored && typeof stored === 'object' ? stored : defaultConfig();
      config = normalizeConfig(stored);
      markConfigClean(config);
      normalizeSelection();
      await loadHeroDraftRecovery(serverRevisionSource);
      // Draft recovery is async; do not render or fetch Media after navigation changes.
      if (!currentHeroLoad(revision)) {
        diagnostics.status = 'aborted';
        diagnostics.reason = 'stale-revision';
        return false;
      }
      renderManager();
      diagnostics.status = 'loaded';
      diagnostics.reason = '';
      // Resolve Settings before opening the second DB-backed GET.
      // Media stays asynchronous; errors degrade its controls rather than Banners.
      mediaPromise = request(MEDIA_PICKER_PATH,{cache:'no-store'}).then(
        response => ({ok:true,response,error:null}),
        error => ({ok:false,response:null,error})
      );
      void mediaPromise
        .then(result => applyMediaLoad(result, revision))
        .catch(error => {
          if (revision !== loadRevision) return;
          media = [];
          mediaReady = false;
          mediaLoadError = String(error?.message || 'MEDIA_RENDER_FAILED');
          console.warn('[BRVTAL] Hero Slider media controls degraded.', error);
          syncMediaControls(root());
        });
      return true;
    } catch (error) {
      diagnostics.status = 'failed';
      diagnostics.reason = 'settings-read-failed';
      diagnostics.error = String(error?.message || 'Unknown error');
      const errorHost = root() || recoverWorkspaceHost(revision);
      if (errorHost) {
        errorHost.replaceChildren();
        const errorNode = document.createElement('div');
        errorNode.className = 'hero-slider-error';
        errorNode.textContent = `No se pudieron cargar los banners: ${diagnostics.error}`;
        errorHost.appendChild(errorNode);
      }
      ignoreMediaPromise(mediaPromise);
      return false;
    }
  }

  function slideList() {
    if (!config.slides.length) return '<div class="hero-slider-empty">TODAVÍA NO HAY BANNERS<br><span>Crea el primer banner. Si no hay contenido publicado, se conserva la portada original.</span></div>';
    return config.slides.map((slide,index) => `<button type="button" class="hero-slide-row ${slide.id === selectedId ? 'active' : ''}" data-select-slide="${esc(slide.id)}"><span class="hero-slide-index">${String(index+1).padStart(2,'0')}</span><span class="hero-slide-row-copy"><strong>${esc(slide.name)}</strong><small>${esc(slide.mediaType.toUpperCase())} · ${slide.layers.length} CAPAS · ${slide.enabled ? 'ACTIVA' : 'OCULTA'}</small></span><span class="hero-slide-row-actions"><i data-move="up" data-id="${esc(slide.id)}">↑</i><i data-move="down" data-id="${esc(slide.id)}">↓</i></span></button>`).join('');
  }

  function layerList(slide) {
    if (!slide) return '';
    const rows = slide.layers.map((layer,index) => `<button type="button" class="hero-layer-row ${layer.id === selectedLayerId ? 'active' : ''}" data-select-layer="${esc(layer.id)}"><span>${String(index+1).padStart(2,'0')}</span><strong>${esc(layer.name)}</strong><small>${esc(layerTypeLabel(layer.type))}</small></button>`).join('');
    return `<div class="hero-layer-list"><div class="hero-layer-list-head"><strong>CAPAS</strong><span>${slide.layers.length}/${MAX_LAYERS}</span></div>${rows || '<p class="hero-layer-empty">Sin capas. Puedes utilizar los campos de título y descripción.</p>'}<div class="hero-layer-add">${layerTypes.map(type => `<button type="button" data-add-layer="${type}">+ ${layerTypeLabel(type)}</button>`).join('')}</div></div>`;
  }

  function legacyEditor(slide) {
    if (!slide) return '<div class="hero-slider-no-selection"><strong>CREA UNA DIAPOSITIVA</strong><p>Añade un banner y, si lo necesitas, incorpora capas visuales.</p></div>';
    return `<div class="hero-editor-head"><div><span class="hero-kicker">CONFIGURACIÓN DEL BANNER</span><strong>${esc(slide.name)}</strong></div><div><button type="button" class="btn ghost" data-duplicate-slide>DUPLICAR</button><button type="button" class="btn ghost" data-delete-slide>ELIMINAR</button></div></div>
      <div class="hero-form-grid">
        <label><span>Nombre para identificar el banner</span><input data-field="name" value="${esc(slide.name)}"></label>
        <label class="hero-switch"><input type="checkbox" data-field="enabled" ${slide.enabled ? 'checked' : ''}><span>Banner activo</span></label>
        <label><span>Tipo de archivo</span><select data-field="mediaType"><option value="image" ${slide.mediaType==='image'?'selected':''}>Imagen</option><option value="video" ${slide.mediaType==='video'?'selected':''}>Vídeo</option></select></label>
        <label><span>Transición</span><select data-field="transition">${['fade','slide','zoom'].map(v => `<option value="${v}" ${slide.transition===v?'selected':''}>${v}</option>`).join('')}</select></label>
        <label class="full"><span>Archivo para escritorio <em>Biblioteca de medios o enlace HTTPS</em></span><div class="hero-media-pair"><input data-field="desktopSrc" value="${esc(slide.desktopSrc)}"><button type="button" class="media-picker-btn" data-media-picker="desktopSrc" data-media-type="${slide.mediaType === 'video' ? 'video' : 'image'}">ELEGIR ARCHIVO</button></div></label>
        <label class="full"><span>Archivo para móvil <em>Opcional · Biblioteca de medios o enlace HTTPS</em></span><div class="hero-media-pair"><input data-field="mobileSrc" value="${esc(slide.mobileSrc)}"><button type="button" class="media-picker-btn" data-media-picker="mobileSrc" data-media-type="${slide.mediaType === 'video' ? 'video' : 'image'}">ELEGIR ARCHIVO</button></div></label>
        ${slide.mediaType==='video'?`<label class="full"><span>Portada del vídeo</span><div class="hero-media-pair"><input data-field="poster" value="${esc(slide.poster)}"><button type="button" class="media-picker-btn" data-media-picker="poster" data-media-type="image">ELEGIR ARCHIVO</button></div></label>`:''}
        <label class="full"><span>Antetítulo alternativo</span><input data-field="kicker" value="${esc(slide.kicker)}"></label>
        <label class="full"><span>Título alternativo</span><input data-field="title" value="${esc(slide.title)}"></label>
        <label class="full"><span>Descripción alternativa</span><textarea data-field="body">${esc(slide.body)}</textarea></label>
        <label><span>Texto del botón alternativo</span><input data-field="ctaLabel" value="${esc(slide.ctaLabel)}"></label>
        <label><span>Enlace del botón alternativo</span><input data-field="ctaUrl" value="${esc(slide.ctaUrl)}"></label>
        <label class="full"><span>Oscurecimiento · <b data-overlay-value>${slide.overlay}%</b></span><input type="range" min="0" max="85" step="5" data-field="overlay" value="${slide.overlay}"></label>
      </div>${layerList(slide)}${layerEditor(selectedLayer())}`;
  }

  function layerEditor(layer) {
    if (!layer) return '';
    const imageFields = layer.type === 'image' || layer.type === 'logo';
    return `<div class="hero-layer-editor"><div class="hero-editor-head"><div><span class="hero-kicker">CAPA SELECCIONADA</span><strong>${esc(layer.name)}</strong></div><button type="button" class="btn ghost" data-delete-layer>ELIMINAR CAPA</button></div><div class="hero-form-grid">
      <label><span>Nombre</span><input data-layer-field="name" value="${esc(layer.name)}"></label>
      <label><span>Tipo</span><input value="${esc(layer.type)}" disabled></label>
      ${imageFields?`<label class="full"><span>Imagen para escritorio</span><div class="hero-media-pair"><input data-layer-field="src" value="${esc(layer.src)}"><button type="button" class="media-picker-btn" data-layer-media="src" data-media-type="image">ELEGIR ARCHIVO</button></div></label><label class="full"><span>Imagen para móvil</span><div class="hero-media-pair"><input data-layer-field="mobileSrc" value="${esc(layer.mobileSrc)}"><button type="button" class="media-picker-btn" data-layer-media="mobileSrc" data-media-type="image">ELEGIR ARCHIVO</button></div></label>`:`<label class="full"><span>Texto</span><textarea data-layer-field="text">${esc(layer.text)}</textarea></label>`}
      ${layer.type==='cta'?`<label class="full"><span>Enlace</span><input data-layer-field="url" value="${esc(layer.url)}"></label>`:''}
      <label><span>X %</span><input type="number" min="0" max="100" data-layer-field="x" value="${layer.x}"></label><label><span>Y %</span><input type="number" min="0" max="100" data-layer-field="y" value="${layer.y}"></label>
      <label><span>Ancho %</span><input type="number" min="5" max="100" data-layer-field="width" value="${layer.width}"></label><label><span>Alineación</span><select data-layer-field="align">${['left','center','right'].map(v=>`<option value="${v}" ${layer.align===v?'selected':''}>${v}</option>`).join('')}</select></label>
      <label><span>Animación</span><select data-layer-field="animation">${animations.map(v=>`<option value="${v}" ${layer.animation===v?'selected':''}>${v}</option>`).join('')}</select></label><label><span>Espera (ms)</span><input type="number" min="0" max="10000" step="50" data-layer-field="delay" value="${layer.delay}"></label>
      <label><span>Duración (ms)</span><input type="number" min="100" max="5000" step="50" data-layer-field="duration" value="${layer.duration}"></label><label class="hero-switch"><input type="checkbox" data-layer-field="hiddenMobile" ${layer.hiddenMobile?'checked':''}><span>Ocultar en móvil</span></label>
      <label><span>Posición X móvil (%)</span><input type="number" min="0" max="100" data-layer-field="mobileX" value="${layer.mobileX ?? ''}" placeholder="Heredar"></label><label><span>Posición Y móvil (%)</span><input type="number" min="0" max="100" data-layer-field="mobileY" value="${layer.mobileY ?? ''}" placeholder="Heredar"></label>
      <label><span>Ancho móvil (%)</span><input type="number" min="5" max="100" data-layer-field="mobileWidth" value="${layer.mobileWidth ?? ''}" placeholder="Heredar"></label>
    </div></div>`;
  }

  function appendPreviewText(parent, tagName, value) {
    if (!value) return;
    const node = document.createElement(tagName);
    node.textContent = String(value);
    parent.appendChild(node);
  }

  function appendPreviewMedia(stage, slide) {
    const src = previewMode === 'mobile' && slide.mobileSrc ? slide.mobileSrc : slide.desktopSrc;
    if (!src) {
      const empty = document.createElement('div');
      empty.className = 'hero-preview-media-empty';
      empty.textContent = 'ELEGIR ARCHIVO';
      stage.appendChild(empty);
      return;
    }
    if (slide.mediaType === 'video') {
      const video = document.createElement('video');
      video.muted = true;
      video.loop = true;
      video.playsInline = true;
      video.autoplay = true;
      if (slide.poster) video.poster = slide.poster;
      const source = document.createElement('source');
      source.src = src;
      video.appendChild(source);
      stage.appendChild(video);
      return;
    }
    const image = document.createElement('img');
    image.src = src;
    image.alt = '';
    stage.appendChild(image);
  }

  function responsiveLayerValue(layer, mobile, desktopKey, mobileKey) {
    return mobile && layer[mobileKey] != null ? layer[mobileKey] : layer[desktopKey];
  }

  function appendPreviewLayerContent(node, layer, src) {
    if (layer.type === 'image' || layer.type === 'logo') {
      if (!src) {
        appendPreviewText(node, 'span', layer.name);
        return;
      }
      const image = document.createElement('img');
      image.src = src;
      image.alt = '';
      node.appendChild(image);
      return;
    }
    const tagName = layer.type === 'cta' ? 'b' : 'span';
    const value = layer.type === 'cta' ? `${layer.text || 'CTA'} ↗` : layer.text || layer.name;
    appendPreviewText(node, tagName, value);
  }

  function appendPreviewLayer(stage, layer) {
    const mobile = previewMode === 'mobile';
    if (mobile && layer.hiddenMobile) return;
    const x = responsiveLayerValue(layer, mobile, 'x', 'mobileX');
    const y = responsiveLayerValue(layer, mobile, 'y', 'mobileY');
    const width = responsiveLayerValue(layer, mobile, 'width', 'mobileWidth');
    const src = mobile && layer.mobileSrc ? layer.mobileSrc : layer.src;
    const node = document.createElement('div');
    node.className = `hero-preview-layer type-${layer.type}${layer.id === selectedLayerId ? ' selected' : ''}`;
    node.dataset.previewLayer = layer.id;
    node.dataset.animation = layer.animation;
    node.style.left = `${x}%`;
    node.style.top = `${y}%`;
    node.style.width = `${width}%`;
    node.style.textAlign = layer.align;
    node.style.setProperty('--delay', `${layer.delay}ms`);
    node.style.setProperty('--duration', `${layer.duration}ms`);
    appendPreviewLayerContent(node, layer, src);
    stage.appendChild(node);
  }

  function renderPreview(frame, slide) {
    if (!frame) return;
    frame.replaceChildren();
    frame.className = `hero-preview-frame ${previewMode === 'mobile' ? 'mobile' : 'desktop'}`;
    if (!slide) {
      const placeholder = document.createElement('div');
      placeholder.className = 'hero-preview-placeholder';
      placeholder.textContent = 'CURRENT BRVTAL HERO REMAINS ACTIVE UNTIL A SLIDE IS PUBLISHED';
      frame.appendChild(placeholder);
      return;
    }

    const stage = document.createElement('div');
    stage.className = `hero-preview-stage align-${slide.contentAlign}`;
    stage.tabIndex = 0;
    stage.setAttribute('role', 'region');
    stage.setAttribute('aria-label', `Vista previa Cromo de ${slide.name}`);
    stage.style.setProperty('--hero-overlay', String(slide.overlay / 100));
    appendPreviewMedia(stage, slide);

    // Match the Cromo public banner: duotone tint above media, below copy and handles.
    const tint = document.createElement('div');
    tint.className = 'hero-preview-tint';
    tint.setAttribute('aria-hidden', 'true');
    stage.appendChild(tint);

    const overlay = document.createElement('div');
    overlay.className = 'hero-preview-overlay';
    stage.appendChild(overlay);

    if (slide.layers.length) {
      slide.layers.forEach(layer => appendPreviewLayer(stage, layer));
    } else {
      const copy = document.createElement('div');
      copy.className = 'hero-preview-copy';
      appendPreviewText(copy, 'span', slide.kicker);
      appendPreviewText(copy, 'h2', slide.title);
      appendPreviewText(copy, 'p', slide.body);
      appendPreviewText(copy, 'b', slide.ctaLabel ? `${slide.ctaLabel} ↗` : '');
      stage.appendChild(copy);
    }
    // An editor-only visual index: no new fields are saved to the slide model.
    const position = Math.max(0, config.slides.findIndex(item => item.id === slide.id));
    const counter = document.createElement('p');
    counter.className = 'hero-preview-counter';
    counter.setAttribute('aria-hidden', 'true');
    appendPreviewText(counter, 'b', String(position + 1).padStart(2, '0'));
    appendPreviewText(counter, 'span', '/ ' + String(config.slides.length).padStart(2, '0'));
    stage.appendChild(counter);

    // Echo the public carousel's progress controls visually. This is purely
    // decorative: no additional timer, event handler or model fields.
    if (config.slides.length > 1) {
      const progress = document.createElement('div');
      progress.className = 'hero-preview-progress';
      progress.setAttribute('aria-hidden', 'true');
      config.slides.forEach((candidate, index) => {
        const bar = document.createElement('span');
        bar.className = 'hero-preview-progress-bar';
        bar.dataset.active = index === position ? 'true' : 'false';
        progress.appendChild(bar);
      });
      stage.appendChild(progress);
    }
    frame.appendChild(stage);
  }

  function intervalOptions() {
    return [4000,5000,7000,9000,12000].map(ms => {
      const selected = config.interval === ms ? ' selected' : '';
      return `<option value="${ms}"${selected}>${ms / 1000}s</option>`;
    }).join('');
  }

  function renderManager() {
    const host = root();
    if (!host) return;
    const slide = selectedSlide();
    host.innerHTML = `<section class="hero-manager"><div class="hero-manager-toolbar"><div><span class="hero-kicker">PORTADA</span><h2>Banners de portada</h2><p>Organiza banners y capas con ajustes independientes para móvil.</p></div><div class="hero-manager-actions"><label class="hero-switch"><input type="checkbox" data-config-field="enabled" ${config.enabled?'checked':''}><span>Publicar banners</span></label><button type="button" class="btn ghost" data-add-slide>+ NUEVO BANNER</button><button type="button" class="btn red" data-save-slider ${mediaReady?'':'disabled'}>${mediaSaveLabel()}</button></div></div><div class="hero-manager-global"><label class="hero-switch"><input type="checkbox" data-config-field="autoplay" ${config.autoplay?'checked':''}><span>Reproducción automática</span></label><label><span>Duración de cada banner</span><select data-config-field="interval">${intervalOptions()}</select></label><span class="hero-manager-fallback">Si no hay banners disponibles, se muestra la portada original de BRVTAL.</span></div><div class="hero-manager-grid"><aside class="hero-slide-list"><div class="hero-slide-list-head"><strong>BANNERS</strong><span>${config.slides.length}/${MAX_SLIDES}</span></div>${slideList()}</aside><section class="hero-slide-editor">${legacyEditor(slide)}</section><section class="hero-preview-panel"><div class="hero-preview-head"><strong>VISTA PREVIA · arrastra la capa seleccionada</strong><div><button type="button" data-preview="desktop" class="${previewMode==='desktop'?'active':''}">ESCRITORIO</button><button type="button" data-preview="mobile" class="${previewMode==='mobile'?'active':''}">MÓVIL</button></div></div><div class="hero-preview-frame ${previewMode === 'mobile' ? 'mobile' : 'desktop'}"></div></section></div></section>`;
    const draftAnchor = host.querySelector('.hero-manager-global');
    draftAnchor?.insertAdjacentHTML('beforebegin', heroDraftStatusMarkup() + heroDraftRecoveryMarkup());
    syncMediaControls(host);
    renderPreview(host.querySelector('.hero-preview-frame'), slide);
    bind();
  }

  function refreshPreview() {
    const frame = document.querySelector('.hero-preview-frame');
    if (!frame) return;
    renderPreview(frame, selectedSlide());
    bindPreviewDrag();
  }

  function slideFieldValue(target, field) {
    if (target.type === 'checkbox') return target.checked;
    if (field === 'overlay') return clamp(target.value,0,85);
    return target.value;
  }

  function layerFieldValue(target, field) {
    if (target.type === 'checkbox') return target.checked;
    if (['x','y','width','mobileX','mobileY','mobileWidth','delay','duration'].includes(field)) {
      if (target.value === '') return null;
      return Number(target.value);
    }
    return target.value;
  }

  function updateSlide(target) {
    const slide = selectedSlide(); if (!slide) return;
    const field = target.dataset.field; if (!field) return;
    const nextValue = slideFieldValue(target, field);
    if (slide[field] === nextValue) return;
    slide[field] = nextValue;
    markHeroDraftDirty();
    if (field === 'overlay') document.querySelector('[data-overlay-value]') && (document.querySelector('[data-overlay-value]').textContent = slide.overlay + '%');
    if (field === 'mediaType') renderManager(); else refreshPreview();
  }

  function updateLayer(target) {
    const layer = selectedLayer(); if (!layer) return;
    const field = target.dataset.layerField; if (!field) return;
    const nextValue = layerFieldValue(target, field);
    if (layer[field] === nextValue) return;
    layer[field] = nextValue;
    markHeroDraftDirty();
    refreshPreview();
  }

  function moveSlide(id,direction) {
    const index = config.slides.findIndex(slide=>slide.id===id); if (index<0) return;
    const next = direction==='up'?index-1:index+1; if (next<0||next>=config.slides.length) return;
    [config.slides[index],config.slides[next]]=[config.slides[next],config.slides[index]];
    markHeroDraftDirty();
    renderManager();
  }

  function bindPreviewDrag() {
    const stage = root()?.querySelector('.hero-preview-stage');
    if (!stage) return;
    stage.querySelectorAll('[data-preview-layer]').forEach(node => {
      node.addEventListener('pointerdown', event => {
        selectedLayerId = node.dataset.previewLayer;
        node.setPointerCapture?.(event.pointerId);
        const move = ev => {
          const rect = stage.getBoundingClientRect();
          const layer = selectedLayer(); if (!layer) return;
          const x = clamp(((ev.clientX-rect.left)/rect.width)*100,0,100);
          const y = clamp(((ev.clientY-rect.top)/rect.height)*100,0,100);
          if (previewMode === 'mobile') { layer.mobileX = Math.round(x); layer.mobileY = Math.round(y); }
          else { layer.x = Math.round(x); layer.y = Math.round(y); }
          node.style.left = x + '%'; node.style.top = y + '%';
        };
        const up = () => {
          window.removeEventListener('pointermove',move);
          window.removeEventListener('pointerup',up);
          markHeroDraftDirty();
          renderManager();
        };
        window.addEventListener('pointermove',move); window.addEventListener('pointerup',up,{once:true});
      });
      node.addEventListener('click', () => { selectedLayerId = node.dataset.previewLayer; renderManager(); });
    });
  }

  function selectSlide(button, event) {
    if (event.target.closest('[data-move]')) return;
    selectedId = button.dataset.selectSlide;
    selectedLayerId = selectedSlide()?.layers[0]?.id || '';
    renderManager();
  }

  function addSlide() {
    if (config.slides.length >= MAX_SLIDES) return;
    const slide = normalizeSlide({ name:'New slide ' + (config.slides.length + 1) });
    config.slides.push(slide);
    selectedId = slide.id;
    selectedLayerId = '';
    markHeroDraftDirty();
    renderManager();
  }

  function duplicateSlide() {
    const slide = selectedSlide();
    if (!slide || config.slides.length >= MAX_SLIDES) return;
    const copy = normalizeSlide(JSON.parse(JSON.stringify(slide)));
    copy.id = uid('slide');
    copy.name = slide.name + ' copy';
    copy.layers = copy.layers.map(layer => ({ ...layer, id:uid('layer') }));
    config.slides.splice(config.slides.indexOf(slide) + 1, 0, copy);
    selectedId = copy.id;
    selectedLayerId = copy.layers[0]?.id || '';
    markHeroDraftDirty();
    renderManager();
  }

  function deleteSlide() {
    const index = config.slides.findIndex(slide => slide.id === selectedId);
    if (index < 0) return;
    config.slides.splice(index, 1);
    selectedId = config.slides[Math.min(index, config.slides.length - 1)]?.id || '';
    selectedLayerId = '';
    markHeroDraftDirty();
    renderManager();
  }

  function addLayer(type) {
    const slide = selectedSlide();
    if (!slide || slide.layers.length >= MAX_LAYERS) return;
    const text = type === 'cta' ? 'ENTER EXPERIENCE' : type === 'text' ? 'NEW TEXT' : '';
    const layer = normalizeLayer({ type, text });
    slide.layers.push(layer);
    selectedLayerId = layer.id;
    markHeroDraftDirty();
    renderManager();
  }

  function deleteLayer() {
    const slide = selectedSlide();
    if (!slide) return;
    const index = slide.layers.findIndex(layer => layer.id === selectedLayerId);
    if (index < 0) return;
    slide.layers.splice(index, 1);
    selectedLayerId = slide.layers[Math.min(index, slide.layers.length - 1)]?.id || '';
    markHeroDraftDirty();
    renderManager();
  }

  function openMediaPicker(host, button, selector) {
    const input = host.querySelector(selector);
    if (!input || typeof window.BRVTALMediaLibrary?.openPicker !== 'function') return;
    const options = button.dataset.mediaType === 'video' ? {videoOnly:true} : {imagesOnly:true};
    void window.BRVTALMediaLibrary.openPicker(input, options);
  }

  function updateConfig(input) {
    const field = input.dataset.configField;
    config[field] = input.type === 'checkbox'
      ? input.checked
      : field === 'interval'
        ? clamp(input.value, 2500, 30000)
        : input.value;
    markHeroDraftDirty();
  }

  function bindSlideControls(host) {
    host.querySelectorAll('[data-select-slide]').forEach(button =>
      button.addEventListener('click', event => selectSlide(button, event))
    );
    host.querySelectorAll('[data-move]').forEach(button =>
      button.addEventListener('click', event => {
        event.preventDefault();
        event.stopPropagation();
        moveSlide(button.dataset.id, button.dataset.move);
      })
    );
    host.querySelector('[data-add-slide]')?.addEventListener('click', addSlide);
    host.querySelector('[data-duplicate-slide]')?.addEventListener('click', duplicateSlide);
    host.querySelector('[data-delete-slide]')?.addEventListener('click', deleteSlide);
  }

  function bindLayerControls(host) {
    host.querySelectorAll('[data-add-layer]').forEach(button =>
      button.addEventListener('click', () => addLayer(button.dataset.addLayer))
    );
    host.querySelectorAll('[data-select-layer]').forEach(button =>
      button.addEventListener('click', () => {
        selectedLayerId = button.dataset.selectLayer;
        renderManager();
      })
    );
    host.querySelector('[data-delete-layer]')?.addEventListener('click', deleteLayer);
  }

  function bindEditorControls(host) {
    host.querySelectorAll('[data-field]').forEach(input => {
      input.addEventListener('input', event => updateSlide(event.target));
      input.addEventListener('change', event => updateSlide(event.target));
    });
    host.querySelectorAll('[data-layer-field]').forEach(input => {
      input.addEventListener('input', event => updateLayer(event.target));
      input.addEventListener('change', event => updateLayer(event.target));
    });
    host.querySelectorAll('[data-media-picker]').forEach(button =>
      button.addEventListener('click', () =>
        openMediaPicker(host, button, `[data-field="${button.dataset.mediaPicker}"]`)
      )
    );
    host.querySelectorAll('[data-layer-media]').forEach(button =>
      button.addEventListener('click', () =>
        openMediaPicker(host, button, `[data-layer-field="${button.dataset.layerMedia}"]`)
      )
    );
  }

  function bindGlobalControls(host) {
    host.querySelectorAll('[data-config-field]').forEach(input =>
      input.addEventListener('change', () => updateConfig(input))
    );
    host.querySelectorAll('[data-preview]').forEach(button =>
      button.addEventListener('click', () => {
        previewMode = button.dataset.preview;
        renderManager();
      })
    );
    host.querySelector('[data-save-slider]')?.addEventListener('click', save);
    host.querySelector('[data-hero-draft-restore]')?.addEventListener('click', restoreHeroDraft);
    host.querySelector('[data-hero-draft-discard]')?.addEventListener('click', () => { void discardHeroDraft(); });
  }

  function bind() {
    const host = root();
    if (!host) return;
    bindSlideControls(host);
    bindLayerControls(host);
    bindEditorControls(host);
    bindGlobalControls(host);
    bindPreviewDrag();
  }

  async function removeHeroDraft() {
    const api = draftApi();
    if (!api) return;
    try { await api.remove(DRAFT_SCOPE, DRAFT_ID); } catch (_) {}
  }

  async function finishHeroSave(payload, submittedSnapshot) {
    markConfigClean(payload);
    heroDraftBaseRevision = await configRevision(payload);

    if (configSnapshot() === submittedSnapshot) {
      config = payload;
      clearHeroDraftTimer();
      await removeHeroDraft();
      if (configSnapshot() !== submittedSnapshot) {
        heroSaveInFlight = false;
        markHeroDraftDirty();
        renderManager();
        return;
      }
      heroDraftRecovery = null;
      setHeroDraftState('Guardado en el servidor', 'server');
      window.BRVTALFeedback?.success?.('Banners guardados. La portada alternativa permanece disponible.','hero-slider-save');
      renderManager();
      return;
    }

    const persisted = await persistHeroDraft();
    setHeroDraftState(
      persisted ? 'Borrador local guardado · aún hay cambios sin publicar' : 'No se guardaron los cambios recientes en el borrador local',
      persisted ? 'saved' : 'error'
    );
    window.BRVTALFeedback?.success?.('Banners guardados. Los cambios recientes permanecen en el borrador local.','hero-slider-save');
    renderManager();
  }

  function humanMediaSaveError(error) {
    const raw = String(error?.message || 'No fue posible guardar');
    const code = String(error?.code || raw.split(' · ')[0]).trim();
    const field = String(error?.field || (raw.includes(' · ') ? raw.slice(raw.lastIndexOf(' · ') + 3) : ''));
    const match = /^slides\.(\d+)\.(desktopSrc|mobileSrc|poster|layers\.(\d+)\.(src|mobileSrc))$/.exec(field);
    if (!match) return raw; // Do not hide unrelated HTTP, CSRF or permission errors.
    const number = Number(match[1]) + 1;
    const slide = config.slides[Number(match[1])] || {};
    const prefix = `Diapositiva ${number} (${slide.name || 'sin nombre'})`;
    const fieldName = match[3] === undefined
      ? ({desktopSrc:'imagen de escritorio',mobileSrc:'imagen móvil',poster:'póster del vídeo'}[match[2]] || 'archivo')
      : `capa ${Number(match[3]) + 1}, ${match[4] === 'src' ? 'imagen de escritorio' : 'imagen móvil'}`;
    const reasons = {
      HERO_SLIDER_MEDIA_NOT_REGISTERED:'no está registrada en la Biblioteca de medios',
      HERO_SLIDER_MEDIA_FILE_MISSING:'el archivo ya no existe; vuelve a subirlo o elige otro',
      HERO_SLIDER_MEDIA_TYPE_MISMATCH:'el tipo de archivo no coincide con el campo',
      HERO_SLIDER_MEDIA_REFERENCE_INVALID:'la ruta no es válida; elige un archivo o enlace HTTPS',
      HERO_SLIDER_MEDIA_REQUIRED:'selecciona una imagen antes de guardar',
    };
    return `${prefix} · ${fieldName}: ${reasons[code] || raw}`;
  }

  async function failHeroSave(error, button) {
    const persisted = await persistHeroDraft();
    const detail = humanMediaSaveError(error);
    setHeroDraftState(
      `Error al guardar · ${detail} · ${persisted ? 'borrador guardado localmente' : 'mantén abierto el editor'}`,
      'error'
    );
    window.BRVTALFeedback?.error?.('No se pudo guardar Banners: ' + detail,'hero-slider-save');
    if (button) {
      button.disabled = false;
      button.textContent = mediaSaveLabel();
    }
  }

  async function save() {
    const button = root()?.querySelector('[data-save-slider]');
    if (button) {
      button.disabled = true;
      button.textContent = 'GUARDANDO…';
    }
    clearHeroDraftTimer();
    heroSaveInFlight = true;
    const payload = normalizeConfig(config);
    const submittedSnapshot = configSnapshot(payload);
    try {
      const integrityError = configMediaError(payload);
      if (integrityError) throw new Error(integrityError);
      await request('/settings',{method:'POST',body:JSON.stringify({setting_key:KEY,setting_value:JSON.stringify(payload),is_json:1})});
      await finishHeroSave(payload, submittedSnapshot);
    } catch (error) {
      await failHeroSave(error, button);
    } finally {
      heroSaveInFlight = false;
    }
  }

  window.BRVTALHeroSliderGuard = {
    requestNavigation,
    commitNavigation,
    hasUnsavedChanges
  };
  window.BRVTALHeroSliderDiagnostics = {
    lastLoad: () => ({...lastLoadDiagnostics}),
    draft: () => ({
      base_revision:heroDraftBaseRevision,
      has_recovery:Boolean(heroDraftRecovery),
      state:{...heroDraftState}
    })
  };

  function install() {
    injectStyles();
    ensureNav();
    window.addEventListener('beforeunload', handleBeforeUnload);
    const observer=new MutationObserver(()=>ensureNav());observer.observe(document.documentElement,{childList:true,subtree:true});
    originalGo=window.go;
    window.go=async function(section){
      const iaOwnsGuard = window.BRVTALAdminIA?.guardsUnsavedChanges === true;
      if (!iaOwnsGuard && !requestNavigation(section)) return false;

      if(section==='hero-slider'){
        const host=prepareWorkspace();
        if(!host) return false;
        const loaded=await load();
        if(loaded && !iaOwnsGuard) commitNavigation(section);
        ensureNav();
        return loaded;
      }

      loadRevision+=1;
      try {
        const result=await originalGo(section);
        if(!iaOwnsGuard) commitNavigation(section);
        ensureNav();
        return result;
      } catch (error) {
        ensureNav();
        throw error;
      }
    };
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', install, {once:true});
  } else {
    install();
  }
})();