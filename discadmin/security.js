(() => {
  'use strict';

  function node(tag, className = '', text = '') {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (text !== '') element.textContent = text;
    return element;
  }

  function button(id, text, className = '') {
    const element = node('button', className, text);
    element.type = 'button';
    element.id = id;
    return element;
  }

  function input(id, maxLength, label) {
    const element = document.createElement('input');
    element.id = id;
    element.inputMode = 'numeric';
    element.maxLength = maxLength;
    element.autocomplete = 'one-time-code';
    element.placeholder = '000000';
    element.setAttribute('aria-label', label);
    return element;
  }

  function statusRow(enabled, emailValue) {
    const row = node('div', 'row');
    const status = node('span', enabled ? 'status on' : 'status');
    status.append(node('span', 'dot'), document.createTextNode(enabled ? '2FA ENABLED' : '2FA DISABLED'));
    const email = node('span', 'muted');
    email.dataset.securityEmail = '1';
    email.textContent = String(emailValue || '');
    row.append(status, email);
    return row;
  }

  function disabledControls(card) {
    card.append(
      node('h2', '', 'Enable 2FA'),
      node('p', 'muted', 'Start enrollment, scan the QR locally, then confirm with the current six-digit code. The secret is never sent to a third-party QR service.'),
      button('start', 'START ENROLLMENT')
    );
    const setup = node('div', 'setup');
    setup.id = 'setup';
    const qr = node('div', 'qr');
    qr.id = 'qr';
    const secret = node('div', 'secret');
    secret.id = 'secret';
    const confirmRow = node('div', 'row');
    confirmRow.append(input('code', 6, 'Authenticator confirmation code'), button('confirm', 'CONFIRM & ENABLE'));
    const message = node('div');
    message.id = 'message';
    setup.append(
      node('h2', '', '1. Add BRVTAL to your authenticator'),
      qr,
      node('div', 'muted', 'If you cannot scan the QR, enter this setup key manually:'),
      secret,
      node('h2', '', '2. Confirm'),
      confirmRow,
      message
    );
    card.append(setup);
  }

  function enabledControls(card) {
    card.append(
      node('h2', '', '2FA is active'),
      node('p', 'muted', 'This administrator has confirmed a TOTP authenticator. Disabling it requires a current authenticator or unused recovery code.'),
      button('disable', 'DISABLE 2FA', 'secondary')
    );
    const disableBox = node('div', 'setup');
    disableBox.id = 'disableBox';
    const row = node('div', 'row');
    row.append(input('disableCode', 20, 'Authenticator or recovery code'), button('disableConfirm', 'CONFIRM DISABLE'));
    disableBox.append(
      node('h2', '', 'Verify before disabling'),
      node('p', 'muted', 'Enter a current six-digit authenticator code or one unused recovery code.'),
      row
    );
    const message = node('div');
    message.id = 'message';
    card.append(disableBox, message);
  }

  function render(host, state = {}, csrf = '') {
    if (!host) return null;
    const enabled = state.enabled === true;
    const section = document.createElement('section');
    section.dataset.adminModule = 'security';
    section.dataset.settingsEmbeddedSecurity = '1';
    section.dataset.csrf = String(csrf || '');

    const wrap = node('div', 'wrap');
    wrap.append(node('h2', '', 'Two-Factor Authentication'));
    const card = node('div', 'card');
    card.append(
      statusRow(enabled, state.email),
      node('div', 'notice', 'Google Authenticator / RFC 6238 is available for this administrator. Enabling it here does not enable 2FA for other administrators.')
    );
    if (enabled) enabledControls(card);
    else disabledControls(card);
    wrap.append(card);
    section.append(wrap);
    host.replaceChildren(section);
    mount(section);
    return section;
  }

  function mount(root) {
    if (!root) return;
    root.dataset.securityMounted = '1';
    const csrf = root.dataset.csrf || '';
    const setup = root.querySelector('#setup');
    const msg = root.querySelector('#message');

    const post = async (action, data = {}) => {
      const response = await fetch('/discadmin/totp-api.php?action=' + encodeURIComponent(action), {
        method:'POST',
        headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
        body:JSON.stringify(data),
        credentials:'same-origin'
      });
      const payload = await response.json().catch(() => ({ok:false,error:'INVALID_RESPONSE'}));
      if (!response.ok || !payload.ok) throw new Error(payload.message || payload.error || 'Request failed');
      return payload;
    };

    const say = (text, className = 'success') => {
      if (!msg) return;
      msg.className = className;
      msg.textContent = text;
    };

    const start = root.querySelector('#start');
    if (start) start.onclick = async () => {
      start.disabled = true;
      try {
        const payload = await post('start');
        root.querySelector('#secret').textContent = payload.secret;
        setup.classList.add('show');
        const qr = root.querySelector('#qr');
        if (window.QRCode) {
          new QRCode(qr, {text:payload.otpauth,width:210,height:210,correctLevel:QRCode.CorrectLevel.M});
        } else {
          qr.textContent = 'QR library unavailable. Use the setup key above.';
        }
      } catch (error) {
        start.disabled = false;
        say(error.message, 'error');
      }
    };

    const confirmBtn = root.querySelector('#confirm');
    if (confirmBtn) confirmBtn.onclick = async () => {
      confirmBtn.disabled = true;
      try {
        const payload = await post('confirm', {code:root.querySelector('#code').value});
        say('2FA enabled. Save these recovery codes now; they will not be shown again.');
        setup.classList.add('show');
        root.querySelector('#secret').textContent = '';
        const qr = root.querySelector('#qr');
        qr.replaceChildren();
        const pre = node('pre', 'codes');
        pre.textContent = payload.recovery_codes.join('\n');
        qr.after(pre);
      } catch (error) {
        confirmBtn.disabled = false;
        start.disabled = false;
        setup.classList.remove('show');
        root.querySelector('#secret').textContent = '';
        root.querySelector('#qr').replaceChildren();
        root.querySelector('#code').value = '';
        say(error.message === 'TOTP_UNAVAILABLE' ? '2FA could not be saved. Start a new enrollment.' : error.message, 'error');
      }
    };

    const disable = root.querySelector('#disable');
    const disableBox = root.querySelector('#disableBox');
    const disableConfirm = root.querySelector('#disableConfirm');
    if (disable) disable.onclick = () => {
      disableBox.classList.toggle('show');
      if (disableBox.classList.contains('show')) root.querySelector('#disableCode').focus();
    };
    if (disableConfirm) disableConfirm.onclick = async () => {
      disableConfirm.disabled = true;
      try {
        await post('disable', {code:root.querySelector('#disableCode').value});
        if (window.BRVTALSettingsV2?.reloadSecurity) await window.BRVTALSettingsV2.reloadSecurity();
        else await window.go?.('security');
      } catch (error) {
        disableConfirm.disabled = false;
        say(error.message, 'error');
      }
    };
  }

  window.BRVTALSecurity = {mount, render};
})();
