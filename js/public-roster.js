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
    if (/^https?:\/\//i.test(raw) || raw.startsWith('/')) return raw;
    return raw.replace(/^\.?\//, '');
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

  const rowMeta = artist => isMember(artist) ? 'BRVTAL / MEMBER' : 'ARTIST / COLLABORATOR';

  const groups = [
    ['member', 'BRVTAL / COLLECTIVE'],
    ['network', 'ARTISTS / COLLABORATORS'],
  ];

  const bindPreview = () => {
    const preview = document.querySelector('.artist-preview img');
    document.querySelectorAll('.artist[data-image]').forEach(artist => {
      artist.addEventListener('mouseenter', () => {
        if (preview && artist.dataset.image) preview.src = artist.dataset.image;
      });
      artist.addEventListener('focus', () => {
        if (preview && artist.dataset.image) preview.src = artist.dataset.image;
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
        const photo = imageUrl(artist.photo ?? artist.image ?? artist.cover_image ?? '');
        const href = slug ? `/artists/${encodeURIComponent(slug)}` : '/#artists';
        return `<a class="artist artist--${status}" href="${escapeHtml(href)}" data-roster-status="${status}"${photo ? ` data-image="${escapeHtml(photo)}"` : ''}>
          <span>${String(index).padStart(2, '0')}</span><strong>${escapeHtml(name)}</strong><i>${escapeHtml(rowMeta(artist))}</i>
        </a>`;
      }).join('');
      return `<section class="roster-group roster-group--${status}" data-roster-group="${status}">
        <div class="roster-group-head mono"><span>${escapeHtml(label)}</span><b>${String(members.length).padStart(2, '0')}</b></div>${rows}
      </section>`;
    }).join('');

    list.innerHTML = markup;
    const firstPhoto = ordered
      .map(artist => imageUrl(artist.photo ?? artist.image ?? artist.cover_image ?? ''))
      .find(Boolean);
    const preview = document.querySelector('.artist-preview img');
    if (preview && firstPhoto) preview.src = firstPhoto;

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
