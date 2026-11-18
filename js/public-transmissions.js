(() => {
  'use strict';

  const section = document.querySelector('[data-dynamic="transmissions"]');
  if (!section) return;

  const list = section.querySelector('[data-transmissions-list]');
  const count = section.querySelector('[data-transmissions-count]');
  if (!list || !count) return;

  const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'
  }[char]));

  const routeUrl = (type, slug) => {
    const clean = String(slug ?? '').trim();
    if (!['blog','events','artists','sets','releases'].includes(type)) return '';
    if (!/^[a-z0-9-]{1,190}$/.test(clean)) return '';
    return `/${type}/${encodeURIComponent(clean)}`;
  };

  const safeMediaUrl = value => {
    const raw = String(value ?? '').trim();
    if (!raw) return '';
    try {
      const url = new URL(raw, window.location.href);
      return /^https?:$/i.test(url.protocol) ? url.href : '';
    } catch (error) {
      if (error instanceof TypeError) return '';
      throw error;
    }
  };

  const formatDate = value => {
    if (!value) return 'TRANSMISSION';
    const timestamp = publicationTime(value);
    if (timestamp === null) return 'TRANSMISSION';
    const date = new Date(timestamp);
    return new Intl.DateTimeFormat('en-GB', {
      day:'2-digit',month:'2-digit',year:'numeric',timeZone:'UTC'
    }).format(date);
  };

  const indexById = items => new Map(
    (Array.isArray(items) ? items : [])
      .filter(item => item?.id != null)
      .map(item => [String(item.id), item])
  );

  const buildRelationIndex = data => ({
    event: indexById([
      ...(Array.isArray(data?.events) ? data.events : []),
      ...(Array.isArray(data?.archive?.events) ? data.archive.events : [])
    ]),
    artist: indexById(data?.artists),
    set: indexById(data?.sets),
    release: indexById(data?.releases)
  });

  const relationLabel = (relation, relationIndex) => {
    const type = String(relation?.related_type || '').toLowerCase();
    const routeType = {event:'events',artist:'artists',set:'sets',release:'releases'}[type];
    const label = {event:'EVENT',artist:'ARTIST',set:'SET',release:'RELEASE'}[type];
    const item = relationIndex[type]?.get(String(relation?.related_id ?? ''));
    const title = item?.title || item?.name || '';
    const href = routeUrl(routeType, item?.slug || '');
    if (!routeType || !title || !href) return '';
    return `<a href="${escapeHtml(href)}">${label} / ${escapeHtml(title)}</a>`;
  };

  const failCover = (figure, image) => {
    figure?.classList.add('is-media-missing');
    if (image) image.hidden = true;
  };

  const bindCover = article => {
    const figure = article.querySelector('[data-transmission-cover]');
    const image = figure?.querySelector('img');
    if (!figure || !image) return;
    image.addEventListener('error', () => failCover(figure, image), {once:true});
    if (image.complete && image.naturalWidth === 0) failCover(figure, image);
  };


  // CMS may return publications out of order. Sort dated posts newest-first;
  // published posts without dates remain visible last, never as 'latest'.
  // Malformed non-null dates cannot invent a publication.
  const publicationTime = value => {
    if (typeof value !== 'string') return null;
    const match = /^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})(?:Z)?$/.exec(value.trim());
    if (!match) return null;
    const exact = match[1] + 'T' + match[2];
    const ms = Date.parse(exact + 'Z');
    return Number.isFinite(ms) && new Date(ms).toISOString().slice(0, 19) === exact ? ms : null;
  };

  // Database-backed CMS flags may be bool, tinyint or a serialized string.
  // If a publication flag exists, fail closed unless it clearly says published.
  const publiclyPublished = post => {
    const flag = post.is_published;
    return flag == null || flag === true || flag === 1
      || (typeof flag === 'string' && /^(?:1|true)$/i.test(flag.trim()));
  };

  const publishedPosts = data => (Array.isArray(data?.blog) ? data.blog : [])
    .map((post, position) => ({post, position, timestamp:publicationTime(post?.published_at)}))
    .filter(({post, timestamp}) => post && typeof post === 'object'
      && (post.published_at == null || timestamp !== null)
      && publiclyPublished(post)
      && (post.status == null || post.status === 'published')
      && routeUrl('blog', post.slug))
    .sort((a, b) => (a.timestamp === null) - (b.timestamp === null)
      || (b.timestamp ?? 0) - (a.timestamp ?? 0) || a.position - b.position)
    .slice(0, 5)
    .map(({post}) => post);

  function render(data) {
    const posts = publishedPosts(data);
    const relationIndex = buildRelationIndex(data);
    list.replaceChildren();
    document.documentElement.dataset.publicTransmissions = 'editorial';
    count.textContent = String(posts.length).padStart(2, '0') + ' SIGNALS';

    section.querySelector('.transmissions-route')?.remove();

    if (!posts.length) {
      list.innerHTML = '<div class="transmissions-empty"><strong>NO TRANSMISSIONS PUBLISHED YET.</strong><span>THE NEXT SIGNAL WILL APPEAR HERE.</span></div>';
      section.dataset.state = 'empty';
      return;
    }

    section.dataset.state = 'ready';
    posts.forEach((post, index) => {
      const article = document.createElement('article');
      article.className = index === 0
        ? 'transmission-card transmission-feature'
        : 'transmission-card transmission-indexed';

      const href = routeUrl('blog', post.slug);
      const title = post.title || 'UNTITLED TRANSMISSION';
      const tags = (Array.isArray(post.tags) ? post.tags : []).slice(0, 3)
        .map(tag => `<span>#${escapeHtml(tag?.name || '')}</span>`).join('');
      const relations = (Array.isArray(post.relations) ? post.relations : [])
        .map(relation => relationLabel(relation, relationIndex))
        .filter(Boolean)
        .slice(0, 4)
        .join('');
      const cover = index === 0 ? safeMediaUrl(post.cover_image) : '';
      let coverMarkup = '';
      if (index === 0) {
        const coverClass = cover
          ? 'transmission-cover'
          : 'transmission-cover is-media-missing';
        const imageMarkup = cover
          ? `<img src="${escapeHtml(cover)}" alt="${escapeHtml(title)}" loading="lazy" decoding="async">`
          : '';
        coverMarkup = `<figure class="${coverClass}" data-transmission-cover>
            ${imageMarkup}
            <span class="mono" aria-hidden="true">JOURNAL / IMAGE SIGNAL</span>
          </figure>`;
      }
      const titleMarkup = href
        ? `<a class="transmission-link" href="${escapeHtml(href)}"><h3>${escapeHtml(title)}</h3></a>`
        : `<div class="transmission-link"><h3>${escapeHtml(title)}</h3></div>`;
      const openMarkup = href
        ? `<a class="transmission-open mono" href="${escapeHtml(href)}" aria-label="Read ${escapeHtml(title)}">READ ↗</a>`
        : '<span class="transmission-open mono" aria-hidden="true">ARCHIVE RECORD</span>';

      article.innerHTML = `
        <div class="transmission-index mono">${String(index + 1).padStart(2, '0')}</div>
        ${coverMarkup}
        <div class="transmission-copy">
          <div class="transmission-meta mono"><span>${formatDate(post.published_at)}</span>${tags}</div>
          ${titleMarkup}
          ${post.excerpt ? `<p>${escapeHtml(post.excerpt)}</p>` : ''}
          ${relations ? `<div class="transmission-relations mono">${relations}</div>` : ''}
        </div>
        ${openMarkup}`;
      list.appendChild(article);
      bindCover(article);
    });

    const latestHref = publicationTime(posts[0]?.published_at) === null
      ? ''
      : routeUrl('blog', posts[0]?.slug);
    if (latestHref) {
      const route = document.createElement('a');
      route.className = 'transmissions-route mono';
      route.href = latestHref;
      route.textContent = 'VIEW JOURNAL ↗';
      route.setAttribute('aria-label', 'Open latest Journal entry');
      section.appendChild(route);
    }

    window.dispatchEvent(new CustomEvent('brvtal:journal-rendered', {
      detail: {count:posts.length}
    }));
  }

  // app.js publishes the CMS request after window.load + 120ms.
  // Journal may be evaluated first, so observe for a bounded interval.
  const waitForDataPromise = maxWaitMs => new Promise(resolve => {
    const existing = window.BRVTALPublicDataPromise;
    if (existing) {
      resolve(existing);
      return;
    }
    const started = Date.now();
    const timer = window.setInterval(() => {
      const request = window.BRVTALPublicDataPromise;
      if (request || Date.now() - started >= maxWaitMs) {
        window.clearInterval(timer);
        resolve(request || null);
      }
    }, 100);
  });

  let adoptedRequest = false;
  const showUnavailable = () => {
    // Public CMS reads can fail offline; preserve static markup and
    // never log upstream errors that may contain private metadata.
    count.textContent = 'SIGNAL UNAVAILABLE';
    section.dataset.state = 'unavailable';
  };
  const adoptRequest = request => {
    if (!request || adoptedRequest) return;
    adoptedRequest = true;
    Promise.resolve(request)
      .then(result => render(result?.payload?.data || {}))
      .catch(showUnavailable);
  };

  waitForDataPromise(4000)
    .then(request => {
      if (request) adoptRequest(request);
      else showUnavailable();
    })
    .catch(showUnavailable);

  // If window.load happens after the 4s deadline, app.js will publish the
  // shared CMS promise 120ms later. Allow a single, finite late recovery.
  // Never fetch again or keep an interval running after the deadline.
  window.addEventListener('load', () => {
    window.setTimeout(() => {
      if (!adoptedRequest && section.dataset.state === 'unavailable') {
        adoptRequest(window.BRVTALPublicDataPromise);
      }
    }, 180);
  }, {once:true});
})();