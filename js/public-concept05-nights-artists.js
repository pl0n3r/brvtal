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

  // Public API archive records only: never mirror the legacy active-events track.
  function renderPastNights(payload) {
    var strip = document.querySelector('[data-c5-past-nights]');
    if (!strip) return;
    var root = payload && payload.data && typeof payload.data === 'object' ? payload.data : payload;
    var items = root && root.archive && Array.isArray(root.archive.events) ? root.archive.events : [];
    var past = items.filter(function (event) {
      if (!event || typeof event !== 'object' || event.is_published === false) return false;
      return !['draft','private','hidden','unpublished','cancelled']
        .includes(String(event.status || 'archived').toLowerCase());
    }).slice(0, 4);
    var cards = past.map(function (event) {
      var card = document.createElement('article');
      card.className = 'event-card c5-night-card';
      card.dataset.c5Lifecycle = 'archive';
      var media = document.createElement('div');
      media.className = 'event-img';
      var photo = safeMediaUrl(event.cover_image || event.image || '');
      if (photo) {
        var image = document.createElement('img');
        image.src = photo;
        image.alt = String(event.title || '');
        image.loading = 'lazy';
        media.appendChild(image);
      }
      var info = document.createElement('div');
      info.className = 'event-info';
      var meta = document.createElement('span');
      meta.className = 'mono';
      meta.textContent = [String(event.event_date || '').slice(0, 10), String(event.city || '')].filter(Boolean).join(' / ');
      var name = document.createElement('h3');
      name.textContent = String(event.title || '');
      info.append(meta, name);
      var slug = String(event.slug || '').trim();
      if (slug) {
        var link = document.createElement('a');
        link.className = 'event-record mono';
        link.href = '/events/' + encodeURIComponent(slug);
        link.textContent = 'VIEW RECORD ↗';
        info.appendChild(link);
      }
      card.append(media, info);
      hydrateNight(card);
      return card;
    });
    strip.replaceChildren.apply(strip, cards);
    var empty = document.querySelector('[data-c5-nights-empty]');
    var all = document.querySelector('.c5-section-route--nights');
    if (empty) empty.hidden = cards.length > 0;
    if (all) all.hidden = cards.length === 0;
    document.documentElement.dataset.c5NightsSource = 'public-cms-archive';
  }

  window.addEventListener('brvtal:public-data', function (event) {
    renderPastNights(event.detail);
  });
  window.addEventListener('load', function () {
    window.setTimeout(function () {
      var request = window.BRVTALPublicDataPromise;
      if (request && typeof request.then === 'function') {
        request.then(function (result) {
          renderPastNights(result && result.payload ? result.payload : result);
        }).catch(function () { /* A missing CMS response never creates fake records. */ });
      }
    }, 260);
  }, { once:true });
  window.BRVTAL_CONCEPT05_RENDER_PAST_NIGHTS = renderPastNights;

  function hydrate() {
    document.querySelectorAll('.events-track .event-card').forEach(hydrateNight);
    document.querySelectorAll('.artist-list .artist').forEach(hydrateArtist);
    ensureRoutes();
    // Visibility derives from actual CMS-created nodes, never canned names or counts.
    var nightEmpty = document.querySelector('[data-c5-nights-empty]');
    var artistEmpty = document.querySelector('[data-c5-artists-empty]');
    if (nightEmpty) nightEmpty.hidden = Boolean(document.querySelector('[data-c5-past-nights] .event-card'));
    if (artistEmpty) artistEmpty.hidden = Boolean(document.querySelector('#artists .artist-list .artist'));
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
