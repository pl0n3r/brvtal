/*
 * BRVTAL — Concept 05 authored Hero runtime (Issue #586).
 * Projects canonical public settings/media into the static Hero without
 * issuing another API request. Concept 05's authored hero has precedence
 * over the legacy banner slider; other public themes keep the slider.
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
    return firstImage(data?.memories)
      || firstImage(data?.media)
      || firstImage(data?.events, ['cover_image','image','photo'])
      || firstImage(data?.archive?.events, ['cover_image','image','photo']);
  }

  function projectManifesto(data) {
    const site = data?.settings?.site;
    const locale = String(document.documentElement.dataset.locale
      || document.documentElement.lang || 'es').toLowerCase().split('-')[0] === 'en'
      ? 'en' : 'es';
    const candidate = site && typeof site === 'object' && !Array.isArray(site)
      ? site['hero_manifesto_' + locale] : null;
    const manifesto = typeof candidate === 'string' ? candidate.trim() : '';
    const isSafe = manifesto.length > 0 && manifesto.length <= 64
      && !/[<>]/.test(manifesto) && !/[\u0000-\u001F\u007F]/.test(manifesto);

    document.querySelectorAll('[data-c5-hero-manifesto]').forEach(node => {
      if (isSafe) {
        // CMS content never becomes markup.
        node.textContent = manifesto;
        node.dataset.c5ManagedCopy = '1';
        node.dataset.c5DisplayLocale = locale;
        return;
      }
      // Restore the locale-specific owner copy after a previous CMS override.
      // The trusted defaults are emitted by the PHP renderer, not by the CMS.
      const fallback = locale === 'en' ? node.dataset.c5DefaultEn : node.dataset.c5DefaultEs;
      if (typeof fallback !== 'string' || !fallback) return;
      // On initial load, preserve the PHP-authored <br> and DOM exactly as
      // rendered; only rebuild when an override or the locale changed.
      if (node.dataset.c5ManagedCopy !== '1' && node.dataset.c5DisplayLocale === locale) return;
      delete node.dataset.c5ManagedCopy;
      node.dataset.c5DisplayLocale = locale;
      const lines = fallback.split('\n');
      const parts = [];
      lines.forEach((line, index) => {
        if (index) parts.push(document.createElement('br'));
        parts.push(document.createTextNode(line));
      });
      node.replaceChildren(...parts);
    });
  }

  function projectHeroEditorialCopy(data) {
    const site = data?.settings?.site;
    const locale = String(document.documentElement.dataset.locale
      || document.documentElement.lang || 'es').toLowerCase().split('-')[0] === 'en'
      ? 'en' : 'es';
    for (const [selector, key, limit] of [
      ['[data-c5-hero-cta]', 'hero_cta_', 48],
      ['[data-c5-hero-eyebrow]', 'hero_eyebrow_', 80],
    ]) {
      const candidate = site && typeof site === 'object' && !Array.isArray(site)
        ? site[key + locale] : null;
      const value = typeof candidate === 'string' ? candidate.trim() : '';
      const valid = value && value.length <= limit && !/[<>]/.test(value)
        && !/[\u0000-\u001F\u007F]/.test(value);
      document.querySelectorAll(selector).forEach(node => {
        const fallback = locale === 'en' ? node.dataset.c5DefaultEn : node.dataset.c5DefaultEs;
        const copy = valid ? value : fallback;
        if (!copy) return;
        if (selector === '[data-c5-hero-cta]') {
          // Preserve the destination and arrow icon; only replace the text node.
          if (valid) node.dataset.c5ManagedCta = '1';
          else delete node.dataset.c5ManagedCta;
          const first = node.firstChild;
          if (first && first.nodeType === 3) first.textContent = copy + ' ';
          else node.insertBefore(document.createTextNode(copy + ' '), first);
        } else {
          if (valid) node.dataset.c5ManagedEyebrow = '1';
          else delete node.dataset.c5ManagedEyebrow;
          node.textContent = copy;
        }
      });
    }
  }

  function projectDescription(data) {
    const site = data?.settings?.site;
    const candidate = site && typeof site === 'object' && !Array.isArray(site)
      ? site.description : null;
    const description = typeof candidate === 'string' ? candidate.trim() : '';
    const valid = description && description.length <= 240
      && !/[<>]/.test(description) && !/[\u0000-\u001F\u007F]/.test(description);

    document.querySelectorAll('[data-c5-hero-description]').forEach(node => {
      if (valid) {
        // Never insert editor-supplied markup.
        node.textContent = description;
        return;
      }
      // Empty/missing/rejected description is *not* the site's tagline.
      // Preserve or restore the canonical PHP-authored owner-v2 copy.
      const fallback = node.dataset.c5DefaultDescription;
      if (typeof fallback === 'string' && fallback && node.textContent !== fallback) {
        node.textContent = fallback;
      }
    });
  }

  function projectDocumentary(data) {
    const figure = document.querySelector('[data-c5-hero-documentary]');
    const image = figure?.querySelector('[data-c5-hero-documentary-image]');
    if (!figure || !image) return false;

    const documentary = documentaryFrom(data);
    if (!documentary) return false;

    image.alt = documentary.alt || 'BRVTAL archive';
    const label = figure.querySelector('[data-c5-hero-documentary-label]');
    if (label) label.textContent = documentary.label || 'BRVTAL ARCHIVE';

    figure.hidden = true;
    image.onload = () => {
      figure.hidden = false;
    };
    image.onerror = () => {
      figure.hidden = true;
      image.removeAttribute('src');
    };
    image.src = documentary.src;
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

  let lastPublicData = null;

  async function init() {
    const hero = document.querySelector('.home-phase-a-hero');
    if (!hero || hero.dataset.c5HeroReady === '1') return;
    hero.dataset.c5HeroReady = '1';

    if (document.readyState !== 'complete') {
      await new Promise(resolve => window.addEventListener('load', resolve, {once:true}));
    }

    const data = await waitForPublicData();
    lastPublicData = data;
    projectDescription(data);
    projectManifesto(data);
    projectHeroEditorialCopy(data);
    projectDocumentary(data);
    animateHero();
  }

  window.addEventListener('brvtal:localechange', () => {
    if (lastPublicData) {
      projectManifesto(lastPublicData);
      projectHeroEditorialCopy(lastPublicData);
    }
  });

  function startInit() {
    void init().catch(error => {
      console.warn('[BRVTAL] Concept 05 hero init failed closed.', error);
    });
  }

  const ready = window.BRVTALRuntimeReady;
  if (ready && typeof ready.then === 'function') {
    void ready.then(startInit, startInit);
  } else if (document.readyState === 'complete') {
    startInit();
  } else {
    window.addEventListener('load', startInit, {once:true});
  }

  window.BRVTALConcept05Hero = {
    init,
    projectDocumentary,
    projectDescription,
    projectManifesto,
    projectHeroEditorialCopy,
  };
})();
