/*
 * BRVTAL — Concept 05 / NIGHTS + ARTISTS progressive enhancement (#592).
 * No network requests. Canonical data rendering stays in app.js/public-roster.js;
 * this layer only hydrates media frames, fail-closed states and honest section CTAs.
 */
(function () {
  'use strict';

  if (!document.querySelector('[data-concept="05"]')) return;

  function safeMediaUrl(value) {
    var raw = String(value || '').trim();
    if (!raw) return '';
    try {
      var url = new URL(raw, window.location.href);
      return /^https?:$/i.test(url.protocol) ? raw : '';
    } catch (_) {
      return '';
    }
  }

  function failClosed(host, image) {
    if (!host) return;
    host.classList.add('is-media-missing');
    if (image) {
      image.hidden = true;
      image.removeAttribute('src');
    }
  }

  function bindImage(host, image) {
    if (!host) return;
    if (!image) {
      failClosed(host, null);
      return;
    }
    if (image.dataset.c5MediaBound === '1') return;
    image.dataset.c5MediaBound = '1';

    image.addEventListener('error', function () {
      failClosed(host, image);
    }, { once:true });

    image.addEventListener('load', function () {
      if (image.naturalWidth > 0) {
        host.classList.remove('is-media-missing');
        image.hidden = false;
      }
    }, { once:true });

    if (image.complete) {
      if (image.naturalWidth > 0) host.classList.remove('is-media-missing');
      else failClosed(host, image);
    }
  }

  function hydrateNight(card) {
    if (!(card instanceof HTMLElement)) return;
    card.classList.add('c5-night-card');
    card.dataset.c5NightCard = '';
    var media = card.querySelector('.event-img');
    if (!media) return;
    media.dataset.c5NightMedia = '';
    bindImage(media, media.querySelector('img'));
  }

  function hydrateArtist(artist) {
    if (!(artist instanceof HTMLElement)) return;
    artist.classList.add('c5-artist-card');

    var number = Array.from(artist.children).find(function (node) {
      return node.tagName === 'SPAN' && !node.classList.contains('c5-artist-media');
    });
    if (number) number.classList.add('c5-artist-index');

    var media = artist.querySelector('.c5-artist-media');
    if (!media) {
      media = document.createElement('span');
      media.className = 'c5-artist-media';
      media.setAttribute('aria-hidden', 'true');
      artist.insertBefore(media, artist.firstChild);
    }

    if (!media.querySelector('img')) {
      var source = safeMediaUrl(artist.dataset.image);
      if (source) {
        var image = document.createElement('img');
        image.alt = '';
        image.loading = 'lazy';
        image.decoding = 'async';
        image.src = source;
        media.appendChild(image);
      }
    }

    bindImage(media, media.querySelector('img'));
  }

  function ensureRoutes() {
    var events = document.querySelector('.events');
    if (events && !events.querySelector('.c5-section-route--nights')) {
      var archive = events.querySelector('#eventArchive');
      if (archive) {
        var nights = document.createElement('a');
        nights.className = 'c5-section-route c5-section-route--nights';
        nights.href = '#eventArchive';
        nights.textContent = 'VIEW ALL NIGHTS ↓';
        events.insertBefore(nights, archive);
      }
    }

    var artists = document.querySelector('.artists');
    if (artists) {
      var firstProfile = artists.querySelector('.artist[href^="/artists/"]');
      var existing = artists.querySelector('.c5-section-route--artists');
      if (firstProfile) {
        if (!existing) {
          existing = document.createElement('a');
          existing.className = 'c5-section-route c5-section-route--artists';
          existing.textContent = 'EXPLORE PROFILES ↗';
          artists.insertBefore(existing, artists.querySelector('.artist-list'));
        }
        existing.href = firstProfile.getAttribute('href');
      } else if (existing) {
        existing.remove();
      }
    }
  }

  function hydrate() {
    document.querySelectorAll('.events-track .event-card').forEach(hydrateNight);
    document.querySelectorAll('.artist-list .artist').forEach(hydrateArtist);
    ensureRoutes();
    document.documentElement.dataset.concept05Strips = 'ready';
    window.dispatchEvent(new CustomEvent('brvtal:concept05-strips-ready'));
  }

  var eventsTrack = document.querySelector('.events-track');
  var artistList = document.querySelector('.artist-list');
  var observer = new MutationObserver(hydrate);
  if (eventsTrack) observer.observe(eventsTrack, { childList:true });
  if (artistList) observer.observe(artistList, { childList:true, subtree:false });

  window.addEventListener('brvtal:roster-rendered', hydrate);
  window.BRVTAL_CONCEPT05_STRIPS_INIT = hydrate;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', hydrate, { once:true });
  } else {
    hydrate();
  }
})();
