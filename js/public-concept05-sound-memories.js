/*
 * BRVTAL — Concept 05 / SOUND + MEMORIES progressive enhancement (#594).
 * No network requests. Canonical Set/Memory renderers own data and interactions;
 * this layer only adds authored composition, decorative signal and fail-closed media.
 */
(function () {
  'use strict';

  if (!document.querySelector('[data-concept="05"]')) return;

  function failMedia(host, media) {
    if (!host) return;
    host.classList.add('is-media-missing');
    if (media) media.hidden = true;
  }

  function bindMedia(host, media) {
    if (!host || !media) return;
    if (media.dataset.c5ArchiveMediaBound === '1') return;
    media.dataset.c5ArchiveMediaBound = '1';

    media.addEventListener('error', function () {
      failMedia(host, media);
      var trigger = host.closest('[data-public-media-item]')?.querySelector('[data-public-media-open]');
      if (trigger) {
        trigger.disabled = true;
        trigger.setAttribute('aria-disabled', 'true');
      }
    }, { once:true });

    if (media instanceof HTMLImageElement && media.complete && media.naturalWidth === 0) {
      failMedia(host, media);
    }
  }

  function hydrateSound() {
    var section = document.querySelector('.sets');
    if (!section) return;

    var items = Array.from(section.querySelectorAll('.set-library-item'));
    items.forEach(function (item, index) {
      item.classList.add('c5-sound-record');
      item.classList.toggle('c5-sound-feature', index === 0);

      var cover = item.querySelector('[data-set-cover]');
      if (cover) bindMedia(cover, cover.querySelector('img'));
    });

    var first = items[0];
    if (first) {
      var main = first.querySelector('.set-main');
      if (main && !main.querySelector('.c5-sound-signal')) {
        var signal = document.createElement('span');
        signal.className = 'c5-sound-signal';
        signal.setAttribute('aria-hidden', 'true');
        main.appendChild(signal);
      }
    }

    var route = section.querySelector('.c5-section-route--sound');
    var record = section.querySelector('.set-record-link[href^="/sets/"]');
    if (record) {
      if (!route) {
        route = document.createElement('a');
        route.className = 'c5-section-route c5-section-route--sound';
        route.textContent = 'EXPLORE SOUND ↗';
        section.appendChild(route);
      }
      route.href = record.getAttribute('href');
    } else if (route) {
      route.remove();
    }

    document.documentElement.dataset.concept05Sound = 'ready';
  }

  function hydrateMemory(item) {
    if (!(item instanceof HTMLElement)) return;
    item.classList.add('c5-memory-cell');
    var open = item.querySelector('.public-media-open');
    var media = open?.querySelector('img,video');
    if (media) bindMedia(open, media);
  }

  function hydrateMemories() {
    var section = document.querySelector('.media');
    if (!section) return;

    section.querySelectorAll('[data-public-media-item]').forEach(hydrateMemory);

    var annotation = section.querySelector('.c5-memory-annotation');
    if (!annotation) {
      annotation = document.createElement('span');
      annotation.className = 'c5-memory-annotation';
      annotation.setAttribute('aria-hidden', 'true');
      annotation.textContent = 'PEOPLE / LIGHTS / MEMORIES / FOREVER';
      var grid = section.querySelector('.media-grid');
      if (grid) grid.after(annotation);
    }

    var route = section.querySelector('.c5-section-route--memories');
    var archive = document.getElementById('eventArchive');
    if (archive && !route) {
      route = document.createElement('a');
      route.className = 'c5-section-route c5-section-route--memories';
      route.href = '#eventArchive';
      route.textContent = 'EXPLORE ARCHIVE ↓';
      section.appendChild(route);
    }

    document.documentElement.dataset.concept05Memories = 'ready';
  }

  function hydrate() {
    hydrateSound();
    hydrateMemories();
    window.dispatchEvent(new CustomEvent('brvtal:concept05-sound-memories-ready'));
  }

  var setList = document.querySelector('.set-list');
  var mediaGrid = document.querySelector('.media-grid');
  var observer = new MutationObserver(hydrate);
  if (setList) observer.observe(setList, { childList:true });
  if (mediaGrid) observer.observe(mediaGrid, { childList:true });

  window.addEventListener('brvtal:sets-library-rendered', hydrateSound);
  window.addEventListener('brvtal:memories-rendered', hydrateMemories);
  window.BRVTAL_CONCEPT05_SOUND_MEMORIES_INIT = hydrate;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', hydrate, { once:true });
  } else {
    hydrate();
  }
})();
