/*
 * BRVTAL · capa de impacto sobre Concept 05 (movimiento).
 * Entrada del wordmark, ráfaga de glitch controlada, paralaje de la figura documental y cinta de texto.
 * No cambia el marcado del sitio: la cinta se crea aquí y es decorativa. Todo queda visible en reposo y
 * se apaga con «reducir movimiento», con los ajustes de Theme Studio y con el gancho de pruebas visuales.
 */
(function () {
  'use strict';

  var TICKER = ['MÁS QUE FIESTAS', 'UNA CULTURA EN MOVIMIENTO', 'RAVE TILL GRAVE', 'PEREIRA / COLOMBIA'];

  function motionDisabled() {
    var root = document.documentElement;
    var theme = root.getAttribute('data-theme-motion');
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches
      || root.classList.contains('c5-visual-test')
      || theme === 'reduced'
      || theme === 'minimal';
  }

  function addTicker(hero) {
    if (hero.querySelector('.c5-ticker')) return;
    var ticker = document.createElement('div');
    ticker.className = 'c5-ticker';
    ticker.setAttribute('aria-hidden', 'true');
    var track = document.createElement('div');
    track.className = 'c5-ticker-track';
    // Dos pasadas idénticas: la animación desplaza -50 % y el bucle no tiene salto.
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
    var gsap = window.gsap;
    var title = hero.querySelector('.hero-title');
    if (title) {
      gsap.from(title, { clipPath: 'inset(0 100% 0 0)', duration: 0.9, delay: 0.1, ease: 'power4.out', clearProps: 'clipPath' });
      if (document.documentElement.getAttribute('data-theme-glitch') !== '0') {
        window.setTimeout(function () {
          title.classList.add('is-glitch');
          window.setTimeout(function () { title.classList.remove('is-glitch'); }, 260);
        }, 1100);
      }
    }
    var figure = hero.querySelector('[data-c5-hero-documentary]:not([hidden])');
    if (figure && !window.matchMedia('(pointer: coarse)').matches) {
      window.addEventListener('scroll', function () {
        var shift = Math.min(window.scrollY, 600) * 0.08;
        gsap.to(figure, { y: shift, duration: 0.4, ease: 'power2.out', overwrite: 'auto' });
      }, { passive: true });
    }
  }

  function waitForGsap(callback, attempts) {
    if (window.gsap) { callback(); return; }
    if (attempts <= 0) return;
    window.setTimeout(function () { waitForGsap(callback, attempts - 1); }, 100);
  }

  function init() {
    var hero = document.querySelector('.home-phase-a-hero');
    if (!hero || hero.dataset.brvtalImpact === '1') return;
    hero.dataset.brvtalImpact = '1';
    addTicker(hero);
    if (motionDisabled()) return;
    waitForGsap(function () { animate(hero); }, 30);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}());
