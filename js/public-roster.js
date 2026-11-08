(() => {
  'use strict';

  const list = document.querySelector('.artist-list');
  if (!list) return;

  const escapeHtml = value => String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');

  const imageUrl = value => {
    const raw = String(value ?? '').trim();
    if (!raw) return '';
    try {
      // Playwright's isolated DOM has an opaque "null" origin; local paths must
      // remain valid there without ever allowing network-external image URLs.
      const isolated = !location.origin || location.origin === 'null';
      if (isolated && !raw.startsWith('/')) return '';
      const base = isolated ? 'https://brvtal.invalid' : location.origin;
      const resolved = new URL(
        raw.startsWith('/') || /^https?:\/\//i.test(raw) ? raw : '/' + raw.replace(/^\.?\//, ''),
        base
      );
      if (!['https:', 'http:'].includes(resolved.protocol) || resolved.origin !== base) return '';
      // Prevent encoded or plain traversals of a relative CMS upload path.
      if (raw.startsWith('/uploads/') && !resolved.pathname.startsWith('/uploads/')) return '';
      // Keep original local path for editorial fidelity; HTML attributes are escaped later.
      return raw.startsWith('/') ? raw : resolved.href;
    } catch (_) { return ''; }
  };

  const isMember = artist =>
    artist?.is_collective_member === true || Number(artist?.is_collective_member ?? 0) === 1;

  const rosterStatus = artist => isMember(artist) ? 'member' : 'network';

  const numberValue = (value, fallback = Number.MAX_SAFE_INTEGER) => {
    const parsed = Number(value);
    return Number.isFinite(parsed) && parsed >= 0 ? parsed : fallback;
  };

  const compareArtists = (a, b) => {
    const groupDelta = (isMember(a) ? 0 : 1) - (isMember(b) ? 0 : 1);
    if (groupDelta) return groupDelta;
    const sortDelta = numberValue(a.sort_order) - numberValue(b.sort_order);
    if (sortDelta) return sortDelta;
    return String(a.name ?? '').localeCompare(String(b.name ?? ''), 'en', { sensitivity: 'base' });
  };

  const rowMeta = artist => isMember(artist) ? 'MIEMBRO DEL COLECTIVO BRVTAL' : 'ARTISTA INVITADO';
  const legacyRole = artist => isMember(artist) ? 'BRVTAL / COLLECTIVE' : 'ARTIST / COLLABORATOR';

  const groups = [
    ['member', 'BRVTAL / COLLECTIVE'],
    ['network', 'ARTISTS / COLLABORATORS'],
  ];

  const bindPreview = () => {
    const preview = document.querySelector('.artist-preview img');
    if (preview && preview.dataset.igFallbackBound !== '1') {
      preview.dataset.igFallbackBound = '1';
      preview.addEventListener('error', () => {
        const fallback = preview.dataset.cmsFallback || '';
        if (fallback && preview.src !== fallback) preview.src = fallback;
        else preview.style.visibility = 'hidden'; // Keep source URL for diagnostics; hide broken-image chrome.
      });
    }
    preview?.addEventListener('load', () => { preview.style.visibility = 'visible'; });
    document.querySelectorAll('.artist[data-image]').forEach(artist => {
      artist.addEventListener('mouseenter', () => {
        if (preview && artist.dataset.image) {
          preview.dataset.cmsFallback = artist.dataset.imageCms || '';
          preview.style.visibility = 'visible';
          preview.src = artist.dataset.image;
        }
      });
      artist.addEventListener('focus', () => {
        if (preview && artist.dataset.image) {
          preview.dataset.cmsFallback = artist.dataset.imageCms || '';
          preview.style.visibility = 'visible';
          preview.src = artist.dataset.image;
        }
      });
    });
  };

  const render = artists => {
    if (!Array.isArray(artists) || artists.length === 0) return false;
    const ordered = [...artists].sort(compareArtists);
    let index = 0;
    const markup = groups.map(([status, label]) => {
      const members = ordered.filter(artist => rosterStatus(artist) === status);
      if (!members.length) return '';
      const rows = members.map(artist => {
        index += 1;
        const name = String(artist.name ?? artist.title ?? 'UNKNOWN');
        const slug = String(artist.slug ?? '').trim();
        const photo = imageUrl(artist.photo || artist.instagram_photo_url || artist.image || artist.cover_image || '');
        const cms = imageUrl(artist.photo_cms || artist.photo || '');
        const href = slug ? `/artists/${encodeURIComponent(slug)}` : '/#artists';
        return `<a class="artist artist--${status}" href="${escapeHtml(href)}" data-roster-status="${status}" data-roster-legacy-role="${escapeHtml(legacyRole(artist))}"${photo ? ` data-image="${escapeHtml(photo)}" data-image-cms="${escapeHtml(cms)}"` : ''}>
          <span>${String(index).padStart(2, '0')}</span><strong>${escapeHtml(name)}</strong><i>${escapeHtml(rowMeta(artist))}</i>
        </a>`;
      }).join('');
      const visibleLabel = status === 'member' ? 'MIEMBROS DEL COLECTIVO BRVTAL' : 'ARTISTAS INVITADOS';
      return `<section class="roster-group roster-group--${status}" data-roster-group="${status}">
        <div class="roster-group-head mono"><span>${escapeHtml(visibleLabel)}</span><b>${String(members.length).padStart(2, '0')}</b></div>${rows}
      </section>`;
    }).join('');

    list.innerHTML = markup;
    const firstArtist = ordered.find(artist =>
      imageUrl(artist.photo || artist.instagram_photo_url || artist.image || artist.cover_image || ''));
    const preview = document.querySelector('.artist-preview img');
    if (preview && firstArtist) {
      preview.dataset.cmsFallback = imageUrl(firstArtist.photo_cms || firstArtist.photo || '');
      preview.style.visibility = 'visible';
      preview.src = imageUrl(firstArtist.photo || firstArtist.instagram_photo_url || firstArtist.image || firstArtist.cover_image || '');
    }

    const sectionCount = document.querySelector('.artists .section-head > span:first-child');
    if (sectionCount) sectionCount.textContent = `BRVTAL ROSTER / ${String(artists.length).padStart(2, '0')}`;
    bindPreview();
    document.documentElement.dataset.publicRoster = 'connected';
    window.dispatchEvent(new CustomEvent('brvtal:roster-rendered', { detail: { count: artists.length } }));
    return true;
  };

  const dataFromSharedRequest = async () => {
    for (let attempt = 0; attempt < 12; attempt += 1) {
      const request = window.BRVTALPublicDataPromise;
      if (request && typeof request.then === 'function') {
        const result = await request;
        const payload = result?.payload ?? result;
        const root = payload?.data && typeof payload.data === 'object' ? payload.data : payload;
        return Array.isArray(root?.artists) ? root.artists : [];
      }
      await new Promise(resolve => window.setTimeout(resolve, 40));
    }
    return [];
  };

  window.BRVTALPublicRoster = { render, compareArtists, rosterStatus };

  window.addEventListener('load', () => {
    window.setTimeout(async () => {
      if (document.documentElement.dataset.publicRoster === 'connected') return;
      try {
        render(await dataFromSharedRequest());
      } catch (_) {
        // Static fallback remains visible when the shared public request fails.
      }
    }, 180);
  }, { once: true });
})();
