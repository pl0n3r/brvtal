<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="referrer" content="no-referrer">
<meta name="theme-color" content="#050505">
<meta name="robots" content="noindex,follow">
<title>Mi cuenta · Acceso | BRVTAL</title>
<link rel="stylesheet" href="/css/buyer-access.css?v=1">
</head>
<body class="buyer-access">
<header class="access-topbar">
  <a class="access-brand" href="/" aria-label="BRVTAL · Volver al inicio">BRVTAL<span>RAVE TILL GRAVE</span></a>
  <span class="access-topnote">TU MÚSICA. TUS ENTRADAS.</span>
</header>
<main class="access-main">
  <div class="access-shell">
    <div class="access-overline"><span class="access-dot" aria-hidden="true"></span> TU ESPACIO EN BRVTAL <span id="view-index">01 / 03</span></div>
    <div class="access-card">
      <div class="access-meta"><span>IDENTIDAD / COMPRADOR</span><span aria-hidden="true">↗</span></div>
      <section class="access-view" id="view-entrar" data-access-view="entrar" aria-labelledby="heading-entrar">
        <h1 id="heading-entrar" tabindex="-1">Entra a tu <em>cuenta.</em></h1>
        <p class="access-lead">Consulta tus boletas, pedidos y reembolsos.</p>
        <form id="form-entrar" autocomplete="on">
          <input name="website" type="text" autocomplete="off" tabindex="-1" aria-hidden="true" hidden>
          <div class="access-field"><label for="entrar-email">Correo electrónico</label><input id="entrar-email" name="email" type="email" autocomplete="email" inputmode="email" maxlength="190" placeholder="tu@correo.com" required></div>
          <div class="access-field"><div class="access-field-row"><label for="entrar-password">Contraseña</label><a href="?vista=recuperar" data-access-link="recuperar">¿Olvidaste tu contraseña?</a></div><input id="entrar-password" name="password" type="password" autocomplete="current-password" required></div>
          <button class="access-submit" type="submit">Entrar <span aria-hidden="true">↗</span></button>
        </form>
        <p class="access-footnote">¿No tienes cuenta? <a href="?vista=crear" data-access-link="crear">Créala</a></p>
      </section>
      <section class="access-view" id="view-crear" data-access-view="crear" aria-labelledby="heading-crear" hidden>
        <h1 id="heading-crear" tabindex="-1">Crea tu <em>cuenta.</em></h1>
        <p class="access-lead">Una cuenta. Todas tus noches.</p>
        <form id="form-crear" autocomplete="on">
          <input name="website" type="text" autocomplete="off" tabindex="-1" aria-hidden="true" hidden>
          <div class="access-field"><label for="crear-nombre">Nombre</label><input id="crear-nombre" name="name" type="text" autocomplete="name" maxlength="120" required></div>
          <div class="access-field"><label for="crear-email">Correo electrónico</label><input id="crear-email" name="email" type="email" autocomplete="email" inputmode="email" maxlength="190" placeholder="tu@correo.com" required></div>
          <div class="access-field"><label for="crear-password">Contraseña</label><div class="access-password"><input id="crear-password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="4096" aria-describedby="password-help" required><button type="button" class="access-toggle" data-password-toggle="crear-password" aria-label="Mostrar contraseña" aria-pressed="false">Mostrar</button></div><p class="access-hint" id="password-help">Mínimo 12 caracteres. Evita contraseñas comunes.</p></div>
          <label class="access-consent"><input id="crear-acepto" name="terms_accepted" type="checkbox" required><span>Acepto los <a href="/pages/terminos-condiciones" target="_blank" rel="noopener noreferrer">términos y condiciones</a> y la <a href="/privacy" target="_blank" rel="noopener noreferrer">política de privacidad</a>.</span></label>
          <div class="access-field">
            <label for="crear-captcha">Verificación humana: <span id="crear-captcha-question">Cargando suma…</span></label>
            <input id="crear-captcha" name="captcha_answer" type="text" inputmode="numeric" pattern="[0-9]{1,2}" autocomplete="off" maxlength="2" required>
            <p class="access-hint">Resuelve la suma para evitar envíos automáticos.</p>
          </div>
          <button class="access-submit" type="submit">Crear cuenta <span aria-hidden="true">↗</span></button>
        </form>
        <p class="access-hint access-mail-note">Te enviaremos un correo para confirmar tu cuenta.</p>
        <p class="access-footnote">¿Ya tienes cuenta? <a href="?vista=entrar" data-access-link="entrar">Entrar</a></p>
      </section>
      <section class="access-view" id="view-recuperar" data-access-view="recuperar" aria-labelledby="heading-recuperar" hidden>
        <h1 id="heading-recuperar" tabindex="-1">Vuelve a <em>entrar.</em></h1>
        <p class="access-lead">Te ayudamos a recuperar tu acceso.</p>
        <form id="form-recuperar" autocomplete="on">
          <input name="website" type="text" autocomplete="off" tabindex="-1" aria-hidden="true" hidden>
          <div class="access-field"><label for="recuperar-email">Correo electrónico</label><input id="recuperar-email" name="email" type="email" autocomplete="email" inputmode="email" maxlength="190" placeholder="tu@correo.com" required></div>
          <div class="access-field">
            <label for="recuperar-captcha">Verificación humana: <span id="recuperar-captcha-question">Cargando suma…</span></label>
            <input id="recuperar-captcha" name="captcha_answer" type="text" inputmode="numeric" pattern="[0-9]{1,2}" autocomplete="off" maxlength="2" required>
            <p class="access-hint">Comprueba que eres una persona sin compartir datos adicionales.</p>
          </div>
          <button class="access-submit" type="submit">Recuperar acceso <span aria-hidden="true">↗</span></button>
        </form>
        <p class="access-footnote"><a href="?vista=entrar" data-access-link="entrar">Volver a entrar</a></p>
      </section>
      <p id="access-message" class="access-message" role="status" aria-live="polite" aria-atomic="true"></p>
    </div>
    <div class="access-bottom"><span>BOLETAS · PEDIDOS · REEMBOLSOS</span><span>BRVTAL / MI CUENTA</span></div>
  </div>
