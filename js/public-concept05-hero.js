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

  /**
   * Choose real Media Engine WebP derivatives before setting src; otherwise
   * Chromium can already be downloading the original multi-MB PNG.
   */
  function responsiveCandidates(original, manifest, card = false) {
    if (!safeCmsMediaPath(original) || !manifest || typeof manifest !== 'object') return [];
    const item = manifest[original];
    if (!item || item.src !== original || !item.variants) return [];
    const originalWidth = Number(item.width);
    const originalHeight = Number(item.height);
    if (!(originalWidth > 0 && originalHeight > 0)) return [];
    const originalRatio = originalWidth / originalHeight;
    const preferred = card ? ['card', 'display', 'w1280', 'w1920'] : ['display', 'w1280', 'w1920'];
    const found = new Map();
    preferred.forEach(name => {
      const variant = item.variants[name];
      const width = Number(variant?.width), height = Number(variant?.height);
      if (!Number.isSafeInteger(width) || !Number.isSafeInteger(height)
        || width < 1 || height < 1 || variant?.type !== 'image/webp'
        || !safeCmsMediaPath(variant.src)
        || Math.abs((width / height) - originalRatio) > originalRatio * 0.03) return;
      if (!found.has(width)) found.set(width, {src:variant.src,width,height});
    });
    return [...found.values()].sort((a,b) => a.width - b.width);
  }

  function bindCmsImageFallback(image, original, onLoad, onFail) {
    image.onload = onLoad;
    image.onerror = () => {
      if (image.dataset.c5WebpActive === '1') {
        image.dataset.c5WebpActive = '0';
        image.removeAttribute('srcset');
        image.removeAttribute('sizes');
        image.src = original;
        return;
      }
      onFail();
    };
  }

  function assignResponsiveCmsImage(image, original, {card = false, sizes = '100vw', dimensions = false} = {}) {
    image.dataset.c5PendingSource = original;
    const apply = manifest => {
      if (image.dataset.c5PendingSource !== original) return;
      const candidates = responsiveCandidates(original, manifest, card);
      image.removeAttribute('srcset');
      image.removeAttribute('sizes');
      image.dataset.c5WebpActive = candidates.length ? '1' : '0';
      if (candidates.length) {
        image.srcset = candidates.map(candidate => candidate.src + ' ' + candidate.width + 'w').join(', ');
        image.sizes = sizes;
        if (dimensions) {
          image.width = Number(manifest[original].width);
          image.height = Number(manifest[original].height);
        }
      }
      image.src = candidates.length ? candidates[0].src : original;
    };
    const load = window.BRVTALPublicMedia?.loadImageDelivery;
    if (!safeCmsMediaPath(original) || typeof load !== 'function') {
      apply(null);
      return;
    }
    Promise.resolve().then(() => load()).then(apply, () => apply(null));
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
    image.loading = 'eager';
    image.decoding = 'async';
    image.fetchPriority = 'high';
    figure.hidden = true;
    bindCmsImageFallback(
      image, documentary.src,
      () => { figure.hidden = false; },
      () => { figure.hidden = true; image.removeAttribute('src'); }
    );
    assignResponsiveCmsImage(image, documentary.src, {
      sizes:'(max-width: 900px) 78vw, 460px', dimensions:true
    });
    return true;
  }

  // The five versioned sources are never browser media URLs.
  function safeCmsMediaPath(value) {
    if (typeof value !== 'string' || !value.startsWith('/uploads/media/')) return false;
    if (value.includes('..') || /[%?#\\]/.test(value)) return false;
    const segments = value.slice('/uploads/media/'.length).split('/');
    if (!segments.length) return false;
    return segments.every((part, index) => index === segments.length - 1
      ? /^[\w-]+\.(?:webp|png|jpe?g|gif)$/i.test(part)
      : /^[\w-]+$/.test(part));
  }

  function projectEditorialMedia(data) {
    const site = data?.settings?.site;
    if (!site || typeof site !== 'object') return;
    const destinations = {
      hero_face:'.home-phase-a-hero',
      crowd:'#genesis',
      nights:'#events',
      artists:'#artists',
      memories:'#media',
    };
    Object.entries(destinations).forEach(([key, selector]) => {
      const section = document.querySelector(selector);
      const record = site['c05_media_' + key];
      if (!section || record?.status !== 'published'
        || !Number.isSafeInteger(Number(record.id)) || Number(record.id) < 1
        || !safeCmsMediaPath(record.url)) return;
      if (section.querySelector('img[data-c5-editorial-media="' + key + '"]')) return;
      const image = document.createElement('img');
      image.dataset.c5EditorialMedia = key;
      image.alt = 'BRVTAL editorial ' + key.replace('_', ' ');
      image.width = 384;
      image.height = 240;
      image.style.maxWidth = '100%';
      image.style.height = 'auto';
      image.style.maxHeight = '220px';
      image.style.objectFit = 'cover';
      image.loading = key === 'hero_face' ? 'eager' : 'lazy';
      image.decoding = 'async';
      image.hidden = true;
      section.append(image);
    });
    document.querySelectorAll('img[data-c5-editorial-media]').forEach(image => {
      const key = image.dataset.c5EditorialMedia;
      const section = document.querySelector(destinations[key]);
      const record = site['c05_media_' + key];
      const url = record && typeof record === 'object' ? record.url : '';
      const id = record && typeof record === 'object' ? Number(record.id) : 0;
      if (record?.status !== 'published' || !Number.isSafeInteger(id) || id < 1 || !safeCmsMediaPath(url)) {
        image.dataset.c5PendingSource = '';
        image.hidden = true;
        image.removeAttribute('srcset');
        image.removeAttribute('src');
        return;
      }
      if (image.dataset.c5PendingSource === url) return;
      image.dataset.c5PendingSource = url;
      image.hidden = true;
      bindCmsImageFallback(
        image, url,
        () => { image.hidden = false; },
        () => { image.hidden = true; image.removeAttribute('src'); }
      );
      const start = () => {
        if (image.dataset.c5PendingSource !== url) return;
        // The image is hidden while loading: native lazy loading would stall.
        // Defer assignment to src instead, then use eager only on intersection.
        image.loading = 'eager';
        if (key === 'hero_face') image.fetchPriority = 'high';
        assignResponsiveCmsImage(image, url, {
          card:true, sizes:'(max-width: 900px) 72vw, 384px'
        });
      };
      if (key === 'hero_face' || !section || typeof IntersectionObserver !== 'function') {
        start();
      } else {
        const observer = new IntersectionObserver(entries => {
          if (!entries.some(entry => entry.isIntersecting && entry.target === section)) return;
          observer.disconnect();
          start();
        }, {rootMargin:'250px 0px'});
        observer.observe(section);
      }
    });
  }

  let heroMotionMedia = null;
  function animateHero() {
    const hero = document.querySelector('.home-phase-a-hero');
    if (!hero || !window.gsap || reducedMotion()) return;
    heroMotionMedia?.revert();
    const gsap = window.gsap;
    heroMotionMedia = gsap.matchMedia();
    heroMotionMedia.add('(prefers-reduced-motion: no-preference)', () => {
      if (reducedMotion()) return;
      const context = gsap.context(() => {
        const figure = hero.querySelector('[data-c5-hero-documentary]:not([hidden])');
        const statement = hero.querySelector('.hero-declaration');
        const explore = hero.querySelector('.c5-hero-explore');
        // One sequence; no animated layout properties.
        const sequence = gsap.timeline();
        if (figure) sequence.from(figure, {
          opacity: 0, y: 24, rotation: -4,
          duration: .72, ease: 'power3.out', clearProps: 'opacity,transform'
        }, .18);
        if (statement) sequence.from(statement, {
          opacity: 0, x: 32,
          duration: .62, ease: 'power3.out', clearProps: 'opacity,transform'
        }, .12);
        if (explore) sequence.from(explore, {
          opacity: 0, y: 10,
          duration: .4, ease: 'power2.out', clearProps: 'opacity,transform'
        }, .42);
      }, hero);
      return () => context.revert();
    });
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
    projectEditorialMedia(data);
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

  window.addEventListener('pagehide', () => heroMotionMedia?.revert(), { once: true });

  window.BRVTALConcept05Hero = {
    init,
    projectDocumentary,
    projectDescription,
    projectManifesto,
    projectHeroEditorialCopy,
    projectEditorialMedia,
  };
})();
