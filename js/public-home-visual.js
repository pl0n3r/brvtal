(() => {
  'use strict';

  const track = document.querySelector('.events-track');
  if (!track) return;

  function enhanceCard(card) {
    if (!(card instanceof HTMLElement) || card.dataset.visualCtaReady === '1') return;
    card.dataset.visualCtaReady = '1';

    const info = card.querySelector('.event-info');
    if (!info) return;
    if (info.querySelector('.event-ticket,.event-view')) return;

    const title = (info.querySelector('h3')?.textContent || 'event').trim();
    const link = document.createElement('a');
    link.className = 'event-view mono';
    link.href = '#eventArchive';
    link.textContent = 'EXPLORE EVENTS ↓';
    link.setAttribute('aria-label', `Explore BRVTAL events after ${title}`);
    info.appendChild(link);
  }

  function enhanceAll() {
    track.querySelectorAll('.event-card').forEach(enhanceCard);
  }

  enhanceAll();

  const observer = new MutationObserver(enhanceAll);
  observer.observe(track, { childList: true });
})();
