(() => {
  'use strict';
  const source = document.querySelector('script[data-ga-id]');
  const id = source?.dataset.gaId || '';
  if (!/^G-[A-Z0-9]{4,20}$/.test(id)) return;

  const key = 'brvtal.analytics.choice.v1';
  const consent = () => {
    try { return localStorage.getItem(key); } catch { return null; }
  };
  const save = choice => {
    try { localStorage.setItem(key, choice); } catch { /* Still honor this visit's choice. */ }
  };
  let loaded = false;
  let panel;

  function loadGoogle() {
    if (loaded) return;
    loaded = true;
    window.dataLayer = window.dataLayer || [];
    window.gtag = function () { window.dataLayer.push(arguments); };
    window.gtag('consent', 'default', {
      analytics_storage: 'denied', ad_storage: 'denied',
      ad_user_data: 'denied', ad_personalization: 'denied'
    });
    window.gtag('js', new Date());
    window.gtag('consent', 'update', { analytics_storage: 'granted' });
    window.gtag('config', id, { send_page_view: true });
    const script = document.createElement('script');
    script.async = true;
    script.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(id);
    document.head.appendChild(script);
  }

  function choose(value) {
    save(value);
    panel?.remove();
    panel = null;
    if (value === 'accepted') loadGoogle();
    else if (loaded) {
      window.gtag?.('consent', 'update', { analytics_storage: 'denied' });
      location.reload();
    }
  }

  function show() {
    if (panel) return;
    panel = document.createElement('section');
    panel.className = 'analytics-choice';
    panel.setAttribute('aria-label', 'Analytics preferences');
    panel.innerHTML = '<p>BRVTAL uses optional Google Analytics to understand visits. It stays off until you agree. You can change this choice here at any time.</p><div><button type="button" data-analytics-choice="rejected">NO THANKS</button><button type="button" data-analytics-choice="accepted">ALLOW ANALYTICS</button></div>';
    panel.addEventListener('click', event => {
      const button = event.target.closest('[data-analytics-choice]');
      if (button) choose(button.dataset.analyticsChoice);
    });
    document.body.appendChild(panel);
  }

  function init() {
    const control = document.createElement('button');
    control.type = 'button';
    control.className = 'analytics-preferences';
    control.textContent = 'ANALYTICS SETTINGS';
    control.addEventListener('click', show);
    document.body.appendChild(control);
    const choice = consent();
    if (choice === 'accepted') loadGoogle();
    else if (choice !== 'rejected') show();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})();
