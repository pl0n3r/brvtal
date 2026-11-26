<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="referrer" content="no-referrer">
<meta name="theme-color" content="#050505">
<meta name="robots" content="noindex,follow">
<title>Restablecer acceso | BRVTAL</title>
<link rel="stylesheet" href="/css/buyer-access.css?v=1">
</head>
<body class="buyer-access buyer-reset">
<header class="access-topbar">
  <a class="access-brand" href="/" aria-label="BRVTAL · Volver al inicio">BRVTAL<span>RAVE TILL GRAVE</span></a>
  <span class="access-topnote">TU MÚSICA. TUS ENTRADAS.</span>
</header>
<main class="access-main">
  <div class="access-shell">
    <div class="access-overline"><span class="access-dot" aria-hidden="true"></span> TU ESPACIO EN BRVTAL <span>ACCESO / SEGURO</span></div>
    <div class="access-card">
      <div class="access-meta"><span>IDENTIDAD / COMPRADOR</span><span aria-hidden="true">↗</span></div>
      <section id="reset-password" aria-labelledby="reset-heading">
        <h1 id="reset-heading">Nueva <em>contraseña.</em></h1>
        <p class="access-lead">Elige una contraseña segura para volver a tu cuenta.</p>
        <form id="reset-form">
          <div class="access-field"><label for="reset-new-password">Nueva contraseña</label><div class="access-password"><input id="reset-new-password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="4096" aria-describedby="reset-password-help" required><button type="button" class="access-toggle" id="reset-toggle" aria-label="Mostrar contraseña" aria-pressed="false">Mostrar</button></div><p id="reset-password-help" class="access-hint">Mínimo 12 caracteres. Evita contraseñas comunes.</p></div>
          <div class="access-field"><label for="reset-confirm-password">Confirmar contraseña</label><input id="reset-confirm-password" name="confirmation" type="password" autocomplete="new-password" minlength="12" maxlength="4096" required></div>
          <button class="access-submit" type="submit" disabled>Guardar contraseña <span aria-hidden="true">↗</span></button>
        </form>
      </section>
      <p id="access-message" class="access-message" role="status" aria-live="polite" aria-atomic="true"></p>
      <p class="access-footnote"><a href="/mi-cuenta/acceso">Entrar a mi cuenta</a> · <a href="/mi-cuenta/acceso?vista=recuperar">Solicitar otro enlace</a></p>
    </div>
    <div class="access-bottom"><span>BOLETAS · PEDIDOS · REEMBOLSOS</span><span>BRVTAL / MI CUENTA</span></div>
  </div>
