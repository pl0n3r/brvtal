/*
 * BRVTAL — capa de impacto Concepto 05.
 * Una secuencia por Hero, transforms/opacity y limpieza al desmontar.
 */
(function () {
  'use strict';

  var TICKER = ['MÁS QUE FIESTAS', 'UNA CULTURA EN MOVIMIENTO', 'RAVE TILL GRAVE', 'PEREIRA / COLOMBIA'];
  var media = null;
  var retryTimer = null;
  var released = false;

  function motionDisabled() {
    var root = document.documentElement;
    var theme = root.getAttribute('data-theme-motion');
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches
      || root.classList.contains('c5-visual-test')
      || theme === 'reduced' || theme === 'minimal';
  }

  function addTicker(hero) {
    if (hero.querySelector('.c5-ticker')) return;
    var ticker = document.createElement('div');
    ticker.className = 'c5-ticker';
    ticker.setAttribute('aria-hidden', 'true');
    var track = document.createElement('div');
    track.className = 'c5-ticker-track';
    for (var pass = 0; pass < 2; pass += 1) {
      TICKER.forEach(function (text) {
        var item = document.createElement('span');
        item.textContent = text + ' ·';
        track.appendChild(item);
      });
    }
    ticker.appendChild(track);
    hero.appendChild(ticker);
  }

  function animate(hero) {
    if (released || motionDisabled() || !window.gsap) return;
    var gsap = window.gsap;
    if (window.ScrollTrigger) gsap.registerPlugin(window.ScrollTrigger);
    media?.revert();
    media = gsap.matchMedia();
    media.add('(prefers-reduced-motion: no-preference)', function () {
      if (motionDisabled() || released) return;
      var glitchStart = null;
      var glitchEnd = null;
      var context = gsap.context(function () {
        var title = hero.querySelector('.hero-title');
        var figure = hero.querySelector('[data-c5-hero-documentary]:not([hidden])');
        if (title) {
          // Only transform and opacity; no clipPath/textShadow paint animation.
          var sequence = gsap.timeline();
          sequence.from(title, {
            x: -18, opacity: .94, duration: .9, delay: .1,
            ease: 'power4.out', clearProps: 'transform,opacity'
          });
          if (document.documentElement.getAttribute('data-theme-glitch') !== '0') {
            glitchStart = window.setTimeout(function () {
              title.classList.add('is-glitch');
              glitchEnd = window.setTimeout(function () {
                title.classList.remove('is-glitch');
              }, 260);
            }, 1100);
          }
        }
        if (figure && !window.matchMedia('(pointer: coarse)').matches && window.ScrollTrigger) {
          gsap.to(figure, {
            y: 48, ease: 'none', overwrite: 'auto',
            scrollTrigger: {
              trigger: hero, start: 'top top',
              end: 'bottom top', scrub: true
            }
          });
        }
      }, hero);
      var refresh = function () { window.ScrollTrigger?.refresh?.(); };
      if (document.readyState === 'complete') refresh();
      else window.addEventListener('load', refresh, { once: true });
      return function () {
        window.clearTimeout(glitchStart);
        window.clearTimeout(glitchEnd);
        hero.querySelector('.hero-title')?.classList.remove('is-glitch');
        window.removeEventListener('load', refresh);
        context.revert();
      };
    });
  }

  function waitForGsap(hero, attempts) {
    if (released || motionDisabled()) return;
    if (window.gsap) { animate(hero); return; }
    if (attempts <= 0) return;
    retryTimer = window.setTimeout(function () {
      waitForGsap(hero, attempts - 1);
    }, 100);
  }

  function init() {
    var hero = document.querySelector('.home-phase-a-hero');
    if (!hero || hero.dataset.brvtalImpact === '1') return;
    hero.dataset.brvtalImpact = '1';
    addTicker(hero);
    if (!motionDisabled()) waitForGsap(hero, 30);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }

  window.addEventListener('pagehide', function () {
    released = true;
    window.clearTimeout(retryTimer);
    media?.revert();
    media = null;
  }, { once: true });
}());
