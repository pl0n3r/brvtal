(() => {
  'use strict';

  const endpoint = '/api/hero-slider.php';
  let config = null;
  let index = 0;
  let timer = null;
  let mounted = false;
  let paused = false;

  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
  const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const isMobile = () => window.matchMedia('(max-width: 700px)').matches;

  function injectStyles() {
    if (document.querySelector('link[data-hero-v2-public]')) return;
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = '/css/hero-slider-v2.css';
    link.dataset.heroV2Public = '1';
    document.head.appendChild(link);
  }

  function sourceFor(slide) {
    return isMobile() && slide.mobileSrc ? slide.mobileSrc : slide.desktopSrc;
  }

  function mediaMarkup(slide, position) {
    const src = sourceFor(slide);
    if (!src) return '';
    if (slide.mediaType === 'video') {
      return `<video class="brvtal-hero-media" muted loop playsinline preload="metadata" ${slide.poster ? `poster="${esc(slide.poster)}"` : ''}><source src="${esc(src)}"></video>`;
    }
    return `<img class="brvtal-hero-media" ${position === 0 ? `src="${esc(src)}" loading="eager" fetchpriority="high"` : `data-src="${esc(src)}"`} alt="" decoding="async">`;
  }

  function layerMarkup(layer, position) {
    if (isMobile() && layer.hiddenMobile) return '';
    const mobile = isMobile();
    const x = mobile && layer.mobileX != null ? layer.mobileX : layer.x;
    const y = mobile && layer.mobileY != null ? layer.mobileY : layer.y;
    const width = mobile && layer.mobileWidth != null ? layer.mobileWidth : layer.width;
    const src = mobile && layer.mobileSrc ? layer.mobileSrc : layer.src;
    const style = `left:${Number(x)}%;top:${Number(y)}%;width:${Number(width)}%;text-align:${esc(layer.align)};--layer-delay:${Number(layer.delay || 0)}ms;--layer-duration:${Number(layer.duration || 650)}ms`;
    if (layer.type === 'image' || layer.type === 'logo') {
      if (!src) return '';
      return `<div class="brvtal-hero-layer type-${esc(layer.type)} anim-${esc(layer.animation)}" style="${style}"><img ${position === 0 ? `src="${esc(src)}"` : `data-src="${esc(src)}"`} alt="" decoding="async"></div>`;
    }
    if (layer.type === 'cta') {
      const text = esc(layer.text || 'ENTER EXPERIENCE');
      return layer.url ? `<a class="brvtal-hero-layer type-cta anim-${esc(layer.animation)} magnetic" style="${style}" href="${esc(layer.url)}">${text} <span>↗</span></a>` : `<div class="brvtal-hero-layer type-cta anim-${esc(layer.animation)}" style="${style}">${text}</div>`;
    }
    return `<div class="brvtal-hero-layer type-text anim-${esc(layer.animation)}" style="${style}">${esc(layer.text || '')}</div>`;
  }

  function legacyCopy(slide) {
    if (Array.isArray(slide.layers) && slide.layers.length) return '';
    const hasCta = slide.ctaLabel && slide.ctaUrl;
    return `<div class="brvtal-hero-content">${slide.kicker ? `<div class="brvtal-hero-kicker mono">${esc(slide.kicker)}</div>` : ''}${slide.title ? `<h1>${esc(slide.title)}</h1>` : ''}${slide.body ? `<p>${esc(slide.body)}</p>` : ''}${hasCta ? `<a class="brvtal-hero-cta magnetic" href="${esc(slide.ctaUrl)}">${esc(slide.ctaLabel)} <span>↗</span></a>` : ''}</div>`;
  }

  function slideMarkup(slide, position) {
    const transition = ['fade','slide','zoom'].includes(slide.transition) ? slide.transition : 'fade';
    return `<article class="brvtal-hero-slide ${position === 0 ? 'active' : ''} align-${esc(slide.contentAlign)} transition-${transition}" data-hero-slide="${position}" aria-hidden="${position === 0 ? 'false' : 'true'}" style="--hero-overlay:${Number(slide.overlay ?? 35) / 100}">${mediaMarkup(slide, position)}<div class="brvtal-hero-overlay"></div>${legacyCopy(slide)}${Array.isArray(slide.layers) ? slide.layers.map(layer => layerMarkup(layer, position)).join('') : ''}</article>`;
  }

  function controlsMarkup(total) {
    if (total <= 1) return '';
    const dots = Array.from({length: total}, (_, i) => `<button type="button" data-hero-dot="${i}" class="${i === 0 ? 'active' : ''}" aria-label="Show slide ${i + 1}"></button>`).join('');
    return `<div class="brvtal-hero-controls"><button type="button" data-hero-prev aria-label="Previous slide">←</button><div class="brvtal-hero-dots">${dots}</div><button type="button" data-hero-next aria-label="Next slide">→</button><button type="button" data-hero-pause aria-label="Pause automatic slides" aria-pressed="false">Ⅱ</button></div>`;
  }

  function mount(data) {
    const hero = document.querySelector('main#top > .hero');
    if (!hero || !data?.enabled || !Array.isArray(data.slides) || !data.slides.length) return;
    injectStyles();
    config = data;
    const root = document.createElement('div');
    root.className = 'brvtal-hero-slider';
    root.setAttribute('aria-roledescription','carousel');
    root.setAttribute('aria-label','BRVTAL featured content');
    root.innerHTML = `<div class="brvtal-hero-track">${data.slides.map(slideMarkup).join('')}</div>${controlsMarkup(data.slides.length)}<div class="brvtal-hero-counter mono"><span data-hero-current>01</span> / ${String(data.slides.length).padStart(2,'0')}</div>`;
    hero.prepend(root);
    hero.classList.add('hero-slider-active');
    mounted = true;
    bind(root);
    activateVideos();
    schedule();
  }

  function slides() { return [...document.querySelectorAll('[data-hero-slide]')]; }

  function go(next) {
    const all = slides();
    if (!all.length) return;
    index = (next + all.length) % all.length;
    const currentSlide = all[index];
    currentSlide.querySelectorAll('img[data-src]').forEach(img => { img.src = img.dataset.src; delete img.dataset.src; });
    all.forEach((slide, i) => {
      const active = i === index;
      slide.classList.toggle('active', active);
      slide.setAttribute('aria-hidden', active ? 'false' : 'true');
      const video = slide.querySelector('video');
      if (video) {
        if (active) video.play().catch(() => {});
        else { video.pause(); video.currentTime = 0; }
      }
    });
    document.querySelectorAll('[data-hero-dot]').forEach((dot, i) => dot.classList.toggle('active', i === index));
    const current = document.querySelector('[data-hero-current]');
    if (current) current.textContent = String(index + 1).padStart(2,'0');
    schedule();
  }

  function activateVideos() {
    slides().forEach((slide, i) => {
      const video = slide.querySelector('video');
      if (!video) return;
      if (i === index) video.play().catch(() => {}); else video.pause();
    });
  }

  function schedule() {
    clearTimeout(timer);
    if (!mounted || !config?.autoplay || config.slides.length <= 1 || reducedMotion() || paused || document.hidden) return;
    timer = setTimeout(() => go(index + 1), Math.max(2500, Math.min(30000, Number(config.interval) || 7000)));
  }

  function bind(root) {
    root.querySelector('[data-hero-prev]')?.addEventListener('click', () => go(index - 1));
    root.querySelector('[data-hero-next]')?.addEventListener('click', () => go(index + 1));
    root.querySelectorAll('[data-hero-dot]').forEach(dot => dot.addEventListener('click', () => go(Number(dot.dataset.heroDot || 0))));
    const pauseButton = root.querySelector('[data-hero-pause]');
    if (pauseButton) {
      pauseButton.hidden = !config.autoplay || reducedMotion();
      pauseButton.addEventListener('click', () => {
        paused = !paused;
        pauseButton.setAttribute('aria-pressed', String(paused));
        pauseButton.setAttribute('aria-label', paused ? 'Resume automatic slides' : 'Pause automatic slides');
        pauseButton.textContent = paused ? '▶' : 'Ⅱ';
        schedule();
      });
    }
    root.addEventListener('mouseenter', () => clearTimeout(timer));
    root.addEventListener('mouseleave', schedule);
    root.addEventListener('focusin', () => clearTimeout(timer));
    root.addEventListener('focusout', event => { if (!root.contains(event.relatedTarget)) schedule(); });
    document.addEventListener('visibilitychange', schedule);
  }

  async function init() {
    try {
      const response = await fetch(endpoint, {cache:'no-store', credentials:'same-origin'});
      const payload = await response.json();
      if (!response.ok || payload?.ok !== true) return;
      mount(payload.data);
    } catch (_) {
      // Safe by design: the original art-directed hero remains untouched.
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once:true}); else init();
})();
