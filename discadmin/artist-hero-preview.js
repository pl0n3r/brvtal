/* Artist profile Hero editor; never changes the Home artist-card crop. */
window.BRVTALArtistHeroPreview = (() => {
  'use strict';
  const defaults = {zoom:1,x:50,y:50};
  const viewports = ['desktop','mobile'];
  const controls = ['zoom','x','y'];

  function crop(viewport) {
    const output = {};
    for (const key of controls) {
      const input = document.getElementById('f_hero_' + viewport + '_' + key);
      const raw = Number(input?.value ?? defaults[key]);
      const min = key === 'zoom' ? 0.8 : 0;
      const max = key === 'zoom' ? 2.2 : 100;
      output[key] = Number.isFinite(raw) ? Math.min(max,Math.max(min,raw)) : defaults[key];
    }
    return output;
  }

  function values() {
    return Object.fromEntries(viewports.map(viewport => [viewport,crop(viewport)]));
  }

  function sync(root) {
    if (!root?.isConnected) return;
    const viewport = root.dataset.viewport === 'mobile' ? 'mobile' : 'desktop';
    const frame = crop(viewport);
    const photo = document.getElementById('f_photo');
    const img = root.querySelector('[data-hero-preview-img]');
    const path = String(photo?.value || '').trim();
    const safePath = path.startsWith('/') && !path.startsWith('//')
      || /^https?:\/\//i.test(path) ? path : '';
    if (img && img.getAttribute('src') !== safePath) {
      if (safePath) img.setAttribute('src',safePath);
      else img.removeAttribute('src');
    }
    if (img) {
      img.style.objectPosition = frame.x + '% ' + frame.y + '%';
      img.style.transformOrigin = frame.x + '% ' + frame.y + '%';
      img.style.transform = 'scale(' + frame.zoom + ')';
    }
    const name = root.querySelector('[data-hero-preview-name]');
    if (name) name.textContent = document.getElementById('f_name')?.value || 'ARTISTA';
    for (const vp of viewports) {
      const panel = root.querySelector('[data-hero-controls="' + vp + '"]');
      if (panel) panel.hidden = vp !== viewport;
      const button = root.querySelector('[data-hero-switch="' + vp + '"]');
      if (button) button.setAttribute('aria-pressed',String(vp === viewport));
      for (const key of controls) {
        const id = 'f_hero_' + vp + '_' + key;
        const counter = root.querySelector('[data-hero-output="' + id + '"]');
        const value = crop(vp)[key];
        if (counter) counter.textContent = (key === 'zoom' ? Math.round(value * 100) : value) + '%';
      }
    }
  }

  function attach(root) {
    if (!root || root.dataset.heroBound === '1') return;
    root.dataset.heroBound = '1';
    root.addEventListener('input',event => {
      if (event.target.closest('.artist-hero-controls')) sync(root);
    });
    root.addEventListener('click',event => {
      const switcher = event.target.closest('[data-hero-switch]');
      if (switcher) {
        root.dataset.viewport = switcher.dataset.heroSwitch;
        sync(root);
        return;
      }
      if (event.target.closest('[data-hero-reset]')) {
        for (const key of controls) {
          const input = document.getElementById(
            'f_hero_' + root.dataset.viewport + '_' + key
          );
          if (input) {
            input.value = String(defaults[key]);
            input.dispatchEvent(new Event('input',{bubbles:true}));
          }
        }
        sync(root);
      }
    });
    const form = root.closest('#mcontent');
    form?.querySelector('#f_photo')?.addEventListener('input',() => sync(root));
    form?.querySelector('#f_name')?.addEventListener('input',() => sync(root));
    sync(root);
  }

  function mount() {
    document.querySelectorAll('[data-artist-hero]').forEach(attach);
  }

  const observer = new MutationObserver(records => {
    if (records.some(record => record.addedNodes.length > 0)) mount();
  });
  observer.observe(document.documentElement,{childList:true,subtree:true});
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded',mount);
  else mount();

  return {values,mount};
})();
