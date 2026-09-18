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

  async function request(path, options = {}) {
    if (typeof window.req === 'function') return window.req(path, options);
    const headers = {'Content-Type':'application/json', ...(options.headers || {})};
    if (window.csrf) headers['X-CSRF-Token'] = window.csrf;
    const response = await fetch(API + path,{...options,headers,credentials:'same-origin'});
    const json = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(json.error || 'REQUEST_FAILED');
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
      button.textContent = 'HERO SLIDER';
      button.addEventListener('click', () => window.go('hero-slider'));
      const events = [...nav.querySelectorAll('button')].find(node => node.textContent.trim().toUpperCase() === 'EVENTS');
      if (events?.nextSibling) nav.insertBefore(button,events.nextSibling); else nav.appendChild(button);
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
      top.querySelector('h1') && (top.querySelector('h1').textContent = 'HERO SLIDER');
    }
    [...main.children].forEach(child => { if (child !== top) child.remove(); });
    const host = document.createElement('div');
    host.id = 'hero-slider-root';
    main.appendChild(host);
    return host;
  }

  function mediaOptions(type) {
    const allowed = media.filter(item => type === 'video' ? item.type === 'video' : item.type === 'image');
    return ['<option value="">Choose from Media Library…</option>',...allowed.map(item => `<option value="${esc(item.file_path || '')}">${esc(item.title || item.file_path || ('Media #' + item.id))}</option>`)].join('');
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
      host.innerHTML = `<div class="hero-slider-error">Unable to load Hero Slider: ${esc(error.message)}</div>`;
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
        <label class="full"><span>Desktop media</span><div class="hero-media-pair"><input data-field="desktopSrc" value="${esc(slide.desktopSrc)}"><select data-media-picker="desktopSrc">${mediaOptions(slide.mediaType)}</select></div></label>
        <label class="full"><span>Mobile override <em>optional</em></span><div class="hero-media-pair"><input data-field="mobileSrc" value="${esc(slide.mobileSrc)}"><select data-media-picker="mobileSrc">${mediaOptions(slide.mediaType)}</select></div></label>
        ${slide.mediaType==='video'?`<label class="full"><span>Video poster</span><div class="hero-media-pair"><input data-field="poster" value="${esc(slide.poster)}"><select data-media-picker="poster">${mediaOptions('image')}</select></div></label>`:''}
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
      ${imageFields?`<label class="full"><span>Desktop asset</span><div class="hero-media-pair"><input data-layer-field="src" value="${esc(layer.src)}"><select data-layer-media="src">${mediaOptions('image')}</select></div></label><label class="full"><span>Mobile asset override</span><div class="hero-media-pair"><input data-layer-field="mobileSrc" value="${esc(layer.mobileSrc)}"><select data-layer-media="mobileSrc">${mediaOptions('image')}</select></div></label>`:`<label class="full"><span>Text</span><textarea data-layer-field="text">${esc(layer.text)}</textarea></label>`}
      ${layer.type==='cta'?`<label class="full"><span>Link URL</span><input data-layer-field="url" value="${esc(layer.url)}"></label>`:''}
      <label><span>X %</span><input type="number" min="0" max="100" data-layer-field="x" value="${layer.x}"></label><label><span>Y %</span><input type="number" min="0" max="100" data-layer-field="y" value="${layer.y}"></label>
      <label><span>Width %</span><input type="number" min="5" max="100" data-layer-field="width" value="${layer.width}"></label><label><span>Align</span><select data-layer-field="align">${['left','center','right'].map(v=>`<option value="${v}" ${layer.align===v?'selected':''}>${v}</option>`).join('')}</select></label>
      <label><span>Animation</span><select data-layer-field="animation">${animations.map(v=>`<option value="${v}" ${layer.animation===v?'selected':''}>${v}</option>`).join('')}</select></label><label><span>Delay ms</span><input type="number" min="0" max="10000" step="50" data-layer-field="delay" value="${layer.delay}"></label>
      <label><span>Duration ms</span><input type="number" min="100" max="5000" step="50" data-layer-field="duration" value="${layer.duration}"></label><label class="hero-switch"><input type="checkbox" data-layer-field="hiddenMobile" ${layer.hiddenMobile?'checked':''}><span>Hide on mobile</span></label>
      <label><span>Mobile X %</span><input type="number" min="0" max="100" data-layer-field="mobileX" value="${layer.mobileX ?? ''}" placeholder="inherit"></label><label><span>Mobile Y %</span><input type="number" min="0" max="100" data-layer-field="mobileY" value="${layer.mobileY ?? ''}" placeholder="inherit"></label>
      <label><span>Mobile width %</span><input type="number" min="5" max="100" data-layer-field="mobileWidth" value="${layer.mobileWidth ?? ''}" placeholder="inherit"></label>
    </div></div>`;
  }

  function layerMarkup(layer) {
    const mobile = previewMode === 'mobile';
    if (mobile && layer.hiddenMobile) return '';
    const x = mobile && layer.mobileX != null ? layer.mobileX : layer.x;
    const y = mobile && layer.mobileY != null ? layer.mobileY : layer.y;
    const width = mobile && layer.mobileWidth != null ? layer.mobileWidth : layer.width;
    const src = mobile && layer.mobileSrc ? layer.mobileSrc : layer.src;
    let content = '';
    if (layer.type === 'image' || layer.type === 'logo') content = src ? `<img src="${esc(src)}" alt="">` : `<span>${esc(layer.name)}</span>`;
    else if (layer.type === 'cta') content = `<b>${esc(layer.text || 'CTA')} ↗</b>`;
    else content = `<span>${esc(layer.text || layer.name)}</span>`;
    return `<div class="hero-preview-layer type-${layer.type} ${layer.id===selectedLayerId?'selected':''}" data-preview-layer="${esc(layer.id)}" style="left:${x}%;top:${y}%;width:${width}%;text-align:${layer.align};--delay:${layer.delay}ms;--duration:${layer.duration}ms" data-animation="${esc(layer.animation)}">${content}</div>`;
  }

  function previewMarkup(slide) {
    if (!slide) return '<div class="hero-preview-placeholder">CURRENT BRVTAL HERO REMAINS ACTIVE UNTIL A SLIDE IS PUBLISHED</div>';
    const src = previewMode === 'mobile' && slide.mobileSrc ? slide.mobileSrc : slide.desktopSrc;
    const mediaMarkup = src ? (slide.mediaType === 'video' ? `<video muted loop playsinline autoplay ${slide.poster?`poster="${esc(slide.poster)}"`:''}><source src="${esc(src)}"></video>` : `<img src="${esc(src)}" alt="">`) : '<div class="hero-preview-media-empty">SELECT MEDIA</div>';
    const legacy = slide.layers.length ? '' : `<div class="hero-preview-copy">${slide.kicker?`<span>${esc(slide.kicker)}</span>`:''}${slide.title?`<h2>${esc(slide.title)}</h2>`:''}${slide.body?`<p>${esc(slide.body)}</p>`:''}${slide.ctaLabel?`<b>${esc(slide.ctaLabel)} ↗</b>`:''}</div>`;
    return `<div class="hero-preview-stage align-${esc(slide.contentAlign)}" style="--hero-overlay:${slide.overlay/100}">${mediaMarkup}<div class="hero-preview-overlay"></div>${legacy}${slide.layers.map(layerMarkup).join('')}</div>`;
  }

  function renderManager() {
    const host = root();
    if (!host) return;
    const slide = selectedSlide();
    host.innerHTML = `<section class="hero-manager"><div class="hero-manager-toolbar"><div><span class="hero-kicker">HOME / HERO</span><h2>SLIDER MANAGER V2</h2><p>LayerSlider-inspired visual layers with safe mobile overrides.</p></div><div class="hero-manager-actions"><label class="hero-switch"><input type="checkbox" data-config-field="enabled" ${config.enabled?'checked':''}><span>Publish slider</span></label><button type="button" class="btn ghost" data-add-slide>+ ADD SLIDE</button><button type="button" class="btn red" data-save-slider>SAVE</button></div></div><div class="hero-manager-global"><label class="hero-switch"><input type="checkbox" data-config-field="autoplay" ${config.autoplay?'checked':''}><span>Autoplay</span></label><label><span>Slide duration</span><select data-config-field="interval">${[4000,5000,7000,9000,12000].map(ms=>`<option value="${ms}" ${config.interval===ms?'selected':''}>${ms/1000}s</option>`).join('')}</select></label><span class="hero-manager-fallback">SAFE FALLBACK · original BRVTAL hero remains if managed content is unavailable.</span></div><div class="hero-manager-grid"><aside class="hero-slide-list"><div class="hero-slide-list-head"><strong>SLIDES</strong><span>${config.slides.length}/${MAX_SLIDES}</span></div>${slideList()}</aside><section class="hero-slide-editor">${legacyEditor(slide)}</section><section class="hero-preview-panel"><div class="hero-preview-head"><strong>LIVE PREVIEW · drag selected layers</strong><div><button type="button" data-preview="desktop" class="${previewMode==='desktop'?'active':''}">DESKTOP</button><button type="button" data-preview="mobile" class="${previewMode==='mobile'?'active':''}">MOBILE</button></div></div><div class="hero-preview-frame ${previewMode}">${previewMarkup(slide)}</div></section></div></section>`;
    bind();
  }

  function refreshPreview() {
    const frame = document.querySelector('.hero-preview-frame');
    if (!frame) return;
    frame.className = 'hero-preview-frame ' + previewMode;
    frame.innerHTML = previewMarkup(selectedSlide());
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

  function bind() {
    const host = root(); if (!host) return;
    host.querySelectorAll('[data-select-slide]').forEach(button=>button.addEventListener('click',event=>{ if(event.target.closest('[data-move]')) return; selectedId=button.dataset.selectSlide; selectedLayerId=selectedSlide()?.layers[0]?.id||''; renderManager(); }));
    host.querySelectorAll('[data-move]').forEach(button=>button.addEventListener('click',event=>{event.preventDefault();event.stopPropagation();moveSlide(button.dataset.id,button.dataset.move);}));
    host.querySelector('[data-add-slide]')?.addEventListener('click',()=>{if(config.slides.length>=MAX_SLIDES)return;const slide=normalizeSlide({name:'New slide '+(config.slides.length+1)});config.slides.push(slide);selectedId=slide.id;selectedLayerId='';renderManager();});
    host.querySelector('[data-duplicate-slide]')?.addEventListener('click',()=>{const slide=selectedSlide();if(!slide||config.slides.length>=MAX_SLIDES)return;const copy=normalizeSlide(JSON.parse(JSON.stringify(slide)));copy.id=uid('slide');copy.name=slide.name+' copy';copy.layers=copy.layers.map(layer=>({...layer,id:uid('layer')}));config.slides.splice(config.slides.indexOf(slide)+1,0,copy);selectedId=copy.id;selectedLayerId=copy.layers[0]?.id||'';renderManager();});
    host.querySelector('[data-delete-slide]')?.addEventListener('click',()=>{const index=config.slides.findIndex(slide=>slide.id===selectedId);if(index<0)return;config.slides.splice(index,1);selectedId=config.slides[Math.min(index,config.slides.length-1)]?.id||'';selectedLayerId='';renderManager();});
    host.querySelectorAll('[data-add-layer]').forEach(button=>button.addEventListener('click',()=>{const slide=selectedSlide();if(!slide||slide.layers.length>=MAX_LAYERS)return;const layer=normalizeLayer({type:button.dataset.addLayer,text:button.dataset.addLayer==='cta'?'ENTER EXPERIENCE':button.dataset.addLayer==='text'?'NEW TEXT':''});slide.layers.push(layer);selectedLayerId=layer.id;renderManager();}));
    host.querySelectorAll('[data-select-layer]').forEach(button=>button.addEventListener('click',()=>{selectedLayerId=button.dataset.selectLayer;renderManager();}));
    host.querySelector('[data-delete-layer]')?.addEventListener('click',()=>{const slide=selectedSlide();if(!slide)return;const index=slide.layers.findIndex(layer=>layer.id===selectedLayerId);if(index<0)return;slide.layers.splice(index,1);selectedLayerId=slide.layers[Math.min(index,slide.layers.length-1)]?.id||'';renderManager();});
    host.querySelectorAll('[data-field]').forEach(input=>{input.addEventListener('input',event=>updateSlide(event.target));input.addEventListener('change',event=>updateSlide(event.target));});
    host.querySelectorAll('[data-layer-field]').forEach(input=>{input.addEventListener('input',event=>updateLayer(event.target));input.addEventListener('change',event=>updateLayer(event.target));});
    host.querySelectorAll('[data-media-picker]').forEach(select=>select.addEventListener('change',()=>{if(!select.value)return;const input=host.querySelector(`[data-field="${select.dataset.mediaPicker}"]`);if(input){input.value=select.value;input.dispatchEvent(new Event('input',{bubbles:true}));}select.value='';}));
    host.querySelectorAll('[data-layer-media]').forEach(select=>select.addEventListener('change',()=>{if(!select.value)return;const input=host.querySelector(`[data-layer-field="${select.dataset.layerMedia}"]`);if(input){input.value=select.value;input.dispatchEvent(new Event('input',{bubbles:true}));}select.value='';}));
    host.querySelectorAll('[data-config-field]').forEach(input=>input.addEventListener('change',()=>{const field=input.dataset.configField;config[field]=input.type==='checkbox'?input.checked:field==='interval'?clamp(input.value,2500,30000):input.value;}));
    host.querySelectorAll('[data-preview]').forEach(button=>button.addEventListener('click',()=>{previewMode=button.dataset.preview;renderManager();}));
    host.querySelector('[data-save-slider]')?.addEventListener('click',save);
    bindPreviewDrag();
  }

  async function save() {
    const button=root()?.querySelector('[data-save-slider]');if(button){button.disabled=true;button.textContent='SAVING…';}
    try {
      const payload=normalizeConfig(config);
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