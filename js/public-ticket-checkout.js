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
  async function json(response) {
    const payload = await response.json();
    if (!response.ok || !payload.ok) throw new Error(payload.error || 'CHECKOUT_UNAVAILABLE');
    return payload;
  }
  async function refresh(orderId) {
    if (panel) panel.hidden = false;
    const url = new URL(location.pathname, location.origin);
    url.searchParams.set('order_id', String(orderId));
    try {
      const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store',
        headers: { 'X-BRVTAL-TICKET-CHECKOUT': 'status' } });
      const payload = await json(response);
      const states = {
        approved: 'Pago confirmado por el servidor. Consulta tus entradas en tu cuenta.',
        failed: 'El pago no fue aprobado. Puedes volver a intentarlo.',
        cancelled: 'El pago fue cancelado.',
        pending: 'Pago pendiente de confirmación. Actualiza esta página para consultar el estado.',
        unverified: 'No existe una confirmación verificable. No se han emitido boletas.',
      };
      say(states[payload.state] || states.unverified);
      if (['approved', 'failed', 'cancelled'].includes(payload.state)) {
        clear();
        intent = null;
      }
    } catch {
      say('No se pudo consultar el pago. No se han emitido boletas.');
    }
  }
  if (/^[1-9]\d{0,17}$/.test(String(record?.order_id || ''))) {
    void refresh(record.order_id);
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

  // No new tickets may be sold, yet a previously created order can still be queried.
  const initCheckout = () => {
  const form = document.getElementById('brvtal-ticket-checkout');
  if (!form || form.dataset.t28Initialized === '1') return;
  form.dataset.t28Initialized = '1';
  const type = form.elements.namedItem('ticket_type_id');
  const quantity = form.elements.namedItem('quantity');
  const method = form.elements.namedItem('method');
  const invitation = form.elements.namedItem('waitlist_token');
  const button = form.querySelector('button[type="submit"]');
  const total = document.getElementById('ticket-checkout-total');
  const money = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP',
    maximumFractionDigits: 0 });
  let submitting = false;
  const selected = () => type.selectedOptions[0];
  const code = () => typeof invitation?.value === 'string' ? invitation.value.trim() : '';
  const validCode = () => /^[A-Za-z0-9_-]{43}$/.test(code());
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
    const codeValid = !hasCode || validCode();
    const available = Math.min(20, Number(option?.dataset.available || 0));
    const max = option?.dataset.inviteOnly === '1'
      ? (validCode() ? 1 : 0)
      : (hasCode ? (validCode() ? 1 : 0) : available);
    quantity.max = String(max);
    const count = Number(quantity.value);
    const valid = codeValid && Number.isInteger(count) && count >= 1 && count <= max;
    button.disabled = submitting || !valid;
    type.disabled = submitting;
    quantity.disabled = submitting;
    method.disabled = submitting;
    if (invitation) invitation.disabled = submitting;
    total.textContent = valid ? money.format(Number(option.dataset.price) * count)
      : 'Cantidad fuera del cupo disponible';
  }
  function newPurchase() {
    if (submitting) return; // Never discard an in-flight key on form input.
    intent = null;
    clear();
    update();
  }
  type.addEventListener('change', newPurchase);
  quantity.addEventListener('input', newPurchase);
  method.addEventListener('change', newPurchase);
  invitation?.addEventListener('input', () => { if (!submitting) update(); });
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (submitting || button.disabled) return;
    const selection = input();
    submitting = true;
    update();
    say('Verificando acceso y cupo…');
    try {
      const auth = await json(await fetch('/api/buyer/auth.php',
        { credentials: 'same-origin', cache: 'no-store' }));
      if (!auth.authenticated) {
        say('Inicia sesión para continuar. Si olvidaste tu clave, recupera el acceso desde Cuenta de comprador.');
        return;
      }
      if (record?.input && !same(record.input, selection)) {
        say('La selección de tu reserva cambió. Modifícala para empezar una nueva compra.');
        return;
      }
      intent ||= 'T13-' + crypto.randomUUID();
      const pending = { intent, input: selection, ...(record?.order_id ? { order_id: record.order_id } : {}) };
      if (!save(pending)) {
        say('No se pudo guardar tu reserva para reintentos. Activa el almacenamiento de sesión.');
        return;
      }
      const response = await fetch(location.pathname, {
        method: 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': auth.csrf,
          'X-BRVTAL-TICKET-CHECKOUT': 'prepare' },
        body: JSON.stringify({ ...selection, idempotency_key: intent,
          ...(code() ? { waitlist_token: code() } : {}) }),
      });
      const result = await json(response);
      const checkout = result.checkout;
      save({ ...pending, order_id: String(result.order_id) });
      if (invitation) invitation.value = ''; // Never persist or retain the opaque token.
      say('Reserva preparada. El pago sigue pendiente hasta confirmación del servidor.');
      if (checkout.environment === 'mock') return; // Synthetic mode NEVER charges.
      if (checkout.environment !== 'sandbox' || checkout.currency !== 'COP'
        || !checkout.public_key?.startsWith('pub_test_')) throw new Error('INVALID_ENVIRONMENT');
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
      hosted.submit(); // Redirect never means payment was confirmed.
    } catch (error) {
      if (error.message === 'CHECKOUT_EXPIRED') {
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
  update();
  };
  initCheckout();
  void mountWaitlists();
})();
