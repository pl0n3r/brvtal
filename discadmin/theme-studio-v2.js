(() => {
  'use strict';

  const V2 = {
    editingSlug: '',
    baseline: '',
    previewMode: 'desktop',
    pickerField: '',
    lastFocus: null,
  };

  const clone = value => JSON.parse(JSON.stringify(value ?? {}));

  const FONT_CATALOG = Object.freeze({
    display:[
      ['"Space Grotesk", Arial, sans-serif','Space Grotesk · recommended'],
      ['"Barlow Condensed", Arial, sans-serif','Barlow Condensed · industrial condensed'],
      ['"Inter Tight", Arial, sans-serif','Inter Tight · compact grotesk'],
      ['Arial, Helvetica, sans-serif','System grotesk · offline-safe'],
    ],
    body:[
      ['"Space Grotesk", Arial, sans-serif','Space Grotesk · recommended'],
      ['"Inter Tight", Arial, sans-serif','Inter Tight · compact grotesk'],
      ['Arial, Helvetica, sans-serif','System grotesk · offline-safe'],
    ],
    mono:[
      ['"Space Mono", monospace','Space Mono · recommended'],
      ['"IBM Plex Mono", monospace','IBM Plex Mono · technical'],
      ['ui-monospace, SFMono-Regular, Menlo, monospace','System mono · offline-safe'],
    ],
  });

  const GOOGLE_FONT_QUERY = Object.freeze({
    'Space Grotesk':'Space+Grotesk:wght@400;500;600;700',
    'Barlow Condensed':'Barlow+Condensed:wght@400;500;600;700;800;900',
    'Inter Tight':'Inter+Tight:wght@400;500;600;700;800',
    'Space Mono':'Space+Mono:wght@400;700',
    'IBM Plex Mono':'IBM+Plex+Mono:wght@400;500;600;700',
  });

  const CONCEPT05_VISUAL = Object.freeze({
    colors:{bg:'#050505',surface:'#0A0B0C',text:'#F4F1E8',muted:'#8A8A82',primary:'#E31B23',accent:'#B6FF00',border:'#30302D'},
    typography:{display:'"Space Grotesk", Arial, sans-serif',body:'"Space Grotesk", Arial, sans-serif',mono:'"Space Mono", monospace',h1:'clamp(58px,9vw,132px)',bodySize:'16px',tracking:'-0.055em'},
    navigation:{fixed:true,transparentHero:true,blur:true,menuStyle:'fullscreen',logoPosition:'left',sceneIndicator:false,soundToggle:true},
    effects:{grain:true,scanlines:true,glitch:true,cursor:true,magnetic:true,motion:'brvtal'},
  });
  const escapeHtml = value => String(value ?? '')
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  function trimEdgeDashes(value) {
    let start = 0;
    let end = value.length;
    while (start < end && value.startsWith('-', start)) start += 1;
    while (end > start && value.endsWith('-', end)) end -= 1;
    return value.slice(start, end);
  }
  const safeSlug = value => trimEdgeDashes(
    trimEdgeDashes(
      String(value || '').trim().toLowerCase().replace(/[^a-z0-9_-]+/g, '-')
    ).slice(0, 60)
  );
  const imagePath = value => {
    const raw = String(value || '').trim();
    if (!raw) return '';
    if (/^https?:\/\//i.test(raw) || raw.startsWith('/')) return raw;
    return '/' + raw.replace(/^\.?\//, '').replace(/^\/+/, '');
  };
  const mergeTheme = extra => {
    try { return deepMergeTheme(THEME_DEFAULT, extra || {}); }
    catch (_) { return clone(extra || {}); }
  };
  const value = id => document.getElementById('th_' + id)?.value ?? '';
  const checked = id => Boolean(document.getElementById('th_' + id)?.checked);

  function themeRows() {
    return (state.themeSettings || [])
      .filter(row => /^theme\.[a-z0-9_-]+$/i.test(String(row.setting_key || '')) && row.setting_key !== 'theme.active')
      .map(row => {
        let theme = row.setting_value;
        try { if (typeof theme === 'string') theme = JSON.parse(theme); } catch (_) { theme = {}; }
        const slug = String(row.setting_key).slice(6);
        return { slug, name: String(theme?.name || slug).trim() || slug, theme: mergeTheme(theme || {}) };
      })
      .sort((a, b) => a.name.localeCompare(b.name));
  }

  function activeSlug() {
    const row = (state.themeSettings || []).find(item => item.setting_key === 'theme.active');
    const slug = safeSlug(row?.setting_value || 'core');
    return slug || 'core';
  }

  function findTheme(slug) {
    return themeRows().find(item => item.slug === slug)?.theme || null;
  }

  function textField(id, label, current, help = '', attrs = '') {
    return `<label class="tsv2-field"><span>${escapeHtml(label)}</span><input id="th_${id}" value="${escapeHtml(current ?? '')}" ${attrs}>${help ? `<small>${escapeHtml(help)}</small>` : ''}</label>`;
  }

  function selectField(id, label, current, options, help = '') {
    const opts = options.map(([key, name]) => `<option value="${escapeHtml(key)}" ${String(key) === String(current) ? 'selected' : ''}>${escapeHtml(name)}</option>`).join('');
    return `<label class="tsv2-field"><span>${escapeHtml(label)}</span><select id="th_${id}">${opts}</select>${help ? `<small>${escapeHtml(help)}</small>` : ''}</label>`;
  }

  function fontSelectField(id, label, current, role, help = '') {
    const options = [...(FONT_CATALOG[role] || [])];
    if (current && !options.some(([stack]) => stack === current)) {
      options.unshift([current, 'Current / legacy stack']);
    }
    return selectField(id, label, current, options, help);
  }

  function fontFamilyName(stack) {
    return String(stack || '').split(',')[0].trim().replace(/^["']|["']$/g, '');
  }

  function ensurePreviewFonts(typography) {
    const families = [...new Set(['display','body','mono']
      .map(key => GOOGLE_FONT_QUERY[fontFamilyName(typography?.[key])])
      .filter(Boolean))];
    let link = document.getElementById('brvtal-theme-studio-fonts');
    if (!families.length) { link?.remove(); return; }
    if (!link) {
      link = document.createElement('link');
      link.id = 'brvtal-theme-studio-fonts';
      link.rel = 'stylesheet';
      document.head.appendChild(link);
    }
    link.href = 'https://fonts.googleapis.com/css2?' + families.map(family => 'family=' + family).join('&') + '&display=swap';
  }

  function contrastRatio(a, b) {
    const luminance = value => {
      const match = /^#([0-9a-f]{6})$/i.exec(String(value || ''));
      if (!match) return 0;
      const parts = [0,2,4].map(offset => Number.parseInt(match[1].slice(offset, offset + 2), 16) / 255)
        .map(channel => channel <= .03928 ? channel / 12.92 : Math.pow((channel + .055) / 1.055, 2.4));
      return .2126 * parts[0] + .7152 * parts[1] + .0722 * parts[2];
    };
    const l1 = luminance(a), l2 = luminance(b);
    return (Math.max(l1,l2) + .05) / (Math.min(l1,l2) + .05);
  }

  function colorField(id, label, current, help = '') {
    const color = /^#[0-9a-f]{6}$/i.test(String(current || '')) ? String(current) : '#000000';
    return `<label class="tsv2-field tsv2-color-field"><span>${escapeHtml(label)}</span><div class="tsv2-color-row"><input id="th_${id}_picker" type="color" value="${escapeHtml(color)}" aria-label="${escapeHtml(label)} color picker"><input id="th_${id}" value="${escapeHtml(color)}" inputmode="text" spellcheck="false"><i style="background:${escapeHtml(color)}" aria-hidden="true"></i></div>${help ? `<small>${escapeHtml(help)}</small>` : ''}</label>`;
  }

  function toggleField(id, label, current, help = '') {
    return `<label class="tsv2-toggle"><span><b>${escapeHtml(label)}</b>${help ? `<small>${escapeHtml(help)}</small>` : ''}</span><input id="th_${id}" type="checkbox" ${current ? 'checked' : ''}><i aria-hidden="true"></i></label>`;
  }

  function assetField(id, label, current, help = '') {
    const src = imagePath(current);
    return `<div class="tsv2-asset" data-theme-asset="${escapeHtml(id)}"><div class="tsv2-asset-copy"><strong>${escapeHtml(label)}</strong>${help ? `<small>${escapeHtml(help)}</small>` : ''}<code data-theme-asset-path>${escapeHtml(current || 'Not selected')}</code></div><div class="tsv2-asset-preview">${src ? `<img src="${escapeHtml(src)}" alt="${escapeHtml(label)} preview" loading="lazy">` : '<span>NO ASSET</span>'}</div><input id="th_${id}_custom" type="hidden" value="${escapeHtml(current || '')}"><div class="tsv2-asset-actions"><button type="button" class="btn ghost" data-pick-media="${escapeHtml(id)}">CHOOSE FROM MEDIA</button><button type="button" class="btn ghost" data-clear-media="${escapeHtml(id)}" ${current ? '' : 'disabled'}>CLEAR</button></div></div>`;
  }

  function themeSelector() {
    const active = activeSlug();
    const rows = themeRows();
    if (!rows.some(row => row.slug === V2.editingSlug)) {
      rows.push({ slug: V2.editingSlug || active, name: state.theme?.name || V2.editingSlug || active });
    }
    return rows.map(row => `<option value="${escapeHtml(row.slug)}" ${row.slug === V2.editingSlug ? 'selected' : ''}>${escapeHtml(row.name)}${row.slug === active ? ' · ACTIVE' : ''}</option>`).join('');
  }

  function panel(theme) {
    const t = mergeTheme(theme);
    const b = t.branding || {}, c = t.colors || {}, ty = t.typography || {}, n = t.navigation || {}, e = t.effects || {}, s = t.sound || {};
    const isActive = V2.editingSlug === activeSlug();
    return `<div class="theme-v2" data-theme-studio-v2>
      <header class="tsv2-toolbar">
        <div class="tsv2-title"><span>APPEARANCE / THEME SYSTEM</span><h2>THEME STUDIO</h2><p>Design the public BRVTAL experience with production-wired controls and assets from Media.</p></div>
        <div class="tsv2-theme-switcher"><label for="tsv2-theme-select">EDITING THEME</label><select id="tsv2-theme-select">${themeSelector()}</select><span class="tsv2-live ${isActive ? 'live' : ''}" id="tsv2-live-state">${isActive ? 'LIVE NOW' : 'NOT LIVE'}</span></div>
      </header>

      <div class="tsv2-statusbar"><span id="tsv2-dirty-state" class="clean">SAVED</span><span>Save stores a draft. Activate publishes this theme to the public site.</span></div>

      <div class="tsv2-layout">
        <aside class="tsv2-tabs" aria-label="Theme Studio sections">
          ${[['brand','BRAND'],['palette','PALETTE'],['type','TYPE'],['navigation','NAVIGATION'],['effects','EXPERIENCE'],['manage','MANAGE']].map(([id,label],index) => `<button type="button" class="${index === 0 ? 'active' : ''}" data-theme-tab="${id}"><span>0${index + 1}</span>${label}</button>`).join('')}
        </aside>

        <section class="tsv2-editor">
          <div class="tsv2-pane active" data-theme-pane="brand">
            <div class="tsv2-section-head"><div><span>01 / BRAND</span><h3>Identity</h3></div><p>These values drive the public wordmark, hero identity and browser assets.</p></div>
            <div class="tsv2-grid two">${textField('name','Theme name',t.name,'Internal label shown only in DISCADMIN.')}${textField('slug','Theme slug',t.slug,'Stable identifier used for activation.','autocomplete="off" spellcheck="false"')}</div>
            <div class="tsv2-grid two">${textField('siteName','Public site name',b.siteName,'Applied to visible BRVTAL name elements.')}${textField('tagline','Public tagline',b.tagline,'Applied to the header and hero identity.')}</div>
            <div class="tsv2-assets">${assetField('logo','Main logo',b.logo,'Desktop/public primary logo.')}${assetField('mobileLogo','Mobile logo',b.mobileLogo,'Optional compact logo below 900px. Falls back to Main logo.')}${assetField('favicon','Favicon',b.favicon,'Browser tab icon. Choose a square asset when possible.')}${assetField('preloaderLogo','Preloader logo',b.preloaderLogo,'Reserved branding asset. Main logo remains the safe fallback.')}</div>
          </div>

          <div class="tsv2-pane" data-theme-pane="palette">
            <div class="tsv2-section-head"><div><span>02 / PALETTE</span><h3>BRVTAL mother palette</h3></div><div class="tsv2-section-side"><p>BLACK / PAPER / RED remain the mother identity. SIGNAL is a bounded neon accent, never a replacement background.</p><button type="button" class="btn ghost" data-theme-reset="palette">RESET PALETTE</button></div></div>
            <div class="tsv2-grid two">${colorField('bg','BLACK · canvas',c.bg,'Primary black canvas.')}${colorField('surface','BLACK · elevated',c.surface,'Panels and opaque navigation.')}${colorField('text','PAPER · foreground',c.text,'Primary editorial foreground.')}${colorField('muted','PAPER · muted',c.muted,'Secondary copy and metadata.')}${colorField('primary','RED · mother signal',c.primary,'Core BRVTAL red role.')}${colorField('accent','SIGNAL · neon accent',c.accent,'Bounded glitch/highlight role; never the page background.')}${colorField('border','LINE · structure',c.border,'Editorial rules and separators.')}</div>
            <div class="tsv2-palette-strip" aria-label="Current palette">${[c.bg,c.surface,c.text,c.muted,c.primary,c.accent,c.border].map(x => `<i style="background:${escapeHtml(x || '#000')}"></i>`).join('')}</div>
            <div class="tsv2-contrast" data-contrast-check><span>TEXT / BLACK CONTRAST</span><strong data-contrast-value>—</strong><small data-contrast-copy>WCAG readability check for the primary editorial pair.</small></div>
          </div>

          <div class="tsv2-pane" data-theme-pane="type">
            <div class="tsv2-section-head"><div><span>03 / TYPE</span><h3>Typography</h3></div><div class="tsv2-section-side"><p>Choose from the curated BRVTAL catalog. Google Fonts load only for selected supported families and always use system fallbacks.</p><button type="button" class="btn ghost" data-theme-reset="type">RESET TYPE</button></div></div>
            <div class="tsv2-grid two">${fontSelectField('display','Display family',ty.display,'display','Space Grotesk is the recommended Concept 05 display family.')}${fontSelectField('body','Body family',ty.body,'body','Independent public body family with safe fallback.')}${fontSelectField('mono','Mono family',ty.mono,'mono','Technical metadata / data labels.')}${textField('bodySize','Body size',ty.bodySize,'Global baseline only; authored Concept 05 display geometry remains design-system owned.')}</div>
            <div class="tsv2-type-specimens" aria-label="Typography preview"><article data-type-specimen="display"><span>DISPLAY</span><strong>RAVE TILL GRAVE</strong></article><article data-type-specimen="mono"><span>MONO / DATA</span><strong>02 / NIGHTS · PEREIRA / COLOMBIA</strong></article></div>
          </div>

          <div class="tsv2-pane" data-theme-pane="navigation">
            <div class="tsv2-section-head"><div><span>04 / NAVIGATION</span><h3>Header & menu</h3></div><p>Only controls with a public runtime mapping are exposed here.</p></div>
            <div class="tsv2-toggle-list">${toggleField('navFixed','Fixed header',n.fixed,'Keep navigation pinned while scrolling.')}${toggleField('navTransparent','Transparent over hero',n.transparentHero,'Use the visual hero behind navigation.')}${toggleField('navBlur','Header backdrop blur',n.blur,'Apply glass blur when supported.')}${toggleField('soundToggle','Sound control',n.soundToggle && s.enabled !== false,'Show the user-initiated sound control.')}</div>
            <div class="tsv2-grid two">${selectField('menuStyle','Menu presentation',n.menuStyle,[['fullscreen','Fullscreen'],['dropdown','Dropdown'],['slide','Slide panel']],'Applied to the public menu container.')}${selectField('logoPosition','Brand position',n.logoPosition,[['left','Left'],['center','Center']],'Header brand alignment.')}</div>
          </div>

          <div class="tsv2-pane" data-theme-pane="effects">
            <div class="tsv2-section-head"><div><span>05 / EXPERIENCE</span><h3>Visual behavior</h3></div><p>These switches intentionally cover effects that can be enforced safely at runtime.</p></div>
            <div class="tsv2-toggle-list">${toggleField('grain','Grain layer',e.grain,'Show the runtime texture canvas.')}${toggleField('scanlines','Scanline overlays',e.scanlines,'Show scanning/noise overlays.')}${toggleField('glitch','Glitch treatments',e.glitch,'Enable duplicated/glitch title treatments.')}${toggleField('cursor','Custom cursor',e.cursor,'Desktop fine-pointer cursor only.')}${toggleField('magnetic','Magnetic interactions',e.magnetic,'Allow button/link magnetic transforms.')}</div>
            ${selectField('motion','Motion profile',e.motion,[['brvtal','BRVTAL / full'],['subtle','Subtle'],['minimal','Minimal'],['reduced','Reduced']],'Reduces CSS motion progressively; OS reduced-motion preference always wins.')}
          </div>

          <div class="tsv2-pane" data-theme-pane="manage">
            <div class="tsv2-section-head"><div><span>06 / MANAGE</span><h3>Theme lifecycle</h3></div><p>Draft safely, duplicate before experiments, then activate when ready.</p></div>
            <div class="tsv2-manage-grid">
              <article><span>ACTIVE THEME</span><strong>${escapeHtml(activeSlug().toUpperCase())}</strong><p>The public API serves this theme under <code>settings.theme</code>.</p></article>
              <article><span>EDITING</span><strong>${escapeHtml((V2.editingSlug || t.slug || '').toUpperCase())}</strong><p>Saving does not change production until Activate is used.</p></article>
              <article><span>MEDIA</span><strong>${(state.themeMedia || []).filter(item => item.type === 'image').length}</strong><p>Image assets currently available to this workspace.</p></article>
            </div>
            <div class="tsv2-manage-actions"><button type="button" class="btn ghost" data-theme-action="duplicate">DUPLICATE THEME</button><button type="button" class="btn ghost" data-theme-action="revert">REVERT SAVED</button><button type="button" class="btn ghost" data-theme-action="preset-concept05">RESET VISUALS TO CONCEPT 05</button></div>
            <div class="tsv2-legacy-note"><strong>LEGACY SETTINGS PRESERVED</strong><p>Existing preloader, responsive, analytics and custom-code values remain stored when this editor saves. They are intentionally not exposed here unless the public runtime can honor them safely.</p></div>
          </div>
        </section>

        <aside class="tsv2-preview" data-preview-mode="${escapeHtml(V2.previewMode)}">
          <div class="tsv2-preview-head"><div><span>LOCAL THEME PREVIEW</span><b>CONCEPT 05 / TOKENS</b></div><div><button type="button" class="${V2.previewMode === 'desktop' ? 'active' : ''}" data-preview-mode="desktop">1440</button><button type="button" class="${V2.previewMode === 'mobile' ? 'active' : ''}" data-preview-mode="mobile">390</button></div></div>
          <div class="tsv2-device"><div class="tsv2-preview-browser"><span data-preview-favicon>B</span><b>brvtal.co / local preview</b></div><div class="tsv2-preview-nav"><div data-preview-brand>BRVTAL</div><div class="tsv2-preview-links"><span>NIGHTS</span><span>ARTISTS</span><span>SOUND</span></div><b>PEREIRA / COLOMBIA</b></div><div class="tsv2-preview-hero"><span>01 / HOME · UNDERGROUND CULTURE</span><h4 data-preview-title>BRVTAL</h4><div data-preview-logo></div><p data-preview-tagline>RAVE TILL GRAVE</p><div class="tsv2-preview-signal"><i>RED / MOTHER</i><i>SIGNAL / GLITCH</i></div></div><div class="tsv2-preview-grid"><i><b>02</b>NIGHTS</i><i><b>03</b>ARTISTS</i><i><b>04</b>SOUND</i></div></div>
          <div class="tsv2-preview-meta"><span id="tsv2-preview-name">${escapeHtml(t.name || '')}</span><code id="tsv2-preview-slug">theme.${escapeHtml(t.slug || '')}</code></div>
        </aside>
      </div>

      <footer class="tsv2-footer"><div><span id="tsv2-footer-state">NO UNSAVED CHANGES</span><small>Activate only after reviewing the preview.</small></div><div><button type="button" class="btn ghost" data-theme-action="revert">REVERT</button><button type="button" class="btn ghost" data-theme-action="save">SAVE DRAFT</button><button type="button" class="btn red" data-theme-action="activate">SAVE & ACTIVATE</button></div></footer>
    </div>`;
  }

  function currentTheme() {
    const t = mergeTheme(state.theme || {});
    t.name = value('name').trim() || t.name || 'THEME';
    t.slug = safeSlug(value('slug') || V2.editingSlug || t.slug || 'theme') || 'theme';
    t.branding = {
      ...(t.branding || {}),
      siteName: value('siteName').trim(),
      tagline: value('tagline').trim(),
      logo: value('logo_custom'),
      mobileLogo: value('mobileLogo_custom'),
      favicon: value('favicon_custom'),
      preloaderLogo: value('preloaderLogo_custom'),
    };
    t.colors = {
      ...(t.colors || {}),
      bg:value('bg'), surface:value('surface'), text:value('text'), muted:value('muted'),
      primary:value('primary'), accent:value('accent'), border:value('border'),
    };
    t.typography = {
      ...(t.typography || {}), display:value('display'), body:value('body'), mono:value('mono'),
      bodySize:value('bodySize'),
    };
    t.navigation = {
      ...(t.navigation || {}), fixed:checked('navFixed'), transparentHero:checked('navTransparent'),
      blur:checked('navBlur'), menuStyle:value('menuStyle'), logoPosition:value('logoPosition'),
      sceneIndicator:t.navigation?.sceneIndicator ?? false, soundToggle:checked('soundToggle'),
    };
    t.effects = {
      ...(t.effects || {}), grain:checked('grain'), scanlines:checked('scanlines'),
      glitch:checked('glitch'), cursor:checked('cursor'), magnetic:checked('magnetic'), motion:value('motion'),
    };
    t.sound = { ...(t.sound || {}), enabled:checked('soundToggle') };
    // Canonical SEO is server-rendered. Preserve legacy theme.seo values
    // without exposing controls that currently have no public authority.
    t.seo = { ...t.seo };
    return t;
  }

  function renderPreview() {
    const root = document.querySelector('[data-theme-studio-v2]');
    if (!root) return;
    const t = currentTheme();
    const c = t.colors || {}, b = t.branding || {}, ty = t.typography || {};
    const preview = root.querySelector('.tsv2-device');
    if (!preview) return;
    preview.style.setProperty('--p-bg', c.bg || '#050505');
    preview.style.setProperty('--p-surface', c.surface || '#0a0b0c');
    preview.style.setProperty('--p-text', c.text || '#f4f5f6');
    preview.style.setProperty('--p-muted', c.muted || '#7d848b');
    preview.style.setProperty('--p-primary', c.primary || '#ff2038');
    preview.style.setProperty('--p-accent', c.accent || '#b6ff00');
    preview.style.setProperty('--p-border', c.border || '#292d31');
    preview.style.setProperty('--p-display', ty.display || 'Arial, sans-serif');
    preview.style.setProperty('--p-body', ty.body || 'Arial, sans-serif');
    preview.style.setProperty('--p-mono', ty.mono || 'monospace');
    ensurePreviewFonts(ty);
    root.querySelectorAll('[data-type-specimen="display"] strong').forEach(node => { node.style.fontFamily = ty.display || 'Arial, sans-serif'; });
    root.querySelectorAll('[data-type-specimen="mono"] strong').forEach(node => { node.style.fontFamily = ty.mono || 'monospace'; });
    const ratio = contrastRatio(c.text || '#F4F1E8', c.bg || '#050505');
    const contrast = root.querySelector('[data-contrast-check]');
    const contrastValue = contrast?.querySelector('[data-contrast-value]');
    const contrastCopy = contrast?.querySelector('[data-contrast-copy]');
    if (contrastValue) contrastValue.textContent = ratio.toFixed(1) + ':1 · ' + (ratio >= 4.5 ? 'PASS' : 'REVIEW');
    if (contrastCopy) contrastCopy.textContent = ratio >= 4.5 ? 'Primary editorial pair meets WCAG AA for normal text.' : 'Increase PAPER / BLACK contrast before activation.';
    contrast?.toggleAttribute('data-low-contrast', ratio < 4.5);
    root.querySelector('[data-preview-brand]').textContent = b.siteName || 'BRVTAL';
    root.querySelector('[data-preview-title]').textContent = b.siteName || 'BRVTAL';
    root.querySelector('[data-preview-tagline]').textContent = b.tagline || 'RAVE TILL GRAVE';
    const logo = imagePath((V2.previewMode === 'mobile' ? b.mobileLogo : '') || b.logo);
    root.querySelector('[data-preview-logo]').innerHTML = logo ? `<img src="${escapeHtml(logo)}" alt="Theme logo preview">` : '<span>LOGO / MEDIA</span>';
    const favicon = imagePath(b.favicon);
    root.querySelector('[data-preview-favicon]').innerHTML = favicon ? `<img src="${escapeHtml(favicon)}" alt="Favicon preview">` : escapeHtml((b.siteName || 'B').slice(0,1));
    root.querySelector('#tsv2-preview-name').textContent = t.name || 'THEME';
    root.querySelector('#tsv2-preview-slug').textContent = 'theme.' + (t.slug || 'theme');
    root.querySelectorAll('.tsv2-color-field').forEach(field => {
      const input = field.querySelector('input:not([type=color])');
      const swatch = field.querySelector('i');
      if (swatch && /^#[0-9a-f]{6}$/i.test(input?.value || '')) swatch.style.background = input.value;
    });
  }

  function snapshot(theme = null) {
    try { return JSON.stringify(theme || currentTheme()); }
    catch (_) { return ''; }
  }

  function updateDirty() {
    const dirty = snapshot() !== V2.baseline;
    const stateEl = document.getElementById('tsv2-dirty-state');
    const footer = document.getElementById('tsv2-footer-state');
    if (stateEl) { stateEl.textContent = dirty ? 'UNSAVED CHANGES' : 'SAVED'; stateEl.className = dirty ? 'dirty' : 'clean'; }
    if (footer) footer.textContent = dirty ? 'UNSAVED CHANGES' : 'NO UNSAVED CHANGES';
    document.querySelector('[data-theme-studio-v2]')?.toggleAttribute('data-dirty', dirty);
    return dirty;
  }

  function render(theme) {
    state.theme = mergeTheme(theme || {});
    const root = document.getElementById('theme-root');
    if (!root) return;
    root.innerHTML = panel(state.theme);
    bind(root);
    renderPreview();
    updateDirty();
  }

  async function loadThemeStudioV2(preferred = '') {
    try {
      const [settingsResponse, mediaResponse] = await Promise.all([req('/settings'), req('/media')]);
      state.themeSettings = settingsResponse.data || [];
      state.themeMedia = mediaResponse.data || [];
    } catch (error) {
      state.themeSettings = [];
      state.themeMedia = [];
      if (window.BRVTALFeedback) BRVTALFeedback.error('Theme Studio could not load: ' + (error?.message || 'ERROR'), 'theme-studio');
    }
    const active = activeSlug();
    const candidate = safeSlug(preferred || V2.editingSlug || active) || active;
    V2.editingSlug = findTheme(candidate) ? candidate : active;
    const theme = findTheme(V2.editingSlug) || mergeTheme(typeof THEME_DEFAULT !== 'undefined' ? THEME_DEFAULT : {});
    state.theme = theme;
    V2.baseline = snapshot(theme);
    render(theme);
  }

  async function persist(activate = false) {
    const theme = currentTheme();
    if (!theme.slug) return;
    const action = activate ? 'Activating theme…' : 'Saving theme draft…';
    if (window.BRVTALFeedback) BRVTALFeedback.progress(action, 'theme-studio-save');
    try {
      await req('/settings', { method:'POST', body:JSON.stringify({ setting_key:'theme.' + theme.slug, setting_value:JSON.stringify(theme), is_json:1 }) });
      if (activate) {
        await req('/settings', { method:'POST', body:JSON.stringify({ setting_key:'theme.active', setting_value:theme.slug, is_json:0 }) });
      }
      V2.editingSlug = theme.slug;
      if (window.BRVTALFeedback) BRVTALFeedback.success(activate ? 'Theme saved and activated.' : 'Theme draft saved.', 'theme-studio-save');
      await loadThemeStudioV2(theme.slug);
    } catch (error) {
      if (window.BRVTALFeedback) BRVTALFeedback.error(error?.message || 'Theme save failed.', 'theme-studio-save');
      else if (typeof showThemeNotice === 'function') showThemeNotice('ERROR · ' + (error?.message || 'SAVE FAILED'), true);
    }
  }

  function switchTheme(slug) {
    const next = safeSlug(slug);
    if (!next || next === V2.editingSlug) return;
    if (updateDirty() && !window.confirm('Discard unsaved Theme Studio changes?')) {
      const select = document.getElementById('tsv2-theme-select');
      if (select) select.value = V2.editingSlug;
      return;
    }
    const theme = findTheme(next);
    if (!theme) return;
    V2.editingSlug = next;
    state.theme = theme;
    V2.baseline = snapshot(theme);
    render(theme);
  }

  function setAsset(field, path) {
    const input = document.getElementById('th_' + field + '_custom');
    if (!input) return;
    input.value = String(path || '');
    const card = input.closest('[data-theme-asset]');
    const preview = card?.querySelector('.tsv2-asset-preview');
    const label = card?.querySelector('[data-theme-asset-path]');
    const clear = card?.querySelector('[data-clear-media]');
    if (label) label.textContent = path || 'Not selected';
    if (preview) {
      const src = imagePath(path);
      preview.innerHTML = src ? `<img src="${escapeHtml(src)}" alt="Selected media preview" loading="lazy">` : '<span>NO ASSET</span>';
    }
    if (clear) clear.disabled = !path;
    renderPreview();
    updateDirty();
  }

  function closePicker() {
    document.getElementById('tsv2-media-picker')?.remove();
    if (V2.lastFocus?.isConnected) V2.lastFocus.focus();
    V2.pickerField = '';
  }

  function openPicker(field, trigger) {
    closePicker();
    V2.pickerField = field;
    V2.lastFocus = trigger || document.activeElement;
    const images = (state.themeMedia || []).filter(item => item.type === 'image');
    const overlay = document.createElement('div');
    overlay.id = 'tsv2-media-picker';
    overlay.className = 'tsv2-picker-overlay';
    overlay.innerHTML = `<div class="tsv2-picker" role="dialog" aria-modal="true" aria-labelledby="tsv2-picker-title"><header><div><span>MEDIA LIBRARY</span><h3 id="tsv2-picker-title">Choose image</h3></div><button type="button" data-picker-close aria-label="Close media picker">×</button></header><label class="tsv2-picker-search"><span>SEARCH</span><input type="search" placeholder="Title or file path" autocomplete="off"></label><div class="tsv2-picker-grid">${images.length ? images.map(item => { const src = imagePath(item.file_path); return `<button type="button" class="tsv2-media-card" data-media-path="${escapeHtml(item.file_path || '')}" data-media-search="${escapeHtml(String(item.title || '') + ' ' + String(item.file_path || ''))}"><span>${src ? `<img src="${escapeHtml(src)}" alt="" loading="lazy">` : 'NO IMAGE'}</span><strong>${escapeHtml(item.title || 'Untitled image')}</strong><code>${escapeHtml(item.file_path || '')}</code></button>`; }).join('') : '<p class="tsv2-picker-empty">No image assets are available in Media yet.</p>'}</div></div>`;
    document.body.appendChild(overlay);
    const search = overlay.querySelector('input[type=search]');
    overlay.querySelector('[data-picker-close]').addEventListener('click', closePicker);
    overlay.addEventListener('click', event => { if (event.target === overlay) closePicker(); });
    overlay.querySelectorAll('[data-media-path]').forEach(button => button.addEventListener('click', () => { setAsset(field, button.dataset.mediaPath || ''); closePicker(); }));
    search?.addEventListener('input', () => {
      const query = search.value.trim().toLowerCase();
      overlay.querySelectorAll('[data-media-search]').forEach(card => { card.hidden = Boolean(query) && !String(card.dataset.mediaSearch || '').toLowerCase().includes(query); });
    });
    overlay.addEventListener('keydown', event => {
      if (event.key === 'Escape') { event.preventDefault(); closePicker(); return; }
      if (event.key !== 'Tab') return;
      const focusable = [...overlay.querySelectorAll('button:not([disabled]),input:not([disabled])')].filter(el => !el.hidden);
      if (!focusable.length) return;
      const first = focusable[0], last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    });
    setTimeout(() => search?.focus(), 0);
  }

  function applyConcept05Section(theme, section) {
    theme[section] = { ...theme[section], ...CONCEPT05_VISUAL[section] };
  }

  function resetGroup(group) {
    const next = currentTheme();
    const section = group === 'palette' ? 'colors' : group === 'type' ? 'typography' : '';
    if (!section) return;
    applyConcept05Section(next, section);
    state.theme = next;
    render(next);
    tab(group);
  }

  function preset(kind) {
    if (kind !== 'concept05') return;
    const next = mergeTheme(currentTheme());
    ['colors','typography','navigation','effects'].forEach(section => applyConcept05Section(next, section));
    next.sound = { ...next.sound, enabled:true };
    if ((next.slug || 'core') === 'core') next.name = 'BRVTAL CONCEPT 05';
    state.theme = next;
    render(next);
  }

  function duplicate() {
    const next = currentTheme();
    const suffix = Date.now().toString().slice(-4);
    next.slug = safeSlug((next.slug || 'theme') + '-' + suffix);
    next.name = (next.name || 'THEME') + ' COPY';
    state.theme = next;
    V2.editingSlug = next.slug;
    V2.baseline = '';
    render(next);
  }

  function tab(id) {
    const root = document.querySelector('[data-theme-studio-v2]');
    if (!root) return;
    root.querySelectorAll('[data-theme-tab]').forEach(button => button.classList.toggle('active', button.dataset.themeTab === id));
    root.querySelectorAll('[data-theme-pane]').forEach(pane => pane.classList.toggle('active', pane.dataset.themePane === id));
  }

  function bind(root) {
    root.querySelectorAll('[data-theme-tab]').forEach(button => button.addEventListener('click', () => tab(button.dataset.themeTab)));
    root.querySelector('#tsv2-theme-select')?.addEventListener('change', event => switchTheme(event.target.value));
    root.querySelectorAll('[data-pick-media]').forEach(button => button.addEventListener('click', () => openPicker(button.dataset.pickMedia, button)));
    root.querySelectorAll('[data-clear-media]').forEach(button => button.addEventListener('click', () => setAsset(button.dataset.clearMedia, '')));
    root.querySelectorAll('[data-preview-mode]').forEach(button => button.addEventListener('click', () => {
      V2.previewMode = button.dataset.previewMode || 'desktop';
      root.querySelector('.tsv2-preview')?.setAttribute('data-preview-mode', V2.previewMode);
      root.querySelectorAll('[data-preview-mode]').forEach(item => item.classList.toggle('active', item.dataset.previewMode === V2.previewMode));
      renderPreview();
    }));
    root.querySelectorAll('[data-theme-reset]').forEach(button => button.addEventListener('click', () => resetGroup(button.dataset.themeReset || '')));
    root.querySelectorAll('[data-theme-action]').forEach(button => button.addEventListener('click', () => {
      const action = button.dataset.themeAction;
      if (action === 'save') persist(false);
      if (action === 'activate') persist(true);
      if (action === 'revert') loadThemeStudioV2(V2.editingSlug);
      if (action === 'duplicate') duplicate();
      if (action === 'preset-concept05') preset('concept05');
    }));
    root.addEventListener('input', event => {
      const target = event.target;
      if (!(target instanceof HTMLInputElement || target instanceof HTMLSelectElement)) return;
      if (target.id.endsWith('_picker')) {
        const base = target.id.replace('_picker', '');
        const paired = document.getElementById(base);
        if (paired) paired.value = target.value.toUpperCase();
      } else if (/^th_(?:bg|surface|text|muted|primary|accent|border)$/.test(target.id) && /^#[0-9a-f]{6}$/i.test(target.value)) {
        const picker = document.getElementById(target.id + '_picker');
        if (picker) picker.value = target.value;
      }
      renderPreview();
      updateDirty();
    });
    root.addEventListener('change', () => { renderPreview(); updateDirty(); });
  }

  window.BRVTALThemeStudioV2 = {
    load:loadThemeStudioV2,
    save:() => persist(false),
    activate:() => persist(true),
    tab,
    openMediaPicker:openPicker,
    closeMediaPicker:closePicker,
    currentTheme,
  };

  // Replace the legacy Theme Studio entry points while preserving the same single-shell route.
  window.loadThemeStudio = () => loadThemeStudioV2();
  window.saveTheme = () => persist(false);
  window.activateTheme = () => persist(true);
  window.duplicateTheme = duplicate;
  window.loadThemePreset = preset;

  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && document.getElementById('tsv2-media-picker')) closePicker();
  });
})();
