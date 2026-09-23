/*
 * BRVTAL — Concept 05 Next Experience media safety (#590).
 * No data fetching and no motion ownership: the server renderer owns canonical
 * Event data and public-concept05-motion.js owns GSAP. This runtime only makes
 * the artwork fail closed when a managed media reference cannot load.
 */
(function () {
  'use strict';

  function failClosed(host, image) {
    if (!host || !image) return;
    host.classList.add('is-media-missing');
    image.hidden = true;
    image.removeAttribute('src');
  }

  function bindArtwork(host) {
    if (!host || host.dataset.c5MediaBound === '1') return;
    host.dataset.c5MediaBound = '1';

    var image = host.querySelector('img');
    if (!image) {
      host.classList.add('is-media-missing');
      return;
    }

    image.addEventListener('error', function () {
      failClosed(host, image);
    }, { once:true });

    image.addEventListener('load', function () {
      if (image.naturalWidth > 0) {
        host.classList.remove('is-media-missing');
        image.hidden = false;
      }
    }, { once:true });

    if (image.complete) {
      if (image.naturalWidth > 0) {
        host.classList.remove('is-media-missing');
      } else {
        failClosed(host, image);
      }
    }
  }

  function init() {
    document.querySelectorAll('[data-c5-experience-artwork]').forEach(bindArtwork);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once:true });
  } else {
    init();
  }

  window.BRVTAL_CONCEPT05_EXPERIENCE_INIT = init;
})();
