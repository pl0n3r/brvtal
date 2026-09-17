(() => {
  'use strict';

  const source = document.querySelector('script[data-gtm-id]');
  const id = source?.dataset.gtmId || '';
  if (!/^GTM-[A-Z0-9]{4,20}$/.test(id)) return;
  if (window.__BRVTAL_GTM_LOADED__) return;
  window.__BRVTAL_GTM_LOADED__ = true;

  window.dataLayer = window.dataLayer || [];

  function gtag() {
    window.dataLayer.push(arguments);
  }

  gtag('consent', 'default', {
    analytics_storage: 'granted',
    ad_storage: 'denied',
    ad_user_data: 'denied',
    ad_personalization: 'denied',
  });

  window.dataLayer.push({ 'gtm.start': Date.now(), event: 'gtm.js' });

  const script = document.createElement('script');
  script.async = true;
  script.dataset.brvtalGtm = '1';
  script.src = 'https://www.googletagmanager.com/gtm.js?id=' + encodeURIComponent(id);
  document.head.appendChild(script);

  window.dispatchEvent(new CustomEvent('brvtal:analytics-ready'));
})();
