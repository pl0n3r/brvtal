<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="referrer" content="no-referrer">
<title>Acceso comprador · BRVTAL</title>
<style>
:root{color-scheme:dark;font-family:system-ui,sans-serif;background:#101010;color:white}
body{margin:0;min-height:100dvh;display:grid;place-items:center}
main{width:min(100% - 2rem,28rem);padding:1.5rem;border:1px solid #555;border-radius:1rem}
h1{font-size:1.65rem}label{display:block;margin:1rem 0 .35rem}
input,button{box-sizing:border-box;width:100%;padding:.85rem;border-radius:.35rem;font:inherit}
input{background:#222;color:#fff;border:1px solid #999}
button{margin-top:1.2rem;background:#fff;color:#111;border:0;font-weight:700}
button:disabled{opacity:.6}a{color:#fff}
:focus-visible{outline:3px solid #f44;outline-offset:3px}
</style>
</head>
<body>
<main>
<h1>Cuenta de comprador</h1>
<p>Acceso independiente de DISCADMIN.</p>
<form id="buyer-form">
<label for="email">Correo electrónico</label>
<input id="email" type="email" required autocomplete="email" maxlength="190">
<label for="password">Contraseña</label>
<input id="password" type="password" required autocomplete="current-password">
<button id="submit" type="submit">Entrar</button>
<p id="message" role="status" aria-live="polite"></p>
</form>
</main>
<script>
(() => {
  const form = document.getElementById('buyer-form');
  const message = document.getElementById('message');
  const button = document.getElementById('submit');
  let csrf = '';
  const api = '/api/buyer/auth.php';
  async function initialize() {
    try {
      const response = await fetch(api, {credentials:'same-origin',cache:'no-store'});
      if (!response.ok) throw Error('UNAVAILABLE');
      csrf = String((await response.json()).csrf || '');
      if (!csrf) throw Error('UNAVAILABLE');
    } catch (_) {
      message.textContent = 'No está disponible el acceso en este momento.';
    }
  }
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    button.disabled = true;
    message.textContent = 'Verificando acceso…';
    try {
      if (!csrf) throw Error('UNAVAILABLE');
      const response = await fetch(api, {
        method:'POST',credentials:'same-origin',
        headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
        body:JSON.stringify({action:'login',email:form.email.value,password:form.password.value})
      });
      if (!response.ok) throw Error('UNAVAILABLE');
      message.textContent = 'Acceso confirmado.';
    } catch (_) {
      message.textContent = 'No se pudo iniciar sesión. Verifica los datos o inténtalo después.';
      document.getElementById('email').focus();
    } finally { button.disabled = false; }
  });
  initialize();
})();
</script>
</body>
</html>
