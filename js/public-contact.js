(() => {
  'use strict';

  const endpoint = '/api/contact.php';

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

  function setStatus(form, text, state = 'idle') {
    const status = form.querySelector('[data-contact-status]');
    if (!status) return;
    status.textContent = text;
    status.dataset.state = state;
  }

  async function loadChallenge(form, { announce = false } = {}) {
    const question = form.querySelector('[data-contact-captcha-question]');
    const token = form.querySelector('[data-contact-captcha-token]');
    const answer = form.elements.captcha_answer;
    const submit = form.querySelector('[type="submit"]');
    if (!question || !token || !answer || !submit) return;

    question.textContent = 'LOADING…';
    token.value = '';
    answer.value = '';
    answer.disabled = true;
    submit.disabled = true;
    try {
      const response = await fetch(endpoint, { credentials:'same-origin', headers:{Accept:'application/json'}, cache:'no-store' });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok || payload.ok === false || !payload.data?.token) throw new Error(payload.error || `HTTP_${response.status}`);
      question.textContent = String(payload.data.question || 'HUMAN CHECK');
      token.value = String(payload.data.token);
      answer.disabled = false;
      submit.disabled = false;
      if (announce) setStatus(form, 'CAPTCHA REFRESHED / READY', 'idle');
    } catch (_) {
      question.textContent = 'CHECK UNAVAILABLE';
      setStatus(form, 'ANTI-BOT SERVICE UNAVAILABLE. TRY AGAIN LATER.', 'error');
    }
  }

  function clearErrors(form) {
    form.querySelectorAll('[aria-invalid="true"]').forEach(el => el.removeAttribute('aria-invalid'));
    form.querySelectorAll('[data-error-for]').forEach(el => { el.textContent = ''; });
  }

  function fieldMessage(code) {
    return ({
      INVALID_NAME:'ENTER A NAME BETWEEN 2 AND 100 CHARACTERS.',
      INVALID_EMAIL:'ENTER A VALID EMAIL ADDRESS.',
      INVALID_SUBJECT:'ENTER A SUBJECT BETWEEN 2 AND 140 CHARACTERS.',
      INVALID_MESSAGE:'MESSAGE MUST BE BETWEEN 10 AND 5000 CHARACTERS.',
      INVALID_CAPTCHA:'CAPTCHA ANSWER IS NOT VALID OR EXPIRED.',
      CAPTCHA_TOO_FAST:'WAIT A MOMENT AND COMPLETE THE CAPTCHA AGAIN.',
      BOT_DETECTED:'SUBMISSION REJECTED.'
    })[code] || 'CHECK THIS FIELD.';
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
    setStatus(form, 'TRANSMITTING…', 'idle');

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
        setStatus(form, 'MESSAGE SENT / SIGNAL RECEIVED', 'success');
        await loadChallenge(form);
        return;
      }
      if (response.status === 422) {
        showFieldErrors(form, data.fields || {});
        setStatus(form, 'CHECK THE HIGHLIGHTED FIELDS.', 'error');
        if (data.fields?.captcha) await loadChallenge(form);
        return;
      }
      if (response.status === 429) {
        const seconds = Math.max(1, Number(data.retry_after || response.headers.get('Retry-After') || 60));
        setStatus(form, `RATE LIMITED / TRY AGAIN IN ${seconds}s`, 'error');
        return;
      }
      setStatus(form, 'CONTACT CHANNEL UNAVAILABLE. YOUR MESSAGE WAS NOT CLEARED.', 'error');
    } catch (_) {
      setStatus(form, 'NETWORK ERROR. YOUR MESSAGE WAS NOT CLEARED.', 'error');
    } finally {
      if (!form.querySelector('[data-contact-captcha-token]')?.value) await loadChallenge(form);
      else submit.disabled = false;
    }
  }

  function init() {
    const root = document.querySelector('[data-public-contact-page]');
    if (!root || root.dataset.brvtalContactReady === '1') return false;
    root.dataset.brvtalContactReady = '1';

    const socialMount = root.querySelector('[data-contact-social-mount]');
    if (socialMount && !socialMount.querySelector('.brvtal-social-rail')) {
      socialMount.innerHTML = socialMarkup();
    }

    const form = root.querySelector('#brvtalContactForm');
    if (form && !form.dataset.bound) {
      form.dataset.bound = '1';
      form.addEventListener('submit', submitContact);
      loadChallenge(form);
    }

    const hydrate = () => getPublicData().then(data => applySocials(data, root)).catch(() => {});
    if (document.readyState === 'complete') window.setTimeout(hydrate, 100);
    else window.addEventListener('load', () => window.setTimeout(hydrate, 100), { once:true });
    return true;
  }

  window.BRVTALContact = { init, applySocials, loadChallenge };
  init();
})();
