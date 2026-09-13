window.BRVTALHomeBanners = (() => {
  const key = 'home.hero.slides';
  const maxSlides = 5;
  let request;
  let slides = [];
  let media = [];
  const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
  const root = () => document.getElementById('home-banners-root');
  const imageOptions = selected => '<option value="">Selecciona una imagen publicada</option>' + media.map(item => `<option value="${escape(item.file_path)}" ${item.file_path === selected ? 'selected' : ''}>${escape(item.title || item.file_path)}</option>`).join('');

  function render() {
    const host = root();
    if (!host) return;
    host.innerHTML = `<div class="hb-shell"><div class="hb-intro"><h2>HOME BANNERS</h2><p>Destaca eventos o anuncios en el primer espacio del home. Máximo cinco banners. Usa imágenes publicadas de MEDIA; el primer banner carga primero. Si no hay banners activos, el home conserva su portada BRVTAL actual.</p></div><div id="hb-notice" aria-live="polite"></div><div class="hb-toolbar"><button type="button" class="btn ghost" data-hb-action="add" ${slides.length >= maxSlides ? 'disabled' : ''}>+ AÑADIR BANNER</button><button type="button" class="btn red" data-hb-action="save">GUARDAR BANNERS</button><a class="btn ghost" href="/" target="_blank" rel="noopener">VER HOME ↗</a></div><div class="hb-list">${slides.length ? slides.map(card).join('') : '<div class="hb-notice">Aún no hay banners. Añade uno para preparar la portada.</div>'}</div></div>`;
  }

  function card(slide, index) {
    const preview = slide.image ? `<img src="${escape(slide.image)}" alt="Vista previa del banner ${index + 1}">` : 'SIN IMAGEN';
    return `<article class="hb-card" data-hb-index="${index}"><div class="hb-card-head"><strong>BANNER ${String(index + 1).padStart(2, '0')}</strong><div class="hb-card-actions"><button class="iconbtn" type="button" data-hb-action="up" aria-label="Subir banner ${index + 1}" ${index ? '' : 'disabled'}>↑</button><button class="iconbtn" type="button" data-hb-action="down" aria-label="Bajar banner ${index + 1}" ${index < slides.length - 1 ? '' : 'disabled'}>↓</button><button class="iconbtn" type="button" data-hb-action="remove" aria-label="Quitar banner ${index + 1}">QUITAR</button></div></div><div class="hb-preview">${preview}</div><div class="hb-fields"><label class="hb-field hb-wide">Imagen de Media Library<select data-hb-field="image">${imageOptions(slide.image)}</select></label><label class="hb-field">Línea superior<input data-hb-field="eyebrow" maxlength="70" value="${escape(slide.eyebrow)}" placeholder="PRÓXIMO EVENTO / BRVTAL"></label><label class="hb-field">Título<input data-hb-field="title" maxlength="100" value="${escape(slide.title)}" placeholder="NOMBRE DEL EVENTO"></label><label class="hb-field hb-wide">Descripción<textarea data-hb-field="description" maxlength="240" placeholder="Una frase breve y clara">${escape(slide.description)}</textarea></label><label class="hb-field">Texto alternativo de la imagen<input data-hb-field="alt" maxlength="180" value="${escape(slide.alt)}" placeholder="Descripción visual"></label><label class="hb-field">Texto del botón<input data-hb-field="label" maxlength="40" value="${escape(slide.label)}" placeholder="DESCUBRIR"></label><label class="hb-field hb-wide">Enlace del botón<input data-hb-field="url" maxlength="500" value="${escape(slide.url)}" placeholder="/events/slug o https://..."></label><label class="hb-publish hb-wide"><input type="checkbox" data-hb-field="enabled" ${slide.enabled ? 'checked' : ''}> PUBLICAR ESTE BANNER</label></div></article>`;
  }

  function notice(message, error = false) {
    const target = document.getElementById('hb-notice');
    if (target) target.innerHTML = `<div class="hb-notice ${error ? 'error' : ''}">${escape(message)}</div>`;
  }

  function validUrl(url) {
    if (!url) return true;
    if (/^\/(events|artists|sets|releases|blog|pages)\/[a-z0-9-]{1,190}$/.test(url)) return true;
    try { const parsed = new URL(url); return parsed.protocol === 'https:' && !parsed.username && !parsed.password; } catch { return false; }
  }

  async function save() {
    if (slides.length > maxSlides) return notice('Máximo cinco banners.', true);
    for (const [index, slide] of slides.entries()) {
      if (slide.enabled && (!slide.title.trim() || !media.some(item => item.file_path === slide.image))) return notice(`El banner ${index + 1} necesita título e imagen publicada.`, true);
      if (!validUrl(slide.url.trim())) return notice(`El enlace del banner ${index + 1} debe ser una ruta pública o URL HTTPS.`, true);
    }
    const value = JSON.stringify(slides);
    try {
      await request('/settings', {method:'POST', body:JSON.stringify({setting_key:key, setting_value:value, is_json:1})});
      notice('Banners guardados. Comprueba la portada pública para ver el resultado.');
    } catch (error) { notice(`No se pudo guardar: ${error.message}`, true); }
  }

  async function load(req) {
    request = req;
    const host = root();
    if (!host) return;
    host.innerHTML = '<div class="hb-notice">Cargando banners…</div>';
    try {
      const [settings, files] = await Promise.all([request('/settings'), request('/media')]);
      media = (files.data || []).filter(item => item.type === 'image' && item.status === 'published' && /^\/uploads\/media\//.test(item.file_path || ''));
      const record = (settings.data || []).find(item => item.setting_key === key);
      const parsed = record ? JSON.parse(record.setting_value || '[]') : [];
      slides = Array.isArray(parsed) ? parsed.slice(0, maxSlides).map(item => ({enabled:!!item.enabled, image:String(item.image || ''), eyebrow:String(item.eyebrow || ''), title:String(item.title || ''), description:String(item.description || ''), alt:String(item.alt || ''), label:String(item.label || 'DISCOVER'), url:String(item.url || '')})) : [];
      render();
      host.addEventListener('input', updateField);
      host.addEventListener('change', updateField);
      host.addEventListener('click', click);
    } catch (error) { host.innerHTML = `<div class="hb-notice error">${escape(error.message)}</div>`; }
  }

  function updateField(event) {
    const field = event.target.closest('[data-hb-field]');
    const card = event.target.closest('[data-hb-index]');
    if (!field || !card) return;
    const slide = slides[Number(card.dataset.hbIndex)];
    if (!slide) return;
    slide[field.dataset.hbField] = field.type === 'checkbox' ? field.checked : field.value;
    if (field.dataset.hbField === 'image') card.querySelector('.hb-preview').innerHTML = slide.image ? `<img src="${escape(slide.image)}" alt="Vista previa">` : 'SIN IMAGEN';
  }

  function click(event) {
    const button = event.target.closest('[data-hb-action]');
    if (!button) return;
    const action = button.dataset.hbAction;
    const card = button.closest('[data-hb-index]');
    const index = card ? Number(card.dataset.hbIndex) : -1;
    if (action === 'save') return void save();
    if (action === 'add' && slides.length < maxSlides) slides.push({enabled:false,image:'',eyebrow:'',title:'',description:'',alt:'',label:'DISCOVER',url:''});
    if (action === 'remove' && index >= 0) {
      if (!window.confirm('¿Quitar este banner del borrador? Se aplicará al guardar.')) return;
      slides.splice(index, 1);
    }
    if (action === 'up' && index > 0) [slides[index - 1], slides[index]] = [slides[index], slides[index - 1]];
    if (action === 'down' && index >= 0 && index < slides.length - 1) [slides[index + 1], slides[index]] = [slides[index], slides[index + 1]];
    render();
  }

  return {load};
})();
