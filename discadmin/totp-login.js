(() => {
  'use strict';
  const AUTH = '/api/auth';
  let active = false;

  function esc(v) {
    return String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  }

  function ensureStyles() {
    if (document.getElementById('brvtal-totp-style')) return;
    const s = document.createElement('style');
    s.id = 'brvtal-totp-style';
    s.textContent = `#brvtal-totp-overlay{position:fixed;inset:0;background:rgba(0,0,0,.92);backdrop-filter:blur(10px);display:grid;place-items:center;z-index:99999;padding:18px}.brvtal-totp-box{width:min(430px,94vw);background:#090a0b;border:1px solid #30353a;padding:28px;color:#f4f5f6;font-family:Arial,Helvetica,sans-serif}.brvtal-totp-box h2{margin:0;font-size:28px;letter-spacing:-1px}.brvtal-totp-box p{font-size:11px;color:#858b91;line-height:1.55}.brvtal-totp-box input{width:100%;box-sizing:border-box;background:#050606;border:1px solid #353a3f;color:#fff;padding:13px;margin:8px 0 12px;font-size:20px;letter-spacing:4px;text-align:center}.brvtal-totp-actions{display:flex;gap:8px}.brvtal-totp-actions button{flex:1;padding:12px;border:1px solid #363b40;background:#111315;color:#fff;font-weight:900;cursor:pointer}.brvtal-totp-actions .primary{background:#ff2038;border-color:#ff2038}.brvtal-totp-error{display:none;color:#ff5555;font-size:10px;margin:0 0 12px}.brvtal-totp-error.show{display:block}.brvtal-totp-mode{display:block;margin-top:12px;color:#777e84;font-size:10px;text-align:center;cursor:pointer;text-decoration:underline}`;
    document.head.appendChild(s);
  }

  function ensureSecurityLink() {
    if (document.getElementById('brvtal-security-link')) return;
    const nav = document.querySelector('.nav');
    if (!nav) return;
    const button = document.createElement('button');
    button.id = 'brvtal-security-link';
    button.type = 'button';
    button.textContent = 'SECURITY / 2FA';
    button.addEventListener('click', () => { window.location.href = '/discadmin/totp-status.php'; });
    nav.appendChild(button);
  }

  async function cancelPending() {
    try {
      await nativeFetch(AUTH, {method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({action:'totp_cancel'})});
    } catch (_) {}
  }

  function challenge() {
    if (active) return Promise.reject(new Error('TOTP challenge already active'));
    active = true;
    ensureStyles();
    return new Promise(resolve => {
      const overlay = document.createElement('div');
      overlay.id = 'brvtal-totp-overlay';
      overlay.innerHTML = `<div class="brvtal-totp-box"><div style="font-size:9px;letter-spacing:2px;color:#747b82;margin-bottom:8px">BRVTAL / DISCADMIN</div><h2>2FA VERIFICATION</h2><p>Enter the 6-digit code from Google Authenticator. You can also use a recovery code if you no longer have access to the authenticator.</p><input id="brvtal-totp-code" inputmode="numeric" autocomplete="one-time-code" maxlength="20" placeholder="000000"><div id="brvtal-totp-error" class="brvtal-totp-error"></div><div class="brvtal-totp-actions"><button type="button" id="brvtal-totp-cancel">CANCEL</button><button type="button" class="primary" id="brvtal-totp-submit">VERIFY</button></div><span id="brvtal-totp-recovery" class="brvtal-totp-mode">Use recovery code</span></div>`;
      document.body.appendChild(overlay);
      const input = overlay.querySelector('#brvtal-totp-code');
      const error = overlay.querySelector('#brvtal-totp-error');
      const submit = overlay.querySelector('#brvtal-totp-submit');
      const cancel = overlay.querySelector('#brvtal-totp-cancel');
      const recovery = overlay.querySelector('#brvtal-totp-recovery');
      let recoveryMode = false;

      const fail = msg => { error.textContent = msg; error.classList.add('show'); input.focus(); };
      const close = () => { overlay.remove(); active = false; };
      const verify = async () => {
        const code = input.value.trim();
        if (!code) return fail('Enter your verification code.');
        submit.disabled = true;
        error.classList.remove('show');
        try {
          const r = await nativeFetch(AUTH, {method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({action:'totp_verify',code})});
          const data = await r.json();
          if (!r.ok || !data.ok) throw new Error(data.error === 'RATE_LIMITED' ? 'Too many attempts. Try again later.' : 'Invalid verification code.');
          close(); resolve(data);
        } catch (e) {
          submit.disabled = false;
          fail(e.message || 'Verification failed.');
        }
      };
      recovery.addEventListener('click', () => {
        recoveryMode = !recoveryMode;
        recovery.textContent = recoveryMode ? 'Use authenticator code' : 'Use recovery code';
        input.value = '';
        input.maxLength = recoveryMode ? 10 : 6;
        input.placeholder = recoveryMode ? 'XXXXXXXXXX' : '000000';
        input.inputMode = recoveryMode ? 'text' : 'numeric';
        input.focus();
      });
      submit.addEventListener('click', verify);
      input.addEventListener('keydown', e => { if (e.key === 'Enter') verify(); });
      cancel.addEventListener('click', async () => { await cancelPending(); close(); resolve(null); });
      input.focus();
    });
  }

  const nativeFetch = window.fetch.bind(window);
  window.fetch = async (...args) => {
    const request = args[0];
    const options = args[1] || {};
    const url = typeof request === 'string' ? request : request?.url || '';
    const method = String(options.method || (request instanceof Request ? request.method : 'GET')).toUpperCase();
    const response = await nativeFetch(...args);
    if (method !== 'POST' || !url.includes('/api/auth') || response.status === 401 || response.status >= 500) return response;
    let data;
    try { data = await response.clone().json(); } catch (_) { return response; }
    if (!data || !data.requires_totp) return response;
    const verified = await challenge();
    if (!verified) return new Response(JSON.stringify({ok:false,error:'TOTP_CANCELLED'}), {status:401,headers:{'Content-Type':'application/json'}});
    ensureSecurityLink();
    return new Response(JSON.stringify(verified), {status:200,headers:{'Content-Type':'application/json'}});
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ensureSecurityLink, {once:true});
  else ensureSecurityLink();
})();
