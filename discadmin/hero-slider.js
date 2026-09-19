(() => {
  'use strict';

  const KEY = 'home.hero.slider';
  const API = '../api/index.php';
  const MAX_SLIDES = 20;
  const MAX_LAYERS = 12;
  const layerTypes = ['text','image','logo','cta'];
  const animations = ['none','fade','slide-up','slide-left','zoom'];
  const defaultConfig = () => ({enabled:false,autoplay:true,interval:7000,slides:[]});
  let config = defaultConfig();
  let media = [];
  let selectedId = '';
  let selectedLayerId = '';
  let previewMode = 'desktop';
  let originalGo = null;

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
      name: String(input.name || (type === 'cta' ? 'Button' : type === 'image' ? 'Image' : type === 'logo' ? 'Logo' : 'Text')),
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
      name: String(input.name || input.title || 'New slide'),
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
    if (!reference) return required ? `${label} is required for an enabled slide.` : '';
    if (/^https:\/\//i.test(reference)) return '';
    if (!reference.startsWith('/uploads/') || reference.includes('..')) {
      return `${label} must use a Media Library asset or an external HTTPS URL.`;
    }
    const item = mediaRecordForPath(reference);
    if (!item) return `${label} is not registered in the Media Library.`;
    if (String(item.status || 'published') !== 'published') {
      return `${label} must use a published Media Library asset.`;
    }
    if (String(item.type || '') !== expectedType) {
      return `${label} must use a ${expectedType} asset.`;
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
      const layerLabel = `${label} · ${layerName}`;
      const error = firstMediaError([
        [layer.src, 'image', false, `${layerLabel}: Desktop asset`],
        [layer.mobileSrc, 'image', false, `${layerLabel}: Mobile asset`],
      ]);
      if (error) return error;
    }
    return '';
  }

  function slideMediaError(slide, slideIndex) {
    if (slide.enabled === false) return '';
    const label = slide.name || `Slide ${slideIndex + 1}`;
    const expectedType = slide.mediaType === 'video' ? 'video' : 'image';
    const checks = [
      [slide.desktopSrc, expectedType, true, `${label}: Desktop media`],
      [slide.mobileSrc, expectedType, false, `${label}: Mobile media`],
    ];
    if (expectedType === 'video') {
      checks.push([slide.poster, 'image', false, `${label}: Video poster`]);
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
      throw new Error((json.error || 'REQUEST_FAILED') + field);
    }
    return json;
  }

  const root = () => document.getElementById('hero-slider-root');
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
      button.textContent = 'BANNERS';
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
      top.querySelector('h1') && (top.querySelector('h1').textContent = 'BANNERS');
    }
    [...main.children].forEach(child => { if (child !== top) child.remove(); });
    const host = document.createElement('div');
    host.id = 'hero-slider-root';
    main.appendChild(host);
    return host;
  }

  function hydrateMediaPicker(select) {
    const type = select.dataset.mediaType === 'video' ? 'video' : 'image';
    const allowed = media.filter(item => type === 'video' ? item.type === 'video' : item.type === 'image');
    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = 'Choose from Media Library…';
    select.replaceChildren(placeholder);
    allowed.forEach(item => {
      const option = document.createElement('option');
      option.value = String(item.file_path || '');
      option.textContent = String(item.title || item.file_path || ('Media #' + item.id));
      select.appendChild(option);
    });
  }

  function hydrateMediaPickers(host) {
    host.querySelectorAll('[data-media-picker],[data-layer-media]').forEach(hydrateMediaPicker);
  }

  async function load() {
    const host = root();
    if (!host) return;
    host.innerHTML = '<div class="hero-slider-loading">LOADING HERO MANAGER…</div>';
    try {
      const [settingsResponse,mediaResponse] = await Promise.all([request('/settings'),request('/media')]);
      const settings = Array.isArray(settingsResponse.data) ? settingsResponse.data : [];
      const record = settings.find(item => item.setting_key === KEY);
      let stored = record?.setting_value || null;
      if (typeof stored === 'string') { try { stored = JSON.parse(stored); } catch (_) { stored = null; } }
      config = normalizeConfig(stored);
      media = Array.isArray(mediaResponse.data) ? mediaResponse.data : [];
      if (!config.slides.some(slide => slide.id === selectedId)) selectedId = config.slides[0]?.id || '';
      const slide = selectedSlide();
      if (!slide?.layers.some(layer => layer.id === selectedLayerId)) selectedLayerId = slide?.layers[0]?.id || '';
      renderManager();
    } catch (error) {
      host.replaceChildren();
      const errorNode = document.createElement('div');
      errorNode.className = 'hero-slider-error';
      errorNode.textContent = `Unable to load Banners: ${String(error?.message || 'Unknown error')}`;
      host.appendChild(errorNode);
    }
  }

  function slideList() {
    if (!config.slides.length) return '<div class="hero-slider-empty">NO SLIDES YET<br><span>Create the first banner; the original hero remains the fallback.</span></div>';
    return config.slides.map((slide,index) => `<button type="button" class="hero-slide-row ${slide.id === selectedId ? 'active' : ''}" data-select-slide="${esc(slide.id)}"><span class="hero-slide-index">${String(index+1).padStart(2,'0')}</span><span class="hero-slide-row-copy"><strong>${esc(slide.name)}</strong><small>${esc(slide.mediaType.toUpperCase())} · ${slide.layers.length} LAYERS · ${slide.enabled ? 'ENABLED' : 'HIDDEN'}</small></span><span class="hero-slide-row-actions"><i data-move="up" data-id="${esc(slide.id)}">↑</i><i data-move="down" data-id="${esc(slide.id)}">↓</i></span></button>`).join('');
  }

  function layerList(slide) {
    if (!slide) return '';
    const rows = slide.layers.map((layer,index) => `<button type="button" class="hero-layer-row ${layer.id === selectedLayerId ? 'active' : ''}" data-select-layer="${esc(layer.id)}"><span>${String(index+1).padStart(2,'0')}</span><strong>${esc(layer.name)}</strong><small>${esc(layer.type.toUpperCase())}</small></button>`).join('');
    return `<div class="hero-layer-list"><div class="hero-layer-list-head"><strong>LAYERS</strong><span>${slide.layers.length}/${MAX_LAYERS}</span></div>${rows || '<p class="hero-layer-empty">No layers. Legacy title/body remain available.</p>'}<div class="hero-layer-add">${layerTypes.map(type => `<button type="button" data-add-layer="${type}">+ ${type.toUpperCase()}</button>`).join('')}</div></div>`;
  }

  function legacyEditor(slide) {
    if (!slide) return '<div class="hero-slider-no-selection"><strong>CREATE A SLIDE</strong><p>Add a banner, then optionally build it with visual layers.</p></div>';
    return `<div class="hero-editor-head"><div><span class="hero-kicker">SLIDE SETTINGS</span><strong>${esc(slide.name)}</strong></div><div><button type="button" class="btn ghost" data-duplicate-slide>DUPLICATE</button><button type="button" class="btn ghost" data-delete-slide>DELETE</button></div></div>
      <div class="hero-form-grid">
        <label><span>Internal name</span><input data-field="name" value="${esc(slide.name)}"></label>
        <label class="hero-switch"><input type="checkbox" data-field="enabled" ${slide.enabled ? 'checked' : ''}><span>Slide enabled</span></label>
        <label><span>Media type</span><select data-field="mediaType"><option value="image" ${slide.mediaType==='image'?'selected':''}>Image</option><option value="video" ${slide.mediaType==='video'?'selected':''}>Video</option></select></label>
        <label><span>Transition</span><select data-field="transition">${['fade','slide','zoom'].map(v => `<option value="${v}" ${slide.transition===v?'selected':''}>${v}</option>`).join('')}</select></label>
        <label class="full"><span>Desktop media <em>Media Library or external HTTPS</em></span><div class="hero-media-pair"><input data-field="desktopSrc" value="${esc(slide.desktopSrc)}"><select data-media-picker="desktopSrc" data-media-type="${slide.mediaType === 'video' ? 'video' : 'image'}"><option value="">Choose from Media Library…</option></select></div></label>
        <label class="full"><span>Mobile override <em>optional · Media Library or external HTTPS</em></span><div class="hero-media-pair"><input data-field="mobileSrc" value="${esc(slide.mobileSrc)}"><select data-media-picker="mobileSrc" data-media-type="${slide.mediaType === 'video' ? 'video' : 'image'}"><option value="">Choose from Media Library…</option></select></div></label>
        ${slide.mediaType==='video'?`<label class="full"><span>Video poster</span><div class="hero-media-pair"><input data-field="poster" value="${esc(slide.poster)}"><select data-media-picker="poster" data-media-type="image"><option value="">Choose from Media Library…</option></select></div></label>`:''}
        <label class="full"><span>Legacy kicker</span><input data-field="kicker" value="${esc(slide.kicker)}"></label>
        <label class="full"><span>Legacy title</span><input data-field="title" value="${esc(slide.title)}"></label>
        <label class="full"><span>Legacy body</span><textarea data-field="body">${esc(slide.body)}</textarea></label>
        <label><span>Legacy CTA label</span><input data-field="ctaLabel" value="${esc(slide.ctaLabel)}"></label>
        <label><span>Legacy CTA URL</span><input data-field="ctaUrl" value="${esc(slide.ctaUrl)}"></label>
        <label class="full"><span>Dark overlay · <b data-overlay-value>${slide.overlay}%</b></span><input type="range" min="0" max="85" step="5" data-field="overlay" value="${slide.overlay}"></label>
      </div>${layerList(slide)}${layerEditor(selectedLayer())}`;
  }

  function layerEditor(layer) {
    if (!layer) return '';
    const imageFields = layer.type === 'image' || layer.type === 'logo';
    return `<div class="hero-layer-editor"><div class="hero-editor-head"><div><span class="hero-kicker">SELECTED LAYER</span><strong>${esc(layer.name)}</strong></div><button type="button" class="btn ghost" data-delete-layer>DELETE LAYER</button></div><div class="hero-form-grid">
      <label><span>Name</span><input data-layer-field="name" value="${esc(layer.name)}"></label>
      <label><span>Type</span><input value="${esc(layer.type)}" disabled></label>
      ${imageFields?`<label class="full"><span>Desktop asset</span><div class="hero-media-pair"><input data-layer-field="src" value="${esc(layer.src)}"><select data-layer-media="src" data-media-type="image"><option value="">Choose from Media Library…</option></select></div></label><label class="full"><span>Mobile asset override</span><div class="hero-media-pair"><input data-layer-field="mobileSrc" value="${esc(layer.mobileSrc)}"><select data-layer-media="mobileSrc" data-media-type="image"><option value="">Choose from Media Library…</option></select></div></label>`:`<label class="full"><span>Text</span><textarea data-layer-field="text">${esc(layer.text)}</textarea></label>`}
      ${layer.type==='cta'?`<label class="full"><span>Link URL</span><input data-layer-field="url" value="${esc(layer.url)}"></label>`:''}
      <label><span>X %</span><input type="number" min="0" max="100" data-layer-field="x" value="${layer.x}"></label><label><span>Y %</span><input type="number" min="0" max="100" data-layer-field="y" value="${layer.y}"></label>
      <label><span>Width %</span><input type="number" min="5" max="100" data-layer-field="width" value="${layer.width}"></label><label><span>Align</span><select data-layer-field="align">${['left','center','right'].map(v=>`<option value="${v}" ${layer.align===v?'selected':''}>${v}</option>`).join('')}</select></label>
      <label><span>Animation</span><select data-layer-field="animation">${animations.map(v=>`<option value="${v}" ${layer.animation===v?'selected':''}>${v}</option>`).join('')}</select></label><label><span>Delay ms</span><input type="number" min="0" max="10000" step="50" data-layer-field="delay" value="${layer.delay}"></label>
      <label><span>Duration ms</span><input type="number" min="100" max="5000" step="50" data-layer-field="duration" value="${layer.duration}"></label><label class="hero-switch"><input type="checkbox" data-layer-field="hiddenMobile" ${layer.hiddenMobile?'checked':''}><span>Hide on mobile</span></label>
      <label><span>Mobile X %</span><input type="number" min="0" max="100" data-layer-field="mobileX" value="${layer.mobileX ?? ''}" placeholder="inherit"></label><label><span>Mobile Y %</span><input type="number" min="0" max="100" data-layer-field="mobileY" value="${layer.mobileY ?? ''}" placeholder="inherit"></label>
      <label><span>Mobile width %</span><input type="number" min="5" max="100" data-layer-field="mobileWidth" value="${layer.mobileWidth ?? ''}" placeholder="inherit"></label>
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
      empty.textContent = 'SELECT MEDIA';
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
    stage.style.setProperty('--hero-overlay', String(slide.overlay / 100));
    appendPreviewMedia(stage, slide);

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
    host.innerHTML = `<section class="hero-manager"><div class="hero-manager-toolbar"><div><span class="hero-kicker">HOME / HERO</span><h2>SLIDER MANAGER V2</h2><p>LayerSlider-inspired visual layers with safe mobile overrides.</p></div><div class="hero-manager-actions"><label class="hero-switch"><input type="checkbox" data-config-field="enabled" ${config.enabled?'checked':''}><span>Publish slider</span></label><button type="button" class="btn ghost" data-add-slide>+ ADD SLIDE</button><button type="button" class="btn red" data-save-slider>SAVE</button></div></div><div class="hero-manager-global"><label class="hero-switch"><input type="checkbox" data-config-field="autoplay" ${config.autoplay?'checked':''}><span>Autoplay</span></label><label><span>Slide duration</span><select data-config-field="interval">${intervalOptions()}</select></label><span class="hero-manager-fallback">SAFE FALLBACK · original BRVTAL hero remains if managed content is unavailable.</span></div><div class="hero-manager-grid"><aside class="hero-slide-list"><div class="hero-slide-list-head"><strong>SLIDES</strong><span>${config.slides.length}/${MAX_SLIDES}</span></div>${slideList()}</aside><section class="hero-slide-editor">${legacyEditor(slide)}</section><section class="hero-preview-panel"><div class="hero-preview-head"><strong>LIVE PREVIEW · drag selected layers</strong><div><button type="button" data-preview="desktop" class="${previewMode==='desktop'?'active':''}">DESKTOP</button><button type="button" data-preview="mobile" class="${previewMode==='mobile'?'active':''}">MOBILE</button></div></div><div class="hero-preview-frame ${previewMode === 'mobile' ? 'mobile' : 'desktop'}"></div></section></div></section>`;
    hydrateMediaPickers(host);
    renderPreview(host.querySelector('.hero-preview-frame'), slide);
    bind();
  }

  function refreshPreview() {
    const frame = document.querySelector('.hero-preview-frame');
    if (!frame) return;
    renderPreview(frame, selectedSlide());
    bindPreviewDrag();
  }

  function updateSlide(target) {
    const slide = selectedSlide(); if (!slide) return;
    const field = target.dataset.field; if (!field) return;
    slide[field] = target.type === 'checkbox' ? target.checked : field === 'overlay' ? clamp(target.value,0,85) : target.value;
    if (field === 'overlay') document.querySelector('[data-overlay-value]') && (document.querySelector('[data-overlay-value]').textContent = slide.overlay + '%');
    if (field === 'mediaType') renderManager(); else refreshPreview();
  }

  function updateLayer(target) {
    const layer = selectedLayer(); if (!layer) return;
    const field = target.dataset.layerField; if (!field) return;
    if (target.type === 'checkbox') layer[field] = target.checked;
    else if (['x','y','width','mobileX','mobileY','mobileWidth','delay','duration'].includes(field)) layer[field] = target.value === '' ? null : Number(target.value);
    else layer[field] = target.value;
    refreshPreview();
  }

  function moveSlide(id,direction) {
    const index = config.slides.findIndex(slide=>slide.id===id); if (index<0) return;
    const next = direction==='up'?index-1:index+1; if (next<0||next>=config.slides.length) return;
    [config.slides[index],config.slides[next]]=[config.slides[next],config.slides[index]]; renderManager();
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
        const up = () => { window.removeEventListener('pointermove',move); window.removeEventListener('pointerup',up); renderManager(); };
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
    renderManager();
  }

  function deleteSlide() {
    const index = config.slides.findIndex(slide => slide.id === selectedId);
    if (index < 0) return;
    config.slides.splice(index, 1);
    selectedId = config.slides[Math.min(index, config.slides.length - 1)]?.id || '';
    selectedLayerId = '';
    renderManager();
  }

  function addLayer(type) {
    const slide = selectedSlide();
    if (!slide || slide.layers.length >= MAX_LAYERS) return;
    const text = type === 'cta' ? 'ENTER EXPERIENCE' : type === 'text' ? 'NEW TEXT' : '';
    const layer = normalizeLayer({ type, text });
    slide.layers.push(layer);
    selectedLayerId = layer.id;
    renderManager();
  }

  function deleteLayer() {
    const slide = selectedSlide();
    if (!slide) return;
    const index = slide.layers.findIndex(layer => layer.id === selectedLayerId);
    if (index < 0) return;
    slide.layers.splice(index, 1);
    selectedLayerId = slide.layers[Math.min(index, slide.layers.length - 1)]?.id || '';
    renderManager();
  }

  function applyPicker(host, select, selector) {
    if (!select.value) return;
    const input = host.querySelector(selector);
    if (input) {
      input.value = select.value;
      input.dispatchEvent(new Event('input', { bubbles:true }));
    }
    select.value = '';
  }

  function updateConfig(input) {
    const field = input.dataset.configField;
    config[field] = input.type === 'checkbox'
      ? input.checked
      : field === 'interval'
        ? clamp(input.value, 2500, 30000)
        : input.value;
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
    host.querySelectorAll('[data-media-picker]').forEach(select =>
      select.addEventListener('change', () =>
        applyPicker(host, select, `[data-field="${select.dataset.mediaPicker}"]`)
      )
    );
    host.querySelectorAll('[data-layer-media]').forEach(select =>
      select.addEventListener('change', () =>
        applyPicker(host, select, `[data-layer-field="${select.dataset.layerMedia}"]`)
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

  async function save() {
    const button=root()?.querySelector('[data-save-slider]');if(button){button.disabled=true;button.textContent='SAVING…';}
    try {
      const payload=normalizeConfig(config);
      const integrityError=configMediaError(payload);
      if(integrityError) throw new Error(integrityError);
      await request('/settings',{method:'POST',body:JSON.stringify({setting_key:KEY,setting_value:JSON.stringify(payload),is_json:1})});
      config=payload;
      window.BRVTALFeedback?.success?.('Hero Slider saved. Public fallback remains protected.','hero-slider-save');
      renderManager();
    } catch(error) {
      window.BRVTALFeedback?.error?.('Hero Slider save failed: '+error.message,'hero-slider-save');
      if(button){button.disabled=false;button.textContent='SAVE';}
    }
  }

  function install() {
    injectStyles();
    ensureNav();
    const observer=new MutationObserver(()=>ensureNav());observer.observe(document.documentElement,{childList:true,subtree:true});
    originalGo=window.go;
    window.go=async function(section){if(section==='hero-slider'){const host=prepareWorkspace();if(host)await load();ensureNav();return;}const result=await originalGo(section);ensureNav();return result;};
  }

  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',install,{once:true});else install();
})();