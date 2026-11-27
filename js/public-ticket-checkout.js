/* T13: only verified server state can confirm payment; never trust redirect data. */
(() => {
  const status = document.getElementById('ticket-checkout-status');
  if (!status) return;
  const panel = status.closest('.event-ticket-checkout');
  const storageKey = 'brvtal-checkout:' + location.pathname;
  let record = null;
  let intent = null;
  try {
    const saved = JSON.parse(sessionStorage.getItem(storageKey) || 'null');
    if (saved && typeof saved === 'object' && !Array.isArray(saved)) record = saved;
    // Compatibility for pending orders saved by older versions of the checkout.
    else if (Number.isSafeInteger(saved) && saved > 0) record = { order_id: String(saved) };
  } catch { /* Storage unavailable: no inferred payment confirmation. */ }
  intent = typeof record?.intent === 'string' ? record.intent : null;
  const save = next => {
    try {
      sessionStorage.setItem(storageKey, JSON.stringify(next));
      record = next;
      return true;
    } catch { return false; }
  };
  const clear = () => {
    record = null;
    try { sessionStorage.removeItem(storageKey); } catch { /* Private mode. */ }
  };
  const say = message => { status.textContent = message; };
  // Hosted Wompi checkout. Each environment only accepts its own public key prefix.
  // The redirect never means the payment was confirmed: only the signed webhook does.
  function goToWompi(checkout) {
    const keyPrefix = { sandbox: 'pub_test_', production: 'pub_prod_' }[checkout?.environment];
    if (!keyPrefix || checkout.currency !== 'COP'
      || !checkout.public_key?.startsWith(keyPrefix)) throw new Error('INVALID_ENVIRONMENT');
    const fields = {
      'public-key': checkout.public_key, currency: 'COP',
      'amount-in-cents': String(checkout.amount_in_cents),
      reference: checkout.reference, 'signature:integrity': checkout.signature_integrity,
      'redirect-url': checkout.redirect_url,
    };
    const hosted = document.createElement('form');
    hosted.action = 'https://checkout.wompi.co/p/';
    hosted.method = 'GET';
    for (const [name, value] of Object.entries(fields)) {
      const field = document.createElement('input');
      field.type = 'hidden';
      field.name = name;
      field.value = value;
      hosted.append(field);
    }
    document.body.append(hosted);
    hosted.submit();
  }
  // #1958: Wompi Widget (docs.wompi.co/docs/colombia/widget-checkout-web): pay without
  // leaving BRVTAL. The widget only starts the payment; the signed webhook or the
  // server's Wompi API check (#1952) is what confirms it. Falls back to the redirect form.
  const WOMPI_WIDGET_SRC = 'https://checkout.wompi.co/widget.js';
  let widgetLoading = null;
  function loadWompiWidget() {
    if (typeof window.WidgetCheckout === 'function') return Promise.resolve(window.WidgetCheckout);
    widgetLoading ||= new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = WOMPI_WIDGET_SRC;
      script.async = true;
      const timer = setTimeout(() => reject(new Error('WIDGET_TIMEOUT')), 8000);
      script.onload = () => {
        clearTimeout(timer);
        if (typeof window.WidgetCheckout === 'function') resolve(window.WidgetCheckout);
        else reject(new Error('WIDGET_UNAVAILABLE'));
      };
      script.onerror = () => { clearTimeout(timer); reject(new Error('WIDGET_UNAVAILABLE')); };
      document.head.append(script);
    }).catch(error => { widgetLoading = null; throw error; });
    return widgetLoading;
  }
  async function payWithWompi(checkout, { token = '', buyer = null } = {}) {
    const keyPrefix = { sandbox: 'pub_test_', production: 'pub_prod_' }[checkout?.environment];
    if (!keyPrefix || checkout.currency !== 'COP'
      || !checkout.public_key?.startsWith(keyPrefix)) throw new Error('INVALID_ENVIRONMENT');
    const confirmation = /^[a-f0-9]{32}$/.test(token) ? '/compra/' + token : '';
    let Widget;
    try {
      Widget = await loadWompiWidget();
    } catch {
      goToWompi(checkout); // Widget unavailable: same payment through the hosted page.
      return;
    }
    const widget = new Widget({
      currency: 'COP', amountInCents: checkout.amount_in_cents, reference: checkout.reference,
      publicKey: checkout.public_key, signature: { integrity: checkout.signature_integrity },
      redirectUrl: checkout.redirect_url,
      ...(buyer ? { customerData: { email: buyer.email, fullName: buyer.name } } : {}),
    });
    // Whatever the widget reports, the confirmation page asks the server.
    widget.open(() => { if (confirmation) location.assign(confirmation); });
  }
  // #1940: a checkout call never waits forever; 25 s then a clear, retryable message.
  const CHECKOUT_TIMEOUT_MS = Number(window.BRVTAL_CHECKOUT_TIMEOUT_MS) > 0
    ? Number(window.BRVTAL_CHECKOUT_TIMEOUT_MS) : 25000;
  async function timedFetch(url, options = {}) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), CHECKOUT_TIMEOUT_MS);
    try {
      return await fetch(url, { ...options, signal: controller.signal });
    } catch (error) {
      if (controller.signal.aborted) throw new Error('CHECKOUT_TIMEOUT');
      throw error;
    } finally {
      clearTimeout(timer);
    }
  }
  const TIMEOUT_MESSAGE = 'El servidor tardó demasiado en responder. Tu selección quedó guardada: vuelve a pulsar «Pagar» para reintentar.';
  async function json(response) {
    const payload = await response.json();
    if (!response.ok || !payload.ok) throw new Error(payload.error || 'CHECKOUT_UNAVAILABLE');
    return payload;
  }
  let statusRetries = 0;
  function retryStatus(saved, response) {
    if (statusRetries === 0) say('No se pudo actualizar el pago. Volveremos a consultar.');
    const raw = response.headers.get('Retry-After') || '';
    const ms = /^\d+$/.test(raw) ? Number(raw) * 1000 : Date.parse(raw) - Date.now();
    const delay = Number.isFinite(ms) ? Math.max(1000, ms) : 8000;
    if (++statusRetries < 45 && delay <= 2147483647) {
      setTimeout(() => { if (record === saved) void refresh(saved); }, delay);
    }
  }
  function renderCheckoutState(saved, state) {
    const states = {
      approved: 'Pago confirmado. Te enviamos las boletas con QR a tu correo.',
      failed: 'El pago no fue aprobado. Puedes volver a intentarlo.',
      cancelled: 'El pago fue cancelado.',
      pending: 'Pago pendiente de confirmación.',
      unverified: 'No existe una confirmación verificable. No se han emitido boletas.',
    };
    const number = /^[1-9]\d{0,17}$/.test(String(saved?.order_id || '')) ? `Pedido #${saved.order_id}: ` : '';
    say(number + (states[state] || states.unverified));
    if (state === 'pending' && /^[a-f0-9]{32}$/.test(String(saved?.order_token || ''))) {
      const link = document.createElement('a');
      link.className = 'event-checkout-follow';
      link.href = '/compra/' + saved.order_token;
      link.textContent = 'Ver el estado de tu compra';
      status.append(' ', link);
    }
    if (['approved', 'failed', 'cancelled'].includes(state)) {
      clear();
      intent = null;
    }
  }
  async function refresh(saved) {
    if (record !== saved) return;
    if (panel) panel.hidden = false;
    const url = new URL(location.pathname, location.origin);
    // #1948: guests have no session; their opaque order_token identifies the order.
    if (/^[a-f0-9]{32}$/.test(String(saved?.order_token || ''))) {
      url.searchParams.set('order_token', saved.order_token);
    } else {
      url.searchParams.set('order_id', String(saved.order_id));
    }
    try {
      const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store',
        headers: { 'X-BRVTAL-TICKET-CHECKOUT': 'status' } });
      if (record !== saved) return;
      if (response.status === 429 || response.status === 503) {
        retryStatus(saved, response);
        return;
      }
      const payload = await json(response);
      if (record !== saved) return;
      statusRetries = 0;
      renderCheckoutState(saved, payload.state);
    } catch {
      if (record === saved) say('No se pudo consultar el pago. Revisa el estado de tu compra más tarde.');
    }
  }
  const cartRecord = () => Array.isArray(record?.lines);
  if (!cartRecord() && /^[1-9]\d{0,17}$/.test(String(record?.order_id || ''))) {
    void refresh(record);
  }

  // #2112: the catalog is not the source of truth for an existing reservation.
  const tokenValid = token => typeof token === 'string' && /^[a-f0-9]{32}$/.test(token);
  const methods = { CARD: 'Tarjeta', NEQUI: 'Nequi', PSE: 'PSE' };
  const cop = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 });
  const cartBody = saved => ({ lines: saved.lines, method: saved.method, idempotency_key: saved.intent });
  const cartAuth = async () => json(await timedFetch('/api/buyer/auth.php', { credentials: 'same-origin', cache: 'no-store' }));
  const cartRequest = (action, body, auth) => timedFetch(location.pathname, {
    method: 'POST', credentials: 'same-origin', cache: 'no-store',
    headers: { 'Content-Type': 'application/json', 'X-BRVTAL-TICKET-CHECKOUT': action,
      ...(auth.authenticated ? { 'X-CSRF-TOKEN': auth.csrf } : {}) },
    body: JSON.stringify(body),
  });
  let recoveryBox, recoveryBusy = false, retryAt = 0;
  let updateCart = () => {}, updateSingle = () => {};
  const sameCart = (a, b) => Array.isArray(b) && a.length === b.length
    && a.every(line => b.some(item => item.ticket_type_id === line?.ticket_type_id && item.quantity === line?.quantity));
  async function readReservation(saved) {
    const auth = await cartAuth();
    if (!/^[A-Za-z0-9:_-]{16,128}$/.test(saved.intent || '') || !Object.hasOwn(methods, saved.method)
      || (!auth.authenticated && !tokenValid(saved.order_token))) return { state: 'unverified', auth };
    const response = await cartRequest('snapshot', auth.authenticated ? cartBody(saved) : { order_token: saved.order_token }, auth);
    if (response.status === 429 || response.status === 503) {
      const raw = response.headers.get('Retry-After') || '';
      const ms = /^\d+$/.test(raw) ? Number(raw) * 1000 : Date.parse(raw) - Date.now();
      retryAt = Date.now() + (Number.isFinite(ms) ? Math.max(1000, ms) : 8000);
      throw new Error('RECOVERY_UNAVAILABLE');
    }
    const result = await json(response);
    const snap = result.snapshot;
    if (!snap || !tokenValid(snap.order_token) || (saved.order_token && snap.order_token !== saved.order_token)
      || !Object.hasOwn(methods, snap.method) || snap.method !== saved.method || snap.currency !== 'COP'
      || !Number.isSafeInteger(snap.total_cop) || snap.total_cop < 1 || !sameCart(saved.lines, snap.lines)) {
      return { state: 'unverified', auth };
    }
    return { state: result.state, snap, auth };
  }
  function amountOf(result, method) {
    const checkout = result.checkout;
    if (!tokenValid(result.order_token) || checkout?.currency !== 'COP'
      || !Number.isSafeInteger(checkout.amount_in_cents) || checkout.amount_in_cents <= 0
      || checkout.amount_in_cents % 100 !== 0
      || !String(checkout.reference).startsWith('BW7-' + result.order_id + '-' + method + '-')) {
      throw new Error('INVALID_CHECKOUT');
    }
    return checkout.amount_in_cents / 100;
  }
  function recoveryControl(label, action) {
    const button = typeof action === 'function';
    const el = document.createElement(button ? 'button' : 'a');
    el.className = button ? 'event-cart-pay' : 'event-checkout-follow'; el.textContent = label;
    if (button) { el.type = 'button'; el.addEventListener('click', action); }
    else el.href = action;
    recoveryBox.append(el); return el;
  }
  function recoveryControls(saved) {
    if (!panel || !cartRecord()) return;
    panel.hidden = false;
    recoveryBox ||= Object.assign(document.createElement('div'), { className: 'event-cart' });
    if (!recoveryBox.isConnected) { recoveryBox.dataset.cartRecovery = '1'; status.after(recoveryBox); }
    recoveryBox.replaceChildren();
    const query = recoveryControl('Consultar reserva', () => void recoverCart(saved));
    query.disabled = Date.now() < retryAt;
    if (query.disabled) setTimeout(() => { if (query.isConnected) query.disabled = false; }, Math.min(2147483647, retryAt - Date.now()));
    if (tokenValid(saved.order_token)) recoveryControl('Ver el estado de tu compra', '/compra/' + saved.order_token);
  }
  const payable = found => found.auth.authenticated === true && found.state === 'reserved'
    && found.snap?.payment_state === 'created';
  const sameQuote = (a, b) => a?.order_token === b?.order_token && a?.method === b?.method
    && a?.total_cop === b?.total_cop && a?.expires_at_utc === b?.expires_at_utc;
  function showReservation(saved, found) {
    recoveryControls(saved);
    const labels = { reserved: 'Reserva vigente', payment_pending: 'Pago pendiente de confirmación',
      expired: 'Reserva vencida', approved: 'Pago confirmado', failed: 'Pago no aprobado',
      cancelled: 'Pago cancelado', refunded: 'Pago reembolsado' };
    const { snap, state } = found;
    if (!snap || !Object.hasOwn(labels, state)) {
      say('No pudimos verificar tu reserva. No inicies otro pago. Inicia sesión o contacta con BRVTAL.');
      recoveryControl('Iniciar sesión', '/mi-cuenta/acceso'); recoveryControl('Contactar con BRVTAL', '/contact');
      return;
    }
    say(labels[state] + '.');
    const details = document.createElement('p');
    details.textContent = snap.lines.map(line => line.quantity + ' × ' + line.name + ' (' + cop.format(line.subtotal_cop) + ')').join(' · ')
      + ' · Total reservado: ' + cop.format(snap.total_cop) + ' · ' + methods[snap.method]
      + ' · Vence (UTC): ' + (snap.expires_at_utc || 'No disponible');
    recoveryBox.prepend(details);
    if (!saved.order_token) recoveryControl('Ver el estado de tu compra', '/compra/' + snap.order_token);
    if (payable(found)) {
      recoveryControl('Confirmar y pagar ' + cop.format(snap.total_cop), () => void recoverCart(saved, snap));
    } else if (['approved','failed','cancelled','refunded'].includes(state)) {
      recoveryControl('Iniciar nueva compra', () => {
        if (recoveryBusy || record !== saved) return;
        clear(); intent = null; recoveryBox.remove(); recoveryBox = null;
        say('Selecciona tus boletas.'); updateCart(); updateSingle();
      });
    }
  }
  async function recoverCart(saved, confirmed = null) {
    if (record !== saved || recoveryBusy || Date.now() < retryAt) return;
    recoveryBusy = true;
    recoveryBox?.querySelectorAll('button').forEach(button => { button.disabled = true; });
    say('Verificando tu reserva…');
    try {
      const found = await readReservation(saved);
      showReservation(saved, found);
      if (!confirmed || !payable(found) || !sameQuote(found.snap, confirmed)) return;
      recoveryBox.querySelectorAll('button').forEach(button => { button.disabled = true; });
      const result = await json(await cartRequest('prepare', cartBody(saved), found.auth));
      if (result.order_token !== confirmed.order_token || amountOf(result, confirmed.method) !== confirmed.total_cop) {
        throw new Error('QUOTE_CHANGED');
      }
      const latest = await readReservation(saved);
      showReservation(saved, latest);
      if (!payable(latest) || !sameQuote(latest.snap, confirmed)) return;
      if (!save({ ...saved, order_token: result.order_token, order_id: String(result.order_id) })) throw new Error('STORAGE');
      recoveryControls(record);
      say('Completa el pago en Wompi. Si no puedes confirmar el resultado, consulta la reserva.');
      await payWithWompi(result.checkout, { token: result.order_token });
    } catch {
      recoveryControls(record);
      say('No se pudo verificar la reserva. Consulta de nuevo cuando el servicio esté disponible; no inicies otro pago.');
    } finally { recoveryBusy = false; }
  }

  // T28 public discovery is read-only and independent of public_page.php.
  // Avoid overlapping the buyer-account (#1821) V3 claim on that renderer.
  const mountWaitlists = async () => {
    const match = location.pathname.match(/^\/events\/([a-z0-9-]{1,100})\/?$/);
    if (!match) return;
    try {
      const catalog = await json(await fetch(
        '/api/ticketing/waitlist.php?catalog=' + encodeURIComponent(match[1]),
        { credentials:'same-origin',cache:'no-store' }));
      const types = Array.isArray(catalog.types) ? catalog.types : [];
      if (!types.length || !Number.isSafeInteger(catalog.event_id)
          || catalog.event_id < 1) return;
      const parent = panel?.parentElement;
      if (!parent) return;

      const section = document.createElement('section');
      section.className = 'event-ticket-checkout';
      section.id = 'lista-espera';
      section.setAttribute('aria-label','Lista de espera');
      const title = document.createElement('h2');
      title.textContent = 'Lista de espera';
      const notice = document.createElement('p');
      notice.textContent = 'Inscripción con consentimiento. No reserva cupos ni confirma pagos.';
      section.append(title,notice);
      const containers = [];
      for (const type of types.slice(0,30)) {
        if (!Number.isSafeInteger(type.id) || type.id < 1
            || typeof type.name !== 'string') continue;
        const box = document.createElement('div');
        box.className = 'brvtal-public-waitlist';
        const h3 = document.createElement('h3');
        h3.textContent = type.name;
        box.append(h3);
        section.append(box);
        containers.push([box,type.id]);
      }
      if (!containers.length) return;
      parent.insertBefore(section,panel.nextSibling);

      // Sold-out pages have a status-only checkout. Create only an invite
      // form using server-confirmed public prices and supported methods.
      const checkout = catalog.checkout;
      let newForm = false;
      if (!document.getElementById('brvtal-ticket-checkout')
          && checkout && checkout.invite_mode === true
          && Array.isArray(checkout.offers) && Array.isArray(checkout.methods)) {
        const offers = checkout.offers.filter(o => Number.isSafeInteger(o.id)
          && o.id > 0 && Number.isSafeInteger(o.price_cop) && o.price_cop > 0
          && Number.isSafeInteger(o.available) && o.available >= 0
          && typeof o.title === 'string');
        const methods = checkout.methods.filter(m =>
          ['CARD','NEQUI','PSE'].includes(m));
        if (offers.length && methods.length && panel) {
          const form = document.createElement('form');
          form.id = 'brvtal-ticket-checkout';
          const field = (caption,name,tag) => {
            const label = document.createElement('label');
            label.append(document.createTextNode(caption));
            const control = document.createElement(tag);
            control.name = name;
            label.append(control);
            form.append(label);
            return control;
          };
          const type = field('Tipo','ticket_type_id','select');
          for (const offer of offers) {
            const option = document.createElement('option');
            option.value = String(offer.id);
            option.textContent = offer.title + (offer.available === 0 ? ' · solo con invitación' : '');
            option.dataset.price = String(offer.price_cop);
            option.dataset.available = String(Math.min(20,offer.available));
            option.dataset.inviteOnly = offer.invite_only ? '1' : '0';
            type.append(option);
          }
          const quantity = field('Cantidad','quantity','input');
          quantity.type='number';quantity.value='1';quantity.min='1';
          quantity.max='20';quantity.step='1';
          const method = field('Método','method','select');
          for (const name of methods) {
            const option = document.createElement('option');
            option.value = name;option.textContent = name;method.append(option);
          }
          const code = field('Código de invitación','waitlist_token','input');
          code.type='text';code.maxLength=43;code.autocomplete='off';code.spellcheck=false;
          const total=document.createElement('output');
          total.id='ticket-checkout-total';total.className='checkout-total';
          total.setAttribute('aria-live','polite');
          const button=document.createElement('button');
          button.type='submit';button.textContent='Continuar al pago';
          form.append(total,button);
          panel.querySelector('h2')?.replaceChildren(
            document.createTextNode('Escoge tus boletas'));
          panel.insertBefore(form,status);
          panel.hidden=false;
          newForm=true;
        }
      }
      const auth = await json(await fetch('/api/buyer/auth.php', {
        credentials:'same-origin',cache:'no-store' }));
      if (!auth.authenticated || typeof auth.csrf !== 'string' || !auth.csrf) {
        for (const [container] of containers) {
          const link = document.createElement('a');
          link.href = '/buyer/login.php';
          link.textContent = 'Inicia sesión y verifica tu correo para inscribirte';
          container.append(link);
        }
      } else {
        const { mountPublic } = await import('/discadmin/ticketing-waitlist.js');
        for (const [container,typeId] of containers) {
          mountPublic(container,{ eventId:catalog.event_id,typeId,csrf:auth.csrf });
        }
      }
      if (newForm) initCheckout();
    } catch {
      // Fail closed: no signup or checkout UI without a verified catalog.
    }
  };

  // #1929 cart: several ticket types in one order. Prices shown here are a preview;
  // the server recomputes prices and capacity, and only the signed webhook confirms payment.
  const initCart = () => {
    const form = document.getElementById('brvtal-ticket-cart');
    if (!form || form.dataset.cartInitialized === '1') return;
    form.dataset.cartInitialized = '1';
    const currency = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 });
    // Same "$25.000" shape the server renders (Intl inserts a space after the symbol).
    const money = { format: value => currency.format(value).replace(/\s/gu, '') };
    const rows = Array.from(form.querySelectorAll('[data-cart-type]')).map(row => ({
      row, id: Number(row.dataset.cartType), name: row.dataset.name || '',
      price: Number(row.dataset.price), available: Math.min(20, Number(row.dataset.available) || 0),
      output: row.querySelector('[data-cart-qty]'),
      minus: row.querySelector('[data-cart-step="-1"]'), plus: row.querySelector('[data-cart-step="1"]'),
    })).filter(item => Number.isSafeInteger(item.id) && item.id > 0 && Number.isSafeInteger(item.price) && item.price > 0);
    const quantities = new Map(rows.map(item => [item.id, 0]));
    const totalEl = form.querySelector('[data-cart-total]');
    const linesEl = form.querySelector('[data-cart-lines]');
    const pay = form.querySelector('[data-cart-pay]');
    const stickySummary = document.querySelector('[data-cart-sticky-summary]');
    const stickyChoose = document.querySelector('[data-cart-sticky-choose]');
    const stickyPay = document.querySelector('[data-cart-sticky-pay]');
    const stickyIdle = stickySummary?.textContent || '';
    let submitting = false;
    const buyerBox = form.querySelector('[data-cart-buyer]');
    const accountNote = form.querySelector('[data-cart-account]');
    const field = name => form.elements.namedItem(name);
    const buyerErrors = {
      buyer_email: 'Escribe un correo válido: ahí te llegan las boletas.',
      buyer_name: 'Escribe tu nombre (mínimo 2 letras).',
      buyer_consent: 'Acepta el tratamiento de datos para poder comprar.',
    };
    // Guest details: validated here for fast feedback, re-validated by the server.
    function readBuyer() {
      for (const name of Object.keys(buyerErrors)) field(name)?.removeAttribute('aria-invalid');
      const email = field('buyer_email');
      const name = field('buyer_name');
      const consent = field('buyer_consent');
      email.value = email.value.trim();
      name.value = name.value.replace(/\s+/gu, ' ').trim();
      for (const input of [email, name, consent]) {
        if (!input.checkValidity() || (input === name && name.value.length < 2)) {
          input.setAttribute('aria-invalid', 'true');
          say(buyerErrors[input.name]);
          input.focus();
          return null;
        }
      }
      return { email: email.value, name: name.value, consent: true, website: field('website')?.value || '' };
    }
    function lockBuyer(locked) {
      for (const name of ['buyer_email', 'buyer_name']) {
        const input = field(name);
        if (input) input.readOnly = locked;
      }
      const consent = field('buyer_consent');
      if (consent) consent.disabled = locked;
    }
    const signedIn = authenticated => {
      if (buyerBox) buyerBox.hidden = authenticated;
      if (accountNote) accountNote.hidden = authenticated;
    };
    fetch('/api/buyer/auth.php', { credentials: 'same-origin', cache: 'no-store' })
      .then(response => response.json()).then(auth => signedIn(auth?.authenticated === true))
      .catch(() => { /* Unknown session: keep the guest fields visible. */ });
    const method = () => form.querySelector('input[name="method"]:checked')?.value || '';
    const lines = () => rows.filter(item => quantities.get(item.id) > 0)
      .map(item => ({ ticket_type_id: item.id, quantity: quantities.get(item.id) }));
    const units = () => Array.from(quantities.values()).reduce((sum, value) => sum + value, 0);
    function update() {
      const count = units();
      let total = 0;
      const summary = [];
      for (const item of rows) {
        const qty = quantities.get(item.id);
        total += qty * item.price;
        item.output.textContent = String(qty);
        item.row.classList.toggle('is-selected', qty > 0);
        item.minus.disabled = submitting || qty < 1;
        item.plus.disabled = submitting || qty >= item.available || count >= 20;
        if (qty > 0) {
          const li = document.createElement('li');
          const label = document.createElement('span');
          label.textContent = `${qty} × ${item.name}`;
          const amount = document.createElement('span');
          amount.textContent = money.format(qty * item.price);
          li.append(label, amount);
          summary.push(li);
        }
      }
      linesEl?.replaceChildren(...summary);
      if (totalEl) totalEl.textContent = money.format(total);
      const ready = count > 0 && method() !== '';
      pay.disabled = submitting || cartRecord() || !ready;
      pay.textContent = submitting ? 'Reservando…' : cartRecord() ? 'Consulta tu reserva' : (count > 0 ? `Pagar ${money.format(total)}` : 'Elige tus boletas');
      form.querySelectorAll('input[name="method"]').forEach(input => { input.disabled = submitting; });
      if (stickySummary) stickySummary.textContent = count > 0
        ? `${count} ${count === 1 ? 'boleta' : 'boletas'} · ${money.format(total)}` : stickyIdle;
      if (stickyChoose) stickyChoose.hidden = count > 0;
      if (stickyPay) { stickyPay.hidden = count < 1; stickyPay.disabled = pay.disabled; }
    }
    function newPurchase() {
      if (submitting) return; // Never discard an in-flight key on input.
      update();
    }
    form.addEventListener('click', event => {
      const step = event.target.closest('[data-cart-step]');
      if (!step || step.disabled) return;
      const item = rows.find(candidate => candidate.row.contains(step));
      if (!item) return;
      const next = quantities.get(item.id) + Number(step.dataset.cartStep);
      if (next < 0 || next > item.available || (next > quantities.get(item.id) && units() >= 20)) return;
      quantities.set(item.id, next);
      newPurchase();
    });
    form.addEventListener('change', event => {
      if (event.target.name === 'method') newPurchase();
    });
    // Keep error presentation separate from the payment/redirect flow.
    // Server responses stay authoritative; never infer a successful payment.
    function reportCheckoutError(error) {
      const guestField = {
        GUEST_EMAIL_INVALID: 'buyer_email',
        GUEST_NAME_INVALID: 'buyer_name',
        GUEST_CONSENT_REQUIRED: 'buyer_consent',
      }[error.message];
      if (guestField) {
        field(guestField)?.setAttribute('aria-invalid', 'true');
        field(guestField)?.focus();
        say(buyerErrors[guestField]);
        return;
      }
      const feedback = {
        CHECKOUT_TIMEOUT: 'El servidor tardó demasiado en responder. Consulta tu reserva antes de volver a pagar.',
        CHECKOUT_EXPIRED: 'La reserva expiró. Consulta su estado antes de iniciar otra compra.',
        CHECKOUT_RETRYABLE: 'Otra solicitud está preparando tu reserva. Consulta su estado antes de pagar.',
        RATE_LIMITED: 'Demasiados intentos con este correo. Espera unos minutos y vuelve a intentarlo.',
        CSRF_OR_METHOD: 'Tu sesión cambió. Recarga la página y vuelve a intentarlo.',
        AUTH_REQUIRED: 'Tu sesión cambió. Recarga la página y vuelve a intentarlo.',
        // The public API intentionally masks blocked accounts as unavailable
        // to avoid revealing account status to unauthenticated visitors.
        CHECKOUT_UNAVAILABLE: 'No fue posible reservar. Verifica el cupo; si tu cuenta está bloqueada, contacta con BRVTAL.',
      };
      say(feedback[error.message] ||
        'No fue posible reservar. Puede que ya no haya cupo; revisa tu selección y vuelve a intentarlo.');
    }
    async function resolveCartBuyer(auth) {
      signedIn(auth.authenticated === true);
      if (auth.authenticated) return { buyer: null };
      const buyer = readBuyer();
      if (!buyer) return null;
      // Freeze the selected identity while prepare is in flight. It is unlocked
      // in the submit handler's finally even on timeout or network failure.
      lockBuyer(true);
      return { buyer };
    }
    function persistCartIntent(selection) {
      if (cartRecord()) return null;
      intent = 'T13-' + crypto.randomUUID();
      const pending = { intent, lines: selection.lines, method: selection.method };
      if (!save(pending)) {
        say('No se pudo guardar tu reserva para reintentos. Activa el almacenamiento de sesión.');
        return null;
      }
      updateSingle();
      return pending;
    }
    async function sendCartPrepare(auth, buyer, selection, pending) {
      return json(await timedFetch(location.pathname, {
        method: 'POST', credentials: 'same-origin', cache: 'no-store',
        // Guests prove same-origin via browser Fetch Metadata, not session CSRF.
        headers: { 'Content-Type': 'application/json', 'X-BRVTAL-TICKET-CHECKOUT': 'prepare',
          ...(buyer ? {} : { 'X-CSRF-TOKEN': auth.csrf }) },
        body: JSON.stringify({ lines: selection.lines, method: selection.method,
          idempotency_key: pending.intent, ...(buyer ? { buyer } : {}) }),
      }));
    }
    function finishCartPrepare(pending, result, buyer, preview) {
      if (!save({ ...pending, order_id: String(result.order_id),
        ...(/^[a-f0-9]{32}$/.test(String(result.order_token || '')) ? { order_token: result.order_token } : {}) })) throw new Error('STORAGE');
      recoveryControls(record);
      if (result.checkout?.environment === 'mock') {
        // Synthetic mode never charges.
        say('Reserva preparada. El pago sigue pendiente hasta la confirmación del servidor.');
        return;
      }
      const amount = amountOf(result, pending.method);
      if (amount !== preview) {
        say('El importe final es ' + cop.format(amount) + '. Consulta y confirma tu reserva antes de pagar.');
        return;
      }
      say('Completa el pago en la ventana de Wompi. Consulta tu reserva si cierras la ventana.');
      return payWithWompi(result.checkout, { token: String(result.order_token || ''), buyer });
    }
    form.addEventListener('submit', async event => {
      event.preventDefault();
      if (submitting || pay.disabled) return;
      const selection = { lines: lines(), method: method() };
      const preview = rows.reduce((sum, item) => sum + item.price * quantities.get(item.id), 0);
      submitting = true;
      recoveryBusy = true;
      update();
      say('Verificando acceso y cupo…');
      try {
        const auth = await json(await timedFetch('/api/buyer/auth.php', {
          credentials: 'same-origin', cache: 'no-store',
        }));
        const identity = await resolveCartBuyer(auth);
        if (!identity) return;
        const pending = persistCartIntent(selection);
        if (!pending) return;
        const result = await sendCartPrepare(auth, identity.buyer, selection, pending);
        await finishCartPrepare(pending, result, identity.buyer, preview);
      } catch (error) {
        reportCheckoutError(error);
        if (cartRecord()) recoveryControls(record);
      } finally {
        lockBuyer(false);
        submitting = false;
        recoveryBusy = false;
        update();
      }
    });
    updateCart = update;
    update();
  };

  // No new tickets may be sold, yet a previously created order can still be queried.
  const initCheckout = () => {
  const form = document.getElementById('brvtal-ticket-checkout');
  if (!form || form.dataset.t28Initialized === '1') return;
  form.dataset.t28Initialized = '1';
  const type = form.elements.namedItem('ticket_type_id');
  const quantity = form.elements.namedItem('quantity');
  const method = form.elements.namedItem('method');
  const invitation = form.elements.namedItem('waitlist_token');
  // T23 is opt-in only in synthetic fixtures; public checkout stays unchanged.
  const promoDraft = form.dataset.t23Synthetic === '1';
  let promo = null;
  if (promoDraft) {
    const label = document.createElement('label');
    label.textContent = 'Cupón promocional (solo pruebas) ';
    promo = document.createElement('input');
    promo.type = 'text'; promo.name = 'promo_code';
    promo.maxLength = 32; promo.autocomplete = 'off'; promo.spellcheck = false;
    label.append(promo); method.closest('label')?.after(label);
  }
  const button = form.querySelector('button[type="submit"]');
  const total = document.getElementById('ticket-checkout-total');
  const money = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP',
    maximumFractionDigits: 0 });
  let submitting = false;
  let confirmedPromo = null;
  const selected = () => type.selectedOptions[0];
  const code = () => typeof invitation?.value === 'string' ? invitation.value.trim() : '';
  const validCode = () => /^[A-Za-z0-9_-]{43}$/.test(code());
  const promoCode = () => typeof promo?.value === 'string' ? promo.value.trim() : '';
  const validPromo = () => !promoCode() ||
    /^[A-Za-z0-9][A-Za-z0-9_-]{7,31}$/.test(promoCode());
  const input = () => ({ ticket_type_id: Number(type.value), quantity: Number(quantity.value),
    method: method.value });
  const same = (a, b) => a && a.ticket_type_id === b.ticket_type_id
    && a.quantity === b.quantity && a.method === b.method;
  if (record?.input) {
    // Rehydrate the immutable intent inputs after a lost response and reload.
    if (Array.from(type.options).some(option => Number(option.value) === record.input.ticket_type_id)) {
      type.value = String(record.input.ticket_type_id);
      quantity.value = String(record.input.quantity);
    }
    if (Array.from(method.options).some(option => option.value === record.input.method)) {
      method.value = record.input.method;
    }
  }
  function update() {
    const option = selected();
    const hasCode = code() !== '';
    const hasPromo = promoCode() !== '';
    const codeValid = (!hasCode || validCode()) && validPromo() &&
      !(hasPromo && hasCode) &&
      (!record?.promo_used || hasPromo);
    const available = Math.min(20, Number(option?.dataset.available || 0));
    const max = option?.dataset.inviteOnly === '1'
      ? (validCode() ? 1 : 0)
      : (hasCode ? (validCode() ? 1 : 0) : available);
    quantity.max = String(max);
    const count = Number(quantity.value);
    const valid = codeValid && Number.isInteger(count) && count >= 1 && count <= max;
    button.disabled = submitting || cartRecord() || !valid;
    type.disabled = submitting;
    quantity.disabled = submitting;
    method.disabled = submitting;
    if (invitation) invitation.disabled = submitting;
    if (promo) promo.disabled = submitting;
    if (confirmedPromo) {
      total.textContent = 'Subtotal ' + money.format(confirmedPromo.subtotal_cop) +
        ' · Descuento ' + money.format(confirmedPromo.discount_cop) +
        ' · Total ' + money.format(confirmedPromo.total_cop) + ' (servidor)';
    } else if (valid && hasPromo) {
      total.textContent = 'Subtotal ' + money.format(Number(option.dataset.price) * count) +
        ' · Cupón pendiente de verificación en servidor';
    } else {
      total.textContent = valid ? money.format(Number(option.dataset.price) * count)
        : 'Cantidad fuera del cupo disponible';
    }
  }
  function newPurchase() {
    if (submitting || cartRecord()) return; // Preserve a cart reservation.
    intent = null;
    confirmedPromo = null;
    clear();
    update();
  }
  type.addEventListener('change', newPurchase);
  quantity.addEventListener('input', newPurchase);
  method.addEventListener('change', newPurchase);
  invitation?.addEventListener('input', () => { if (!submitting) update(); });
  promo?.addEventListener('input', () => {
    if (submitting) return;
    // A lost-response retry retains the original idempotency key; the server
    // verifies coupon digest. Do not store plaintext coupon in sessionStorage.
    if (!record?.promo_used || record?.order_id) newPurchase();
    else update();
  });
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (submitting || cartRecord() || button.disabled) return;
    const selection = input();
    submitting = true;
    update();
    say('Verificando acceso y cupo…');
    try {
      const auth = await json(await timedFetch('/api/buyer/auth.php',
        { credentials: 'same-origin', cache: 'no-store' }));
      if (!auth.authenticated) {
        say('Inicia sesión para continuar. Si olvidaste tu clave, recupera el acceso desde Cuenta de comprador.');
        return;
      }
      if (record?.input && (!same(record.input, selection)
          || Boolean(record.promo_used) !== Boolean(promoCode()))) {
        say('La selección de tu reserva cambió. Modifícala para empezar una nueva compra.');
        return;
      }
      intent ||= 'T13-' + crypto.randomUUID();
      const pending = { intent, input: selection,
        ...(promoCode() ? {promo_used: true} : {}),
        ...(record?.order_id ? { order_id: record.order_id } : {}) };
      if (!save(pending)) {
        say('No se pudo guardar tu reserva para reintentos. Activa el almacenamiento de sesión.');
        return;
      }
      const response = await timedFetch(location.pathname, {
        method: 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': auth.csrf,
          'X-BRVTAL-TICKET-CHECKOUT': 'prepare' },
        body: JSON.stringify({ ...selection, idempotency_key: intent,
          ...(code() ? { waitlist_token: code() } : {}),
          ...(promoCode() ? { promo_code: promoCode() } : {}) }),
      });
      const result = await json(response);
      const checkout = result.checkout;
      if (promoCode()) {
        const quote = result.promo_breakdown;
        if (!quote || !['subtotal_cop','discount_cop','total_cop']
            .every(key => Number.isSafeInteger(quote[key]) && quote[key] >= 0)
            || quote.subtotal_cop - quote.discount_cop !== quote.total_cop
            || checkout?.environment !== 'mock'
            || checkout.amount_in_cents !== quote.total_cop * 100) {
          throw new Error('INVALID_PROMO_BREAKDOWN');
        }
        confirmedPromo = quote; // Display ONLY server-authoritative totals.
      }
      save({ ...pending, order_id: String(result.order_id) });
      if (invitation) invitation.value = ''; // Never persist or retain the opaque token.
      if (promo) promo.value = ''; // Never retain raw coupon after confirmed prepare.
      say('Reserva preparada. El pago sigue pendiente hasta confirmación del servidor.');
      if (checkout.environment === 'mock') return; // Synthetic mode NEVER charges.
      goToWompi(checkout);
    } catch (error) {
      if (error.message === 'CHECKOUT_TIMEOUT') {
        say(TIMEOUT_MESSAGE);
      } else if (error.message === 'CHECKOUT_EXPIRED') {
        say('La reserva expiró. Cambia tu selección para iniciar una nueva compra.');
      } else if (error.message === 'CHECKOUT_RETRYABLE') {
        say('Otra solicitud está preparando tu reserva. Reintenta sin cambiar la selección.');
      } else {
        say('No fue posible iniciar el checkout. Revisa tu conexión y vuelve a intentar.');
      }
    } finally {
      submitting = false;
      update();
    }
  });
  updateSingle = update;
  update();
  };
  initCheckout();
  initCart();
  if (cartRecord()) { recoveryControls(record); void recoverCart(record); }
  void mountWaitlists();
})();
