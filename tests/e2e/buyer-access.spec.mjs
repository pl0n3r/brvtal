import { test, expect } from '@playwright/test';
import { readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const root = process.cwd();
const login = readFileSync(join(root, 'buyer/login.php'), 'utf8');
const reset = readFileSync(join(root, 'buyer/reset.php'), 'utf8');
const css = readFileSync(join(root, 'css/buyer-access.css'), 'utf8');
const previousLogin = "<!doctype html>\n<html lang=\"es\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">\n<meta name=\"referrer\" content=\"no-referrer\">\n<title>Acceso comprador · BRVTAL</title>\n<style>\n:root{color-scheme:dark;font-family:system-ui,sans-serif;background:#101010;color:white}\nbody{margin:0;min-height:100dvh;display:grid;place-items:center}\nmain{width:min(100% - 2rem,28rem);padding:1.5rem;border:1px solid #555;border-radius:1rem}\nh1{font-size:1.65rem}label{display:block;margin:1rem 0 .35rem}\ninput,button{box-sizing:border-box;width:100%;padding:.85rem;border-radius:.35rem;font:inherit}\ninput{background:#222;color:#fff;border:1px solid #999}\nbutton{margin-top:1.2rem;background:#fff;color:#111;border:0;font-weight:700}\nbutton:disabled{opacity:.6}a{color:#fff}\n:focus-visible{outline:3px solid #f44;outline-offset:3px}\n</style>\n</head>\n<body>\n<main>\n<h1>Cuenta de comprador</h1>\n<p>Acceso independiente de DISCADMIN.</p>\n<form id=\"buyer-form\">\n<label for=\"email\">Correo electrónico</label>\n<input id=\"email\" type=\"email\" required autocomplete=\"email\" maxlength=\"190\">\n<label for=\"password\">Contraseña</label>\n<input id=\"password\" type=\"password\" required autocomplete=\"current-password\">\n<button id=\"submit\" type=\"submit\">Entrar</button>\n<p id=\"message\" role=\"status\" aria-live=\"polite\"></p>\n</form>\n</main>\n<script>\n(() => {\n  const form = document.getElementById('buyer-form');\n  const message = document.getElementById('message');\n  const button = document.getElementById('submit');\n  let csrf = '';\n  const api = '/api/buyer/auth.php';\n  async function initialize() {\n    try {\n      const response = await fetch(api, {credentials:'same-origin',cache:'no-store'});\n      if (!response.ok) throw Error('UNAVAILABLE');\n      csrf = String((await response.json()).csrf || '');\n      if (!csrf) throw Error('UNAVAILABLE');\n    } catch (_) {\n      message.textContent = 'No está disponible el acceso en este momento.';\n    }\n  }\n  form.addEventListener('submit', async (event) => {\n    event.preventDefault();\n    button.disabled = true;\n    message.textContent = 'Verificando acceso…';\n    try {\n      if (!csrf) throw Error('UNAVAILABLE');\n      const response = await fetch(api, {\n        method:'POST',credentials:'same-origin',\n        headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},\n        body:JSON.stringify({action:'login',email:form.email.value,password:form.password.value})\n      });\n      if (!response.ok) throw Error('UNAVAILABLE');\n      message.textContent = 'Acceso confirmado.';\n    } catch (_) {\n      message.textContent = 'No se pudo iniciar sesión. Verifica los datos o inténtalo después.';\n      document.getElementById('email').focus();\n    } finally { button.disabled = false; }\n  });\n  initialize();\n})();\n</script>\n</body>\n</html>\n"; // Exact pre-redesign snapshot main@8d39c2754757107aa73ed26dd49f11e9940433d9
// Exact pre-#2118 login source from main@3ec0fb53291da7c5b4e6f4dad8d3612b29b6d77c.
const previousRegister = "<!doctype html>\n<html lang=\"es\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">\n<meta name=\"referrer\" content=\"no-referrer\">\n<meta name=\"theme-color\" content=\"#050505\">\n<meta name=\"robots\" content=\"noindex,follow\">\n<title>Mi cuenta · Acceso | BRVTAL</title>\n<link rel=\"stylesheet\" href=\"/css/buyer-access.css?v=1\">\n</head>\n<body class=\"buyer-access\">\n<header class=\"access-topbar\">\n  <a class=\"access-brand\" href=\"/\" aria-label=\"BRVTAL · Volver al inicio\">BRVTAL<span>RAVE TILL GRAVE</span></a>\n  <span class=\"access-topnote\">TU MÚSICA. TUS ENTRADAS.</span>\n</header>\n<main class=\"access-main\">\n  <div class=\"access-shell\">\n    <div class=\"access-overline\"><span class=\"access-dot\" aria-hidden=\"true\"></span> TU ESPACIO EN BRVTAL <span id=\"view-index\">01 / 03</span></div>\n    <div class=\"access-card\">\n      <div class=\"access-meta\"><span>IDENTIDAD / COMPRADOR</span><span aria-hidden=\"true\">↗</span></div>\n      <section class=\"access-view\" id=\"view-entrar\" data-access-view=\"entrar\" aria-labelledby=\"heading-entrar\">\n        <h1 id=\"heading-entrar\" tabindex=\"-1\">Entra a tu <em>cuenta.</em></h1>\n        <p class=\"access-lead\">Consulta tus boletas, pedidos y reembolsos.</p>\n        <form id=\"form-entrar\" autocomplete=\"on\">\n          <input name=\"website\" type=\"text\" autocomplete=\"off\" tabindex=\"-1\" aria-hidden=\"true\" hidden>\n          <div class=\"access-field\"><label for=\"entrar-email\">Correo electrónico</label><input id=\"entrar-email\" name=\"email\" type=\"email\" autocomplete=\"email\" inputmode=\"email\" maxlength=\"190\" placeholder=\"tu@correo.com\" required></div>\n          <div class=\"access-field\"><div class=\"access-field-row\"><label for=\"entrar-password\">Contraseña</label><a href=\"?vista=recuperar\" data-access-link=\"recuperar\">¿Olvidaste tu contraseña?</a></div><input id=\"entrar-password\" name=\"password\" type=\"password\" autocomplete=\"current-password\" required></div>\n          <button class=\"access-submit\" type=\"submit\">Entrar <span aria-hidden=\"true\">↗</span></button>\n        </form>\n        <p class=\"access-footnote\">¿No tienes cuenta? <a href=\"?vista=crear\" data-access-link=\"crear\">Créala</a></p>\n      </section>\n      <section class=\"access-view\" id=\"view-crear\" data-access-view=\"crear\" aria-labelledby=\"heading-crear\" hidden>\n        <h1 id=\"heading-crear\" tabindex=\"-1\">Crea tu <em>cuenta.</em></h1>\n        <p class=\"access-lead\">Una cuenta. Todas tus noches.</p>\n        <form id=\"form-crear\" autocomplete=\"on\">\n          <input name=\"website\" type=\"text\" autocomplete=\"off\" tabindex=\"-1\" aria-hidden=\"true\" hidden>\n          <div class=\"access-field\"><label for=\"crear-nombre\">Nombre</label><input id=\"crear-nombre\" name=\"name\" type=\"text\" autocomplete=\"name\" maxlength=\"120\" required></div>\n          <div class=\"access-field\"><label for=\"crear-email\">Correo electrónico</label><input id=\"crear-email\" name=\"email\" type=\"email\" autocomplete=\"email\" inputmode=\"email\" maxlength=\"190\" placeholder=\"tu@correo.com\" required></div>\n          <div class=\"access-field\"><label for=\"crear-password\">Contraseña</label><div class=\"access-password\"><input id=\"crear-password\" name=\"password\" type=\"password\" autocomplete=\"new-password\" minlength=\"12\" maxlength=\"4096\" aria-describedby=\"password-help\" required><button type=\"button\" class=\"access-toggle\" data-password-toggle=\"crear-password\" aria-label=\"Mostrar contraseña\" aria-pressed=\"false\">Mostrar</button></div><p class=\"access-hint\" id=\"password-help\">Mínimo 12 caracteres. Evita contraseñas comunes.</p></div>\n          <label class=\"access-consent\"><input id=\"crear-acepto\" name=\"terms_accepted\" type=\"checkbox\" required><span>Acepto los <a href=\"/pages/terminos-condiciones\" target=\"_blank\" rel=\"noopener noreferrer\">términos y condiciones</a> y la <a href=\"/privacy\" target=\"_blank\" rel=\"noopener noreferrer\">política de privacidad</a>.</span></label>\n          <div class=\"access-field\">\n            <label for=\"crear-captcha\">Verificación humana: <span id=\"crear-captcha-question\">Cargando suma…</span></label>\n            <input id=\"crear-captcha\" name=\"captcha_answer\" type=\"text\" inputmode=\"numeric\" pattern=\"[0-9]{1,2}\" autocomplete=\"off\" maxlength=\"2\" required>\n            <p class=\"access-hint\">Resuelve la suma para evitar envíos automáticos.</p>\n          </div>\n          <button class=\"access-submit\" type=\"submit\">Crear cuenta <span aria-hidden=\"true\">↗</span></button>\n        </form>\n        <p class=\"access-hint access-mail-note\">Te enviaremos un correo para confirmar tu cuenta.</p>\n        <p class=\"access-footnote\">¿Ya tienes cuenta? <a href=\"?vista=entrar\" data-access-link=\"entrar\">Entrar</a></p>\n      </section>\n      <section class=\"access-view\" id=\"view-recuperar\" data-access-view=\"recuperar\" aria-labelledby=\"heading-recuperar\" hidden>\n        <h1 id=\"heading-recuperar\" tabindex=\"-1\">Vuelve a <em>entrar.</em></h1>\n        <p class=\"access-lead\">Te ayudamos a recuperar tu acceso.</p>\n        <form id=\"form-recuperar\" autocomplete=\"on\">\n          <input name=\"website\" type=\"text\" autocomplete=\"off\" tabindex=\"-1\" aria-hidden=\"true\" hidden>\n          <div class=\"access-field\"><label for=\"recuperar-email\">Correo electrónico</label><input id=\"recuperar-email\" name=\"email\" type=\"email\" autocomplete=\"email\" inputmode=\"email\" maxlength=\"190\" placeholder=\"tu@correo.com\" required></div>\n          <div class=\"access-field\">\n            <label for=\"recuperar-captcha\">Verificación humana: <span id=\"recuperar-captcha-question\">Cargando suma…</span></label>\n            <input id=\"recuperar-captcha\" name=\"captcha_answer\" type=\"text\" inputmode=\"numeric\" pattern=\"[0-9]{1,2}\" autocomplete=\"off\" maxlength=\"2\" required>\n            <p class=\"access-hint\">Comprueba que eres una persona sin compartir datos adicionales.</p>\n          </div>\n          <button class=\"access-submit\" type=\"submit\">Recuperar acceso <span aria-hidden=\"true\">↗</span></button>\n        </form>\n        <p class=\"access-footnote\"><a href=\"?vista=entrar\" data-access-link=\"entrar\">Volver a entrar</a></p>\n      </section>\n      <p id=\"access-message\" class=\"access-message\" role=\"status\" aria-live=\"polite\" aria-atomic=\"true\"></p>\n    </div>\n    <div class=\"access-bottom\"><span>BOLETAS · PEDIDOS · REEMBOLSOS</span><span>BRVTAL / MI CUENTA</span></div>\n  </div>\n</main>\n<footer class=\"access-footer\"><span>RAVE TILL GRAVE</span><a href=\"/\">← Volver al inicio</a></footer>\n<script>\n(() => {\n  'use strict';\n  const api = '/api/buyer/auth.php';\n  const states = ['entrar', 'crear', 'recuperar'];\n  const message = document.getElementById('access-message');\n  const sections = Array.from(document.querySelectorAll('[data-access-view]'));\n  let csrf = '';\n  let challenges = {};\n  let busy = false;\n\n  function announce(text, type = 'neutral') {\n    message.textContent = text;\n    message.dataset.tone = type;\n  }\n  function currentView() {\n    const value = new URLSearchParams(location.search).get('vista');\n    return states.includes(value) ? value : 'entrar';\n  }\n  function render(view, focus = false) {\n    const selected = states.includes(view) ? view : 'entrar';\n    sections.forEach(section => { section.hidden = section.dataset.accessView !== selected; });\n    document.getElementById('view-index').textContent =\n      String(states.indexOf(selected) + 1).padStart(2, '0') + ' / 03';\n    announce('');\n    if (focus) document.getElementById('heading-' + selected).focus();\n  }\n  function navigate(view) {\n    const url = new URL(location.href);\n    url.searchParams.set('vista', view);\n    history.pushState(null, '', url.pathname + url.search + url.hash);\n    render(view, true);\n  }\n  document.querySelectorAll('[data-access-link]').forEach(anchor => {\n    anchor.addEventListener('click', event => {\n      if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;\n      event.preventDefault();\n      navigate(anchor.dataset.accessLink);\n    });\n  });\n  addEventListener('popstate', () => render(currentView(), true));\n\n  document.querySelectorAll('[data-password-toggle]').forEach(button => {\n    button.addEventListener('click', () => {\n      const input = document.getElementById(button.dataset.passwordToggle);\n      const revealing = input.type === 'password';\n      input.type = revealing ? 'text' : 'password';\n      button.textContent = revealing ? 'Ocultar' : 'Mostrar';\n      button.setAttribute('aria-pressed', String(revealing));\n      button.setAttribute('aria-label', revealing ? 'Ocultar contraseña' : 'Mostrar contraseña');\n      input.focus();\n    });\n  });\n  const errors = {\n    INVALID_CREDENTIALS: 'Correo o contraseña incorrectos. Revisa tus datos.',\n    RATE_LIMITED: 'Demasiados intentos. Espera un momento antes de volver a entrar.',\n    EMAIL_NOT_VERIFIED: 'Necesitas confirmar tu correo antes de entrar.',\n    PASSWORD_TOO_SHORT: 'Usa una contraseña de al menos 12 caracteres.',\n    PASSWORD_COMMON: 'Elige una contraseña menos común.',\n    CSRF_INVALID: 'La sesión ha caducado. Recarga la página e inténtalo otra vez.',\n    CAPTCHA_INVALID: 'Resuelve la suma de verificación e inténtalo de nuevo.',\n    CAPTCHA_UNAVAILABLE: 'La verificación humana no está disponible.',\n    BOT_DETECTED: 'No se pudo validar la solicitud. Recarga la página.'\n  };\n  const pending = 'Pronto podrás crear tu cuenta aquí. Mientras tanto, tus boletas llegan a tu correo.';\n  const unavailable = 'El servicio no está disponible en este momento. Inténtalo más tarde.';\n  function safeDestination() {\n    const raw = new URLSearchParams(location.search).get('volver') || '';\n    if (!raw.startsWith('/') || raw.startsWith('//') || /[\\u0000-\\u001f\\\\]/.test(raw)) return '/mi-cuenta';\n    try {\n      const url = new URL(raw, location.origin);\n      return url.origin === location.origin ? url.pathname + url.search + url.hash : '/mi-cuenta';\n    } catch (_) { return '/mi-cuenta'; }\n  }\n  async function initialize() {\n    try {\n      const response = await fetch(api, { credentials: 'same-origin', cache: 'no-store' });\n      const body = await response.json();\n      if (!response.ok || body.ok === false || typeof body.csrf !== 'string' || !body.csrf) throw Error('UNAVAILABLE');\n      csrf = body.csrf;\n      challenges = body.challenges || {};\n      for (const [scope, view] of [['register', 'crear'], ['request-reset', 'recuperar']]) {\n        const data = challenges[scope];\n        document.getElementById(view + '-captcha-question').textContent =\n          typeof data?.question === 'string' ? data.question : 'Verificación no disponible';\n      }\n    } catch (_) { announce(unavailable, 'error'); }\n  }\n  async function send(action, values) {\n    if (!csrf) return { ok: false, error: 'UNAVAILABLE' };\n    try {\n      const response = await fetch(api, {\n        method: 'POST', credentials: 'same-origin', cache: 'no-store',\n        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },\n        body: JSON.stringify({ action, ...values })\n      });\n      const result = await response.json();\n      return response.ok && result.ok === true ? { ok: true } :\n        { ok: false, error: String(result.error || 'UNAVAILABLE') };\n    } catch (_) { return { ok: false, error: 'UNAVAILABLE' }; }\n  }\n  function handle(formId, action, values, onSuccess) {\n    const form = document.getElementById(formId);\n    form.addEventListener('submit', async event => {\n      event.preventDefault();\n      if (busy || !form.reportValidity()) return;\n      busy = true;\n      const button = form.querySelector('[type=\"submit\"]');\n      button.disabled = true;\n      announce('Comprobando los datos…');\n      try {\n        const result = await send(action, values(form));\n        if (result.ok) { onSuccess(); return; }\n        announce(result.error === 'BUYER_MAIL_PENDING' ? pending :\n          errors[result.error] || unavailable, 'error');\n        if (result.error === 'INVALID_CREDENTIALS') form.elements.namedItem('email').focus();\n        if (result.error === 'CAPTCHA_INVALID' || result.error === 'CAPTCHA_UNAVAILABLE') {\n          await initialize();\n          const answer = form.elements.namedItem('captcha_answer');\n          if (answer) answer.value = '';\n        }\n      } finally { busy = false; button.disabled = false; }\n    });\n  }\n  handle('form-entrar', 'login', form => ({\n    email: form.elements.namedItem('email').value.trim(),\n    password: form.elements.namedItem('password').value,\n    website: form.elements.namedItem('website').value\n  }), () => { location.assign(safeDestination()); });\n  handle('form-crear', 'register', form => ({\n    name: form.elements.namedItem('name').value.trim(),\n    email: form.elements.namedItem('email').value.trim(),\n    password: form.elements.namedItem('password').value,\n    terms_accepted: form.elements.namedItem('terms_accepted').checked,\n    privacy_accepted: form.elements.namedItem('terms_accepted').checked,\n    captcha_token: challenges.register?.captcha_token || '',\n    captcha_answer: form.elements.namedItem('captcha_answer').value.trim(),\n    website: form.elements.namedItem('website').value\n  }), () => {\n    announce('Revisa tu correo para confirmar tu cuenta.', 'success');\n  });\n  handle('form-recuperar', 'request-reset', form => ({\n    email: form.elements.namedItem('email').value.trim(),\n    captcha_token: challenges['request-reset']?.captcha_token || '',\n    captcha_answer: form.elements.namedItem('captcha_answer').value.trim(),\n    website: form.elements.namedItem('website').value\n  }), () => {\n    announce('Si hay una cuenta con ese correo, te enviamos un enlace para crear una nueva contraseña.', 'success');\n  });\n  render(currentView());\n  initialize();\n})();\n</script>\n</body>\n</html>\n";
const brokenGlass = readFileSync(join(root, 'assets/fonts/BrokenGlass.ttf'));
const base = 'http://127.0.0.1:4173';
const evidence = join(root, 'visual-evidence');
// Exact pre-change activation page from main@d8a27a8569445ea2a3c69776d98aeda587b8221d.
const previousReset = "<!doctype html>\n<html lang=\"es\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">\n<meta name=\"referrer\" content=\"no-referrer\">\n<meta name=\"theme-color\" content=\"#050505\">\n<meta name=\"robots\" content=\"noindex,follow\">\n<title>Restablecer acceso | BRVTAL</title>\n<link rel=\"stylesheet\" href=\"/css/buyer-access.css?v=1\">\n</head>\n<body class=\"buyer-access buyer-reset\">\n<header class=\"access-topbar\">\n  <a class=\"access-brand\" href=\"/\" aria-label=\"BRVTAL · Volver al inicio\">BRVTAL<span>RAVE TILL GRAVE</span></a>\n  <span class=\"access-topnote\">TU MÚSICA. TUS ENTRADAS.</span>\n</header>\n<main class=\"access-main\">\n  <div class=\"access-shell\">\n    <div class=\"access-overline\"><span class=\"access-dot\" aria-hidden=\"true\"></span> TU ESPACIO EN BRVTAL <span>ACCESO / SEGURO</span></div>\n    <div class=\"access-card\">\n      <div class=\"access-meta\"><span>IDENTIDAD / COMPRADOR</span><span aria-hidden=\"true\">↗</span></div>\n      <section id=\"reset-password\" aria-labelledby=\"reset-heading\">\n        <h1 id=\"reset-heading\">Nueva <em>contraseña.</em></h1>\n        <p class=\"access-lead\">Elige una contraseña segura para volver a tu cuenta.</p>\n        <form id=\"reset-form\">\n          <div class=\"access-field\"><label for=\"reset-new-password\">Nueva contraseña</label><div class=\"access-password\"><input id=\"reset-new-password\" name=\"password\" type=\"password\" autocomplete=\"new-password\" minlength=\"12\" maxlength=\"4096\" aria-describedby=\"reset-password-help\" required><button type=\"button\" class=\"access-toggle\" id=\"reset-toggle\" aria-label=\"Mostrar contraseña\" aria-pressed=\"false\">Mostrar</button></div><p id=\"reset-password-help\" class=\"access-hint\">Mínimo 12 caracteres. Evita contraseñas comunes.</p></div>\n          <div class=\"access-field\"><label for=\"reset-confirm-password\">Confirmar contraseña</label><input id=\"reset-confirm-password\" name=\"confirmation\" type=\"password\" autocomplete=\"new-password\" minlength=\"12\" maxlength=\"4096\" required></div>\n          <button class=\"access-submit\" type=\"submit\">Guardar contraseña <span aria-hidden=\"true\">↗</span></button>\n        </form>\n      </section>\n      <section id=\"reset-verify\" aria-labelledby=\"verify-heading\" hidden>\n        <h1 id=\"verify-heading\">Confirma tu <em>correo.</em></h1>\n        <p class=\"access-lead\">Estamos verificando tu enlace de confirmación.</p>\n      </section>\n      <p id=\"access-message\" class=\"access-message\" role=\"status\" aria-live=\"polite\" aria-atomic=\"true\"></p>\n      <p class=\"access-footnote\"><a href=\"/mi-cuenta/acceso\">Entrar a mi cuenta</a> · <a href=\"/mi-cuenta/acceso?vista=recuperar\">Solicitar otro enlace</a></p>\n    </div>\n    <div class=\"access-bottom\"><span>BOLETAS · PEDIDOS · REEMBOLSOS</span><span>BRVTAL / MI CUENTA</span></div>\n  </div>\n</main>\n<footer class=\"access-footer\"><span>RAVE TILL GRAVE</span><a href=\"/\">← Volver al inicio</a></footer>\n<script>\n(() => {\n  'use strict';\n  const api = '/api/buyer/auth.php';\n  const params = new URLSearchParams(location.search);\n  const token = params.get('token') || '';\n  const verifying = ['verificar', 'verify'].includes(params.get('accion')) ||\n    ['verificar', 'verify'].includes(params.get('action')) ||\n    params.get('vista') === 'verificar' || params.has('verify');\n  const feedback = document.getElementById('access-message');\n  const form = document.getElementById('reset-form');\n  const primary = document.getElementById('reset-password');\n  const verification = document.getElementById('reset-verify');\n  // Copy changes only. Server trust comes from the one-use token, never from\n  // the untrusted 'origen' parameter in the purchase email.\n  const fromPurchase = params.get('origen') === 'compra' && !verifying;\n  if (fromPurchase) {\n    document.getElementById('reset-heading').firstChild.textContent = 'Crea tu ';\n    primary.querySelector('.access-lead').textContent =\n      'Crea una contraseña para acceder a tus pedidos y boletas en Mi cuenta.';\n    form.querySelector('[type=\"submit\"]').firstChild.textContent = 'Crear contraseña ';\n  }\n  let csrf = '';\n  let busy = false;\n  function announce(text, tone = 'neutral') {\n    feedback.textContent = text;\n    feedback.dataset.tone = tone;\n  }\n  const pending = 'Pronto podrás crear tu cuenta aquí. Mientras tanto, tus boletas llegan a tu correo.';\n  const failures = {\n    RESET_TOKEN_INVALID: 'El enlace no es válido o ha caducado. Solicita uno nuevo.',\n    TOKEN_INVALID: 'El enlace no es válido o ha caducado. Solicita uno nuevo.',\n    RATE_LIMITED: 'Demasiados intentos. Espera un momento antes de continuar.',\n    PASSWORD_TOO_SHORT: 'Usa una contraseña de al menos 12 caracteres.',\n    PASSWORD_COMMON: 'Elige una contraseña menos común.',\n    CSRF_INVALID: 'La sesión ha caducado. Recarga la página e inténtalo de nuevo.'\n  };\n  const unavailable = 'El servicio no está disponible en este momento. Inténtalo más tarde.';\n  const clearTokenFromAddress = () => {\n    const url = new URL(location.href);\n    url.searchParams.delete('token');\n    url.searchParams.delete('verify');\n    history.replaceState(null, '', url.pathname + url.search + url.hash);\n  };\n  async function send(action, values) {\n    if (!csrf) return { ok: false, error: 'UNAVAILABLE' };\n    try {\n      const response = await fetch(api, {\n        method: 'POST', credentials: 'same-origin', cache: 'no-store',\n        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },\n        body: JSON.stringify({ action, ...values })\n      });\n      const body = await response.json();\n      return response.ok && body.ok === true ? { ok: true } :\n        { ok: false, error: String(body.error || 'UNAVAILABLE') };\n    } catch (_) { return { ok: false, error: 'UNAVAILABLE' }; }\n  }\n  function explain(result) {\n    announce(result.error === 'BUYER_MAIL_PENDING' ? pending :\n      failures[result.error] || unavailable, 'error');\n  }\n  function success(text) {\n    announce(text, 'success');\n    clearTokenFromAddress();\n    const controls = form.querySelectorAll('input,button');\n    controls.forEach(control => { control.disabled = true; });\n  }\n  async function initialize() {\n    primary.hidden = verifying;\n    verification.hidden = !verifying;\n    if (!token || token.length > 512) {\n      if (verifying) verification.querySelector('.access-lead').textContent = 'El enlace de verificación no es válido.';\n      else form.hidden = true;\n      announce('El enlace no es válido o ha caducado. Solicita uno nuevo.', 'error');\n      return;\n    }\n    try {\n      const response = await fetch(api, { credentials: 'same-origin', cache: 'no-store' });\n      const body = await response.json();\n      if (!response.ok || body.ok === false || typeof body.csrf !== 'string' || !body.csrf) throw Error('UNAVAILABLE');\n      csrf = body.csrf;\n    } catch (_) { announce(unavailable, 'error'); return; }\n    if (verifying) {\n      announce('Verificando el correo…');\n      const result = await send('verify', { token });\n      if (result.ok) {\n        verification.querySelector('.access-lead').textContent = 'Tu correo está confirmado. Ya puedes entrar a tu cuenta.';\n        announce('Correo confirmado correctamente.', 'success');\n        clearTokenFromAddress();\n      } else explain(result);\n    }\n  }\n  document.getElementById('reset-toggle').addEventListener('click', event => {\n    const button = event.currentTarget, input = document.getElementById('reset-new-password');\n    const revealing = input.type === 'password';\n    input.type = revealing ? 'text' : 'password';\n    button.textContent = revealing ? 'Ocultar' : 'Mostrar';\n    button.setAttribute('aria-pressed', String(revealing));\n    button.setAttribute('aria-label', revealing ? 'Ocultar contraseña' : 'Mostrar contraseña');\n    input.focus();\n  });\n  form.addEventListener('submit', async event => {\n    event.preventDefault();\n    if (busy || !form.reportValidity()) return;\n    const password = form.elements.namedItem('password').value;\n    const confirmation = form.elements.namedItem('confirmation').value;\n    if (password !== confirmation) {\n      announce('Las contraseñas no coinciden. Revísalas.', 'error');\n      form.elements.namedItem('confirmation').focus();\n      return;\n    }\n    busy = true;\n    const button = form.querySelector('[type=\"submit\"]');\n    button.disabled = true;\n    announce('Guardando contraseña…');\n    try {\n      const result = await send('confirm-reset', { token, password });\n      if (result.ok) success(fromPurchase\n        ? 'Contraseña creada. Entra a Mi cuenta para ver tus pedidos.'\n        : 'Contraseña actualizada. Ya puedes entrar a tu cuenta.');\n      else explain(result);\n    } finally { busy = false; if (!form.hidden && !button.closest('form').querySelector('[disabled][name=\"password\"]')) button.disabled = false; }\n  });\n  initialize();\n})();\n</script>\n</body>\n</html>\n";
async function capture(page, testInfo, name) {
  mkdirSync(evidence, { recursive: true });
  const png = await page.screenshot({ fullPage: true });
  writeFileSync(join(evidence, name + '.png'), png);
  await testInfo.attach(name, { body: png, contentType: 'image/png' });
}

async function setup(page, onAction = () => ({ status: 503, error: 'BUYER_MAIL_PENDING' })) {
  const calls = [];
  await page.route('**/css/buyer-access.css*', route =>
    route.fulfill({ contentType: 'text/css', body: css }));
  await page.route('**/assets/fonts/BrokenGlass.ttf', route =>
    route.fulfill({ contentType: 'font/ttf', body: brokenGlass }));
  await page.route('**/mi-cuenta/acceso**', route =>
    route.fulfill({ contentType: 'text/html; charset=utf-8', body: login }));
  await page.route('**/mi-cuenta/restablecer**', route =>
    route.fulfill({ contentType: 'text/html; charset=utf-8', body: reset }));
  await page.route('**/mi-cuenta', route =>
    route.fulfill({ contentType: 'text/html', body: '<h1>Mis compras</h1>' }));
  await page.route('**/events/demo', route =>
    route.fulfill({ contentType: 'text/html', body: '<h1>Evento interno</h1>' }));
  await page.route('**/api/buyer/auth.php', async route => {
    if (route.request().method() === 'GET') {
      await route.fulfill({ contentType: 'application/json',
        body: JSON.stringify({ ok: true, csrf: 'csrf-sintetico', authenticated: false, challenges: { register: { question: '2 + 3 = ?', captcha_token: 'synthetic-register-token' }, 'request-reset': { question: '4 + 5 = ?', captcha_token: 'synthetic-reset-token' }, 'resend-verification': { question: '1 + 2 = ?', captcha_token: 'synthetic-resend-token' } } }) });
      return;
    }
    const payload = route.request().postDataJSON();
    calls.push(payload);
    const result = await onAction(payload);
    await route.fulfill({
      status: result.status ?? 200, contentType: 'application/json',
      body: JSON.stringify({ ok: result.status == null || result.status < 400, error: result.error || undefined })
    });
  });
  return calls;
}

async function checkA11y(page, selector) {
  await expect.poll(() => page.evaluate(() =>
    document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
  await expect(page.locator('#access-message')).toHaveAttribute('aria-live', 'polite');
  await page.locator(selector).focus();
  await expect(page.locator(selector)).toBeFocused();
  const focusWidth = await page.locator(selector).evaluate(el => {
    const styles = getComputedStyle(el);
    return styles.outlineStyle === 'none' ? 0 : parseFloat(styles.outlineWidth);
  });
  expect(focusWidth).toBeGreaterThanOrEqual(2);
}

for (const width of [1440, 390]) {
  test('acceso ' + width + 'px: tres vistas navegables, semántica y captura', async ({ page }, testInfo) => {
    await page.setViewportSize({ width, height: 850 });
    let releaseRegistration;
    const pendingRegistration = new Promise(resolve => { releaseRegistration = resolve; });
    await setup(page, () => pendingRegistration);
    await page.route('**/mi-cuenta/acceso-antes', route => route.fulfill({ contentType: 'text/html', body: previousLogin }));
    await page.goto(base + '/mi-cuenta/acceso-antes');
    await capture(page, testInfo, 'antes-entrar-' + width + 'px');
    await page.route('**/signup-before*', route => route.fulfill({ contentType: 'text/html; charset=utf-8', body: previousRegister }));
    await page.goto(base + '/signup-before?vista=crear');
    await expect(page.locator('#crear-password')).toBeVisible();
    await expect(page.locator('#crear-captcha-question')).toContainText('2 + 3');
    await page.evaluate(() => document.fonts.ready);
    await capture(page, testInfo, 'antes-2118-crear-' + width + 'px');
    await page.goto(base + '/mi-cuenta/acceso?vista=entrar');
    await expect(page.getByRole('heading', { name: /Entra a tu cuenta/i })).toBeVisible();
    await expect(page.getByText('Consulta tus boletas, pedidos y reembolsos.')).toBeVisible();
    await expect(page.locator('.access-brand')).toHaveAttribute('href', '/');
    await expect(page.getByText('DISCADMIN')).toHaveCount(0);
    await expect(page.locator('#entrar-email')).toHaveAttribute('autocomplete', 'email');
    await expect(page.locator('#entrar-password')).toHaveAttribute('autocomplete', 'current-password');
    await checkA11y(page, '#entrar-email');
    await capture(page, testInfo, 'despues-entrar-' + width + 'px');

    await page.getByRole('link', { name: '¿Olvidaste tu contraseña?' }).click();
    await expect(page).toHaveURL(/vista=recuperar/);
    await expect(page.getByRole('heading', { name: /Vuelve a entrar/i })).toBeVisible();
    await expect(page.locator('#recuperar-email')).toHaveAttribute('autocomplete', 'email');
    await checkA11y(page, '#recuperar-email');
    await capture(page, testInfo, 'despues-recuperar-' + width + 'px');

    await page.getByRole('link', { name: 'Volver a entrar' }).click();
    await page.getByRole('link', { name: 'Créala' }).click();
    await expect(page).toHaveURL(/vista=crear/);
    await expect(page.getByRole('heading', { name: /Crea tu cuenta/i })).toBeVisible();
    await expect(page.locator('#crear-nombre')).toHaveAttribute('autocomplete', 'name');
    await expect(page.locator('#form-crear [name="password"]')).toHaveCount(0);
    await expect(page.locator('#crear-acepto')).not.toBeChecked();
    await expect(page.getByText('Elige tu contraseña cuando confirmes tu correo.')).toBeVisible();
    await expect(page.getByRole('link', { name: 'términos y condiciones' })).toHaveAttribute('href', /terminos-condiciones/);
    await expect(page.getByRole('link', { name: 'política de privacidad' })).toHaveAttribute('href', '/privacy');
    await page.keyboard.press('Tab');
    await checkA11y(page, '#crear-nombre');
    await page.evaluate(() => document.fonts.ready);
    await capture(page, testInfo, 'despues-crear-' + width + 'px');
    await page.locator('#crear-nombre').fill('Persona Sintética');
    await page.locator('#crear-email').fill('sintetica@example.test');
    await page.locator('#crear-acepto').check();
    await page.locator('#crear-captcha').fill('5');
    const submit = page.getByRole('button', { name: /Crear cuenta/i });
    await submit.click();
    await expect(submit).toBeDisabled();
    await expect(page.locator('#access-message')).toContainText('Comprobando los datos');
    await capture(page, testInfo, '2118-crear-carga-' + width + 'px');
    releaseRegistration({ status: 503, error: 'BUYER_MAIL_PENDING' });
    await expect(submit).toBeEnabled();
    await expect(page.locator('#access-message')).toContainText('Pronto podrás crear tu cuenta aquí');
    await capture(page, testInfo, '2118-crear-no-disponible-' + width + 'px');
    await page.reload();
    await expect(page.getByRole('heading', { name: /Crea tu cuenta/i })).toBeVisible();
    await page.goBack();
    await expect(page.getByRole('heading', { name: /Entra a tu cuenta/i })).toBeVisible();
  });

  test('restablecer ' + width + 'px: confirmación, verificación y estados honestos', async ({ page }, testInfo) => {
    await page.setViewportSize({ width, height: 850 });
    const actions = await setup(page, () => ({}));
    await page.goto(base + '/mi-cuenta/restablecer?token=un-token-sintetico');
    await expect(page.getByRole('heading', { name: /Nueva contraseña/i })).toBeVisible();
    await expect(page.locator('#reset-new-password')).toHaveAttribute('autocomplete', 'new-password');
    await expect(page.locator('#reset-confirm-password')).toHaveAttribute('autocomplete', 'new-password');
    await checkA11y(page, '#reset-new-password');
    await capture(page, testInfo, 'despues-restablecer-' + width + 'px');
    await page.locator('#reset-new-password').fill('UnaClaveLarga1234');
    await page.locator('#reset-confirm-password').fill('ClaveDistinta1234');
    await page.getByRole('button', { name: /Guardar contraseña/i }).click();
    await expect(page.locator('#access-message')).toContainText('no coinciden');
    expect(actions).toHaveLength(0);
    await page.locator('#reset-confirm-password').fill('UnaClaveLarga1234');
    await page.getByRole('button', { name: /Guardar contraseña/i }).click();
    await expect(page.locator('#access-message')).toContainText('Contraseña actualizada');
    expect(actions).toHaveLength(1);
    expect(actions[0]).toMatchObject({ action: 'confirm-reset', token: 'un-token-sintetico',
      password: 'UnaClaveLarga1234' });
    await expect(page).not.toHaveURL(/token=/);

    await page.route('**/activation-before*', route =>
      route.fulfill({ contentType: 'text/html; charset=utf-8', body: previousReset }));
    await page.goto(base + '/activation-before?accion=verificar&token=before-synthetic');
    await expect(page.locator('#access-message')).toContainText('Correo confirmado');
    await capture(page, testInfo, 'antes-activacion-' + width + 'px');
    actions.length = 0;
    await page.goto(base + '/mi-cuenta/restablecer?accion=verificar&token=otro-token-sintetico');
    await expect(page.getByRole('heading', { name: /Confirma tu correo/i })).toBeVisible();
    await expect(page.getByRole('button', { name: /Activar cuenta/i })).toBeEnabled();
    expect(actions).toHaveLength(0);
    await checkA11y(page, '#reset-new-password');
    await capture(page, testInfo, 'despues-activacion-' + width + 'px');
    await page.keyboard.type('UnaClaveLarga1234');
    await page.locator('#reset-confirm-password').fill('UnaClaveLarga1234');
    await page.getByRole('button', { name: /Activar cuenta/i }).click();
    await expect(page.locator('#access-message')).toContainText('Correo confirmado y cuenta activada');
    expect(actions).toHaveLength(1);
    expect(actions[0]).toMatchObject({ action: 'verify', token: 'otro-token-sintetico',
      password: 'UnaClaveLarga1234' });
    await expect(page).not.toHaveURL(/token=/);
    await page.keyboard.press('Tab');
    await checkA11y(page, '.access-footnote a:first-child');
  });
}

test('crear y recuperar respetan MAIL_PENDING, sin inventar envíos o cuentas', async ({ page }) => {
  const actions = await setup(page, () => ({ status: 503, error: 'BUYER_MAIL_PENDING' }));
  await page.goto(base + '/mi-cuenta/acceso?vista=crear');
  await page.locator('#crear-nombre').fill('Persona Sintética');
  await page.locator('#crear-email').fill('sintetica@example.test');
  await expect(page.locator('#form-crear [name="password"]')).toHaveCount(0);
  await page.locator('#crear-acepto').check();
  await expect(page.locator('#crear-captcha-question')).toContainText('2 + 3');
  await page.locator('#crear-captcha').fill('5');
  await page.getByRole('button', { name: /Crear cuenta/i }).click();
  await expect(page.locator('#access-message')).toContainText('Pronto podrás crear tu cuenta aquí');
  await expect(page.locator('#access-message')).not.toContainText('Revisa tu correo');
  expect(actions[0]).not.toHaveProperty('password');
  expect(actions[0]).toMatchObject({ action: 'register', terms_accepted: true,
    email: 'sintetica@example.test', captcha_token: 'synthetic-register-token',
    captcha_answer: '5', website: '' });
  await page.getByRole('link', { name: 'Entrar', exact: true }).click();
  await page.getByRole('link', { name: '¿Olvidaste tu contraseña?' }).click();
  await page.locator('#recuperar-email').fill('sintetica@example.test');
  await page.locator('#recuperar-captcha').fill('9');
  await page.getByRole('button', { name: /Recuperar acceso/i }).click();
  await expect(page.locator('#access-message')).toContainText('Pronto podrás');
  await expect(page.locator('#access-message')).not.toContainText('te enviamos un enlace');
  expect(actions[1]).toMatchObject({action:'request-reset',
    captcha_token:'synthetic-reset-token',captcha_answer:'9',website:''});
});

test('recuperación neutral ante respuesta afirmativa y errores humanos de login', async ({ page }) => {
  const actions = await setup(page, payload => payload.action === 'login'
    ? { status: 401, error: 'INVALID_CREDENTIALS' } : {});
  await page.goto(base + '/mi-cuenta/acceso?vista=recuperar');
  await page.locator('#recuperar-email').fill('desconocida@example.test');
  await page.locator('#recuperar-captcha').fill('9');
  await page.getByRole('button', { name: /Recuperar acceso/i }).click();
  await expect(page.locator('#access-message')).toHaveText(
    'Si hay una cuenta con ese correo, te enviamos un enlace para crear una nueva contraseña.');
  await page.getByRole('link', { name: 'Volver a entrar' }).click();
  await page.locator('#entrar-email').fill('sintetica@example.test');
  await page.locator('#entrar-password').fill('invalida');
  await page.getByRole('button', { name: /Entrar/i }).click();
  await expect(page.locator('#access-message')).toContainText('Correo o contraseña incorrectos');
  expect(actions.map(a => a.action)).toEqual(['request-reset', 'login']);
});

test('login simulado redirige a Mi cuenta, solo admite volver interno', async ({ page }) => {
  await setup(page, () => ({}));
  await page.goto(base + '/mi-cuenta/acceso?vista=entrar&volver=https%3A%2F%2Fevil.example');
  await page.locator('#entrar-email').fill('sintetica@example.test');
  await page.locator('#entrar-password').fill('UnaClaveLarga1234');
  await page.getByRole('button', { name: /Entrar/i }).click();
  await expect(page).toHaveURL(base + '/mi-cuenta');
  await page.goto(base + '/mi-cuenta/acceso?volver=%2Fevents%2Fdemo');
  await page.locator('#entrar-email').fill('sintetica@example.test');
  await page.locator('#entrar-password').fill('UnaClaveLarga1234');
  await page.getByRole('button', { name: /Entrar/i }).click();
  await expect(page).toHaveURL(base + '/events/demo');
});

test('enlace inválido no llama a confirm-reset ni verifica sin token', async ({ page }) => {
  const actions = await setup(page);
  await page.goto(base + '/mi-cuenta/restablecer');
  await expect(page.locator('#access-message')).toContainText('no es válido');
  await expect(page.locator('#reset-form')).toBeHidden();
  await page.goto(base + '/mi-cuenta/restablecer?accion=verificar');
  await expect(page.locator('#access-message')).toContainText('no es válido');
  expect(actions).toHaveLength(0);
});

test('activación caducada no consume al abrir y conserva recuperación', async ({ page }) => {
  const actions = await setup(page, () => ({ status: 422, error: 'TOKEN_INVALID' }));
  await page.goto(base + '/mi-cuenta/restablecer?accion=verify&token=legacy-synthetic');
  await expect(page.getByRole('button', { name: /Activar cuenta/i })).toBeEnabled();
  expect(actions).toHaveLength(0);
  await page.locator('#reset-new-password').fill('UnaClaveLarga1234');
  await page.locator('#reset-confirm-password').fill('UnaClaveLarga1234');
  await page.getByRole('button', { name: /Activar cuenta/i }).click();
  await expect(page.locator('#access-message')).toContainText('ha caducado');
  await expect(page.getByRole('link', { name: 'Solicitar otro enlace' })).toBeVisible();
  expect(actions).toHaveLength(1);
});
