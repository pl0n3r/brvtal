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

  if (typeof nativeReq === 'function') {
    window.req = async function brvtalReliableReq(path = '', options = {}) {
      const shouldBoundWait = path === '/settings' || path === '/media';
      if (!shouldBoundWait) return nativeReq(path, options);
      const bounded = withTimeoutSignal(options);
      try {
        return await nativeReq(path, bounded.options);
      } catch (error) {
        if (error?.name === 'AbortError') throw new Error('REQUEST_TIMEOUT');
        throw error;
      } finally {
        bounded.cancel();
      }
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
        csrf = response.csrf || '';
        state.authed = true;
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
      try {
        await nativeReq('/auth', {method:'DELETE'});
      } catch (error) {
        if (String(error?.message || '') === 'AUTH_REQUIRED') {
          csrf = '';
          return;
        }
        window.alert?.('LOGOUT COULD NOT BE CONFIRMED. TRY AGAIN.');
        return;
      }
      csrf = '';
      state.authed = false;
      render();
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

  async function hydrateSetRelations() {
    if (typeof nativeReq !== 'function') return;
    const [artistsResult, eventsResult] = await Promise.allSettled([
      nativeReq('/artists'),
      nativeReq('/events')
    ]);
    if (artistsResult.status === 'fulfilled') state.artists = artistsResult.value.data || [];
    if (eventsResult.status === 'fulfilled') state.events = eventsResult.value.data || [];
  }

  if (typeof nativeGo === 'function') {
    window.go = async function brvtalReliableGo(section) {
      if (section === 'sets') await hydrateSetRelations();
      return nativeGo(section);
    };
  }

  if (typeof nativeOpenModal === 'function') {
    window.openModal = async function brvtalReliableOpenModal(type, id = null) {
      if (type === 'sets') await hydrateSetRelations();
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
