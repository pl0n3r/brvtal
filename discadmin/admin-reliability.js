(() => {
  'use strict';

  const RELATED_LOAD_TIMEOUT_MS = 12000;
  const nativeReq = window.req;
  const nativeGo = window.go;
  const nativeEventForm = window.eventForm;
  const nativeLogin = window.login;
  const nativeOpenModal = window.openModal;
  const nativeOpenSettingByKey = window.openSettingByKey;

  function withTimeoutSignal(options = {}, timeoutMs = RELATED_LOAD_TIMEOUT_MS) {
    if (options.signal || typeof AbortController !== 'function') return {options, cancel: () => {}};
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort('BRVTAL_REQUEST_TIMEOUT'), timeoutMs);
    return {
      options: {...options, signal: controller.signal},
      cancel: () => clearTimeout(timer)
    };
  }

  async function boundedNativeReq(path, options = {}, timeoutMs = RELATED_LOAD_TIMEOUT_MS) {
    if (typeof nativeReq !== 'function') throw new Error('REQUEST_UNAVAILABLE');
    const bounded = withTimeoutSignal(options, timeoutMs);
    try {
      return await nativeReq(path, bounded.options);
    } catch (error) {
      if (error?.name === 'AbortError') throw new Error('REQUEST_TIMEOUT');
      throw error;
    } finally {
      bounded.cancel();
    }
  }

  if (typeof nativeReq === 'function') {
    window.req = async function brvtalReliableReq(path = '', options = {}) {
      const shouldBoundWait = path === '/settings' || path === '/media';
      if (!shouldBoundWait) return nativeReq(path, options);
      return boundedNativeReq(path, options);
    };
  }

  function loginErrorMessage(error) {
    const code = String(error?.message || '');
    if (code === 'RATE_LIMITED') return 'Demasiados intentos. Acceso temporalmente limitado; inténtalo de nuevo más tarde.';
    if (code === 'INVALID_CREDENTIALS') return 'Credenciales inválidas.';
    if (code === 'EMAIL_AND_PASSWORD_REQUIRED') return 'Ingresa un correo y una contraseña válidos.';
    if (code === 'AUTH_REQUIRED') return 'Sesión no válida.';
    return 'No se pudo iniciar sesión. Inténtalo de nuevo.';
  }

  if (typeof nativeLogin === 'function' && typeof nativeReq === 'function') {
    window.login = async function brvtalReliableLogin(event) {
      event.preventDefault();
      const form = event.target;
      const data = new FormData(form);
      const button = form.querySelector('button[type=submit],button');
      if (button) {
        button.disabled = true;
        button.textContent = 'AUTHENTICATING...';
      }
      try {
        const response = await window.req('/auth', {
          method: 'POST',
          body: JSON.stringify({email:data.get('email'),password:data.get('password')})
        });
        window.csrf = response.csrf || '';
        window.state.authed = true;
        await window.go('dashboard');
      } catch (error) {
        const output = document.querySelector('.error');
        if (output) output.textContent = loginErrorMessage(error);
        if (button) {
          button.disabled = false;
          button.textContent = 'ENTER';
        }
      }
    };
  }

  if (typeof nativeReq === 'function') {
    window.logout = async function brvtalReliableLogout() {
      const unsaved = window.BRVTALUnsavedChanges;
      const hero = window.BRVTALHeroSliderGuard;
      const hasUnsavedGuard = typeof unsaved?.requestNavigation === 'function';
      const unsavedToken = hasUnsavedGuard ? unsaved.requestNavigation('logout') : null;
      if (hasUnsavedGuard && unsavedToken === 0) return false;
      if (typeof hero?.requestNavigation === 'function'
        && hero.requestNavigation('logout') === false) {
        unsaved?.cancelNavigation?.(unsavedToken);
        return false;
      }

      const finalizeLogout = () => {
        window.csrf = '';
        window.state.authed = false;
        const committed = typeof unsaved?.commitNavigation === 'function'
          ? unsaved.commitNavigation(unsavedToken)
          : true;
        if (committed !== true) {
          window.BRVTALAdminAuthBoundary?.preserveUnsavedAuthState?.();
          return false;
        }
        try {
          window.BRVTALDrafts?.clearAll?.();
        } catch (_) {
          document.documentElement.dataset.brvtalDraftStorage = 'unavailable';
        }
        hero?.commitNavigation?.('logout');
        window.BRVTALAdminAuthBoundary?.clearDeferredAuthState?.();
        render();
        return true;
      };

      try {
        await nativeReq('/auth', {method:'DELETE'});
      } catch (error) {
        if (String(error?.message || '') === 'AUTH_REQUIRED') return finalizeLogout();
        unsaved?.cancelNavigation?.(unsavedToken);
        window.alert?.('LOGOUT COULD NOT BE CONFIRMED. TRY AGAIN.');
        return false;
      }
      return finalizeLogout();
    };
  }

  function normalizeDatetimeLocal(value) {
    const raw = String(value || '').trim();
    if (!raw) return '';
    return raw.replace(' ', 'T').slice(0, 16);
  }

  if (typeof nativeEventForm === 'function') {
    window.eventForm = function brvtalReliableEventForm(record) {
      if (!record) return nativeEventForm(record);
      return nativeEventForm({...record, event_date: normalizeDatetimeLocal(record.event_date)});
    };
  }

  const SET_RELATION_ATTEMPTS = 2;
  const SET_RELATION_RETRY_DELAY_MS = 120;
  let setRelationsPromise = null;

  function retryableSetRelationError(error) {
    const code = String(error?.message || '');
    return !['AUTH_REQUIRED','INVALID_CREDENTIALS','RATE_LIMITED'].includes(code);
  }

  async function loadSetRelationCollection(path) {
    let lastError = null;
    for (let attempt = 1; attempt <= SET_RELATION_ATTEMPTS; attempt += 1) {
      try {
        const response = await boundedNativeReq(path);
        if (!Array.isArray(response?.data)) throw new Error('INVALID_RELATION_COLLECTION');
        return response.data;
      } catch (error) {
        lastError = error;
        if (attempt >= SET_RELATION_ATTEMPTS || !retryableSetRelationError(error)) break;
        await new Promise(resolve => setTimeout(resolve, SET_RELATION_RETRY_DELAY_MS));
      }
    }
    throw lastError || new Error('RELATION_LOAD_FAILED');
  }

  async function hydrateSetRelations() {
    if (typeof nativeReq !== 'function') return;
    if (setRelationsPromise) return setRelationsPromise;

    setRelationsPromise = (async () => {
      const [artists, events] = await Promise.all([
        loadSetRelationCollection('/artists'),
        loadSetRelationCollection('/events')
      ]);
      window.state.artists = artists;
      window.state.events = events;
      return {artists, events};
    })();

    try {
      return await setRelationsPromise;
    } finally {
      setRelationsPromise = null;
    }
  }

  if (typeof nativeGo === 'function') {
    window.go = async function brvtalReliableGo(section) {
      const result = await nativeGo(section);
      if (section === 'sets') void hydrateSetRelations().catch(() => {});
      return result;
    };
  }

  if (typeof nativeOpenModal === 'function') {
    window.openModal = async function brvtalReliableOpenModal(type, id = null) {
      if (type === 'sets') {
        try {
          await hydrateSetRelations();
        } catch (error) {
          window.BRVTALFeedback?.error?.(
            'Artist and Event options could not be loaded. Retry New Set.',
            'set-relations'
          );
          return false;
        }
      }
      return nativeOpenModal.call(this, type, id);
    };
  }

  if (typeof nativeOpenSettingByKey === 'function') {
    window.openSettingByKey = function brvtalReliableOpenSettingByKey(key) {
      const result = nativeOpenSettingByKey.call(this, key);
      const input = document.getElementById('f_setting_key');
      if (input) {
        input.readOnly = true;
        input.setAttribute('aria-readonly', 'true');
        input.title = 'Setting keys opened by name cannot be renamed here. Use Advanced Setting to create a new key.';
      }
      return result;
    };
  }

  function attachHeroRetry(root = document) {
    const error = root.querySelector?.('.hero-slider-error');
    if (!error || error.querySelector('[data-hero-retry]')) return;
    const retry = document.createElement('button');
    retry.type = 'button';
    retry.className = 'btn ghost';
    retry.dataset.heroRetry = '1';
    retry.textContent = 'RETRY';
    retry.style.marginLeft = '12px';
    retry.addEventListener('click', () => window.go?.('hero-slider'));
    error.appendChild(retry);
  }

  if (typeof MutationObserver === 'function') {
    const observer = new MutationObserver(() => attachHeroRetry());
    observer.observe(document.documentElement, {subtree: true, childList: true});
  }
})();
