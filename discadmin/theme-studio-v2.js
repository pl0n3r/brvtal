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
    String(value || '').trim().toLowerCase().replace(/[^a-z0-9_-]+/g, '-')
  ).slice(0, 60);
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
    const b = t.branding || {}, c = t.colors || {}, ty = t.typography || {}, n = t.navigation || {}, e = t.effects || {}, s = t.sound || {}, seo = t.seo || {};
    const isActive = V2.editingSlug === activeSlug();
    return `<div class="theme-v2" data-theme-studio-v2>
      <header class="tsv2-toolbar">
        <div class="tsv2-title"><span>APPEARANCE / THEME SYSTEM</span><h2>THEME STUDIO</h2><p>Design the public BRVTAL experience with production-wired controls and assets from Media.</p></div>
        <div class="tsv2-theme-switcher"><label for="tsv2-theme-select">EDITING THEME</label><select id="tsv2-theme-select">${themeSelector()}</select><span class="tsv2-live ${isActive ? 'live' : ''}" id="tsv2-live-state">${isActive ? 'LIVE NOW' : 'NOT LIVE'}</span></div>
      </header>

      <div class="tsv2-statusbar"><span id="tsv2-dirty-state" class="clean">SAVED</span><span>Save stores a draft. Activate publishes this theme to the public site.</span></div>

      <div class="tsv2-layout">
        <aside class="tsv2-tabs" aria-label="Theme Studio sections">
          ${[['brand','BRAND'],['palette','PALETTE'],['type','TYPE'],['navigation','NAVIGATION'],['effects','EXPERIENCE'],['seo','SEO'],['manage','MANAGE']].map(([id,label],index) => `<button type="button" class="${index === 0 ? 'active' : ''}" data-theme-tab="${id}"><span>0${index + 1}</span>${label}</button>`).join('')}
        </aside>

        <section class="tsv2-editor">
          <div class="tsv2-pane active" data-theme-pane="brand">
            <div class="tsv2-section-head"><div><span>01 / BRAND</span><h3>Identity</h3></div><p>These values drive the public wordmark, hero identity and browser assets.</p></div>
            <div class="tsv2-grid two">${textField('name','Theme name',t.name,'Internal label shown only in DISCADMIN.')}${textField('slug','Theme slug',t.slug,'Stable identifier used for activation.','autocomplete="off" spellcheck="false"')}</div>
            <div class="tsv2-grid two">${textField('siteName','Public site name',b.siteName,'Applied to visible BRVTAL name elements.')}${textField('tagline','Public tagline',b.tagline,'Applied to the header and hero identity.')}</div>
            <div class="tsv2-assets">${assetField('logo','Main logo',b.logo,'Desktop/public primary logo.')}${assetField('mobileLogo','Mobile logo',b.mobileLogo,'Optional compact logo below 900px. Falls back to Main logo.')}${assetField('favicon','Favicon',b.favicon,'Browser tab icon. Choose a square asset when possible.')}${assetField('preloaderLogo','Preloader logo',b.preloaderLogo,'Reserved branding asset. Main logo remains the safe fallback.')}</div>
          </div>

          <div class="tsv2-pane" data-theme-pane="palette">
            <div class="tsv2-section-head"><div><span>02 / PALETTE</span><h3>Color system</h3></div><p>Every color below maps to a live public CSS token.</p></div>
            <div class="tsv2-grid two">${colorField('bg','Background',c.bg,'Main canvas.')}${colorField('surface','Surface',c.surface,'Panels and non-transparent navigation.')}${colorField('text','Text',c.text,'Primary foreground.')}${colorField('muted','Muted',c.muted,'Secondary copy and metadata.')}${colorField('primary','Primary',c.primary,'BRVTAL signal/red role.')}${colorField('accent','Accent',c.accent,'Acid/highlight role.')}${colorField('border','Border',c.border,'Lines and separators.')}</div>
            <div class="tsv2-palette-strip" aria-label="Current palette">${[c.bg,c.surface,c.text,c.muted,c.primary,c.accent,c.border].map(x => `<i style="background:${escapeHtml(x || '#000')}"></i>`).join('')}</div>
          </div>

          <div class="tsv2-pane" data-theme-pane="type">
            <div class="tsv2-section-head"><div><span>03 / TYPE</span><h3>Typography</h3></div><p>Use installed/system font stacks. Invalid CSS values are ignored by the public runtime.</p></div>
            <div class="tsv2-grid two">${textField('display','Display font stack',ty.display,'Headlines and high-impact labels.')}${textField('body','Body font stack',ty.body,'General interface/body copy.')}${textField('mono','Mono font stack',ty.mono,'Metadata and technical labels.')}${textField('h1','Hero title scale',ty.h1,'Any valid CSS font-size such as clamp(...).')}${textField('bodySize','Body size',ty.bodySize,'Example: 16px.')}${textField('tracking','Display tracking',ty.tracking,'Example: -0.04em.')}</div>
          </div>

          <div class="tsv2-pane" data-theme-pane="navigation">
            <div class="tsv2-section-head"><div><span>04 / NAVIGATION</span><h3>Header & menu</h3></div><p>Only controls with a public runtime mapping are exposed here.</p></div>
            <div class="tsv2-toggle-list">${toggleField('navFixed','Fixed header',n.fixed,'Keep navigation pinned while scrolling.')}${toggleField('navTransparent','Transparent over hero',n.transparentHero,'Use the visual hero behind navigation.')}${toggleField('navBlur','Header backdrop blur',n.blur,'Apply glass blur when supported.')}${toggleField('sceneIndicator','Scene indicator',n.sceneIndicator,'Show the CORE / SIGNAL scene marker.')}${toggleField('soundToggle','Sound control',n.soundToggle && s.enabled !== false,'Show the user-initiated sound control.')}</div>
            <div class="tsv2-grid two">${selectField('menuStyle','Menu presentation',n.menuStyle,[['fullscreen','Fullscreen'],['dropdown','Dropdown'],['slide','Slide panel']],'Applied to the public menu container.')}${selectField('logoPosition','Brand position',n.logoPosition,[['left','Left'],['center','Center']],'Header brand alignment.')}</div>
          </div>

          <div class="tsv2-pane" data-theme-pane="effects">
            <div class="tsv2-section-head"><div><span>05 / EXPERIENCE</span><h3>Visual behavior</h3></div><p>These switches intentionally cover effects that can be enforced safely at runtime.</p></div>
            <div class="tsv2-toggle-list">${toggleField('grain','Grain layer',e.grain,'Show the runtime texture canvas.')}${toggleField('scanlines','Scanline overlays',e.scanlines,'Show scanning/noise overlays.')}${toggleField('glitch','Glitch treatments',e.glitch,'Enable duplicated/glitch title treatments.')}${toggleField('cursor','Custom cursor',e.cursor,'Desktop fine-pointer cursor only.')}${toggleField('magnetic','Magnetic interactions',e.magnetic,'Allow button/link magnetic transforms.')}</div>
            ${selectField('motion','Motion profile',e.motion,[['brvtal','BRVTAL / full'],['subtle','Subtle'],['minimal','Minimal'],['reduced','Reduced']],'Reduces CSS motion progressively; OS reduced-motion preference always wins.')}
          </div>

          <div class="tsv2-pane" data-theme-pane="seo">
            <div class="tsv2-section-head"><div><span>06 / SEO</span><h3>Global presentation</h3></div><p>Theme SEO is a client-side visual fallback; canonical server SEO remains authoritative for entity pages.</p></div>
            <div class="tsv2-grid">${textField('seoTitle','Default site title',seo.siteTitle,'Used on the public home when configured.')}${textField('seoDescription','Default description',seo.description,'Public home meta description fallback.')}${assetField('ogImage','Default share image',seo.ogImage,'Choose the default social preview artwork from Media.')}</div>
          </div>

          <div class="tsv2-pane" data-theme-pane="manage">
            <div class="tsv2-section-head"><div><span>07 / MANAGE</span><h3>Theme lifecycle</h3></div><p>Draft safely, duplicate before experiments, then activate when ready.</p></div>
            <div class="tsv2-manage-grid">
              <article><span>ACTIVE THEME</span><strong>${escapeHtml(activeSlug().toUpperCase())}</strong><p>The public API serves this theme under <code>settings.theme</code>.</p></article>
              <article><span>EDITING</span><strong>${escapeHtml((V2.editingSlug || t.slug || '').toUpperCase())}</strong><p>Saving does not change production until Activate is used.</p></article>
              <article><span>MEDIA</span><strong>${(state.themeMedia || []).filter(item => item.type === 'image').length}</strong><p>Image assets currently available to this workspace.</p></article>
            </div>
            <div class="tsv2-manage-actions"><button type="button" class="btn ghost" data-theme-action="duplicate">DUPLICATE THEME</button><button type="button" class="btn ghost" data-theme-action="revert">REVERT SAVED</button><button type="button" class="btn ghost" data-theme-action="preset-core">LOAD CORE PRESET</button><button type="button" class="btn ghost" data-theme-action="preset-genesis">LOAD GENESIS PRESET</button></div>
            <div class="tsv2-legacy-note"><strong>LEGACY SETTINGS PRESERVED</strong><p>Existing preloader, responsive, analytics and custom-code values remain stored when this editor saves. They are intentionally not exposed here unless the public runtime can honor them safely.</p></div>
          </div>
        </section>

        <aside class="tsv2-preview" data-preview-mode="${escapeHtml(V2.previewMode)}">
          <div class="tsv2-preview-head"><div><span>LIVE PREVIEW</span><b>PUBLIC TOKENS</b></div><div><button type="button" class="${V2.previewMode === 'desktop' ? 'active' : ''}" data-preview-mode="desktop">DESKTOP</button><button type="button" class="${V2.previewMode === 'mobile' ? 'active' : ''}" data-preview-mode="mobile">MOBILE</button></div></div>
          <div class="tsv2-device"><div class="tsv2-preview-nav"><div data-preview-brand>BRVTAL</div><span data-preview-tagline>RAVE TILL GRAVE</span><b>MENU +</b></div><div class="tsv2-preview-hero"><span>BRVTAL / SYSTEM</span><h4 data-preview-title>BRVTAL</h4><div data-preview-logo></div><p data-preview-copy>THE EXPERIENCE IS THE INTERFACE.</p><button type="button">ENTER EXPERIENCE ↗</button></div><div class="tsv2-preview-grid"><i></i><i></i><i></i></div></div>
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
      h1:value('h1'), bodySize:value('bodySize'), tracking:value('tracking'),
    };
    t.navigation = {
      ...(t.navigation || {}), fixed:checked('navFixed'), transparentHero:checked('navTransparent'),
      blur:checked('navBlur'), menuStyle:value('menuStyle'), logoPosition:value('logoPosition'),
      sceneIndicator:checked('sceneIndicator'), soundToggle:checked('soundToggle'),
    };
    t.effects = {
      ...(t.effects || {}), grain:checked('grain'), scanlines:checked('scanlines'),
      glitch:checked('glitch'), cursor:checked('cursor'), magnetic:checked('magnetic'), motion:value('motion'),
    };
    t.sound = { ...(t.sound || {}), enabled:checked('soundToggle') };
    t.seo = {
      ...(t.seo || {}), siteTitle:value('seoTitle').trim(), description:value('seoDescription').trim(),
      ogImage:value('ogImage_custom'),
    };
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
    root.querySelector('[data-preview-brand]').textContent = b.siteName || 'BRVTAL';
    root.querySelector('[data-preview-title]').textContent = b.siteName || 'BRVTAL';
    root.querySelector('[data-preview-tagline]').textContent = b.tagline || 'RAVE TILL GRAVE';
    const logo = imagePath((V2.previewMode === 'mobile' ? b.mobileLogo : '') || b.logo);
    root.querySelector('[data-preview-logo]').innerHTML = logo ? `<img src="${escapeHtml(logo)}" alt="Theme logo preview">` : '<span>LOGO / MEDIA</span>';
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

  function preset(kind) {
    let next = mergeTheme(typeof THEME_DEFAULT !== 'undefined' ? THEME_DEFAULT : state.theme || {});
    if (kind === 'genesis') {
      next.name = 'GENESIS';
      next.slug = 'genesis';
      next.branding = { ...(next.branding || {}), tagline:'GENESIS / BRVTAL' };
      next.colors = { ...(next.colors || {}), primary:'#B6FF00', accent:'#B6FF00', border:'#343B2B' };
      next.effects = { ...(next.effects || {}), glitch:true };
    }
    state.theme = next;
    V2.editingSlug = next.slug || kind;
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
    root.querySelectorAll('[data-theme-action]').forEach(button => button.addEventListener('click', () => {
      const action = button.dataset.themeAction;
      if (action === 'save') persist(false);
      if (action === 'activate') persist(true);
      if (action === 'revert') loadThemeStudioV2(V2.editingSlug);
      if (action === 'duplicate') duplicate();
      if (action === 'preset-core') preset('core');
      if (action === 'preset-genesis') preset('genesis');
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
