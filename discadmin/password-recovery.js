(() => {
  'use strict';
  const pageType = document.body.dataset.recoveryPage || '';
  const message = document.getElementById('recovery-message');
  const show = (text, kind = 'success') => {
    if (!message) return;
    message.className = 'message ' + kind;
    message.textContent = text;
  };
  const post = async payload => {
    const response = await fetch('/api/index.php/auth', {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
    const data = await response.json().catch(() => ({ok:false,error:'INVALID_RESPONSE'}));
    if (!response.ok || !data.ok) throw new Error(data.error || 'REQUEST_FAILED');
    return data;
  };
  if (pageType === 'forgot') {
    document.getElementById('forgot-form')?.addEventListener('submit', async event => {
      event.preventDefault();
      const button = event.currentTarget.querySelector('button');
      button.disabled = true;
      try {
        const data = await post({action:'forgot_password',email:document.getElementById('recovery-email').value});
        show(data.message || 'Si la cuenta existe, enviaremos instrucciones de recuperación.');
      } catch (error) {
        show(error.message === 'RATE_LIMITED' ? 'Demasiadas solicitudes. Inténtalo más tarde.' : 'Si la cuenta existe, enviaremos instrucciones de recuperación.', error.message === 'RATE_LIMITED' ? 'error' : 'success');
      } finally { button.disabled = false; }
    });
    return;
  }
  if (pageType === 'reset') {
    const params = new URLSearchParams(location.hash.startsWith('#') ? location.hash.slice(1) : '');
    const token = String(params.get('token') || '');
    history.replaceState(null, '', location.pathname + location.search);
    if (!/^[a-f0-9]{64}$/i.test(token)) {
      show('El enlace de recuperación no es válido.', 'error');
      document.getElementById('reset-form')?.setAttribute('hidden', 'hidden');
      return;
    }
    document.getElementById('reset-form')?.addEventListener('submit', async event => {
      event.preventDefault();
      const form = event.currentTarget;
      const password = document.getElementById('new-password').value;
      if (password !== document.getElementById('confirm-password').value) {
        show('Las contraseñas no coinciden.', 'error'); return;
      }
      const button = form.querySelector('button'); button.disabled = true;
      try {
        await post({action:'reset_password',token,new_password:password,totp_code:document.getElementById('second-factor').value});
        form.reset(); show('Contraseña actualizada. Ya puedes iniciar sesión.');
        document.getElementById('back-login')?.removeAttribute('hidden');
      } catch (error) {
        const messages={SECOND_FACTOR_REQUIRED:'Ingresa tu código 2FA o un código de recuperación válido.',RESET_TOKEN_INVALID:'El enlace expiró, ya fue usado o fue revocado.',RATE_LIMITED:'Demasiados intentos. Inténtalo más tarde.',PASSWORD_TOO_SHORT:'La nueva contraseña debe tener al menos 12 caracteres.',PASSWORD_REUSED:'La nueva contraseña debe ser diferente a la anterior.',PASSWORD_COMMON:'Elige una contraseña menos común.'};
        show(messages[error.message] || 'No se pudo actualizar la contraseña.', 'error');
      } finally { button.disabled = false; }
    });
  }
})();
