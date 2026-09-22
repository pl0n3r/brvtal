/*
 * BRVTAL — Concept 05 motion foundation (Issue #584, parent #583).
 * Reusable GSAP reveal/registration primitives for elements opted into
 * `[data-concept="05"]`. Foundation only: no Hero/section rewrite here.
 * Respects prefers-reduced-motion and the deterministic visual-test hook.
 */
(function () {
  'use strict';

  function reducedMotion() {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches
      || document.documentElement.classList.contains('c5-visual-test');
  }

  function init() {
    var hosts = document.querySelectorAll('[data-concept="05"]');
    if (!hosts.length) return;
    if (!window.gsap || reducedMotion()) return;

    var gsap = window.gsap;
    if (window.ScrollTrigger) gsap.registerPlugin(window.ScrollTrigger);

    hosts.forEach(function (host) {
      host.querySelectorAll('.c5-module').forEach(function (el) {
        gsap.from(el, {
          opacity: 0,
          y: 24,
          duration: 0.6,
          ease: 'power3.out',
          clearProps: 'transform,opacity',
          scrollTrigger: window.ScrollTrigger
            ? { trigger: el, start: 'top 88%', once: true }
            : undefined,
        });
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  window.BRVTAL_CONCEPT05_MOTION_INIT = init;
})();
