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

  // Bind to the image identity, not the container alone: a CMS refresh may
  // reuse the host and replace its child <img> without recreating the section.
  var artworkImages = new WeakMap();

  function bindArtwork(host) {
    if (!host) return;
    var image = host.querySelector('img');
    if (artworkImages.has(host) && artworkImages.get(host) === image) return;
    artworkImages.set(host, image);
    host.dataset.c5MediaBound = '1';

    if (!image) {
      host.classList.add('is-media-missing');
      return;
    }

    // An old detached image must not reset a newly rendered image's state.
    function isCurrent() {
      return host.querySelector('img') === image;
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
      // Keep a replacement concealed until real load success, including
      // when the previous CMS image had failed closed.
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
