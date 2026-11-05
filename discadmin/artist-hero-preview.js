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

  function safeImageUrl(value) {
    const raw = String(value ?? '').trim();
    if (!raw || /[<>"'\x60\\\x00-\x1f]/.test(raw)) return '';
    const local = raw.startsWith('/');
    if (local && (!raw.startsWith('/uploads/') || raw.startsWith('//'))) return '';
    if (!local && !/^https?:\/\//i.test(raw)) return '';

    let url;
    try { url = new URL(raw, window.location.origin); }
    catch (_) { return ''; }
    if (!['http:', 'https:'].includes(url.protocol)) return '';
    if (url.username || url.password) return '';
    if (local && (url.origin !== window.location.origin || !url.pathname.startsWith('/uploads/'))) return '';
    return url.href;
  }

  function syncImage(root, frame) {
    const img = root.querySelector('[data-hero-preview-img]');
    if (!img) return;
    const safeUrl = safeImageUrl(document.getElementById('f_photo')?.value);
    if (img.src !== safeUrl) {
      if (safeUrl) img.src = safeUrl;
      else img.removeAttribute('src');
    }
    const focus = frame.x + '% ' + frame.y + '%';
    img.style.objectPosition = focus;
    img.style.transformOrigin = focus;
    img.style.transform = 'scale(' + frame.zoom + ')';
  }

  function syncControls(root, active) {
    for (const viewport of viewports) {
      const panel = root.querySelector('[data-hero-controls="' + viewport + '"]');
      if (panel) panel.hidden = viewport !== active;
      const button = root.querySelector('[data-hero-switch="' + viewport + '"]');
      if (button) button.setAttribute('aria-pressed',String(viewport === active));
      const values = crop(viewport);
      for (const key of controls) {
        const id = 'f_hero_' + viewport + '_' + key;
        const counter = root.querySelector('[data-hero-output="' + id + '"]');
        if (counter) {
          const value = key === 'zoom' ? Math.round(values[key] * 100) : values[key];
          counter.textContent = value + '%';
        }
      }
    }
  }

  function sync(root) {
    if (!root?.isConnected) return;
    const viewport = root.dataset.viewport === 'mobile' ? 'mobile' : 'desktop';
    syncImage(root,crop(viewport));
    const name = root.querySelector('[data-hero-preview-name]');
    if (name) name.textContent = document.getElementById('f_name')?.value || 'ARTISTA';
    syncControls(root,viewport);
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
