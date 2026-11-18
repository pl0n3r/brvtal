(() => {
  'use strict';

  const entityUrl = (type, slug) => {
    const allowed = ['events', 'artists', 'sets'];
    const value = String(slug || '').trim();
    return allowed.includes(type) && /^[a-z0-9-]{1,190}$/.test(value)
      ? `/${type}/${encodeURIComponent(value)}`
      : '';
  };

  function linkHeading(heading, href, label) {
    if (!heading || !href || heading.querySelector('[data-public-canonical]')) return;
    const text = heading.textContent || label;
    heading.textContent = '';
    const link = document.createElement('a');
    link.href = href;
    link.dataset.publicCanonical = '1';
    link.setAttribute('aria-label', `${label}: ${text.trim()}`);
    link.style.color = 'inherit';
    link.style.textDecoration = 'none';
    link.textContent = text;
    heading.appendChild(link);
  }

  function apply(data) {
    if (!data || typeof data !== 'object') return;

    const events = Array.isArray(data.events) ? data.events : [];
    const eventById = new Map(events.map(event => [Number(event?.id) || 0, event]));
    [...document.querySelectorAll('.events-track .event-card')].forEach((card, index) => {
      const eventId = Number(card.dataset.publicEventId) || 0;
      const event = (eventId && eventById.get(eventId)) || events[index];
      const href = entityUrl('events', event?.slug);
      linkHeading(card.querySelector('.event-info h3'), href, 'View event');
    });

    const artists = Array.isArray(data.artists) ? data.artists : [];
    [...document.querySelectorAll('.artist-list .artist')].forEach((link, index) => {
      const href = entityUrl('artists', artists[index]?.slug);
      if (!href) return;
      link.href = href;
      link.dataset.publicCanonical = '1';
      const name = artists[index]?.name || link.querySelector('strong')?.textContent || 'artist';
      link.setAttribute('aria-label', `View artist: ${String(name).trim()}`);
    });

    const sets = Array.isArray(data.sets) ? data.sets : [];
    [...document.querySelectorAll('.set-list .set-item')].forEach((item, index) => {
      const href = entityUrl('sets', sets[index]?.slug);
      linkHeading(item.querySelector('.set-main h4'), href, 'View set');
    });
  }

  window.addEventListener('brvtal:public-data', event => {
    queueMicrotask(() => apply(event.detail));
  }, { passive: true });

  window.addEventListener('load', () => {
    window.setTimeout(async () => {
      try {
        const request = window.BRVTALPublicDataPromise;
        if (!request) return;
        const result = await request;
        const payload = result?.payload && typeof result.payload === 'object' ? result.payload : result;
        const data = payload?.data && typeof payload.data === 'object' ? payload.data : payload;
        apply(data);
      } catch (_) {
        // Core runtime owns the public API fallback state.
      }
    }, 260);
  }, { once: true });

  window.BRVTALPublicCanonicalNavigation = { apply };
})();
