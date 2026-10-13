/*
 * BRVTAL — Concept 05 / SOUND + MEMORIES progressive enhancement (#594).
 * No network requests. Canonical Set/Memory renderers own data and interactions;
 * this layer only adds authored composition, decorative signal and fail-closed media.
 */
(function () {
  'use strict';

  if (!document.querySelector('[data-concept="05"]')) return;

  function isEnglish() {
    var locale = String(document.documentElement.dataset.locale || document.documentElement.lang || 'es')
      .toLowerCase().split('-')[0];
    return locale === 'en';
  }

  // Opt-in third-party embeds: never create an iframe before a user gesture.
  // Only official HTTPS player endpoints are allowed; no arbitrary CMS HTML.
  function safeEmbedUrl(value) {
    try {
      var url = new URL(String(value || ''));
      if (url.protocol !== 'https:' || url.username || url.password || url.port) return '';
      var host = url.hostname;
      var path = url.pathname;
      var trusted =
        (host === 'w.soundcloud.com' && path.startsWith('/player/')) ||
        (host === 'open.spotify.com' && path.startsWith('/embed/')) ||
        (host === 'www.youtube-nocookie.com' && path.startsWith('/embed/')) ||
        (host === 'player.mixcloud.com' && path.startsWith('/widget/iframe/')) ||
        (host === 'bandcamp.com' && path.startsWith('/EmbeddedPlayer/'));
      return trusted ? url.href : '';
    } catch (_) {
      return '';
    }
  }

  var playerSyncTicket = 0;
  // The CMS may replace a Set without changing its numeric ID. Track source
  // identity so old opt-in audio is stopped before awaiting new CMS data.
  var lastFeaturedNode = null;
  var lastFeaturedRequest = null;

  function clearFeaturedPlayer(section) {
    section.querySelector('.c5-sound-player')?.remove();
    section.dataset.c5Player = 'unavailable';
  }

  async function syncFeaturedPlayer(section, featured) {
    var ticket = ++playerSyncTicket;
    var id = Number(featured?.dataset.setId);
    // Stop the former provider synchronously when a newly featured Set replaces it.
    // A pending CMS Promise must never keep obsolete audio playing.
    var request = window.BRVTALPublicDataPromise;
    var contentChanged = featured !== lastFeaturedNode || request !== lastFeaturedRequest;
    lastFeaturedNode = featured;
    lastFeaturedRequest = request;
    var previous = section.querySelector('.c5-sound-player');
    if (previous && (previous.dataset.c5Set !== String(id) || contentChanged)) {
      clearFeaturedPlayer(section);
    }
    if (!id || !request || typeof request.then !== 'function') {
      clearFeaturedPlayer(section);
      return;
    }

    var response;
    try {
      response = await request;
    } catch (_) {
      if (ticket === playerSyncTicket) clearFeaturedPlayer(section);
      return;
    }
    if (ticket !== playerSyncTicket || !featured.isConnected
      || section.querySelector('.set-library-item') !== featured) return;

    var payload = response?.payload ?? response;
    var data = payload?.data && typeof payload.data === 'object' ? payload.data : payload;
    var records = Array.isArray(data?.sets) ? data.sets : [];
    var record = records.find(function (set) { return Number(set?.id) === id; });
    var url = safeEmbedUrl(record?.embed_url);
    if (!url) {
      clearFeaturedPlayer(section);
      return;
    }

    var player = section.querySelector('.c5-sound-player');
    if (!player || player.dataset.c5Embed !== url || player.dataset.c5Set !== String(id)) {
      player?.remove();
      player = document.createElement('div');
      player.className = 'c5-sound-player';
      player.dataset.c5Embed = url;
      player.dataset.c5Set = String(id);

      var toggle = document.createElement('button');
      toggle.type = 'button';
      toggle.className = 'c5-sound-player-toggle';
      toggle.setAttribute('aria-expanded', 'false');
      toggle.setAttribute('aria-controls', 'c5-featured-sound-frame');

      var frame = document.createElement('div');
      frame.id = 'c5-featured-sound-frame';
      frame.className = 'c5-sound-player-frame';
      frame.hidden = true;

      toggle.addEventListener('click', function () {
        var opened = toggle.getAttribute('aria-expanded') === 'true';
        if (opened) {
          frame.replaceChildren(); // Removes the provider iframe and stops playback.
        } else {
          var iframe = document.createElement('iframe');
          iframe.title = String(record?.title || 'BRVTAL Sound');
          iframe.src = url;
          iframe.loading = 'lazy';
          // YouTube requires the embedding site's origin; other providers stay private.
          iframe.referrerPolicy = new URL(url).hostname === 'www.youtube-nocookie.com'
            ? 'strict-origin-when-cross-origin'
            : 'no-referrer';
          iframe.allow = 'autoplay; encrypted-media; picture-in-picture';
          iframe.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-presentation');
          frame.replaceChildren(iframe);
        }
        frame.hidden = opened;
        toggle.setAttribute('aria-expanded', String(!opened));
        toggle.textContent = isEnglish()
          ? (opened ? 'PLAY FEATURED SET ↗' : 'CLOSE PLAYER ×')
          : (opened ? 'REPRODUCIR SET DESTACADO ↗' : 'CERRAR REPRODUCTOR ×');
      });

      player.append(toggle, frame);
      section.querySelector('.set-list')?.after(player);
    }

    var button = player.querySelector('.c5-sound-player-toggle');
    var opened = button?.getAttribute('aria-expanded') === 'true';
    if (button) button.textContent = isEnglish()
      ? (opened ? 'CLOSE PLAYER ×' : 'PLAY FEATURED SET ↗')
      : (opened ? 'CERRAR REPRODUCTOR ×' : 'REPRODUCIR SET DESTACADO ↗');
    section.dataset.c5Player = 'available';
  }

  function failMedia(host, media) {
    if (!host) return;
    host.classList.add('is-media-missing');
    if (media) media.hidden = true;
    if (host.matches?.('[data-set-cover]')) {
      host.classList.add('set-library-cover--empty');
      host.setAttribute('aria-hidden', 'true');
    }
    var item = host.closest?.('[data-public-media-item]');
    var trigger = item?.querySelector('[data-public-media-open]');
    if (trigger) {
      trigger.disabled = true;
      trigger.setAttribute('aria-disabled', 'true');
    }
  }

  function restoreCoverAfterFallback(host, media) {
    // The canonical Sets renderer eagerly hides the cover on the first image
    // error. Unhide during fallback so native lazy loading can fetch original.
    if (!host.matches?.('[data-set-cover]')) return;
    host.classList.remove('set-library-cover--empty');
    host.removeAttribute('aria-hidden');
    media.hidden = false;
  }

  function bindMedia(host, media) {
    if (!host || !media) return;
    var soundCover = media instanceof HTMLImageElement && host.matches?.('[data-set-cover]');
    if (soundCover) media.dataset.brvtalImageContext = 'card';
    if (media.dataset.c5ArchiveMediaBound === '1') return;
    media.dataset.c5ArchiveMediaBound = '1';

    // The canonical image-delivery handler may retry a failing WebP derivative
    // against the original upload. The first error is not final in that case.
    var fallbackPending = false;
    var fallbackRecovered = false;
    media.addEventListener('error', function () {
      if (media instanceof HTMLImageElement && !fallbackPending && !fallbackRecovered
        && media.dataset.brvtalWebpFallbackBound === '1'
        && (media.dataset.brvtalWebpSrc || media.dataset.brvtalFallbackApplied === '1')) {
        fallbackPending = true;
        restoreCoverAfterFallback(host, media);
        return;
      }
      failMedia(host, media);
    });
    media.addEventListener('load', function () {
      if (media instanceof HTMLImageElement && media.naturalWidth > 0) {
        // An asynchronously delivered valid variant may load after the first
        // image failed. Only a real successful load can reverse failMedia().
        fallbackPending = false;
        fallbackRecovered = true;
        host.classList.remove('is-media-missing');
        media.hidden = false;
        restoreCoverAfterFallback(host, media);
        var item = host.closest?.('[data-public-media-item]');
        var trigger = item?.querySelector('[data-public-media-open]');
        if (trigger) {
          trigger.disabled = false;
          trigger.removeAttribute('aria-disabled');
        }
      }
    });

    if (media instanceof HTMLImageElement && media.complete && media.naturalWidth === 0) {
      failMedia(host, media);
    }
    // Covers may be rendered after the image-delivery manifest has resolved.
    // Bind native load/error listeners before selecting the responsive card.
    if (soundCover) window.BRVTALPublicMedia?.applyResponsiveImage?.(media, 'card');
  }

  function hydrateSound() {
    var section = document.querySelector('.sets');
    if (!section) return;

    var items = Array.from(section.querySelectorAll('.set-library-item'));
    items.forEach(function (item, index) {
      item.classList.add('c5-sound-record');
      item.classList.toggle('c5-sound-feature', index === 0);

      var cover = item.querySelector('[data-set-cover]');
      if (cover) bindMedia(cover, cover.querySelector('img'));
    });

    var first = items[0];
    if (first) {
      var main = first.querySelector('.set-main');
      if (main && !main.querySelector('.c5-sound-signal')) {
        var signal = document.createElement('span');
        signal.className = 'c5-sound-signal';
        signal.setAttribute('aria-hidden', 'true');
        main.appendChild(signal);
      }
    }

    var route = section.querySelector('.c5-section-route--sound');
    var record = section.querySelector('.set-record-link[href^="/sets/"]');
    if (record) {
      if (!route) {
        route = document.createElement('a');
        route.className = 'c5-section-route c5-section-route--sound';
        section.appendChild(route);
      }
      route.href = record.getAttribute('href');
      route.textContent = isEnglish() ? 'EXPLORE SOUND ↗' : 'EXPLORAR SONIDO ↗';
    } else if (route) {
      route.remove();
    }

    void syncFeaturedPlayer(section, first);

    document.documentElement.dataset.concept05Sound = 'ready';
  }

  function hydrateMemory(item) {
    if (!(item instanceof HTMLElement)) return;
    item.classList.add('c5-memory-cell');
    var open = item.querySelector('.public-media-open');
    var media = open?.querySelector('img,video');
    if (media) bindMedia(item, media);
  }

  function hydrateMemories() {
    var section = document.querySelector('.media');
    if (!section) return;

    var curated = section.querySelectorAll('[data-public-media-item]');
    curated.forEach(hydrateMemory);
    // Canonical search/type filters hide nonmatching CMS cards without removing them.
    var hasVisibleMemories = Array.from(curated).some(function (item) { return !item.hidden; });

    var annotation = section.querySelector('.c5-memory-annotation');
    // Only display documentary decoration when curated results remain visible.
    if (!hasVisibleMemories && annotation) {
      annotation.remove();
      annotation = null;
    }
    if (hasVisibleMemories && !annotation) {
      annotation = document.createElement('span');
      annotation.className = 'c5-memory-annotation';
      annotation.setAttribute('aria-hidden', 'true');
      annotation.textContent = 'PEOPLE / LIGHTS / MEMORIES / FOREVER';
      var grid = section.querySelector('.media-grid');
      if (grid) grid.after(annotation);
    }

    var route = section.querySelector('.c5-section-route--memories');
    var archive = document.getElementById('eventArchive');
    if (archive && !route) {
      route = document.createElement('a');
      route.className = 'c5-section-route c5-section-route--memories';
      route.href = '#eventArchive';
      section.appendChild(route);
    } else if (!archive && route) {
      // A removed archive anchor must not leave a dead action in the public UI.
      route.remove();
      route = null;
    }
    if (route) route.textContent = isEnglish() ? 'EXPLORE ARCHIVE ↓' : 'EXPLORAR ARCHIVO ↓';

    document.documentElement.dataset.concept05Memories = 'ready';
  }

  function notifyReady() {
    window.dispatchEvent(new CustomEvent('brvtal:concept05-sound-memories-ready'));
  }

  function hydrate() {
    hydrateSound();
    hydrateMemories();
    notifyReady();
  }

  // Changes to Memories must not read/synchronize the consented Sound player.
  function hydrateSoundOnMutation() {
    hydrateSound();
    notifyReady();
  }
  function hydrateMemoriesOnMutation() {
    hydrateMemories();
    notifyReady();
  }

  var setList = document.querySelector('.set-list');
  var mediaGrid = document.querySelector('.media-grid');
  var setObserver = new MutationObserver(hydrateSoundOnMutation);
  var memoryObserver = new MutationObserver(hydrateMemoriesOnMutation);
  if (setList) setObserver.observe(setList, { childList:true });
  if (mediaGrid) memoryObserver.observe(mediaGrid, { childList:true, subtree:true, attributes:true, attributeFilter:['hidden'] });

  window.addEventListener('brvtal:sets-library-rendered', hydrateSound);
  window.addEventListener('brvtal:memories-rendered', hydrateMemories);
  window.addEventListener('brvtal:localechange', hydrate);
  window.BRVTAL_CONCEPT05_SOUND_MEMORIES_INIT = hydrate;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', hydrate, { once:true });
  } else {
    hydrate();
  }
})();
