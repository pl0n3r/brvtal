/*
 * BRVTAL — Concept 05 motion foundation (Issue #584, parent #583).
 * Reusable GSAP reveal/registration primitives for elements opted into
 * `[data-concept="05"]`. Foundation only: no Hero/section rewrite here.
 * Respects prefers-reduced-motion, coarse pointers and the deterministic visual-test hook.
 */
(function () {
  'use strict';

  function motionDisabled() {
    var themeMotion = document.documentElement.getAttribute('data-theme-motion');
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches
      || window.matchMedia('(pointer: coarse)').matches
      || document.documentElement.classList.contains('c5-visual-test')
      || themeMotion === 'reduced'
      || themeMotion === 'minimal';
  }

  function init() {
    var hosts = document.querySelectorAll('[data-concept="05"]');
    if (!hosts.length) return;
    if (!window.gsap || motionDisabled()) return;

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

      // Real Home sections dressed by brvtal_public_home_concept05_dressing():
      // cut-in reveal on the numbered editorial label + registration-offset
      // glitch on the section title, once per section on scroll-in.
      host.querySelectorAll('.c5-numbered').forEach(function (section) {
        gsap.from(section, {
          clipPath: 'inset(0 0 100% 0)',
          duration: 0.5,
          ease: 'power4.inOut',
          scrollTrigger: window.ScrollTrigger
            ? { trigger: section, start: 'top 85%', once: true }
            : undefined,
        });

        var title = section.querySelector('h2, h3');
        if (title && window.ScrollTrigger) {
          var tl = gsap.timeline({
            scrollTrigger: { trigger: section, start: 'top 80%', once: true },
          });
          tl.fromTo(
            title,
            { textShadow: '4px 0 var(--c5-signal-red), -3px 0 rgba(255,255,255,.3)' },
            { textShadow: '0 0 rgba(0,0,0,0)', duration: 0.18, ease: 'steps(1)' }
          );
        }
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
