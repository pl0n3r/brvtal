/* Artist profile Hero editor; never changes the Home artist-card crop. */
window.BRVTALArtistHeroPreview = (() => {
  'use strict';
  const defaults = {zoom:1,x:50,y:50};
  const viewports = ['desktop','mobile'];
  const controls = ['zoom','x','y'];

  function crop(viewport) {
    const output = {};
    for (const key of controls) {
      const input = document.getElementById('f_hero_' + viewport + '_' + key);
      const raw = Number(input?.value ?? defaults[key]);
      const min = key === 'zoom' ? 0.8 : 0;
      const max = key === 'zoom' ? 2.2 : 100;
      output[key] = Number.isFinite(raw) ? Math.min(max,Math.max(min,raw)) : defaults[key];
    }
    return output;
  }

  function values() {
    return Object.fromEntries(viewports.map(viewport => [viewport,crop(viewport)]));
  }

  function safeImageUrl(value) {
    const raw = String(value ?? '').trim();
    if (!raw || /[<>"'\x60\\\x00-\x1f]/.test(raw)) return '';
    const local = raw.startsWith('/');
    if (local && (!raw.startsWith('/uploads/') || raw.startsWith('//'))) return '';
    if (!local && !/^https?:\/\//i.test(raw)) return '';

    let url;
    try { url = new URL(raw, window.location.origin); }
    catch (_) { return ''; }
    if (!['http:', 'https:'].includes(url.protocol)) return '';
    if (url.username || url.password) return '';
    if (local && (url.origin !== window.location.origin || !url.pathname.startsWith('/uploads/'))) return '';
    return url.href;
  }

  function officialUrl(value) {
    try {
      const url = new URL(String(value || '').trim());
      if (url.protocol !== 'https:' || !['instagram.com','www.instagram.com'].includes(url.hostname)
        || url.username || url.password || url.port) return '';
      const username = url.pathname.replace(/^\/+|\/+$/g, '').toLowerCase();
      if (!/^[a-z0-9._]{1,30}$/.test(username)
        || ['p','reel','stories','explore'].includes(username)) return '';
      return '/api/instagram-profile-photo.php?username=' + encodeURIComponent(username);
    } catch (_) { return ''; }
  }

  function syncImage(root, frame) {
    const img = root.querySelector('[data-hero-preview-img]');
    if (!img) return;
    const cms = safeImageUrl(document.getElementById('f_photo')?.value);
    const safeUrl = root.dataset.igPreview || cms;
    if (img.src !== safeUrl) {
      if (safeUrl) img.src = safeUrl;
      else img.removeAttribute('src');
    }
    const card = root.querySelector('[data-artist-card-preview-img]');
    if (card && card.src !== safeUrl) {
      if (safeUrl) card.src = safeUrl;
      else card.removeAttribute('src');
    }
    const focus = frame.x + '% ' + frame.y + '%';
    img.style.objectPosition = focus;
    img.style.transformOrigin = focus;
    img.style.transform = 'scale(' + frame.zoom + ')';
  }

  function syncControls(root, active) {
    for (const viewport of viewports) {
      const panel = root.querySelector('[data-hero-controls="' + viewport + '"]');
      if (panel) panel.hidden = viewport !== active;
      const button = root.querySelector('[data-hero-switch="' + viewport + '"]');
      if (button) button.setAttribute('aria-pressed',String(viewport === active));
      const values = crop(viewport);
      for (const key of controls) {
        const id = 'f_hero_' + viewport + '_' + key;
        const counter = root.querySelector('[data-hero-output="' + id + '"]');
        if (counter) {
          const value = key === 'zoom' ? Math.round(values[key] * 100) : values[key];
          counter.textContent = value + '%';
        }
      }
    }
  }

  function sync(root) {
    if (!root?.isConnected) return;
    const viewport = root.dataset.viewport === 'mobile' ? 'mobile' : 'desktop';
    syncImage(root,crop(viewport));
    const name = root.querySelector('[data-hero-preview-name]');
    if (name) name.textContent = document.getElementById('f_name')?.value || 'ARTISTA';
    const cardName = root.querySelector('[data-artist-card-preview-name]');
    if (cardName) cardName.textContent = document.getElementById('f_name')?.value || 'ARTISTA';
    const member = document.getElementById('f_is_collective_member')?.checked === true;
    const label = member ? 'MIEMBRO DEL COLECTIVO BRVTAL' : 'ARTISTA INVITADO';
    for (const selector of ['[data-hero-preview-member]','[data-artist-card-preview-member]']) {
      const node = root.querySelector(selector);
      if (node) node.textContent = label;
    }
    syncControls(root,viewport);
  }


  function photoImportError(code, fallback) {
    const value = String(code || '').toUpperCase();
    if (/URL|HTTPS|SCHEME|HOST|PRIVATE|LOCAL|DNS|REDIRECT/.test(value)) return 'Enlace no válido. Pega una URL HTTPS pública de imagen.';
    if (/SIZE|LARGE|LIMIT|BIG/.test(value)) return 'La imagen es demasiado grande (máximo 8 MB).';
    if (/MIME|TYPE|IMAGE|FORMAT|CONTENT/.test(value)) return 'El enlace no contiene una imagen JPEG, PNG o WebP válida.';
    if (/TIMEOUT|CONNECT|NETWORK|FETCH|REMOTE|HTTP|UNREACHABLE/.test(value)) return 'El sitio no respondió. Inténtalo de nuevo.';
    return fallback || 'No se pudo importar la foto. La imagen anterior se conserva.';
  }

  async function importPhoto(root, form, button) {
    const urlInput = form.querySelector('#artistPhotoImportUrl');
    const status = form.querySelector('#artistPhotoImportStatus');
    const photo = form.querySelector('#f_photo');
    if (!urlInput || !status || !photo || button.disabled) return;
    const raw = String(urlInput.value || '').trim();
    try {
      const parsed = new URL(raw);
      if (parsed.protocol !== 'https:' || parsed.username || parsed.password) throw new Error('INVALID_URL');
    } catch (_) {
      status.dataset.error = 'true';
      status.textContent = photoImportError('INVALID_URL');
      return;
    }
    button.disabled = true;
    status.dataset.error = 'false';
    status.textContent = 'Descargando y registrando la imagen en Media…';
    try {
      let token = (typeof csrf !== 'undefined' && csrf) ? csrf : '';
      if (!token) {
        const authResponse = await fetch('/api/auth',{credentials:'same-origin',cache:'no-store'});
        const auth = await authResponse.json();
        if (!authResponse.ok || !auth.authenticated || !auth.csrf) throw new Error('AUTH_REQUIRED');
        token = auth.csrf;
      }
      const downloaded = await fetch('/api/media-import-url.php',{
        method:'POST',credentials:'same-origin',cache:'no-store',
        headers:{'Content-Type':'application/json','X-CSRF-Token':token},
        body:JSON.stringify({url:raw})
      });
      if (!downloaded.ok) {
        const error = await downloaded.json().catch(() => ({}));
        throw new Error(error.error || ('HTTP_' + downloaded.status));
      }
      const mime = (downloaded.headers.get('content-type') || '').split(';')[0].trim().toLowerCase();
      const extensions = {'image/jpeg':'jpg','image/png':'png','image/webp':'webp'};
      if (!extensions[mime]) throw new Error('INVALID_IMAGE_TYPE');
      const blob = await downloaded.blob();
      if (!blob.size || blob.size > 8 * 1024 * 1024) throw new Error('IMAGE_SIZE_LIMIT');
      const file = new File([blob],'importada.' + extensions[mime],{type:mime});
      const formData = new FormData();
      formData.append('file',file);
      formData.append('title','Foto importada');
      const uploaded = await fetch('/api/media-library.php?action=upload',{
        method:'POST',credentials:'same-origin',cache:'no-store',
        headers:{'X-CSRF-Token':token},body:formData
      });
      const payload = await uploaded.json().catch(() => ({}));
      if (!uploaded.ok || payload.ok !== true) throw new Error(payload.error || ('HTTP_' + uploaded.status));
      const path = payload.data && payload.data.file_path;
      if (typeof path !== 'string' || !path.startsWith('/uploads/')) throw new Error('INVALID_FILE_PATH');
      if (!form.isConnected || !root.isConnected) return;
      photo.value = path;
      root.dataset.igPreview = '';
      photo.dispatchEvent(new Event('input',{bubbles:true}));
      photo.dispatchEvent(new Event('change',{bubbles:true}));
      const thumb = form.querySelector('.thumbcell img');
      if (thumb) thumb.src = path;
      sync(root);
      status.dataset.error = 'false';
      status.textContent = 'Foto importada y registrada en Media. Guarda el artista para asociarla.';
    } catch (error) {
      if (form.isConnected) {
        status.dataset.error = 'true';
        status.textContent = photoImportError(error.message);
      }
    } finally {
      button.disabled = false;
    }
  }

  function attach(root) {
    if (!root || root.dataset.heroBound === '1') return;
    root.dataset.heroBound = '1';
    root.addEventListener('input',event => {
      if (event.target.closest('.artist-hero-controls')) sync(root);
    });
    root.addEventListener('click',event => {
      if (event.target.closest('[data-artist-meta-try]')) {
        const status = root.querySelector('[data-artist-photo-source]');
        const source = officialUrl(document.getElementById('f_instagram_url')?.value);
        if (!source) {
          if (status) status.textContent = 'Vincula un Instagram profesional válido. Las cuentas personales no admiten importación automática.';
          return;
        }
        if (status) status.textContent = 'Comprobando autorización Meta y resolución real de la foto…';
        const proposed = new Image();
        proposed.onload = () => {
          if (!root.isConnected) return;
          if (proposed.naturalWidth < 1024 || proposed.naturalHeight < 640) {
            root.dataset.igPreview = '';
            if (status) status.textContent = 'Meta solo ofrece una miniatura pequeña. Conservamos la foto nítida del CMS; puedes importar una original con derechos.';
          } else {
            root.dataset.igPreview = proposed.src;
            if (status) status.textContent = 'Foto oficial autorizada: ' + proposed.naturalWidth + ' × ' + proposed.naturalHeight + ' px. Previsualización; no sustituye tu archivo CMS.';
          }
          sync(root);
        };
        proposed.onerror = () => {
          if (!root.isConnected) return;
          root.dataset.igPreview = '';
          if (status) status.textContent = 'Meta no permite recuperar esta foto con los permisos actuales. Conservamos el CMS o puedes importar una imagen con derechos.';
          sync(root);
        };
        proposed.src = source;
        return;
      }
      const switcher = event.target.closest('[data-hero-switch]');
      if (switcher) {
        root.dataset.viewport = switcher.dataset.heroSwitch;
        sync(root);
        return;
      }
      if (event.target.closest('[data-hero-reset]')) {
        for (const key of controls) {
          const input = document.getElementById(
            'f_hero_' + root.dataset.viewport + '_' + key
          );
          if (input) {
            input.value = String(defaults[key]);
            input.dispatchEvent(new Event('input',{bubbles:true}));
          }
        }
        sync(root);
      }
    });
    const form = root.closest('#mcontent');
    const importButton = form?.querySelector('#artistPhotoImportBtn');
    importButton?.addEventListener('click',() => { void importPhoto(root,form,importButton); });
    form?.querySelector('#f_photo')?.addEventListener('input',() => sync(root));
    form?.querySelector('#f_name')?.addEventListener('input',() => sync(root));
    form?.querySelector('#f_is_collective_member')?.addEventListener('change',() => sync(root));
    form?.querySelector('#f_instagram_url')?.addEventListener('input',() => {
      root.dataset.igPreview = '';
      const status = root.querySelector('[data-artist-photo-source]');
      if (status) status.textContent = 'Instagram requiere una cuenta profesional y autorización Meta; la foto del CMS permanece como respaldo.';
      sync(root);
    });
    for (const selector of ['[data-hero-preview-img]','[data-artist-card-preview-img]']) {
      const img = root.querySelector(selector);
      img?.addEventListener('error', () => {
        if (!root.dataset.igPreview) return;
        root.dataset.igPreview = '';
        const status = root.querySelector('[data-artist-photo-source]');
        if (status) status.textContent = 'No se pudo cargar la imagen Meta: restaurada la foto del CMS.';
        sync(root);
      });
    }
    sync(root);
  }

  function mount() {
    document.querySelectorAll('[data-artist-hero]').forEach(attach);
  }

  const observer = new MutationObserver(records => {
    if (records.some(record => record.addedNodes.length > 0)) mount();
  });
  observer.observe(document.documentElement,{childList:true,subtree:true});
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded',mount);
  else mount();

  return {values,mount};
})();
