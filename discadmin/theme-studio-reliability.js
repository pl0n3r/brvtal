(() => {
  'use strict';

  let originalLoad = null;
  let originalSave = null;
  let originalActivate = null;
  let originalGo = null;
  let originalTech = null;
  let loading = false;
  let settingsReady = false;
  let mediaReady = false;

  const esc = value => String(value ?? '')
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#039;');

  const themeRoot = () => document.getElementById('theme-root');
  const inThemeStudio = () => typeof window.state === 'object' && window.state?.section === 'theme';
  const hasUnsavedChanges = () => Boolean(document.querySelector('[data-theme-studio-v2][data-dirty]'));

  function feedback(kind, message, key = 'theme-studio-reliability') {
    const api = window.BRVTALFeedback;
    if (api && typeof api[kind] === 'function') api[kind](message, key);
  }

  function renderUnavailable(message) {
    const root = themeRoot();
    if (!root) return;
    root.innerHTML = `<section class="tsv2-source-state" role="status" aria-live="polite">
      <span>THEME STUDIO / DATA SOURCE</span>
      <h2>SETTINGS UNAVAILABLE</h2>
      <p>${esc(message || 'Theme configuration could not be read. Editing is blocked so a generated fallback cannot overwrite the saved theme.')}</p>
      <button type="button" class="btn red" data-theme-source-retry>RETRY</button>
    </section>`;
    root.querySelector('[data-theme-source-retry]')?.addEventListener('click', () => safeLoad());
  }

  function markMediaUnavailable() {
    const root = document.querySelector('[data-theme-studio-v2]');
    if (!root) return;
    root.dataset.mediaUnavailable = '1';
    root.querySelectorAll('[data-pick-media]').forEach(button => {
      button.disabled = true;
      button.setAttribute('aria-disabled', 'true');
      button.title = 'Media Library is temporarily unavailable';
    });
    const status = root.querySelector('.tsv2-statusbar');
    if (status && !root.querySelector('[data-theme-media-warning]')) {
      const warning = document.createElement('span');
      warning.dataset.themeMediaWarning = '1';
      warning.className = 'tsv2-source-warning';
      warning.textContent = 'MEDIA UNAVAILABLE · existing asset paths are preserved; choosing new Media is disabled until retry.';
      status.appendChild(warning);
    }
  }

  async function safeLoad(preferred = '') {
    if (loading) return false;
    if (!originalLoad || typeof window.req !== 'function') return false;
    loading = true;
    const request = window.req;
    const root = themeRoot();
    if (root) root.setAttribute('aria-busy', 'true');

    try {
      const [settingsResult, mediaResult] = await Promise.allSettled([
        request('/settings'),
        request('/media'),
      ]);

      settingsReady = settingsResult.status === 'fulfilled';
      mediaReady = mediaResult.status === 'fulfilled';

      if (!settingsReady) {
        renderUnavailable('Theme Settings could not be loaded. No default theme is editable in this state.');
        feedback('error', 'Theme Studio Settings are unavailable. Editing is blocked until retry.');
        return false;
      }

      const settingsResponse = settingsResult.value;
      const mediaResponse = mediaReady ? mediaResult.value : { data:[] };
      const liveRequest = window.req;

      window.req = async function(path, options = {}) {
        const method = String(options?.method || 'GET').toUpperCase();
        if (method === 'GET' && path === '/settings') return settingsResponse;
        if (method === 'GET' && path === '/media') return mediaResponse;
        return liveRequest.apply(this, arguments);
      };

      try {
        await originalLoad(preferred);
      } finally {
        window.req = liveRequest;
      }

      if (!mediaReady) {
        markMediaUnavailable();
        feedback('error', 'Theme loaded, but Media Library is unavailable. Existing assets are preserved and Media selection is disabled.');
      }
      return true;
    } catch (error) {
      settingsReady = false;
      renderUnavailable(error?.message || 'Theme Studio could not load its authoritative Settings source.');
      feedback('error', 'Theme Studio could not load safely: ' + (error?.message || 'ERROR'));
      return false;
    } finally {
      loading = false;
      themeRoot()?.removeAttribute('aria-busy');
    }
  }

  function canPersist() {
    if (settingsReady) return true;
    feedback('error', 'Theme changes are blocked until Settings load successfully.');
    return false;
  }

  function confirmDiscard() {
    if (!hasUnsavedChanges()) return true;
    return window.confirm('Discard unsaved Theme Studio changes?');
  }

  function installNavigationGuards() {
    if (typeof window.go === 'function' && !window.go.__themeStudioReliability) {
      originalGo = window.go;
      const guardedGo = async function(section, ...args) {
        if (inThemeStudio() && String(section || '') !== 'theme' && !confirmDiscard()) return false;
        return originalGo.apply(this, [section, ...args]);
      };
      guardedGo.__themeStudioReliability = true;
      window.go = guardedGo;
    }

    if (typeof window.tech === 'function' && !window.tech.__themeStudioReliability) {
      originalTech = window.tech;
      const guardedTech = async function(section, ...args) {
        if (inThemeStudio() && !confirmDiscard()) return false;
        return originalTech.apply(this, [section, ...args]);
      };
      guardedTech.__themeStudioReliability = true;
      window.tech = guardedTech;
    }
  }

  function install() {
    const studio = window.BRVTALThemeStudioV2;
    if (!studio || typeof studio.load !== 'function') return false;

    originalLoad = studio.load;
    originalSave = studio.save;
    originalActivate = studio.activate;

    studio.load = safeLoad;
    studio.save = () => canPersist() ? originalSave() : false;
    studio.activate = () => canPersist() ? originalActivate() : false;
    studio.hasUnsavedChanges = hasUnsavedChanges;
    studio.settingsReady = () => settingsReady;
    studio.mediaReady = () => mediaReady;

    window.loadThemeStudio = safeLoad;
    window.saveTheme = () => canPersist() ? originalSave() : false;
    window.activateTheme = () => canPersist() ? originalActivate() : false;

    installNavigationGuards();
    window.addEventListener('beforeunload', event => {
      if (!inThemeStudio() || !hasUnsavedChanges()) return;
      event.preventDefault();
      event.returnValue = '';
    });

    if (inThemeStudio()) queueMicrotask(() => safeLoad());
    return true;
  }

  window.BRVTALThemeStudioReliability = {
    install,
    load:safeLoad,
    hasUnsavedChanges,
    settingsReady:() => settingsReady,
    mediaReady:() => mediaReady,
  };

  if (!install()) {
    window.addEventListener('load', () => install(), { once:true });
  }
})();
