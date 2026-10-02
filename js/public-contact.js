(() => {
  'use strict';

  const endpoint = '/api/contact.php';
  const LOCALE_STORAGE_KEY = 'brvtal.public.locale';
  const LOCALE_EVENT = 'brvtal:localechange';
  const SAFE_LOCALES = new Set(['es', 'en']);
  const CONTACT_CATALOG = Object.freeze({
    es:Object.freeze({
      'contact.skip':'IR AL FORMULARIO',
      'contact.nav':'CONTACTO / 01',
      'contact.home':'INICIO ↙',
      'contact.channel':'CANAL DIRECTO / BRVTAL',
      'contact.title':'CONTACTO',
      'contact.hero_copy':'BOOKINGS, COLABORACIONES, EVENTOS, MEDIA O CONSULTAS GENERALES. ENVÍA UNA SEÑAL CLARA.',
      'contact.kicker':'PEREIRA / COLOMBIA',
      'contact.heading_top':'ABRE',
      'contact.heading_bottom':'CANAL.',
      'contact.copy':'Usa este canal para bookings, colaboraciones, propuestas de eventos, prensa, media y cualquier solicitud que necesite una respuesta directa de BRVTAL.',
      'contact.topic.bookings':'BOOKINGS',
      'contact.topic.collabs':'COLABORACIONES',
      'contact.topic.events':'EVENTOS',
      'contact.topic.media':'MEDIA',
      'contact.topic.general':'GENERAL',
      'contact.external':'SEÑALES EXTERNAS',
      'contact.direct':'CONTACTO / CANAL DIRECTO',
      'contact.instructions':'Completa el formulario. El desafío anti-bot es emitido por BRVTAL y tu mensaje solo se confirma después de una entrega válida.',
      'contact.name':'NOMBRE',
      'contact.email':'EMAIL',
      'contact.subject':'ASUNTO',
      'contact.message':'MENSAJE',
      'contact.captcha':'CONTROL ANTI-BOT',
      'contact.captcha_help':'INGRESA EL RESULTADO PARA CONFIRMAR QUE ERES HUMANO.',
      'contact.submit':'ENVIAR SEÑAL ↗',
      'contact.ready':'LISTO / ESPERANDO SEÑAL',
      'contact.footer_home':'VOLVER AL INICIO',
      'status.loading':'CARGANDO…',
      'status.human_check':'VERIFICACIÓN HUMANA',
      'status.captcha_refreshed':'CAPTCHA ACTUALIZADO / LISTO',
      'status.captcha_unavailable':'SERVICIO ANTI-BOT NO DISPONIBLE. INTENTA MÁS TARDE.',
      'status.transmitting':'TRANSMITIENDO…',
      'status.sent':'MENSAJE ENVIADO / SEÑAL RECIBIDA',
      'status.check_fields':'REVISA LOS CAMPOS MARCADOS.',
      'status.rate_limited':'LÍMITE DE ENVÍOS / INTENTA DE NUEVO EN {seconds}s',
      'status.channel_unavailable':'CANAL DE CONTACTO NO DISPONIBLE. TU MENSAJE NO FUE ENVIADO.',
      'status.network_error':'ERROR DE RED. TU MENSAJE NO FUE ENVIADO.',
      'error.INVALID_NAME':'INGRESA UN NOMBRE ENTRE 2 Y 100 CARACTERES.',
      'error.INVALID_EMAIL':'INGRESA UN EMAIL VÁLIDO.',
      'error.INVALID_SUBJECT':'INGRESA UN ASUNTO ENTRE 2 Y 140 CARACTERES.',
      'error.INVALID_MESSAGE':'EL MENSAJE DEBE TENER ENTRE 10 Y 5000 CARACTERES.',
      'error.INVALID_CAPTCHA':'LA RESPUESTA DEL CAPTCHA NO ES VÁLIDA O EXPIRÓ.',
      'error.CAPTCHA_TOO_FAST':'ESPERA UN MOMENTO Y COMPLETA EL CAPTCHA DE NUEVO.',
      'error.BOT_DETECTED':'ENVÍO RECHAZADO.',
      'error.default':'REVISA ESTE CAMPO.'
    }),
    en:Object.freeze({
      'contact.skip':'SKIP TO FORM',
      'contact.nav':'CONTACT / 01',
      'contact.home':'HOME ↙',
      'contact.channel':'DIRECT CHANNEL / BRVTAL',
      'contact.title':'CONTACT',
      'contact.hero_copy':'BOOKINGS, COLLABORATIONS, EVENTS, MEDIA OR GENERAL INQUIRIES. SEND A CLEAN SIGNAL.',
      'contact.kicker':'PEREIRA / COLOMBIA',
      'contact.heading_top':'OPEN',
      'contact.heading_bottom':'CHANNEL.',
      'contact.copy':'Use this channel for booking requests, collaborations, event proposals, press, media and anything that needs a direct BRVTAL response.',
      'contact.topic.bookings':'BOOKINGS',
      'contact.topic.collabs':'COLLABORATIONS',
      'contact.topic.events':'EVENTS',
      'contact.topic.media':'MEDIA',
      'contact.topic.general':'GENERAL',
      'contact.external':'EXTERNAL SIGNALS',
      'contact.direct':'CONTACT / DIRECT CHANNEL',
      'contact.instructions':'Complete the form below. The anti-bot challenge is issued by BRVTAL and your message is only cleared after confirmed delivery.',
      'contact.name':'NAME',
      'contact.email':'EMAIL',
      'contact.subject':'SUBJECT',
      'contact.message':'MESSAGE',
      'contact.captcha':'ANTI-BOT CHECK',
      'contact.captcha_help':'ENTER THE RESULT TO CONFIRM YOU ARE HUMAN.',
      'contact.submit':'SEND SIGNAL ↗',
      'contact.ready':'READY / WAITING FOR SIGNAL',
      'contact.footer_home':'BACK TO HOME',
      'status.loading':'LOADING…',
      'status.human_check':'HUMAN CHECK',
      'status.captcha_refreshed':'CAPTCHA REFRESHED / READY',
      'status.captcha_unavailable':'ANTI-BOT SERVICE UNAVAILABLE. TRY AGAIN LATER.',
      'status.transmitting':'TRANSMITTING…',
      'status.sent':'MESSAGE SENT / SIGNAL RECEIVED',
      'status.check_fields':'CHECK THE HIGHLIGHTED FIELDS.',
      'status.rate_limited':'RATE LIMITED / TRY AGAIN IN {seconds}s',
      'status.channel_unavailable':'CONTACT CHANNEL UNAVAILABLE. YOUR MESSAGE WAS NOT CLEARED.',
      'status.network_error':'NETWORK ERROR. YOUR MESSAGE WAS NOT CLEARED.',
      'error.INVALID_NAME':'ENTER A NAME BETWEEN 2 AND 100 CHARACTERS.',
      'error.INVALID_EMAIL':'ENTER A VALID EMAIL ADDRESS.',
      'error.INVALID_SUBJECT':'ENTER A SUBJECT BETWEEN 2 AND 140 CHARACTERS.',
      'error.INVALID_MESSAGE':'MESSAGE MUST BE BETWEEN 10 AND 5000 CHARACTERS.',
      'error.INVALID_CAPTCHA':'CAPTCHA ANSWER IS NOT VALID OR EXPIRED.',
      'error.CAPTCHA_TOO_FAST':'WAIT A MOMENT AND COMPLETE THE CAPTCHA AGAIN.',
      'error.BOT_DETECTED':'SUBMISSION REJECTED.',
      'error.default':'CHECK THIS FIELD.'
    })
  });

  const safePublicText = value => {
    if (typeof value !== 'string') return '';
    const text = value.replace(/\r\n?/g, '\n').trim();
    if (!text || text.length > 10000) return '';
    if (/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/.test(text)) return '';
    if (text.includes('<') || text.includes('>')) return '';
    return text;
  };

  const normalizeLocale = value => {
    const locale = String(value || '').trim().toLowerCase();
    return SAFE_LOCALES.has(locale) ? locale : null;
  };

  const storedLocale = () => {
    try {
      return normalizeLocale(window.localStorage.getItem(LOCALE_STORAGE_KEY));
    } catch (_) {
      return null;
    }
  };

  const activeLocale = () => normalizeLocale(document.documentElement.dataset.locale)
    || storedLocale()
    || normalizeLocale(document.documentElement.lang)
    || 'es';

  const textFor = (key, locale = activeLocale()) => {
    const normalized = normalizeLocale(locale) || 'es';
    return safePublicText(CONTACT_CATALOG[normalized]?.[key])
      || safePublicText(CONTACT_CATALOG.es[key]);
  };

  const formatText = (key, locale = activeLocale(), replacements = {}) => {
    let text = textFor(key, locale);
    Object.entries(replacements).forEach(([name, value]) => {
      text = text.replace(`{${name}}`, safePublicText(String(value)));
    });
    return text;
  };

  const readReplacements = element => {
    try {
      const value = JSON.parse(element.dataset.contactI18nReplacements || '{}');
      return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
    } catch (_) {
      return {};
    }
  };

  const applyLocale = (root, locale = activeLocale()) => {
    const normalized = normalizeLocale(locale) || 'es';
    document.documentElement.lang = normalized;
    document.documentElement.dataset.locale = normalized;
    root.querySelectorAll('[data-contact-i18n-key]').forEach(element => {
      const key = String(element.dataset.contactI18nKey || '');
      const value = formatText(key, normalized, readReplacements(element));
      if (!value) return;
      element.textContent = value;
      if (element.hasAttribute('data-text')) element.setAttribute('data-text', value);
    });
    return normalized;
  };


  const cleanUrl = value => {
    const raw = String(value || '').trim();
    if (!raw) return '';
    try {
      const parsed = new URL(raw, location.origin);
      return /^https?:$/.test(parsed.protocol) ? parsed.href : '';
    } catch (_) {
      return '';
    }
  };

  const pick = (object, keys) => {
    for (const key of keys) {
      const value = object?.[key];
      if (value !== undefined && value !== null && String(value).trim() !== '') return value;
    }
    return '';
  };

  const icon = name => ({
    instagram: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7.2 2h9.6A5.2 5.2 0 0 1 22 7.2v9.6a5.2 5.2 0 0 1-5.2 5.2H7.2A5.2 5.2 0 0 1 2 16.8V7.2A5.2 5.2 0 0 1 7.2 2Zm0 2A3.2 3.2 0 0 0 4 7.2v9.6A3.2 3.2 0 0 0 7.2 20h9.6a3.2 3.2 0 0 0 3.2-3.2V7.2A3.2 3.2 0 0 0 16.8 4H7.2Zm10.15 1.5a1.15 1.15 0 1 1 0 2.3 1.15 1.15 0 0 1 0-2.3ZM12 7a5 5 0 1 1 0 10 5 5 0 0 1 0-10Zm0 2a3 3 0 1 0 0 6 3 3 0 0 0 0-6Z"/></svg>',
    soundcloud: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M1 14.25c.28 0 .5.22.5.5v2.5a.5.5 0 0 1-1 0v-2.5c0-.28.22-.5.5-.5Zm2-1.5c.28 0 .5.22.5.5v4.75a.5.5 0 0 1-1 0v-4.75c0-.28.22-.5.5-.5Zm2-1c.28 0 .5.22.5.5v6.25a.5.5 0 0 1-1 0v-6.25c0-.28.22-.5.5-.5Zm2-1.25c.28 0 .5.22.5.5v7.75a.5.5 0 0 1-1 0V11c0-.28.22-.5.5-.5Zm2.1-.95a6.6 6.6 0 0 1 11.98 3.58A4.43 4.43 0 0 1 20.57 22H9.1V9.55Z"/></svg>',
    youtube: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M23.5 6.2a3 3 0 0 0-2.1-2.12C19.54 3.57 12 3.57 12 3.57s-7.54 0-9.4.5A3 3 0 0 0 .5 6.2 31 31 0 0 0 0 12a31 31 0 0 0 .5 5.8 3 3 0 0 0 2.1 2.12c1.86.51 9.4.51 9.4.51s7.54 0 9.4-.5a3 3 0 0 0 2.1-2.13A31 31 0 0 0 24 12a31 31 0 0 0-.5-5.8ZM9.6 15.64V8.36L15.88 12 9.6 15.64Z"/></svg>',
    spotify: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 1.5A10.5 10.5 0 1 0 12 22.5 10.5 10.5 0 0 0 12 1.5Zm4.82 15.14a.82.82 0 0 1-1.13.27c-3.08-1.88-6.96-2.3-11.53-1.26a.82.82 0 0 1-.36-1.6c5-1.13 9.29-.65 12.75 1.47.39.24.51.75.27 1.12Zm1.62-3.62a1.03 1.03 0 0 1-1.42.34c-3.53-2.17-8.91-2.8-13.08-1.53a1.03 1.03 0 1 1-.6-1.97c4.78-1.45 10.71-.75 14.76 1.74.49.3.64.94.34 1.42Zm.14-3.77C14.35 6.74 7.37 6.5 3.34 7.72a1.23 1.23 0 1 1-.72-2.35c4.63-1.4 12.35-1.12 17.22 1.77a1.23 1.23 0 0 1-1.26 2.11Z"/></svg>'
  }[name] || '');

  function socialMarkup() {
    return `<div class="brvtal-social-rail" aria-label="BRVTAL social links">
      ${['instagram','soundcloud','youtube','spotify'].map(name => `<a class="brvtal-social-link" data-brvtal-social="${name}" href="#" target="_blank" rel="noopener noreferrer" aria-label="${name[0].toUpperCase() + name.slice(1)}" hidden>${icon(name)}</a>`).join('')}
    </div>`;
  }

  function normalizePayload(payload) {
    const root = payload?.data && typeof payload.data === 'object' ? payload.data : payload;
    return root && typeof root === 'object' ? root : {};
  }

  async function getPublicData() {
    let request = window.BRVTALPublicDataPromise;
    if (request) {
      const result = await request;
      return normalizePayload(result?.payload ?? result);
    }
    const response = await fetch('/api/public.php', { credentials:'same-origin', headers:{Accept:'application/json'}, cache:'no-store' });
    if (!response.ok) throw new Error(`API ${response.status}`);
    return normalizePayload(await response.json());
  }

  function applySocials(data, root = document) {
    const social = data?.settings?.social && typeof data.settings.social === 'object' ? data.settings.social : {};
    const map = {
      instagram:['instagram','instagram_url','instagramUrl'],
      soundcloud:['soundcloud','soundcloud_url','soundcloudUrl'],
      youtube:['youtube','youtube_url','youtubeUrl'],
      spotify:['spotify','spotify_url','spotifyUrl']
    };
    Object.entries(map).forEach(([name, keys]) => {
      const link = root.querySelector(`[data-brvtal-social="${name}"]`);
      if (!link) return;
      const url = cleanUrl(pick(social, keys));
      if (url) {
        link.href = url;
        link.hidden = false;
      } else {
        link.removeAttribute('href');
        link.hidden = true;
      }
    });
  }

  function setStatus(form, key, state = 'idle', replacements = {}) {
    const status = form.querySelector('[data-contact-status]');
    if (!status) return;
    status.dataset.contactI18nKey = key;
    status.dataset.contactI18nReplacements = JSON.stringify(replacements);
    status.textContent = formatText(key, activeLocale(), replacements);
    status.dataset.state = state;
  }

  async function loadChallenge(form, { announce = false } = {}) {
    const question = form.querySelector('[data-contact-captcha-question]');
    const token = form.querySelector('[data-contact-captcha-token]');
    const answer = form.elements.captcha_answer;
    const submit = form.querySelector('[type="submit"]');
    if (!question || !token || !answer || !submit) return;

    question.textContent = textFor('status.loading');
    token.value = '';
    answer.value = '';
    answer.disabled = true;
    submit.disabled = true;
    try {
      const response = await fetch(endpoint, { credentials:'same-origin', headers:{Accept:'application/json'}, cache:'no-store' });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok || payload.ok === false || !payload.data?.token) throw new Error(payload.error || `HTTP_${response.status}`);
      question.textContent = safePublicText(payload.data.question) || textFor('status.human_check');
      token.value = String(payload.data.token);
      answer.disabled = false;
      submit.disabled = false;
      if (announce) setStatus(form, 'status.captcha_refreshed', 'idle');
    } catch (_) {
      question.textContent = textFor('status.human_check');
      setStatus(form, 'status.captcha_unavailable', 'error');
    }
  }

  function clearErrors(form) {
    form.querySelectorAll('[aria-invalid="true"]').forEach(el => el.removeAttribute('aria-invalid'));
    form.querySelectorAll('[data-error-for]').forEach(el => { el.textContent = ''; });
  }

  function fieldMessage(code) {
    return textFor(`error.${String(code || 'default')}`) || textFor('error.default');
  }

  function showFieldErrors(form, fields = {}) {
    clearErrors(form);
    let first = null;
    Object.entries(fields).forEach(([name, code]) => {
      const key = name === 'form' ? 'captcha' : name;
      const target = form.elements[key === 'captcha' ? 'captcha_answer' : key];
      const message = form.querySelector(`[data-error-for="${key}"]`);
      if (message) message.textContent = fieldMessage(code);
      if (target) {
        target.setAttribute('aria-invalid','true');
        first ||= target;
      }
    });
    first?.focus?.();
  }

  async function submitContact(event) {
    event.preventDefault();
    const form = event.currentTarget;
    clearErrors(form);
    const submit = form.querySelector('[type="submit"]');
    const payload = Object.fromEntries(new FormData(form).entries());
    submit.disabled = true;
    setStatus(form, 'status.transmitting', 'idle');

    try {
      const response = await fetch(endpoint, {
        method:'POST',
        credentials:'same-origin',
        headers:{'Content-Type':'application/json','Accept':'application/json'},
        body:JSON.stringify(payload)
      });
      const data = await response.json().catch(() => ({}));
      if (response.ok && data.ok !== false) {
        form.reset();
        setStatus(form, 'status.sent', 'success');
        await loadChallenge(form);
        return;
      }
      if (response.status === 422) {
        showFieldErrors(form, data.fields || {});
        setStatus(form, 'status.check_fields', 'error');
        if (data.fields?.captcha) await loadChallenge(form);
        return;
      }
      if (response.status === 429) {
        const seconds = Math.max(1, Number(data.retry_after || response.headers.get('Retry-After') || 60));
        setStatus(form, 'status.rate_limited', 'error', {seconds});
        return;
      }
      setStatus(form, 'status.channel_unavailable', 'error');
    } catch (_) {
      setStatus(form, 'status.network_error', 'error');
    } finally {
      if (!form.querySelector('[data-contact-captcha-token]')?.value) await loadChallenge(form);
      else submit.disabled = false;
    }
  }

  function init() {
    const root = document.querySelector('[data-public-contact-page]');
    if (!root || root.dataset.brvtalContactReady === '1') return false;
    root.dataset.brvtalContactReady = '1';

    applyLocale(root);

    const socialMount = root.querySelector('[data-contact-social-mount]');
    if (socialMount && !socialMount.querySelector('.brvtal-social-rail')) {
      socialMount.innerHTML = socialMarkup();
    }

    const form = root.querySelector('#brvtalContactForm');
    if (form && !form.dataset.bound) {
      form.dataset.bound = '1';
      form.addEventListener('submit', submitContact);
      loadChallenge(form).catch(() => setStatus(form, 'status.captcha_unavailable', 'error'));
    }

    const hydrate = () => getPublicData().then(data => applySocials(data, root)).catch(() => {});
    window.setTimeout(hydrate, 100);
    return true;
  }

  const contactRoot = () => document.querySelector('[data-public-contact-page]');

  window.addEventListener(LOCALE_EVENT, event => {
    const root = contactRoot();
    if (!root) return;
    applyLocale(root, event?.detail?.locale);
  });

  window.addEventListener('storage', event => {
    if (event.key !== LOCALE_STORAGE_KEY) return;
    const root = contactRoot();
    if (!root) return;
    applyLocale(root, event.newValue);
  });

  window.BRVTALContact = {
    init,
    applySocials,
    loadChallenge,
    applyLocale,
    currentLocale:activeLocale
  };
  init();
})();
