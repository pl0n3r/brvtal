/*
 * BRVTAL — Cromo home (public).
 * Builds the approved artifact (docs/design/cromo-prototype.html) from the real
 * public data (/api/public.php) and mounts it as #cromo-home. If the data does
 * not load or anything throws, nothing is mounted and the Concept 05 home stays.
 * Every string from the CMS is inserted with textContent, never as HTML.
 */
(() => {
  const root = document.documentElement;
  const MONTHS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

  const el = (tag, attrs, ...kids) => {
    const node = document.createElement(tag);
    Object.entries(attrs || {}).forEach(([key, value]) => {
      if (value === false || value === null || value === undefined) return;
      if (key === 'class') node.className = String(value).split(/\s+/).filter(Boolean).map((c) => 'cr-' + c).join(' ');
      else if (key === 'text') node.textContent = value;
      else node.setAttribute(key, value === true ? '' : String(value));
    });
    kids.flat().forEach((kid) => {
      if (kid === null || kid === undefined || kid === false) return;
      node.append(kid.nodeType ? kid : document.createTextNode(String(kid)));
    });
    return node;
  };
  const text = (value, max) => {
    const clean = String(value ?? '').replace(/\s+/g, ' ').trim();
    return max && clean.length > max ? clean.slice(0, max - 1).trimEnd() + '…' : clean;
  };
  const safeUrl = (value) => {
    const raw = String(value || '').trim();
    if (!raw) return '';
    if (raw.startsWith('/') && !raw.startsWith('//')) return raw;
    try {
      const url = new URL(raw, location.origin);
      return url.protocol === 'https:' || url.protocol === 'http:' ? url.href : '';
    } catch (error) { return ''; }
  };
  const parseDate = (value) => {
    const match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
    if (!match) return null;
    return new Date(+match[1], +match[2] - 1, +match[3], +(match[4] || 0), +(match[5] || 0));
  };
  const two = (n) => String(n).padStart(2, '0');
  const titleCase = (s) => s.toLowerCase().replace(/(^|\s)(\S)/g, (m, a, b) => a + b.toUpperCase());
  const joinNames = (names) => (names.length < 2 ? names.join('') : names.slice(0, -1).join(', ') + ' y ' + names[names.length - 1]);

  const nextEvent = (events) => {
    const now = Date.now() - 6 * 3600 * 1000;
    return events
      .filter((e) => !e.cancelled_at && !e.finished_at && parseDate(e.event_date) && parseDate(e.event_date).getTime() >= now)
      .sort((a, b) => parseDate(a.event_date) - parseDate(b.event_date));
  };

  const lineupText = (artists) => {
    const node = document.querySelector('#genesis .experience-lineup p');
    const raw = node ? text(node.textContent) : '';
    if (!raw) return '';
    const names = raw.split('/').map((s) => s.trim()).filter(Boolean).map((name) => {
      const known = artists.find((a) => String(a.name).toLowerCase() === name.toLowerCase());
      return known ? known.name : titleCase(name);
    });
    return joinNames(names);
  };

  const build = (data) => {
    const events = Array.isArray(data.events) ? data.events : [];
    const artists = Array.isArray(data.artists) ? data.artists : [];
    const sets = Array.isArray(data.sets) ? data.sets : [];
    const memories = Array.isArray(data.memories) ? data.memories : [];
    const posts = Array.isArray(data.blog) ? data.blog : [];
    const upcoming = nextEvent(events);
    const next = upcoming[0] || null;
    const nextTicket = next ? safeUrl(next.ticket_url) : '';
    const home = el('div', { id: 'cromo-home' });

    // --- Header
    const sndLabel = el('span', { 'data-cromo-sound-label': true, text: 'Activar sonido' });
    const snd = el('button', { class: 'snd', id: 'snd', type: 'button', 'aria-pressed': 'false', 'data-cromo-sound': true, 'data-cromo-sound-off': 'Activar sonido', 'data-cromo-sound-on': 'Apagar el pulso' }, el('i'), sndLabel);
    const navLinks = [['noches', 'Noches'], ['artistas', 'Artistas'], ['sonido', 'Sonido'], ['memorias', 'Memorias'], ['journal', 'Journal'], ['contacto', 'Contacto']];
    const nav = el('nav', { 'aria-label': 'Secciones' }, navLinks.map(([id, label]) => el('a', { href: '#' + id, text: label })));
    const ticketsLink = el('a', Object.assign({ class: 'tickets', text: 'Tickets', href: nextTicket || '#noches' }, nextTicket ? { target: '_blank', rel: 'noopener noreferrer' } : {}));
    const header = el('header', { class: 'top' },
      el('a', { href: '#cromo-top', class: 'u', text: 'BRVTAL' }), nav,
      el('div', { style: 'display:flex;gap:10px;align-items:center' }, snd, ticketsLink));

    // --- Hero
    const word = el('h1', { class: 'u', id: 'word', 'aria-label': 'BRVTAL' }, Array.from('BRVTAL').map((c) => el('span', { 'aria-hidden': 'true', text: c })));
    let nextCard = null;
    if (next) {
      const when = parseDate(next.event_date);
      const days = Math.ceil((new Date(when.getFullYear(), when.getMonth(), when.getDate()) - new Date(new Date().getFullYear(), new Date().getMonth(), new Date().getDate())) / 86400000);
      const k = days <= 0 ? 'Es hoy' : days === 1 ? 'Falta 1 día' : 'Faltan ' + days + ' días';
      const lineup = lineupText(artists);
      const place = [text(next.city), text(next.venue)].filter(Boolean).join(', ');
      nextCard = el('aside', { class: 'next', 'aria-label': 'Próxima noche' },
        el('b', { text: k }), el('span', { text: when.getDate() + ' ' + MONTHS[when.getMonth()] }),
        el('small', { text: text(next.title) + (lineup ? ': ' + lineup : '') + (place ? '. ' + place + '.' : '.') }));
    }
    const hero = el('section', { class: 'hero' },
      el('div', { class: 'core' }, word, el('p', { text: 'Más que fiestas. Una cultura en movimiento.' })),
      nextCard,
      el('div', { class: 'cta' }, el('a', { class: 'btn r', href: '#noches', text: 'Ver la próxima noche' }), el('a', { class: 'btn', href: '#sonido', text: 'Escuchar' })));
    const bandItems = ['Rave till grave', 'Pereira, Colombia', 'Más que fiestas'];
    const band = el('div', { class: 'band', 'aria-hidden': 'true' }, el('div', {}, [...bandItems, ...bandItems].map((t) => el('span', { text: t }))));

    // --- Noches
    const nights = el('section', { class: 'sec', id: 'noches' }, el('h2', { class: 'u', text: 'Noches' }));
    upcoming.slice(0, 6).forEach((event) => {
      const when = parseDate(event.event_date);
      const place = [text(event.city), text(event.venue)].filter(Boolean).join(', ');
      const meta = (event === next ? lineupText(artists) : '') ;
      const line = [meta, place + ', ' + two(when.getHours()) + ':' + two(when.getMinutes())].filter(Boolean).join('. ') + '.';
      nights.append(el('a', { class: 'night', href: '/events/' + encodeURIComponent(event.slug) },
        el('span', { class: 'u n', text: two(when.getDate()) + '.' + two(when.getMonth() + 1) }),
        el('span', {}, el('span', { class: 't', text: text(event.title) }), el('span', { class: 'm', text: line })),
        el('span', { class: 'go', text: safeUrl(event.ticket_url) ? 'Comprar tickets' : 'Ver noche' })));
    });
    nights.append(el('a', { class: 'night', href: '#contacto' },
      el('span', { class: 'u n', text: 'Sigue' }),
      el('span', {}, el('span', { class: 't', text: 'Aún sin fecha' }), el('span', { class: 'm', text: 'Escríbenos y te avisamos cuando se anuncie la siguiente.' })),
      el('span', { class: 'go', text: 'Avísame' })));

    // --- Artistas
    const roster = [...artists].sort((a, b) => (b.is_collective_member ? 1 : 0) - (a.is_collective_member ? 1 : 0)).slice(0, 5);
    const artistsGrid = el('div', { class: 'artists' }, roster.map((a) => el('a', { class: 'ac plate chrome-b', href: '/artists/' + encodeURIComponent(a.slug) },
      el('i', { class: 'orb', 'aria-hidden': 'true' }), el('span', { class: 'nm', text: text(a.name) }),
      el('span', { class: 'rl', text: (a.is_collective_member ? 'Colectivo. ' : 'Colaborador. ') + text(a.bio, 90) }))));
    artistsGrid.append(el('a', { class: 'ac plate chrome-b', href: '#contacto' },
      el('i', { class: 'orb', 'aria-hidden': 'true' }), el('span', { class: 'nm', text: 'Tú' }),
      el('span', { class: 'rl', text: '¿Quieres sumarte al roster? Escríbenos y cuéntanos qué suenas.' })));
    const artistsSec = el('section', { class: 'sec', id: 'artistas' }, el('h2', { class: 'u', text: 'Artistas' }), artistsGrid);

    // --- Sonido
    const snd2Label = el('span', { 'data-cromo-sound-label': true, text: 'Encender el pulso' });
    const snd2 = el('button', { class: 'btn r', id: 'snd2', type: 'button', 'aria-pressed': 'false', 'data-cromo-sound': true, 'data-cromo-sound-off': 'Encender el pulso', 'data-cromo-sound-on': 'Apagar el pulso' }, snd2Label);
    const sound = el('section', { class: 'sound', id: 'sonido' }, el('h2', { class: 'u', text: 'Sonido' }),
      el('p', { text: 'Enciende el pulso y el metal líquido late con la pista.' }), el('div', {}, snd2));

    // --- Sets
    let setsSec = el('section', { class: 'sec', id: 'discos' },
      el('h2', { class: 'u', text: 'Sets' }),
      el('p', { class: 'm', text: 'Los próximos sets aparecerán aquí cuando estén publicados.' }));
    if (sets.length) {
      const [first, ...rest] = sets;
      const firstUrl = safeUrl(first.external_url);
      const featBody = el('div', { style: 'position:relative' }, el('p', { text: 'Último set publicado' }), el('h3', { text: text(first.title) }),
        el('p', { style: 'margin-top:12px', text: [text(first.artist_name), first.event_title ? 'en ' + text(first.event_title) : ''].filter(Boolean).join(', ') + '.' }));
      const listen = el('a', Object.assign({ class: 'btn r', text: 'Escuchar' + (first.platform ? ' en ' + titleCase(text(first.platform)) : ''), href: firstUrl || '/sets/' + encodeURIComponent(first.slug) }, firstUrl ? { target: '_blank', rel: 'noopener noreferrer' } : {}));
      const feat = el('article', { class: 'feat plate chrome-b' }, el('div', { class: 'disc', 'aria-hidden': 'true' }), featBody, el('div', { style: 'position:relative' }, listen));
      const list = el('div', { class: 'sl' }, rest.slice(0, 4).map((s) => {
        const url = safeUrl(s.external_url);
        return el('a', Object.assign({ class: 'plate chrome-b', href: url || '/sets/' + encodeURIComponent(s.slug) }, url ? { target: '_blank', rel: 'noopener noreferrer' } : {}),
          el('span', {}, el('b', { text: text(s.title) }), el('small', { text: text(s.artist_name) })), el('span', { class: 'pl', text: 'Escuchar' }));
      }));
      setsSec = el('section', { class: 'sec', id: 'discos' }, el('h2', { class: 'u', text: 'Sets' }), el('div', { class: 'sets' }, feat, list));
    }

    // --- Memorias
    const tiles = memories.slice(0, 9).map((m, index) => {
      const url = safeUrl(m.file_path);
      const isImage = m.type === 'image' && url;
      const tile = el('a', Object.assign({ class: 't chrome-b' + (isImage ? ' has-img' : ''), href: url || '#memorias', 'data-type': m.type || 'image', style: (index === 0 ? 'grid-column:span 2;grid-row:span 2;' : '') + '--a:' + ((index * 90 + 20) % 360) + 'deg' + (isImage ? ';--img:url("' + url.replace(/["()\\]/g, '') + '")' : '') }, url ? { target: '_blank', rel: 'noopener noreferrer' } : {}), el('span', { text: text(m.title) }));
      return tile;
    });
    const chipDefs = [['Todo', ''], ['Imágenes', 'image'], ['Video', 'video'], ['Audio', 'audio']];
    const chips = el('div', { class: 'chips', role: 'group', 'aria-label': 'Filtrar medios' }, chipDefs.map(([label, type], i) => {
      const chip = el('button', { class: 'chip' + (i === 0 ? ' on' : ''), type: 'button', 'aria-pressed': i === 0 ? 'true' : 'false', text: label });
      chip.addEventListener('click', () => {
        chips.querySelectorAll('.cr-chip').forEach((c) => { c.classList.toggle('cr-on', c === chip); c.setAttribute('aria-pressed', c === chip ? 'true' : 'false'); });
        tiles.forEach((tile) => { tile.hidden = !!type && tile.dataset.type !== type; });
      });
      return chip;
    }));
    const memoriesSec = el('section', { class: 'sec', id: 'memorias' }, el('h2', { class: 'u', text: 'Memorias' }), chips,
      tiles.length ? el('div', { class: 'mem' }, tiles) : el('p', { style: 'background:#030303;display:inline-block;padding:10px 16px;font:600 22px/1.4 var(--body)', text: 'Aquí irán las fotos y videos reales de cada noche.' }));

    // --- Journal
    const post = posts[0];
    const cards = [];
    if (post) {
      cards.push(el('article', { class: 'jn plate chrome-b' },
        el('div', {}, el('p', { class: 'k', text: 'Crónica' }), el('h3', { style: 'margin-top:10px', text: text(post.title) })),
        el('p', { text: text(post.excerpt, 180) }),
        el('div', {}, el('a', { class: 'btn', href: '/blog/' + encodeURIComponent(post.slug), text: 'Leer la crónica' }))));
    }
    cards.push(el('article', { class: 'jn plate chrome-b' },
      el('div', {}, el('p', { class: 'k', text: 'Archivo' }), el('h3', { style: 'margin-top:10px', text: 'Todo está conectado' })),
      el('p', { text: 'De una noche puedes llegar a su música y a quien la tocó: eventos, artistas, sets y memorias, enlazados.' }),
      el('div', {}, el('a', { class: 'btn', href: '#memorias', text: 'Explorar el archivo' }))));
    const journalSec = el('section', { class: 'sec', id: 'journal' }, el('h2', { class: 'u', text: 'Journal' }), el('div', { class: 'journal' }, cards));

    // --- Contacto
    const status = el('p', { class: 'form-status', role: 'status', 'aria-live': 'polite', 'data-state': 'idle' });
    const captchaLabel = el('label', { for: 'cromo-c', text: 'Cargando…' });
    const token = el('input', { type: 'hidden', name: 'captcha_token' });
    const submit = el('button', { class: 'btn r', type: 'submit', text: 'Enviar mensaje' });
    const field = (id, label, control, name) => el('div', {}, el('label', { for: id, text: label }), control, el('span', { class: 'form-err', 'data-error-for': name }));
    const form = el('form', { class: 'form plate chrome-b', novalidate: true },
      field('cromo-n', 'Nombre', el('input', { id: 'cromo-n', name: 'name', type: 'text', autocomplete: 'name', maxlength: '100', required: true }), 'name'),
      field('cromo-e', 'Correo', el('input', { id: 'cromo-e', name: 'email', type: 'email', autocomplete: 'email', maxlength: '190', required: true }), 'email'),
      field('cromo-m', 'Mensaje', el('textarea', { id: 'cromo-m', name: 'message', rows: '4', maxlength: '5000', required: true }), 'message'),
      el('div', { class: 'hp', 'aria-hidden': 'true' }, el('input', { name: 'website', tabindex: '-1', autocomplete: 'off' })),
      token,
      el('div', { style: 'display:grid;grid-template-columns:140px 1fr;gap:14px;align-items:end' },
        el('div', {}, captchaLabel, el('input', { id: 'cromo-c', name: 'captcha_answer', type: 'text', inputmode: 'numeric', pattern: '[0-9]*', required: true, autocomplete: 'off' }), el('span', { class: 'form-err', 'data-error-for': 'captcha' })),
        submit),
      status);
    const setStatus = (msg, state) => { status.textContent = msg; status.dataset.state = state; };
    const loadChallenge = async () => {
      captchaLabel.textContent = 'Cargando…';
      token.value = '';
      form.elements.captcha_answer.value = '';
      try {
        const res = await fetch('/api/contact.php', { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' });
        const payload = await res.json();
        if (!res.ok || !payload.data || !payload.data.token) throw new Error('captcha');
        captchaLabel.textContent = text(payload.data.question).replace(/\?\s*$/, '');
        token.value = String(payload.data.token);
      } catch (error) {
        captchaLabel.textContent = 'Control anti-bot';
        setStatus('El control anti-bot no está disponible. Intenta más tarde.', 'error');
      }
    };
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      form.querySelectorAll('[aria-invalid]').forEach((n) => n.removeAttribute('aria-invalid'));
      form.querySelectorAll('.cr-form-err').forEach((n) => { n.textContent = ''; });
      const body = Object.fromEntries(new FormData(form).entries());
      body.subject = 'Contacto desde el home';
      submit.disabled = true;
      setStatus('Enviando…', 'idle');
      try {
        const res = await fetch('/api/contact.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(body) });
        const payload = await res.json().catch(() => ({}));
        if (res.ok && payload.ok !== false) { form.reset(); setStatus('Mensaje enviado. Gracias, te respondemos pronto.', 'success'); await loadChallenge(); return; }
        if (res.status === 422) {
          Object.keys(payload.fields || {}).forEach((name) => {
            const key = name === 'form' ? 'captcha' : name;
            const control = form.elements[key === 'captcha' ? 'captcha_answer' : key];
            const slot = form.querySelector('[data-error-for="' + key + '"]');
            if (slot) slot.textContent = 'Revisa este campo.';
            if (control) control.setAttribute('aria-invalid', 'true');
          });
          setStatus('Revisa los campos marcados.', 'error');
          if (payload.fields && payload.fields.captcha) await loadChallenge();
          return;
        }
        setStatus(res.status === 429 ? 'Demasiados intentos. Espera un momento e inténtalo de nuevo.' : 'No pudimos enviar el mensaje. Inténtalo de nuevo.', 'error');
      } catch (error) {
        setStatus('No pudimos enviar el mensaje. Revisa tu conexión.', 'error');
      } finally {
        submit.disabled = false;
      }
    });
    const end = el('section', { class: 'end', id: 'contacto' }, el('a', { class: 'u', href: '#contacto', text: 'Hablemos' }), form);

    // --- Footer
    const cookiePrefs = document.querySelector('.brvtal-cookie-preferences');
    const footer = el('footer', {}, el('span', { text: 'BRVTAL, Pereira, Colombia' }), el('span', { text: 'Rave till grave' }),
      el('a', { href: '/privacy', text: 'Privacidad y mis derechos' }),
      cookiePrefs ? el('button', { type: 'button', text: 'Preferencias de cookies' }) : null);
    const cookieBtn = footer.querySelector('button');
    if (cookieBtn) cookieBtn.addEventListener('click', () => cookiePrefs.click());

    home.append(el('a', { class: 'skip', href: '#noches', text: 'Saltar al contenido' }), header,
      el('main', { id: 'cromo-top' }, hero, band, nights, artistsSec, sound, setsSec, memoriesSec, journalSec, end), footer);
    return { home, snd, snd2, nav, loadChallenge, word };
  };

  const mount = (data) => {
    const parts = build(data);
    window.BRVTAL_CROMO_ACCENT = [1, .17, .05];
    document.body.appendChild(parts.home);
    root.classList.add('cromo-home');
    const sound = window.BRVTALCromoSound;
    if (sound && sound.bind) { sound.bind(parts.snd); sound.bind(parts.snd2); }
    parts.loadChallenge();

    const links = Array.from(parts.nav.querySelectorAll('a'));
    if ('IntersectionObserver' in window) {
      const io = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
          if (!entry.isIntersecting) return;
          links.forEach((a) => a.removeAttribute('aria-current'));
          const link = links.find((a) => a.getAttribute('href') === '#' + entry.target.id);
          if (link) link.setAttribute('aria-current', 'true');
        });
      }, { rootMargin: '-45% 0px -50% 0px' });
      parts.home.querySelectorAll('section[id]').forEach((s) => io.observe(s));
    }
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)');
    if (!reduce.matches) {
      let px = .5, py = .5, beat = 0;
      window.addEventListener('pointermove', (e) => { px = e.clientX / window.innerWidth; py = e.clientY / window.innerHeight; }, { passive: true });
      window.addEventListener('cromo:beat', () => { beat = 1; });
      const tick = () => {
        beat *= .88;
        parts.word.style.transform = 'translate(' + ((px - .5) * -40) + 'px,' + ((py - .5) * 30) + 'px) scale(' + (1 + beat * .05) + ')';
        window.requestAnimationFrame(tick);
      };
      window.requestAnimationFrame(tick);
    }
  };

  const start = () => {
    fetch('/api/public.php', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then((res) => (res.ok ? res.json() : Promise.reject(new Error('http'))))
      .then((payload) => { if (payload && payload.data && !document.getElementById('cromo-home')) mount(payload.data); })
      .catch(() => { /* keep the Concept 05 home */ });
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
})();
