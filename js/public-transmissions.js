(() => {
  'use strict';

  const section = document.querySelector('[data-dynamic="transmissions"]');
  if (!section || !window.BRVTALPublicDataPromise) return;

  const list = section.querySelector('[data-transmissions-list]');
  const count = section.querySelector('[data-transmissions-count]');
  const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
  const routeUrl = (type, slug) => slug ? `/${type}/${encodeURIComponent(String(slug))}` : '';
  const formatDate = value => {
    if (!value) return 'TRANSMISSION';
    const date = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return 'TRANSMISSION';
    return new Intl.DateTimeFormat('en-GB', {day:'2-digit',month:'2-digit',year:'numeric'}).format(date);
  };
  const relationLabel = relation => {
    const type = String(relation?.related_type || '').toLowerCase();
    const item = relation?.item || relation?.related || relation;
    const title = item?.title || item?.name || relation?.title || relation?.name || '';
    const slug = item?.slug || relation?.slug || '';
    if (!['events','artists','sets','releases'].includes(type) || !title || !slug) return '';
    const singular = {events:'EVENT',artists:'ARTIST',sets:'SET',releases:'RELEASE'}[type];
    return `<a href="${routeUrl(type, slug)}">${singular} / ${escapeHtml(title)}</a>`;
  };

  window.BRVTALPublicDataPromise.then(result => {
    const posts = Array.isArray(result?.payload?.data?.blog) ? result.payload.data.blog.slice(0, 4) : [];
    list.replaceChildren();
    document.documentElement.dataset.publicTransmissions = 'editorial';
    count.textContent = String(posts.length).padStart(2, '0') + ' SIGNALS';

    if (!posts.length) {
      list.innerHTML = '<div class="transmissions-empty"><strong>NO TRANSMISSIONS PUBLISHED YET.</strong><span>THE NEXT SIGNAL WILL APPEAR HERE.</span></div>';
      return;
    }

    posts.forEach((post, index) => {
      const article = document.createElement('article');
      article.className = 'transmission-card';
      const tags = (Array.isArray(post.tags) ? post.tags : []).slice(0, 3)
        .map(tag => `<span>#${escapeHtml(tag?.name || '')}</span>`).join('');
      const relations = (Array.isArray(post.relations) ? post.relations : [])
        .map(relationLabel).filter(Boolean).slice(0, 4).join('');
      article.innerHTML = `
        <div class="transmission-index mono">${String(index + 1).padStart(2, '0')}</div>
        <div class="transmission-copy">
          <div class="transmission-meta mono"><span>${formatDate(post.published_at)}</span>${tags}</div>
          <a class="transmission-link" href="${routeUrl('blog', post.slug)}"><h3>${escapeHtml(post.title || 'UNTITLED TRANSMISSION')}</h3></a>
          ${post.excerpt ? `<p>${escapeHtml(post.excerpt)}</p>` : ''}
          ${relations ? `<div class="transmission-relations mono">${relations}</div>` : ''}
        </div>
        <a class="transmission-open mono" href="${routeUrl('blog', post.slug)}" aria-label="Read ${escapeHtml(post.title || 'transmission')}">READ ↗</a>`;
      list.appendChild(article);
    });
  }).catch(() => {
    count.textContent = 'SIGNAL UNAVAILABLE';
    section.dataset.state = 'unavailable';
  });
})();
