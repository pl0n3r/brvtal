(() => {
  'use strict';

  const STORAGE_KEY = 'brvtal.discadmin.appearance';
  const MODES = ['dark','light','glass'];
  const CONTROL_SELECTOR = 'button[data-discadmin-appearance]';

  function normalizeMode(mode) {
    return MODES.includes(mode) ? mode : 'dark';
  }

  function readMode() {
    try {
      return normalizeMode(localStorage.getItem(STORAGE_KEY));
    } catch (_) {
      return 'dark';
    }
  }

  function syncControls(mode) {
    document.querySelectorAll(CONTROL_SELECTOR).forEach(button => {
      const active = button.dataset.discadminAppearance === mode;
      button.classList.toggle('active', active);
      button.setAttribute('aria-checked', active ? 'true' : 'false');
      button.tabIndex = active ? 0 : -1;
    });
  }

  function setMode(mode, persist = true) {
    const next = normalizeMode(mode);
    document.documentElement.dataset.discadminAppearance = next;
    document.documentElement.style.colorScheme = next === 'light' ? 'light' : 'dark';
    if (persist) {
      try { localStorage.setItem(STORAGE_KEY, next); } catch (_) {}
    }
    syncControls(next);
    document.dispatchEvent(new CustomEvent('brvtal:appearancechange', { detail:{ mode:next } }));
    return next;
  }

  function icon(mode) {
    if (mode === 'light') return '☀';
    if (mode === 'glass') return '◐';
    return '●';
  }

  function selectorMarkup(location) {
    return `
      <div class="discadmin-appearance-label">${location === "preferences" ? "PREFERENCIAS DE APARIENCIA" : "APPEARANCE"}</div>
      <div class="discadmin-appearance-options" role="radiogroup" aria-label="${location === "preferences" ? "Elegir apariencia del panel" : "Choose admin appearance"}">
        ${MODES.map(mode => `<button type="button" role="radio" data-discadmin-appearance="${mode}" aria-checked="false" title="${mode.toUpperCase()}"><span aria-hidden="true">${icon(mode)}</span><b>${mode.toUpperCase()}</b></button>`).join('')}
      </div>`;
  }

  function bindSelector(root) {
    root.addEventListener('click', event => {
      const button = event.target.closest(CONTROL_SELECTOR);
      if (button) setMode(button.dataset.discadminAppearance);
    });
    root.addEventListener('keydown', event => {
      const button = event.target.closest(CONTROL_SELECTOR);
      if (!button || !['ArrowLeft','ArrowRight','Home','End'].includes(event.key)) return;
      event.preventDefault();
      const current = MODES.indexOf(button.dataset.discadminAppearance);
      let index = Math.max(0, current);
      if (event.key === 'ArrowLeft') index = (index - 1 + MODES.length) % MODES.length;
      if (event.key === 'ArrowRight') index = (index + 1) % MODES.length;
      if (event.key === 'Home') index = 0;
      if (event.key === 'End') index = MODES.length - 1;
      const next = MODES[index];
      setMode(next);
      root.querySelector(`button[data-discadmin-appearance="${next}"]`)?.focus();
    });
  }

  function createSelector(location) {
    const root = document.createElement('section');
    root.className = 'discadmin-appearance';
    if (location === 'login') root.className += ' discadmin-appearance-login';
    else if (location === 'preferences') root.className += ' discadmin-appearance-preferences';
    root.dataset.appearanceSelector = location;
    root.setAttribute('aria-label', location === 'preferences' ? 'Preferencias de apariencia' : 'Admin appearance');
    root.innerHTML = selectorMarkup(location);
    bindSelector(root);
    return root;
  }

  function mountPreferences() {
    // A personal preference belongs in Settings, never in the primary
    // navigation.  Settings is re-rendered by its owner, so remount when
    // that host changes without adding a second navigation or state store.
    const settings = document.querySelector('#app .main [data-settings-v2], #app .main .settings-v2');
    if (!settings || settings.querySelector('[data-appearance-selector="preferences"]')) return false;
    const selector = createSelector('preferences');
    const layout = settings.querySelector('.sv2-layout');
    if (layout) layout.before(selector);
    else settings.appendChild(selector);
    return true;
  }

  function mountLogin() {
    const loginbox = document.querySelector('.loginbox');
    if (!loginbox || loginbox.querySelector('[data-appearance-selector="login"]')) return false;
    const selector = createSelector('login');
    const systemCheck = loginbox.querySelector('.syscheck');
    if (systemCheck) systemCheck.before(selector);
    else loginbox.appendChild(selector);
    return true;
  }

  function mount() {
    const mounted = mountLogin() || mountPreferences();
    if (mounted) syncControls(readMode());
  }

  setMode(readMode(), false);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount, { once:true });
  else mount();

  const observer = new MutationObserver(() => mount());
  observer.observe(document.documentElement, { childList:true, subtree:true });

  window.BRVTALAdminAppearance = { setMode, getMode:readMode, modes:[...MODES] };
})();