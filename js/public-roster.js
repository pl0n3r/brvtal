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

  const rosterStatus = artist => {
    const status = String(artist?.collective_status ?? 'none').trim().toLowerCase();
    return status === 'active' || status === 'alumni' ? status : 'network';
  };

  const numberValue = (value, fallback = Number.MAX_SAFE_INTEGER) => {
    const parsed = Number(value);
    return Number.isFinite(parsed) && parsed >= 0 ? parsed : fallback;
  };

  const compareArtists = (a, b) => {
    const groupRank = { active: 0, alumni: 1, network: 2 };
    const groupDelta = groupRank[rosterStatus(a)] - groupRank[rosterStatus(b)];
    if (groupDelta) return groupDelta;

    const status = rosterStatus(a);
    if (status !== 'network') {
      const aCollective = numberValue(a.collective_order);
      const bCollective = numberValue(b.collective_order);
      if (aCollective !== bCollective) return aCollective - bCollective;
    }
    const sortDelta = numberValue(a.sort_order) - numberValue(b.sort_order);
    if (sortDelta) return sortDelta;
    return String(a.name ?? '').localeCompare(String(b.name ?? ''), 'en', { sensitivity: 'base' });
  };

  const year = value => {
    const match = String(value ?? '').match(/^([0-9]{4})/);
    return match ? match[1] : '';
  };

  const rowMeta = artist => {
    const status = rosterStatus(artist);
    if (status === 'active') {
      const since = year(artist.collective_joined_at);
      return `BRVTAL / ACTIVE${since ? ` · SINCE ${since}` : ''}`;
    }
    if (status === 'alumni') {
      const joined = year(artist.collective_joined_at);
      const left = year(artist.collective_left_at);
      const period = [joined, left].filter(Boolean).join('—');
      return `BRVTAL / ALUMNI${period ? ` · ${period}` : ''}`;
    }
    return 'ARTIST / COLLABORATOR';
  };

  const groups = [
    ['active', 'CORE / ACTIVE'],
    ['alumni', 'ALUMNI / ARCHIVE'],
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
    const firstPhoto = ordered.map(artist => imageUrl(artist.photo ?? artist.image ?? artist.cover_image ?? '')).find(Boolean);
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
