/*
 * BRVTAL — Cromo interior pages: binds the sound switch of the shared top bar.
 * The shader backdrop is js/public-cromo.js; the sound engine is js/public-cromo-sound.js.
 */
(() => {
  const bind = () => {
    const sound = window.BRVTALCromoSound;
    if (!sound || typeof sound.bind !== 'function') return;
    document.querySelectorAll('[data-cromo-sound]').forEach((button) => sound.bind(button));
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bind, { once: true });
  else bind();
})();
