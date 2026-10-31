/* BRVTAL #1351 — essential-only until an explicit, stored opt-in. */
(() => {
  'use strict';

  if (window.BRVTALCookieConsent) return;
  const origin = document.currentScript;
  const cookieName = 'brvtal_cookie_choice';
  const ttl = 180 * 24 * 60 * 60;
  const gtm = origin?.dataset.gtmId || '';
  const version = /^[a-zA-Z0-9._-]{1,40}$/.test(origin?.dataset.version || '')
    ? '?v=' + encodeURIComponent(origin.dataset.version)
    : '';
  const validGtm = /^GTM-[A-Z0-9]{4,20}$/.test(gtm);
  let panel;
  let preferences;
  let loaded = false;

  function choice() {
    const pair = document.cookie.split(';').map(s => s.trim()).find(s => s.startsWith(cookieName + '='));
    const value = pair?.slice(cookieName.length + 1) || '';
    return value === 'accepted' || value === 'rejected' ? value : 'rejected';
  }

  function hasChoice() {
    return document.cookie.split(';').some(s => {
      const trimmed = s.trim();
      return trimmed === cookieName + '=accepted' || trimmed === cookieName + '=rejected';
    });
  }

  function writeChoice(value) {
    const secure = location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = cookieName + '=' + value + '; Max-Age=' + ttl
      + '; Path=/; SameSite=Lax' + secure;
  }

  function loadAnalytics() {
    // A missing or blocked cookie is never a grant of consent.
    if (loaded || !hasChoice() || choice() !== 'accepted' || !validGtm) return;
    loaded = true;
    const analytics = document.createElement('script');
    analytics.src = '/js/public-analytics.js' + version;
    analytics.dataset.gtmId = gtm;
    analytics.async = false;
    const measurement = document.createElement('script');
    measurement.src = '/js/public-measurement.js' + version;
    measurement.async = false;
    document.body.append(analytics, measurement);
  }

  function closePanel() {
    if (panel) panel.hidden = true;
    if (preferences) preferences.hidden = false;
  }

  function setChoice(value) {
    if (value !== 'accepted' && value !== 'rejected') return false;
    const wasAccepted = loaded;
    writeChoice(value);
    const stored = hasChoice() && choice() === value;
    if (!stored) {
      // Privacy fails closed when cookies are disabled or storage fails.
      if (panel) panel.hidden = false;
      return false;
    }
    closePanel();
    if (value === 'accepted') loadAnalytics();
    // Third-party scripts already loaded cannot be retracted in place.
    if (value === 'rejected' && wasAccepted) location.reload();
    return true;
  }

  function open() {
    if (!panel) return;
    panel.hidden = false;
    if (preferences) preferences.hidden = true;
    panel.querySelector('[data-cookie-reject]')?.focus();
  }

  function element(tag, name, text) {
    const node = document.createElement(tag);
    if (name) node.className = name;
    if (text) node.textContent = text;
    return node;
  }

  function render() {
    const style = document.createElement('link');
    style.rel = 'stylesheet';
    style.href = '/css/cookie-consent.css' + version;
    document.head.appendChild(style);

    panel = element('section', 'brvtal-cookie-banner');
    panel.id = 'brvtal-cookie-banner';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'false');
    panel.setAttribute('aria-labelledby', 'brvtal-cookie-title');
    panel.setAttribute('aria-describedby', 'brvtal-cookie-description');

    const head = element('div', 'brvtal-cookie-heading');
    const title = element('h2', '', 'PREFERENCIAS DE COOKIES');
    title.id = 'brvtal-cookie-title';
    const close = element('button', 'brvtal-cookie-close', '×');
    close.type = 'button';
    close.setAttribute('aria-label', 'Cerrar y rechazar cookies opcionales');
    close.addEventListener('click', () => setChoice('rejected'));
    head.append(title, close);
    panel.appendChild(head);

    const copy = element('p', '', 'Solo se usan cookies esenciales hasta que aceptes las opcionales. Texto legal pendiente de aprobación profesional.');
    copy.id = 'brvtal-cookie-description';
    panel.appendChild(copy);

    const actions = element('div', 'brvtal-cookie-actions');
    const reject = element('button', '', 'Rechazar');
    reject.type = 'button';
    reject.dataset.cookieReject = '';
    reject.addEventListener('click', () => setChoice('rejected'));
    const accept = element('button', '', 'Aceptar');
    accept.type = 'button';
    accept.dataset.cookieAccept = '';
    accept.addEventListener('click', () => setChoice('accepted'));
    actions.append(reject, accept);
    panel.appendChild(actions);
    panel.addEventListener('keydown', event => {
      if (event.key === 'Escape') {
        event.preventDefault();
        setChoice('rejected');
      }
    });

    preferences = element('button', 'brvtal-cookie-preferences', 'Preferencias de cookies');
    preferences.type = 'button';
    preferences.hidden = true;
    preferences.addEventListener('click', open);
    const footer = document.querySelector('.c5-footer-legal, footer');
    if (!footer) preferences.classList.add('brvtal-cookie-preferences--floating');
    (footer || document.body).appendChild(preferences);
    document.body.appendChild(panel);

    if (hasChoice()) closePanel();
    if (hasChoice() && choice() === 'accepted') loadAnalytics();
  }

  window.BRVTALCookieConsent = Object.freeze({
    get: choice,
    isAccepted: () => hasChoice() && choice() === 'accepted',
    set: setChoice,
    open,
  });
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', render, { once: true });
  } else {
    render();
  }
})();
