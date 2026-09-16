(() => {
  'use strict';

  const esc = value => String(value ?? '')
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;').replace(/'/g,'&#039;');

  function path(value) {
    const raw = String(value || '').trim();
    if (!raw) return '';
    if (/^https?:\/\//i.test(raw) || raw.startsWith('/')) return raw;
    return '/' + raw.replace(/^\.?\//,'').replace(/^\/+/, '');
  }

  function updateWordmark(value) {
    const clean = String(value || '').trim();
    state.theme = state.theme || {};
    state.theme.branding = { ...(state.theme.branding || {}), wordmark:clean };
    const root = document.querySelector('[data-theme-studio-v2]');
    const input = root?.querySelector('#th_wordmark_custom');
    if (input && input.value !== clean) input.value = clean;
    const code = root?.querySelector('[data-theme-wordmark-path]');
    if (code) code.textContent = clean || 'Not selected';
    const preview = root?.querySelector('[data-theme-wordmark-preview]');
    const src = path(clean);
    if (preview) preview.innerHTML = src ? `<img src="${esc(src)}" alt="BRVTAL wordmark preview" loading="lazy">` : '<span>TEXT FALLBACK / BRVTAL</span>';
    const liveBrand = root?.querySelector('[data-preview-brand]');
    if (liveBrand) liveBrand.innerHTML = src ? `<img class="tsv2-wordmark-preview" src="${esc(src)}" alt="BRVTAL">` : esc(state.theme?.branding?.siteName || 'BRVTAL');
    input?.dispatchEvent(new Event('change',{bubbles:true}));
  }

  function closePicker() {
    document.getElementById('tsv2-wordmark-picker')?.remove();
  }

  function openPicker() {
    closePicker();
    const media = (state.themeMedia || []).filter(item => item.type === 'image');
    const overlay = document.createElement('div');
    overlay.id = 'tsv2-wordmark-picker';
    overlay.className = 'tsv2-picker-overlay';
    overlay.innerHTML = `<div class="tsv2-picker" role="dialog" aria-modal="true" aria-labelledby="tsv2-wordmark-picker-title"><header><div><span>MEDIA LIBRARY</span><h3 id="tsv2-wordmark-picker-title">Choose BRVTAL wordmark</h3><p>SVG is supported as an external/public asset path. Raw inline SVG markup is never injected.</p></div><button type="button" data-wordmark-close aria-label="Close media picker">×</button></header><label class="tsv2-picker-search"><span>SEARCH</span><input type="search" placeholder="Title or file path" autocomplete="off"></label><div class="tsv2-picker-grid">${media.length ? media.map(item => {const src=path(item.file_path);return `<button type="button" class="tsv2-media-card" data-wordmark-media="${esc(item.file_path || '')}" data-wordmark-search="${esc((item.title || '')+' '+(item.file_path || ''))}"><span>${src?`<img src="${esc(src)}" alt="" loading="lazy">`:'NO IMAGE'}</span><strong>${esc(item.title || 'Untitled image')}</strong><code>${esc(item.file_path || '')}</code></button>`;}).join('') : '<p class="tsv2-picker-empty">No image assets are available in Media yet.</p>'}</div></div>`;
    document.body.appendChild(overlay);
    const search = overlay.querySelector('input[type=search]');
    overlay.querySelector('[data-wordmark-close]')?.addEventListener('click',closePicker);
    overlay.addEventListener('click',event=>{if(event.target===overlay)closePicker();});
    overlay.querySelectorAll('[data-wordmark-media]').forEach(button=>button.addEventListener('click',()=>{updateWordmark(button.dataset.wordmarkMedia||'');closePicker();}));
    search?.addEventListener('input',()=>{const q=search.value.toLowerCase();overlay.querySelectorAll('[data-wordmark-search]').forEach(card=>{card.hidden=Boolean(q)&&!String(card.dataset.wordmarkSearch||'').toLowerCase().includes(q);});});
    overlay.addEventListener('keydown',event=>{if(event.key==='Escape'){event.preventDefault();closePicker();}});
    search?.focus();
  }

  function injectWordmark(root) {
    const assets = root.querySelector('[data-theme-pane="brand"] .tsv2-assets');
    if (!assets || assets.querySelector('[data-theme-wordmark]')) return;
    const current = String(state.theme?.branding?.wordmark || '');
    const src = path(current);
    const card = document.createElement('div');
    card.className = 'tsv2-asset tsv2-wordmark-card';
    card.dataset.themeWordmark = '1';
    card.innerHTML = `<div class="tsv2-asset-copy"><strong>BRVTAL wordmark</strong><small>Dedicated text-logo asset for the public header and loader. Use an external/public SVG or image path; text BRVTAL remains the fail-safe fallback.</small><code data-theme-wordmark-path>${esc(current || 'Not selected')}</code></div><div class="tsv2-asset-preview" data-theme-wordmark-preview>${src?`<img src="${esc(src)}" alt="BRVTAL wordmark preview" loading="lazy">`:'<span>TEXT FALLBACK / BRVTAL</span>'}</div><input id="th_wordmark_custom" value="${esc(current)}" placeholder="/uploads/.../wordmark.svg or https://..."><div class="tsv2-asset-actions"><button type="button" class="btn ghost" data-wordmark-pick>CHOOSE FROM MEDIA</button><button type="button" class="btn ghost" data-wordmark-clear>CLEAR</button></div>`;
    assets.prepend(card);
    card.querySelector('#th_wordmark_custom')?.addEventListener('input',event=>{
      state.theme = state.theme || {};
      state.theme.branding = { ...(state.theme.branding || {}), wordmark:String(event.target.value || '').trim() };
      const code=card.querySelector('[data-theme-wordmark-path]');if(code)code.textContent=event.target.value||'Not selected';
      const preview=card.querySelector('[data-theme-wordmark-preview]');const image=path(event.target.value);if(preview)preview.innerHTML=image?`<img src="${esc(image)}" alt="BRVTAL wordmark preview" loading="lazy">`:'<span>TEXT FALLBACK / BRVTAL</span>';
      setTimeout(()=>{const brand=root.querySelector('[data-preview-brand]');if(brand)brand.innerHTML=image?`<img class="tsv2-wordmark-preview" src="${esc(image)}" alt="BRVTAL">`:esc(state.theme?.branding?.siteName||'BRVTAL');},0);
    });
    card.querySelector('[data-wordmark-pick]')?.addEventListener('click',openPicker);
    card.querySelector('[data-wordmark-clear]')?.addEventListener('click',()=>updateWordmark(''));
  }

  function restructureOwnership(root) {
    const seoTab = root.querySelector('[data-theme-tab="seo"]');
    const seoPane = root.querySelector('[data-theme-pane="seo"]');
    if (seoTab) seoTab.hidden = true;
    if (seoPane) seoPane.hidden = true;

    const manage = root.querySelector('[data-theme-pane="manage"]');
    if (!manage || manage.querySelector('[data-theme-config-map]')) return;
    const map = document.createElement('div');
    map.className = 'tsv2-config-map';
    map.dataset.themeConfigMap = '1';
    map.innerHTML = `<div class="tsv2-section-head"><div><span>CONFIGURATION MAP</span><h3>Ownership & compatibility</h3></div><p>Every option has one primary home. Stored legacy values remain intact but are not presented as working controls when the public runtime ignores them.</p></div><div class="tsv2-manage-grid"><article><span>LIVE THEME</span><strong>BRAND / PALETTE / TYPE / NAV / EXPERIENCE</strong><p>These controls map directly to current public runtime behavior.</p></article><article><span>MOVED TO SETTINGS</span><strong>SEO / ANALYTICS</strong><p>Canonical SEO and analytics are global behavior, not visual-theme concerns.</p></article><article><span>PRESERVED / UNWIRED</span><strong>RESPONSIVE SCALE / ADVANCED FX / PRELOADER BEHAVIOR</strong><p>Legacy JSON remains round-trippable. It stays out of the normal editor until a real runtime consumer exists.</p></article></div>`;
    manage.appendChild(map);
  }

  function inject(root) {
    if (!root) return;
    injectWordmark(root);
    restructureOwnership(root);
    const current = String(state.theme?.branding?.wordmark || '');
    if (current) {
      const brand = root.querySelector('[data-preview-brand]');
      const src = path(current);
      const preview = brand?.querySelector('.tsv2-wordmark-preview');
      if (brand && src && (!preview || preview.getAttribute('src') !== src)) brand.innerHTML = `<img class="tsv2-wordmark-preview" src="${esc(src)}" alt="BRVTAL">`;
    }
  }

  const observer = new MutationObserver(() => inject(document.querySelector('[data-theme-studio-v2]')));
  observer.observe(document.documentElement,{childList:true,subtree:true});
  inject(document.querySelector('[data-theme-studio-v2]'));
})();
