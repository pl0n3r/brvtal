(() => {
  'use strict';
  // #1945: render the entry QR from the URL fragment only. Nothing here sends
  // the fragment anywhere: no fetch, no storage, no analytics.
  const pattern = /^BRVTAL:T1:([a-f0-9]{32}):[A-Za-z0-9_-]{43}$/;
  const pass = document.getElementById('ticket-pass');
  const error = document.getElementById('ticket-error');
  const code = document.getElementById('ticket-code');
  const ref = document.getElementById('ticket-ref');
  const printButton = document.getElementById('ticket-print');
  let renderedHash = null;

  function show(valid) {
    pass.hidden = !valid;
    error.hidden = valid;
    printButton.disabled = !valid;
  }

  function render() {
    let payload = '';
    try {
      payload = decodeURIComponent(location.hash.slice(1));
    } catch {
      payload = '';
    }
    const match = pattern.exec(payload);
    renderedHash = null;
    ref.textContent = '';
    code.replaceChildren();
    if (!match || typeof window.QRCode !== 'function') {
      show(false);
      return;
    }
    const size = Math.max(200, Math.min(320, Math.floor(window.innerWidth - 96)));
    new window.QRCode(code, {
      text: payload,
      width: size,
      height: size,
      colorDark: '#000000',
      colorLight: '#ffffff',
      correctLevel: window.QRCode.CorrectLevel.M,
    });
    // The library copies the payload into a tooltip; keep the secret off hover.
    code.removeAttribute('title');
    code.querySelectorAll('[title]').forEach((node) => node.removeAttribute('title'));
    code.querySelectorAll('img').forEach((img) => { img.alt = ''; });
    ref.textContent = match[1].slice(0, 8).toUpperCase();
    renderedHash = location.hash;
    show(true);
  }

  printButton.addEventListener('click', () => {
    if (pass.hidden || renderedHash !== location.hash) return;
    window.print();
  });
  window.addEventListener('hashchange', render);
  render();
})();