</main>
<footer class="access-footer"><span>RAVE TILL GRAVE</span><a href="/">← Volver al inicio</a></footer>
<script>
(() => {
  'use strict';
  const api = '/api/buyer/auth.php';
  const params = new URLSearchParams(location.search);
  const token = params.get('token') || '';
  const verifying = ['verificar', 'verify'].includes(params.get('accion')) ||
    ['verificar', 'verify'].includes(params.get('action')) ||
    params.get('vista') === 'verificar' || params.has('verify');
  const feedback = document.getElementById('access-message');
  const form = document.getElementById('reset-form');
  const primary = document.getElementById('reset-password');
  const submit = form.querySelector('[type="submit"]');
  // Copy changes only. Server trust comes from the one-use token, never from
  // the untrusted 'origen' parameter in the purchase email.
  const fromPurchase = params.get('origen') === 'compra' && !verifying;
  if (verifying) {
    document.getElementById('reset-heading').firstChild.textContent = 'Confirma tu ';
    document.getElementById('reset-heading').querySelector('em').textContent = 'correo.';
    primary.querySelector('.access-lead').textContent =
      'Elige tu propia contraseña para activar tu cuenta de BRVTAL.';
    submit.firstChild.textContent = 'Activar cuenta ';
  } else if (fromPurchase) {
    document.getElementById('reset-heading').firstChild.textContent = 'Crea tu ';
    primary.querySelector('.access-lead').textContent =
      'Crea una contraseña para acceder a tus pedidos y boletas en Mi cuenta.';
    form.querySelector('[type="submit"]').firstChild.textContent = 'Crear contraseña ';
  }
  let csrf = '';
  let busy = false;
  function announce(text, tone = 'neutral') {
    feedback.textContent = text;
    feedback.dataset.tone = tone;
  }
  const pending = 'Pronto podrás crear tu cuenta aquí. Mientras tanto, tus boletas llegan a tu correo.';
  const failures = {
    RESET_TOKEN_INVALID: 'El enlace no es válido o ha caducado. Solicita uno nuevo.',
    TOKEN_INVALID: 'El enlace no es válido o ha caducado. Solicita uno nuevo.',
    RATE_LIMITED: 'Demasiados intentos. Espera un momento antes de continuar.',
    PASSWORD_TOO_SHORT: 'Usa una contraseña de al menos 12 caracteres.',
    PASSWORD_COMMON: 'Elige una contraseña menos común.',
    PASSWORD_INVALID: 'Elige una contraseña válida de al menos 12 caracteres.',
    PASSWORD_REUSED: 'Elige una contraseña nueva, distinta de la anterior.',
    PASSWORD_TOO_LONG: 'Usa una contraseña de hasta 4096 caracteres.',
    CSRF_INVALID: 'La sesión ha caducado. Recarga la página e inténtalo de nuevo.'
  };
  const unavailable = 'El servicio no está disponible en este momento. Inténtalo más tarde.';
  const clearTokenFromAddress = () => {
    const url = new URL(location.href);
    url.searchParams.delete('token');
    url.searchParams.delete('verify');
    history.replaceState(null, '', url.pathname + url.search + url.hash);
  };
  async function send(action, values) {
    if (!csrf) return { ok: false, error: 'UNAVAILABLE' };
    try {
      const response = await fetch(api, {
        method: 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ action, ...values })
      });
      const body = await response.json();
      return response.ok && body.ok === true ? { ok: true } :
        { ok: false, error: String(body.error || 'UNAVAILABLE') };
    } catch (_) { return { ok: false, error: 'UNAVAILABLE' }; }
  }
  function explain(result) {
    announce(result.error === 'BUYER_MAIL_PENDING' ? pending :
      failures[result.error] || unavailable, 'error');
  }
  function success(text) {
    announce(text, 'success');
    clearTokenFromAddress();
    const controls = form.querySelectorAll('input,button');
    controls.forEach(control => { control.disabled = true; });
  }
  async function initialize() {
    if (!token || token.length > 512) {
      form.hidden = true;
      announce('El enlace no es válido o ha caducado. Solicita uno nuevo.', 'error');
      return;
    }
    announce('Preparando acceso seguro…');
    try {
      const response = await fetch(api, { credentials: 'same-origin', cache: 'no-store' });
      const body = await response.json();
      if (!response.ok || body.ok === false || typeof body.csrf !== 'string' || !body.csrf) throw Error('UNAVAILABLE');
      csrf = body.csrf;
    } catch (_) { announce(unavailable, 'error'); return; }
    announce('');
    submit.disabled = false;
  }
  document.getElementById('reset-toggle').addEventListener('click', event => {
    const button = event.currentTarget, input = document.getElementById('reset-new-password');
    const revealing = input.type === 'password';
    input.type = revealing ? 'text' : 'password';
    button.textContent = revealing ? 'Ocultar' : 'Mostrar';
    button.setAttribute('aria-pressed', String(revealing));
    button.setAttribute('aria-label', revealing ? 'Ocultar contraseña' : 'Mostrar contraseña');
    input.focus();
  });
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (busy || !csrf || !form.reportValidity()) return;
    const password = form.elements.namedItem('password').value;
    const confirmation = form.elements.namedItem('confirmation').value;
    if (password !== confirmation) {
      announce('Las contraseñas no coinciden. Revísalas.', 'error');
      form.elements.namedItem('confirmation').focus();
      return;
    }
    busy = true;
    const button = form.querySelector('[type="submit"]');
    button.disabled = true;
    announce(verifying ? 'Activando cuenta…' : 'Guardando contraseña…');
    try {
      const result = await send(verifying ? 'verify' : 'confirm-reset', { token, password });
      if (result.ok) success(verifying
        ? 'Correo confirmado y cuenta activada. Ya puedes entrar a tu cuenta.'
        : fromPurchase ? 'Contraseña creada. Entra a Mi cuenta para ver tus pedidos.'
          : 'Contraseña actualizada. Ya puedes entrar a tu cuenta.');
      else explain(result);
    } finally { busy = false; if (!form.hidden && !button.closest('form').querySelector('[disabled][name="password"]')) button.disabled = false; }
  });
  initialize();
})();
</script>
</body>
</html>
