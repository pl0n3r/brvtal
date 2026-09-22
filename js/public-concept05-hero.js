/*
 * BRVTAL — Concept 05 authored Hero runtime (Issue #586).
 * Projects canonical public settings/media into the static Hero without
 * issuing another API request. The managed Hero Slider remains authoritative
 * whenever it is enabled.
 */
(() => {
  'use strict';

  const root = document.documentElement;

  function reducedMotion() {
    const mode = root.dataset.themeMotion;
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches
      || root.classList.contains('c5-visual-test')
      || mode === 'reduced'
      || mode === 'minimal';
  }

  function publicRoot(source) {
    const payload = source?.payload ?? source;
    return payload?.data && typeof payload.data === 'object'
      ? payload.data
      : payload;
  }

  function safeMediaPath(value) {
    const raw = String(value || '').trim();
    if (!raw) return '';
    if (/^https?:\/\//i.test(raw)) return raw;
    if (raw.startsWith('/') && !raw.startsWith('//')) return raw;
    return raw.replace(/^\.\//, '');
  }

  function firstImage(items, pathKeys = ['file_path','cover_image','image','photo']) {
    if (!Array.isArray(items)) return null;
    for (const item of items) {
      if (!item || typeof item !== 'object') continue;
      if (item.type && String(item.type).toLowerCase() !== 'image') continue;
      const path = pathKeys
        .map(key => safeMediaPath(item[key]))
        .find(Boolean);
      if (!path) continue;
      return {
        src:path,
        alt:String(item.alt_text || item.title || item.name || 'BRVTAL archive').trim(),
        label:String(item.title || item.name || item.context || 'BRVTAL ARCHIVE').trim(),
      };
    }
    return null;
  }

  function documentaryFrom(data) {
    return firstImage(data?.media)
      || firstImage(data?.memories)
      || firstImage(data?.events, ['cover_image','image','photo'])
      || firstImage(data?.archive?.events, ['cover_image','image','photo']);
  }

  function projectDescription(data) {
    const site = data?.settings?.site;
    if (!site || typeof site !== 'object') return;
    const description = String(site.description || site.tagline || '').trim();
    if (!description) return;
    document.querySelectorAll('[data-c5-hero-description]').forEach(node => {
      node.textContent = description;
    });
  }

  function projectDocumentary(data) {
    const figure = document.querySelector('[data-c5-hero-documentary]');
    const image = figure?.querySelector('[data-c5-hero-documentary-image]');
    if (!figure || !image) return false;

    const documentary = documentaryFrom(data);
    if (!documentary) return false;

    image.src = documentary.src;
    image.alt = documentary.alt || 'BRVTAL archive';
    const label = figure.querySelector('[data-c5-hero-documentary-label]');
    if (label) label.textContent = documentary.label || 'BRVTAL ARCHIVE';
    figure.hidden = false;
    return true;
  }

  function animateHero() {
    const hero = document.querySelector('.home-phase-a-hero');
    if (!hero || !window.gsap || reducedMotion()) return;

    const figure = hero.querySelector('[data-c5-hero-documentary]:not([hidden])');
    const statement = hero.querySelector('.hero-declaration');
    const explore = hero.querySelector('.c5-hero-explore');

    if (figure) {
      window.gsap.from(figure, {
        opacity:0,
        y:24,
        rotation:-4,
        duration:.72,
        delay:.18,
        ease:'power3.out',
        clearProps:'opacity,transform',
      });
    }
    if (statement) {
      window.gsap.from(statement, {
        opacity:0,
        x:32,
        duration:.62,
        delay:.12,
        ease:'power3.out',
        clearProps:'opacity,transform',
      });
    }
    if (explore) {
      window.gsap.from(explore, {
        opacity:0,
        y:10,
        duration:.4,
        delay:.42,
        ease:'power2.out',
        clearProps:'opacity,transform',
      });
    }
  }

  async function waitForPublicData() {
    for (let attempt = 0; attempt < 16; attempt += 1) {
      const request = window.BRVTALPublicDataPromise;
      if (request) {
        try {
          return publicRoot(await request) || {};
        } catch (_) {
          return {};
        }
      }
      await new Promise(resolve => window.setTimeout(resolve, 80));
    }
    return {};
  }

  async function init() {
    const hero = document.querySelector('.home-phase-a-hero');
    if (!hero || hero.dataset.c5HeroReady === '1') return;
    hero.dataset.c5HeroReady = '1';

    const data = await waitForPublicData();
    projectDescription(data);
    projectDocumentary(data);
    animateHero();
  }

  const ready = window.BRVTALRuntimeReady;
  if (ready && typeof ready.then === 'function') {
    ready.finally(init);
  } else if (document.readyState === 'complete') {
    init();
  } else {
    window.addEventListener('load', init, {once:true});
  }

  window.BRVTALConcept05Hero = {
    init,
    projectDocumentary,
    projectDescription,
  };
})();
