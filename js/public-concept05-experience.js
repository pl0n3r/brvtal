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

  // A reused CMS host may replace an image OR change the source on the
  // same image. Bind each request once and invalidate superseded callbacks.
  var artworkBindings = new WeakMap();

  function bindArtwork(host) {
    if (!host) return;
    var image = host.querySelector('img');
    var source = image ? String(image.getAttribute('src') || '') : '';
    var previous = artworkBindings.get(host);
    if (previous && previous.image === image && previous.source === source) return;

    var binding = { image: image, source: source };
    artworkBindings.set(host, binding);
    host.dataset.c5MediaBound = '1';

    if (!image || !source) {
      host.classList.add('is-media-missing');
      if (image) image.hidden = true;
      return;
    }

    function isCurrent() {
      return artworkBindings.get(host) === binding
        && host.querySelector('img') === image
        && image.getAttribute('src') === source;
    }

    image.addEventListener('error', function () {
      if (isCurrent()) failClosed(host, image);
    }, { once:true });

    image.addEventListener('load', function () {
      if (isCurrent() && image.naturalWidth > 0) {
        host.classList.remove('is-media-missing');
        image.hidden = false;
      }
    }, { once:true });

    if (image.complete) {
      if (image.naturalWidth > 0) {
        host.classList.remove('is-media-missing');
        image.hidden = false;
      } else {
        failClosed(host, image);
      }
    } else {
      // Suppress broken chrome until the replacement source really loads.
      host.classList.add('is-media-missing');
      image.hidden = true;
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
