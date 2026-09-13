(() => {
  'use strict';

  const STORAGE_KEY = 'brvtal.discadmin.appearance';
  const MODES = ['dark','light','glass'];

  function readMode() {
    try {
      const saved = localStorage.getItem(STORAGE_KEY);
      return MODES.includes(saved) ? saved : 'dark';
    } catch (_) {
      return 'dark';
    }
  }

  function setMode(mode, persist = true) {
    const next = MODES.includes(mode) ? mode : 'dark';
    document.documentElement.dataset.discadminAppearance = next;
    document.documentElement.style.colorScheme = next === 'light' ? 'light' : 'dark';
    if (persist) {
      try { localStorage.setItem(STORAGE_KEY, next); } catch (_) {}
    }
    document.querySelectorAll('[data-discadmin-appearance]').forEach(button => {
      const active = button.dataset.discadminAppearance === next;
      button.classList.toggle('active', active);
      button.setAttribute('aria-checked', active ? 'true' : 'false');
      button.tabIndex = active ? 0 : -1;
    });
    document.dispatchEvent(new CustomEvent('brvtal:appearancechange', { detail:{ mode:next } }));
    return next;
  }

  function icon(mode) {
    if (mode === 'light') return '☀';
    if (mode === 'glass') return '◐';
    return '●';
  }

  function mount() {
    const sidefoot = document.querySelector('.sidefoot');
    if (!sidefoot || sidefoot.querySelector('.discadmin-appearance')) return;

    const root = document.createElement('section');
    root.className = 'discadmin-appearance';
    root.setAttribute('aria-label', 'Admin appearance');
    root.innerHTML = `
      <div class="discadmin-appearance-label">APPEARANCE</div>
      <div class="discadmin-appearance-options" role="radiogroup" aria-label="Choose admin appearance">
        ${MODES.map(mode => `<button type="button" role="radio" data-discadmin-appearance="${mode}" aria-checked="false" title="${mode.toUpperCase()}"><span aria-hidden="true">${icon(mode)}</span><b>${mode.toUpperCase()}</b></button>`).join('')}
      </div>`;

    root.addEventListener('click', event => {
      const button = event.target.closest('[data-discadmin-appearance]');
      if (button) setMode(button.dataset.discadminAppearance);
    });
    root.addEventListener('keydown', event => {
      const button = event.target.closest('[data-discadmin-appearance]');
      if (!button || !['ArrowLeft','ArrowRight','Home','End'].includes(event.key)) return;
      event.preventDefault();
      const current = MODES.indexOf(button.dataset.discadminAppearance);
      let index = current;
      if (event.key === 'ArrowLeft') index = (current - 1 + MODES.length) % MODES.length;
      if (event.key === 'ArrowRight') index = (current + 1) % MODES.length;
      if (event.key === 'Home') index = 0;
      if (event.key === 'End') index = MODES.length - 1;
      const next = MODES[index];
      setMode(next);
      root.querySelector(`[data-discadmin-appearance="${next}"]`)?.focus();
    });

    sidefoot.prepend(root);
    setMode(readMode(), false);
  }

  setMode(readMode(), false);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount, { once:true });
  else mount();

  const observer = new MutationObserver(() => mount());
  observer.observe(document.documentElement, { childList:true, subtree:true });

  window.BRVTALAdminAppearance = { setMode, getMode:readMode, modes:[...MODES] };
})();