</main>
<footer class="access-footer"><span>RAVE TILL GRAVE</span><a href="/">← Volver al inicio</a></footer>
<script>
(() => {
  'use strict';
  const api = '/api/buyer/auth.php';
  const states = ['entrar', 'crear', 'recuperar'];
  const message = document.getElementById('access-message');
  const sections = Array.from(document.querySelectorAll('[data-access-view]'));
  let csrf = '';
  let challenges = {};
  let busy = false;

  function announce(text, type = 'neutral') {
    message.textContent = text;
    message.dataset.tone = type;
  }
  function currentView() {
    const value = new URLSearchParams(location.search).get('vista');
    return states.includes(value) ? value : 'entrar';
  }
  function render(view, focus = false) {
    const selected = states.includes(view) ? view : 'entrar';
    sections.forEach(section => { section.hidden = section.dataset.accessView !== selected; });
    document.getElementById('view-index').textContent =
      String(states.indexOf(selected) + 1).padStart(2, '0') + ' / 03';
    announce('');
    if (focus) document.getElementById('heading-' + selected).focus();
  }
  function navigate(view) {
    const url = new URL(location.href);
    url.searchParams.set('vista', view);
    history.pushState(null, '', url.pathname + url.search + url.hash);
    render(view, true);
  }
  document.querySelectorAll('[data-access-link]').forEach(anchor => {
    anchor.addEventListener('click', event => {
      if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      event.preventDefault();
      navigate(anchor.dataset.accessLink);
    });
  });
  addEventListener('popstate', () => render(currentView(), true));

  document.querySelectorAll('[data-password-toggle]').forEach(button => {
    button.addEventListener('click', () => {
      const input = document.getElementById(button.dataset.passwordToggle);
      const revealing = input.type === 'password';
      input.type = revealing ? 'text' : 'password';
      button.textContent = revealing ? 'Ocultar' : 'Mostrar';
      button.setAttribute('aria-pressed', String(revealing));
      button.setAttribute('aria-label', revealing ? 'Ocultar contraseña' : 'Mostrar contraseña');
      input.focus();
    });
  });
  const errors = {
    INVALID_CREDENTIALS: 'Correo o contraseña incorrectos. Revisa tus datos.',
    RATE_LIMITED: 'Demasiados intentos. Espera un momento antes de volver a entrar.',
    EMAIL_NOT_VERIFIED: 'Necesitas confirmar tu correo antes de entrar.',
    PASSWORD_TOO_SHORT: 'Usa una contraseña de al menos 12 caracteres.',
    PASSWORD_COMMON: 'Elige una contraseña menos común.',
    CSRF_INVALID: 'La sesión ha caducado. Recarga la página e inténtalo otra vez.',
    CAPTCHA_INVALID: 'Resuelve la suma de verificación e inténtalo de nuevo.',
    CAPTCHA_UNAVAILABLE: 'La verificación humana no está disponible.',
    BOT_DETECTED: 'No se pudo validar la solicitud. Recarga la página.'
  };
  const pending = 'Pronto podrás crear tu cuenta aquí. Mientras tanto, tus boletas llegan a tu correo.';
  const unavailable = 'El servicio no está disponible en este momento. Inténtalo más tarde.';
  function safeDestination() {
    const raw = new URLSearchParams(location.search).get('volver') || '';
    if (!raw.startsWith('/') || raw.startsWith('//') || /[\u0000-\u001f\\]/.test(raw)) return '/mi-cuenta';
    try {
      const url = new URL(raw, location.origin);
      return url.origin === location.origin ? url.pathname + url.search + url.hash : '/mi-cuenta';
    } catch (_) { return '/mi-cuenta'; }
  }
  async function initialize() {
    try {
      const response = await fetch(api, { credentials: 'same-origin', cache: 'no-store' });
      const body = await response.json();
      if (!response.ok || body.ok === false || typeof body.csrf !== 'string' || !body.csrf) throw Error('UNAVAILABLE');
      csrf = body.csrf;
      challenges = body.challenges || {};
      for (const [scope, view] of [['register', 'crear'], ['request-reset', 'recuperar']]) {
        const data = challenges[scope];
        document.getElementById(view + '-captcha-question').textContent =
          typeof data?.question === 'string' ? data.question : 'Verificación no disponible';
      }
    } catch (_) { announce(unavailable, 'error'); }
  }
  async function send(action, values) {
    if (!csrf) return { ok: false, error: 'UNAVAILABLE' };
    try {
      const response = await fetch(api, {
        method: 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ action, ...values })
      });
      const result = await response.json();
      return response.ok && result.ok === true ? { ok: true } :
        { ok: false, error: String(result.error || 'UNAVAILABLE') };
    } catch (_) { return { ok: false, error: 'UNAVAILABLE' }; }
  }
  function handle(formId, action, values, onSuccess) {
    const form = document.getElementById(formId);
    form.addEventListener('submit', async event => {
      event.preventDefault();
      if (busy || !form.reportValidity()) return;
      busy = true;
      const button = form.querySelector('[type="submit"]');
      button.disabled = true;
      announce('Comprobando los datos…');
      try {
        const result = await send(action, values(form));
        if (result.ok) { onSuccess(); return; }
        announce(result.error === 'BUYER_MAIL_PENDING' ? pending :
          errors[result.error] || unavailable, 'error');
        if (result.error === 'INVALID_CREDENTIALS') form.elements.namedItem('email').focus();
        if (result.error === 'CAPTCHA_INVALID' || result.error === 'CAPTCHA_UNAVAILABLE') {
          await initialize();
          form.elements.namedItem('captcha_answer')?.setAttribute('value', '');
        }
      } finally { busy = false; button.disabled = false; }
    });
  }
  handle('form-entrar', 'login', form => ({
    email: form.elements.namedItem('email').value.trim(),
    password: form.elements.namedItem('password').value,
    website: form.elements.namedItem('website').value
  }), () => { location.assign(safeDestination()); });
  handle('form-crear', 'register', form => ({
    name: form.elements.namedItem('name').value.trim(),
    email: form.elements.namedItem('email').value.trim(),
    password: form.elements.namedItem('password').value,
    terms_accepted: form.elements.namedItem('terms_accepted').checked,
    privacy_accepted: form.elements.namedItem('terms_accepted').checked,
    captcha_token: challenges.register?.captcha_token || '',
    captcha_answer: form.elements.namedItem('captcha_answer').value.trim(),
    website: form.elements.namedItem('website').value
  }), () => {
    announce('Revisa tu correo para confirmar tu cuenta.', 'success');
  });
  handle('form-recuperar', 'request-reset', form => ({
    email: form.elements.namedItem('email').value.trim(),
    captcha_token: challenges['request-reset']?.captcha_token || '',
    captcha_answer: form.elements.namedItem('captcha_answer').value.trim(),
    website: form.elements.namedItem('website').value
  }), () => {
    announce('Si hay una cuenta con ese correo, te enviamos un enlace para crear una nueva contraseña.', 'success');
  });
  render(currentView());
  initialize();
})();
</script>
</body>
</html>
