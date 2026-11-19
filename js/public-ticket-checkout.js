/* T13: server owns stock, totals, holds and final payment state. */
(() => {
  const form = document.getElementById('brvtal-ticket-checkout');
  if (!form) return;
  const type = form.elements.namedItem('ticket_type_id');
  const quantity = form.elements.namedItem('quantity');
  const method = form.elements.namedItem('method');
  const button = form.querySelector('button[type="submit"]');
  const total = document.getElementById('ticket-checkout-total');
  const status = document.getElementById('ticket-checkout-status');
  const money = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP',
    maximumFractionDigits: 0 });
  const storageKey = 'brvtal-checkout:' + location.pathname;
  let intent = null;
  const say = text => { status.textContent = text; };
  const selected = () => type.selectedOptions[0];
  function update() {
    const option = selected();
    const max = Math.min(20, Number(option?.dataset.available || 0));
    quantity.max = String(max);
    const count = Number(quantity.value);
    const valid = Number.isInteger(count) && count >= 1 && count <= max;
    button.disabled = !valid;
    total.textContent = valid ? money.format(Number(option.dataset.price) * count)
      : 'Cantidad fuera del cupo disponible';
    intent = null; // Change of selection creates a new intent; retry keeps it.
  }
  type.addEventListener('change', update);
  quantity.addEventListener('input', update);
  method.addEventListener('change', () => { intent = null; });
  async function json(response) {
    const payload = await response.json();
    if (!response.ok || !payload.ok) throw new Error(payload.error || 'CHECKOUT_UNAVAILABLE');
    return payload;
  }
  async function refresh(orderId) {
    const url = new URL(location.pathname, location.origin);
    url.searchParams.set('order_id', orderId);
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
    } catch {
      say('No se pudo consultar el pago. No se han emitido boletas.');
    }
  }
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (button.disabled) return;
    button.disabled = true;
    say('Verificando acceso y cupo…');
    try {
      const auth = await json(await fetch('/api/buyer/auth.php',
        { credentials: 'same-origin', cache: 'no-store' }));
      if (!auth.authenticated) {
        say('Inicia sesión para continuar. Si olvidaste tu clave, recupera el acceso desde Cuenta de comprador.');
        return;
      }
      intent ||= 'T13-' + crypto.randomUUID();
      const response = await fetch(location.pathname, {
        method: 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': auth.csrf,
          'X-BRVTAL-TICKET-CHECKOUT': 'prepare' },
        body: JSON.stringify({ ticket_type_id: Number(type.value),
          quantity: Number(quantity.value), method: method.value, idempotency_key: intent }),
      });
      const result = await json(response);
      const checkout = result.checkout;
      sessionStorage.setItem(storageKey, String(result.order_id));
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
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        hosted.append(input);
      }
      document.body.append(hosted);
      hosted.submit(); // Wompi redirect is never payment proof.
    } catch {
      say('No fue posible iniciar el checkout. Revisa tu conexión y vuelve a intentar.');
    } finally {
      update();
    }
  });
  update();
  try {
    const orderId = sessionStorage.getItem(storageKey);
    if (orderId && /^[1-9][0-9]{0,17}$/.test(orderId)) refresh(orderId);
  } catch { /* Storage disabled: no claim of confirmed payment. */ }
})();
