/*
 * BRVTAL — Concept 05 motion foundation (Issues #584, #1366).
 * Motion opt-in; text remains readable without scripts.
 */
(function () {
  'use strict';
  var media = null;

  function motionDisabled() {
    var root = document.documentElement;
    var theme = root.getAttribute('data-theme-motion');
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches
      || window.matchMedia('(pointer: coarse)').matches
      || root.classList.contains('c5-visual-test')
      || theme === 'reduced' || theme === 'minimal';
  }

  function init() {
    if (media) { media.revert(); media = null; }
    var hosts = document.querySelectorAll('[data-concept="05"]');
    if (!hosts.length || !window.gsap || motionDisabled()) return;
    var gsap = window.gsap;
    if (window.ScrollTrigger) gsap.registerPlugin(window.ScrollTrigger);
    media = gsap.matchMedia();
    media.add('(prefers-reduced-motion: no-preference)', function () {
      if (motionDisabled()) return;
      var context = gsap.context(function () {
        hosts.forEach(function (host) {
          host.querySelectorAll('.c5-module').forEach(function (el) {
            gsap.from(el, {
              y: 24, duration: 0.6, ease: 'power3.out',
              clearProps: 'transform',
              scrollTrigger: window.ScrollTrigger
                ? { trigger: el, start: 'top 88%', once: true }
                : undefined
            });
          });
          host.querySelectorAll('.c5-numbered').forEach(function (section) {
            var title = section.querySelector('h2, h3');
            // One reversible timeline per numbered section.
            var sequence = gsap.timeline({
              scrollTrigger: window.ScrollTrigger
                ? { trigger: section, start: 'top 85%', once: true }
                : undefined
            });
            sequence.from(section, {
              y: 12, clearProps: 'transform', duration: .5, ease: 'power4.out'
            });
            if (title) sequence.from(title, {
              x: 8, opacity: .96, duration: .18,
              clearProps: 'transform,opacity', ease: 'power2.out'
            }, 0);
          });
        });
      });
      var refresh = function () { window.ScrollTrigger?.refresh?.(); };
      if (document.readyState === 'complete') refresh();
      else window.addEventListener('load', refresh, { once: true });
      return function () {
        window.removeEventListener('load', refresh);
        context.revert();
      };
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }
  window.addEventListener('pagehide', function () {
    media?.revert();
    media = null;
  }, { once: true });
  window.BRVTAL_CONCEPT05_MOTION_INIT = init;
})();
