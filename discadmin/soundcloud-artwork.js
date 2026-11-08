/* BRVTAL · DISCADMIN SoundCloud artwork and safe metadata suggestions.
 * Only official track/playlist URLs. The server is the sole oEmbed proxy;
 * no third-party HTML is inserted into our DOM.
 */
(() => {
  'use strict';
  const endpoint = '/api/soundcloud-artwork.php?url=';
  const requests = new Map();

  function trackUrl(raw) {
    if (typeof raw !== 'string' || raw.length > 512) return '';
    try {
      const u = new URL(raw.trim());
      const pieces = u.pathname.split('/').filter(Boolean);
      if (u.protocol !== 'https:' || !['soundcloud.com', 'www.soundcloud.com'].includes(u.hostname.toLowerCase())
          || u.port || u.username || u.password) return '';
      if (pieces.length !== 2 && !(pieces.length === 3 && pieces[1] === 'sets')) return '';
      if (pieces.some(part => !/^[a-z0-9_-]{1,120}$/i.test(part))) return '';
      if (['discover','stream','search','you','charts','upload','tracks','settings'].includes(pieces[0].toLowerCase())) return '';
      return 'https://soundcloud.com/' + pieces.join('/');
    } catch (_) { return ''; }
  }

  function artworkUrl(raw) {
    try {
      const u = new URL(raw);
      if (u.protocol !== 'https:' || u.port || u.username || u.password) return '';
      if (!/^i[0-9]{1,2}\.sndcdn\.com$/i.test(u.hostname)) return '';
      if (!/^\/artworks-[a-z0-9_-]+(?:-t[0-9]+x[0-9]+)?\.(?:jpg|jpeg|png|webp)$/i.test(u.pathname)) return '';
      return u.origin + u.pathname;
    } catch (_) { return ''; }
  }

  function lookup(raw) {
    const url = trackUrl(raw);
    if (!url) return Promise.resolve({ok:false, error:'INVALID_TRACK_URL'});
    if (!requests.has(url)) {
      const promise = fetch(endpoint + encodeURIComponent(url), {credentials:'same-origin', cache:'default'})
        .then(response => response.ok ? response.json() : {ok:false, error:'SOUNDCLOUD_UNAVAILABLE'})
        .then(data => data && typeof data === 'object' ? data : {ok:false})
        .catch(() => ({ok:false, error:'NETWORK_UNAVAILABLE'}));
      requests.set(url, promise);
    }
    return requests.get(url);
  }

  function hydrate(root = document) {
    const placeholders = [];
    if (root.nodeType === 1 && root.matches?.('[data-soundcloud-url]')) placeholders.push(root);
    placeholders.push(...root.querySelectorAll?.('[data-soundcloud-url]') || []);
    for (const placeholder of placeholders) {
      if (placeholder.dataset.soundcloudResolved) continue;
      const source = trackUrl(placeholder.dataset.soundcloudUrl || '');
      if (!source) continue;
      placeholder.dataset.soundcloudResolved = '1';
      lookup(source).then(data => {
        if (!placeholder.isConnected || trackUrl(placeholder.dataset.soundcloudUrl || '') !== source) return;
        const url = data.ok && artworkUrl(data.artwork_url);
        if (!url) return;
        const img = document.createElement('img');
        img.alt = placeholder.getAttribute('data-soundcloud-title') || 'Carátula de SoundCloud';
        img.className = placeholder.classList.contains('admin-grid-thumb') ? 'admin-grid-thumb'
          : (placeholder.classList.contains('lg') ? 'thumb lg' : 'thumb');
        img.decoding = 'async';
        img.loading = 'lazy';
        img.addEventListener('load', () => {
          if (!placeholder.isConnected) return;
          placeholder.replaceWith(img);
        }, {once:true});
        img.addEventListener('error', () => { /* Keep original NO IMG fallback. */ }, {once:true});
        img.src = url;
      });
    }
  }

  function bindSetEditor() {
    const form = document.querySelector('#modal #mcontent .form');
    const urlInput = form?.querySelector('#f_external_url');
    if (!urlInput || urlInput.dataset.soundcloudBound) return;
    urlInput.dataset.soundcloudBound = '1';
    const platform = form.querySelector('#f_platform');
    const fields = {
      title: form.querySelector('#f_title'), slug: form.querySelector('#f_slug'),
      embed: form.querySelector('#f_embed_url'), cover: form.querySelector('#f_cover_image')
    };
    const dirty = new Set();
    for (const [name, input] of Object.entries(fields)) {
      input?.addEventListener('input', () => dirty.add(name));
    }
    const artistChoices = form.querySelector('#setArtistChoices');
    let artistsManuallyEdited = false;
    artistChoices?.addEventListener('change', () => { artistsManuallyEdited = true; });
    const status = document.createElement('p');
    status.className = 'admin-soundcloud-meta-status';
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    status.textContent = 'Pega un enlace de SoundCloud para consultar sus datos públicos.';
    urlInput.parentElement?.append(status);
    let sequence = 0;

    function suggest(input, name, value) {
      if (!input || dirty.has(name) || input.value.trim() || !value) return false;
      input.value = value;
      input.dispatchEvent(new Event('input', {bubbles:true}));
      // Suggestions, unlike manual edits, remain replaceable only when empty.
      dirty.delete(name);
      return true;
    }

    function inferSingleArtist(author) {
      if (!author || !artistChoices || artistsManuallyEdited || artistChoices.querySelector('input[data-set-artist]:checked')) return;
      const norm = value => String(value || '').normalize('NFKC').toLowerCase().replace(/[^a-z0-9]/g, '');
      const matches = [...artistChoices.querySelectorAll('.set-artist-choice')]
        .filter(label => norm(label.dataset.artistName) === norm(author));
      if (matches.length !== 1) return;
      const input = matches[0].querySelector('input[data-set-artist]');
      if (!input) return;
      input.checked = true;
      input.dispatchEvent(new Event('change', {bubbles:true}));
    }

    let timer;
    const trigger = () => {
      clearTimeout(timer);
      const seq = ++sequence;
      const value = trackUrl(urlInput.value);
      if (!value || platform?.value !== 'soundcloud') {
        status.textContent = 'Introduce un enlace público de pista o playlist de SoundCloud.';
        return;
      }
      status.textContent = 'Consultando SoundCloud…';
      timer = setTimeout(async () => {
        const data = await lookup(value);
        if (!form.isConnected || seq !== sequence || trackUrl(urlInput.value) !== value
            || platform?.value !== 'soundcloud') return;
        if (!data.ok) {
          status.textContent = 'No se pudo consultar SoundCloud. Puedes completar los datos manualmente.';
          return;
        }
        const title = typeof data.title === 'string' ? data.title.trim() : '';
        const cover = artworkUrl(data.artwork_url);
        const populated = suggest(fields.title, 'title', title);
        const slug = title.normalize('NFKD').replace(/[\u0300-\u036f]/g, '').toLowerCase()
          .replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
        suggest(fields.slug, 'slug', slug);
        suggest(fields.embed, 'embed', 'https://w.soundcloud.com/player/?url=' + encodeURIComponent(value));
        const filledCover = suggest(fields.cover, 'cover', cover);
        if (filledCover) hydrate(form);
        inferSingleArtist(typeof data.author_name === 'string' ? data.author_name : '');
        status.textContent = (populated || filledCover)
          ? 'Metadatos disponibles aplicados. Revisa los datos antes de guardar.'
          : 'SoundCloud consultado. Se conservaron los datos introducidos.';
        if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches
            && typeof window.gsap?.fromTo === 'function') {
          window.gsap.fromTo(status, {opacity:.65, y:3}, {
            opacity:1, y:0, duration:.18, ease:'power2.out',
            overwrite:'auto', clearProps:'opacity,transform'
          });
        }
        // oEmbed does not contain a reliable duration in seconds.
      }, 280);
    };
    urlInput.addEventListener('input', trigger);
    urlInput.addEventListener('change', trigger);
    platform?.addEventListener('change', trigger);
    if (urlInput.value) trigger();
  }

  // The administration shell redraws modules without reloading the document.
  // Only inspect newly inserted UI; all network requests are memoized by track.
  let scheduled = false;
  function scheduleHydration() {
    if (scheduled) return;
    scheduled = true;
    requestAnimationFrame(() => { scheduled = false; hydrate(document); });
  }
  function init() {
    hydrate(document);
    const observer = new MutationObserver(records => {
      if (records.some(record => [...record.addedNodes].some(node =>
        node.nodeType === 1 && (node.matches?.('[data-soundcloud-url]') || node.querySelector?.('[data-soundcloud-url]'))
      ))) scheduleHydration();
    });
    observer.observe(document.body, {childList:true, subtree:true});
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once:true});
  else init();

  window.BRVTALAdminSoundCloud = {lookup, hydrate, bindSetEditor, trackUrl, artworkUrl};
})();
