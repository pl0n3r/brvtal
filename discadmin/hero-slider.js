(() => {
  'use strict';

  const KEY = 'home.hero.slider';
  const API = '../api/index.php';
  const defaultConfig = () => ({
    enabled: false,
    autoplay: true,
    interval: 7000,
    slides: []
  });

  let config = defaultConfig();
  let media = [];
  let selectedId = '';
  let previewMode = 'desktop';
  let originalGo = null;

  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
  const uid = () => 'slide-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2,7);
  const asBool = value => value === true || value === 1 || value === '1' || value === 'true';
  const clamp = (n,min,max) => Math.max(min,Math.min(max,Number(n) || min));

  function normalizeSlide(input = {}) {
    return {
      id: String(input.id || uid()),
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
      overlay: clamp(input.overlay ?? 35,0,85)
    };
  }

  function normalizeConfig(input) {
    const next = input && typeof input === 'object' ? input : {};
    return {
      enabled: asBool(next.enabled),
      autoplay: next.autoplay !== false,
      interval: clamp(next.interval || 7000, 2500, 30000),
      slides: Array.isArray(next.slides) ? next.slides.slice(0,20).map(normalizeSlide) : []
    };
  }

  async function request(path, options = {}) {
    if (typeof window.req === 'function') return window.req(path, options);
    const headers = {'Content-Type':'application/json', ...(options.headers || {})};
    if (typeof window.csrf !== 'undefined' && window.csrf) headers['X-CSRF-Token'] = window.csrf;
    const response = await fetch(API + path, {...options, headers, credentials:'same-origin'});
    const json = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(json.error || 'REQUEST_FAILED');
    return json;
  }

  function selectedSlide() {
    return config.slides.find(slide => slide.id === selectedId) || config.slides[0] || null;
  }

  function mediaOptions(type) {
    const allowed = media.filter(item => type === 'video' ? item.type === 'video' : item.type === 'image');
    return ['<option value="">Choose from Media Library…</option>', ...allowed.map(item => `<option value="${esc(item.file_path || '')}">${esc(item.title || item.file_path || ('Media #' + item.id))}</option>`)].join('');
  }

  function root() { return document.getElementById('hero-slider-root'); }

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
      if (events?.nextSibling) nav.insertBefore(button, events.nextSibling); else nav.appendChild(button);
    }
    button.classList.toggle('active', window.state?.section === 'hero-slider');
  }

  function prepareWorkspace() {
    window.state.section = 'hero-slider';
    window.render();
    ensureNav();
    const main = document.querySelector('.main');
    if (!main) return null;
    const top = main.querySelector('.top');
    if (top) {
      const eyebrow = top.querySelector('.eyebrow');
      const title = top.querySelector('h1');
      if (eyebrow) eyebrow.textContent = 'HOME / EXPERIENCE';
      if (title) title.textContent = 'HERO SLIDER';
    }
    [...main.children].forEach(child => { if (child !== top) child.remove(); });
    const host = document.createElement('div');
    host.id = 'hero-slider-root';
    main.appendChild(host);
    return host;
  }

  async function load() {
    const host = root();
    if (!host) return;
    host.innerHTML = '<div class="hero-slider-loading">LOADING HERO MANAGER…</div>';
    try {
      const [settingsResponse, mediaResponse] = await Promise.all([request('/settings'), request('/media')]);
      const settings = Array.isArray(settingsResponse.data) ? settingsResponse.data : [];
      const record = settings.find(item => item.setting_key === KEY);
      let stored = record?.setting_value || null;
      if (typeof stored === 'string') {
        try { stored = JSON.parse(stored); } catch (_) { stored = null; }
      }
      config = normalizeConfig(stored);
      media = Array.isArray(mediaResponse.data) ? mediaResponse.data : [];
      if (!selectedId || !config.slides.some(slide => slide.id === selectedId)) selectedId = config.slides[0]?.id || '';
      renderManager();
    } catch (error) {
      host.innerHTML = `<div class="hero-slider-error">Unable to load Hero Slider: ${esc(error.message)}</div>`;
    }
  }

  function slideList() {
    if (!config.slides.length) return '<div class="hero-slider-empty">NO SLIDES YET<br><span>Create the first banner to keep the current BRVTAL hero as fallback until you publish.</span></div>';
    return config.slides.map((slide,index) => `
      <button type="button" class="hero-slide-row ${slide.id === selectedId ? 'active' : ''}" data-select-slide="${esc(slide.id)}">
        <span class="hero-slide-index">${String(index + 1).padStart(2,'0')}</span>
        <span class="hero-slide-row-copy"><strong>${esc(slide.name || slide.title || 'Untitled')}</strong><small>${esc(slide.mediaType.toUpperCase())} · ${slide.enabled ? 'ENABLED' : 'HIDDEN'}</small></span>
        <span class="hero-slide-row-actions">
          <i data-move="up" data-id="${esc(slide.id)}" aria-label="Move up">↑</i>
          <i data-move="down" data-id="${esc(slide.id)}" aria-label="Move down">↓</i>
        </span>
      </button>`).join('');
  }

  function editor(slide) {
    if (!slide) return '<div class="hero-slider-no-selection"><strong>CREATE A SLIDE</strong><p>Add an image or muted video banner. Desktop is the default; mobile can override only what needs to change.</p></div>';
    return `
      <div class="hero-editor-head"><div><span class="hero-kicker">SLIDE SETTINGS</span><strong>${esc(slide.name || 'Untitled')}</strong></div><button type="button" class="btn ghost" data-delete-slide>DELETE</button></div>
      <div class="hero-form-grid">
        <label><span>Internal name</span><input data-field="name" value="${esc(slide.name)}"></label>
        <label class="hero-switch"><input type="checkbox" data-field="enabled" ${slide.enabled ? 'checked' : ''}><span>Slide enabled</span></label>
        <label><span>Media type</span><select data-field="mediaType"><option value="image" ${slide.mediaType === 'image' ? 'selected' : ''}>Image</option><option value="video" ${slide.mediaType === 'video' ? 'selected' : ''}>Video</option></select></label>
        <label><span>Content alignment</span><select data-field="contentAlign"><option value="left" ${slide.contentAlign === 'left' ? 'selected' : ''}>Left</option><option value="center" ${slide.contentAlign === 'center' ? 'selected' : ''}>Center</option><option value="right" ${slide.contentAlign === 'right' ? 'selected' : ''}>Right</option></select></label>
        <label class="full"><span>Desktop media</span><div class="hero-media-pair"><input data-field="desktopSrc" value="${esc(slide.desktopSrc)}" placeholder="/uploads/... or https://"><select data-media-picker="desktopSrc">${mediaOptions(slide.mediaType)}</select></div></label>
        <label class="full"><span>Mobile override <em>optional</em></span><div class="hero-media-pair"><input data-field="mobileSrc" value="${esc(slide.mobileSrc)}" placeholder="Leave empty to use desktop media"><select data-media-picker="mobileSrc">${mediaOptions(slide.mediaType)}</select></div></label>
        ${slide.mediaType === 'video' ? `<label class="full"><span>Video poster <em>recommended</em></span><div class="hero-media-pair"><input data-field="poster" value="${esc(slide.poster)}" placeholder="Poster image"><select data-media-picker="poster">${mediaOptions('image')}</select></div></label>` : ''}
        <label class="full"><span>Kicker</span><input data-field="kicker" value="${esc(slide.kicker)}" placeholder="BRVTAL / NEXT EXPERIENCE"></label>
        <label class="full"><span>Title</span><input data-field="title" value="${esc(slide.title)}" placeholder="GENESIS"></label>
        <label class="full"><span>Body</span><textarea data-field="body" placeholder="Optional supporting copy">${esc(slide.body)}</textarea></label>
        <label><span>CTA label</span><input data-field="ctaLabel" value="${esc(slide.ctaLabel)}" placeholder="ENTER EXPERIENCE"></label>
        <label><span>CTA URL</span><input data-field="ctaUrl" value="${esc(slide.ctaUrl)}" placeholder="#events or https://"></label>
        <label class="full"><span>Dark overlay · <b data-overlay-value>${slide.overlay}%</b></span><input type="range" min="0" max="85" step="5" data-field="overlay" value="${slide.overlay}"></label>
      </div>`;
  }

  function previewMarkup(slide) {
    if (!slide) return '<div class="hero-preview-placeholder">CURRENT BRVTAL HERO REMAINS ACTIVE UNTIL A SLIDE IS PUBLISHED</div>';
    const useMobile = previewMode === 'mobile' && slide.mobileSrc;
    const src = useMobile ? slide.mobileSrc : slide.desktopSrc;
    const mediaMarkup = src ? (slide.mediaType === 'video'
      ? `<video muted loop playsinline autoplay ${slide.poster ? `poster="${esc(slide.poster)}"` : ''}><source src="${esc(src)}"></video>`
      : `<img src="${esc(src)}" alt="">`) : '<div class="hero-preview-media-empty">SELECT MEDIA</div>';
    return `<div class="hero-preview-stage align-${esc(slide.contentAlign)}" style="--hero-overlay:${slide.overlay / 100}">${mediaMarkup}<div class="hero-preview-overlay"></div><div class="hero-preview-copy">${slide.kicker ? `<span>${esc(slide.kicker)}</span>` : ''}${slide.title ? `<h2>${esc(slide.title)}</h2>` : ''}${slide.body ? `<p>${esc(slide.body)}</p>` : ''}${slide.ctaLabel ? `<b>${esc(slide.ctaLabel)} ↗</b>` : ''}</div></div>`;
  }

  function renderManager() {
    const host = root();
    if (!host) return;
    const slide = selectedSlide();
    host.innerHTML = `
      <section class="hero-manager">
        <div class="hero-manager-toolbar">
          <div><span class="hero-kicker">HOME / HERO</span><h2>SLIDER MANAGER</h2><p>LayerSlider-inspired, constrained for BRVTAL. Desktop first, mobile overrides only when needed.</p></div>
          <div class="hero-manager-actions"><label class="hero-switch"><input type="checkbox" data-config-field="enabled" ${config.enabled ? 'checked' : ''}><span>Publish slider</span></label><button type="button" class="btn ghost" data-add-slide>+ ADD SLIDE</button><button type="button" class="btn red" data-save-slider>SAVE</button></div>
        </div>
        <div class="hero-manager-global">
          <label class="hero-switch"><input type="checkbox" data-config-field="autoplay" ${config.autoplay ? 'checked' : ''}><span>Autoplay</span></label>
          <label><span>Slide duration</span><select data-config-field="interval">${[4000,5000,7000,9000,12000].map(ms => `<option value="${ms}" ${config.interval === ms ? 'selected' : ''}>${ms/1000}s</option>`).join('')}</select></label>
          <span class="hero-manager-fallback">SAFE FALLBACK · if no enabled slide is available, the original BRVTAL hero stays visible.</span>
        </div>
        <div class="hero-manager-grid">
          <aside class="hero-slide-list"><div class="hero-slide-list-head"><strong>SLIDES</strong><span>${config.slides.length}/20</span></div>${slideList()}</aside>
          <section class="hero-slide-editor">${editor(slide)}</section>
          <section class="hero-preview-panel"><div class="hero-preview-head"><strong>LIVE PREVIEW</strong><div><button type="button" data-preview="desktop" class="${previewMode === 'desktop' ? 'active' : ''}">DESKTOP</button><button type="button" data-preview="mobile" class="${previewMode === 'mobile' ? 'active' : ''}">MOBILE</button></div></div><div class="hero-preview-frame ${previewMode}">${previewMarkup(slide)}</div></section>
        </div>
      </section>`;
    bind();
  }

  function updateSelectedFromForm(target) {
    const slide = selectedSlide();
    if (!slide) return;
    const field = target.dataset.field;
    if (!field) return;
    if (target.type === 'checkbox') slide[field] = target.checked;
    else if (field === 'overlay') slide[field] = clamp(target.value,0,85);
    else slide[field] = target.value;
    if (field === 'name') {
      const label = document.querySelector('.hero-slide-row.active strong');
      if (label) label.textContent = slide.name || 'Untitled';
      const heading = document.querySelector('.hero-editor-head strong');
      if (heading) heading.textContent = slide.name || 'Untitled';
    }
    if (field === 'overlay') {
      const label = document.querySelector('[data-overlay-value]');
      if (label) label.textContent = slide.overlay + '%';
    }
    refreshPreview();
  }

  function refreshPreview() {
    const frame = document.querySelector('.hero-preview-frame');
    if (!frame) return;
    frame.className = 'hero-preview-frame ' + previewMode;
    frame.innerHTML = previewMarkup(selectedSlide());
  }

  function moveSlide(id, direction) {
    const index = config.slides.findIndex(slide => slide.id === id);
    if (index < 0) return;
    const next = direction === 'up' ? index - 1 : index + 1;
    if (next < 0 || next >= config.slides.length) return;
    [config.slides[index], config.slides[next]] = [config.slides[next], config.slides[index]];
    renderManager();
  }

  function bind() {
    const host = root();
    if (!host) return;
    host.querySelectorAll('[data-select-slide]').forEach(button => button.addEventListener('click', event => {
      if (event.target.closest('[data-move]')) return;
      selectedId = button.dataset.selectSlide;
      renderManager();
    }));
    host.querySelectorAll('[data-move]').forEach(button => button.addEventListener('click', event => {
      event.preventDefault(); event.stopPropagation(); moveSlide(button.dataset.id, button.dataset.move);
    }));
    host.querySelector('[data-add-slide]')?.addEventListener('click', () => {
      if (config.slides.length >= 20) return;
      const slide = normalizeSlide({name:'New slide ' + (config.slides.length + 1)});
      config.slides.push(slide); selectedId = slide.id; renderManager();
    });
    host.querySelector('[data-delete-slide]')?.addEventListener('click', () => {
      const index = config.slides.findIndex(slide => slide.id === selectedId);
      if (index < 0) return;
      config.slides.splice(index,1);
      selectedId = config.slides[Math.min(index, config.slides.length - 1)]?.id || '';
      renderManager();
    });
    host.querySelectorAll('[data-field]').forEach(input => {
      input.addEventListener('input', event => updateSelectedFromForm(event.target));
      input.addEventListener('change', event => updateSelectedFromForm(event.target));
    });
    host.querySelectorAll('[data-media-picker]').forEach(select => select.addEventListener('change', () => {
      if (!select.value) return;
      const field = select.dataset.mediaPicker;
      const input = host.querySelector(`[data-field="${field}"]`);
      if (input) { input.value = select.value; input.dispatchEvent(new Event('input',{bubbles:true})); }
      select.value = '';
    }));
    host.querySelectorAll('[data-config-field]').forEach(input => input.addEventListener('change', () => {
      const field = input.dataset.configField;
      config[field] = input.type === 'checkbox' ? input.checked : (field === 'interval' ? clamp(input.value,2500,30000) : input.value);
    }));
    host.querySelectorAll('[data-preview]').forEach(button => button.addEventListener('click', () => {
      previewMode = button.dataset.preview;
      host.querySelectorAll('[data-preview]').forEach(item => item.classList.toggle('active', item === button));
      refreshPreview();
    }));
    host.querySelector('[data-save-slider]')?.addEventListener('click', save);
  }

  async function save() {
    const button = root()?.querySelector('[data-save-slider]');
    if (button) { button.disabled = true; button.textContent = 'SAVING…'; }
    try {
      const payload = normalizeConfig(config);
      await request('/settings',{method:'POST',body:JSON.stringify({setting_key:KEY,setting_value:JSON.stringify(payload),is_json:1})});
      config = payload;
      window.BRVTALFeedback?.success?.('Hero Slider saved. Public fallback remains protected.','hero-slider-save');
      renderManager();
    } catch (error) {
      window.BRVTALFeedback?.error?.('Hero Slider save failed: ' + error.message,'hero-slider-save');
      if (button) { button.disabled = false; button.textContent = 'SAVE'; }
    }
  }

  function install() {
    ensureNav();
    const observer = new MutationObserver(() => ensureNav());
    observer.observe(document.documentElement,{childList:true,subtree:true});
    originalGo = window.go;
    window.go = async function(section) {
      if (section === 'hero-slider') {
        const host = prepareWorkspace();
        if (host) await load();
        ensureNav();
        return;
      }
      const result = await originalGo(section);
      ensureNav();
      return result;
    };
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install, {once:true});
  else install();
})();
