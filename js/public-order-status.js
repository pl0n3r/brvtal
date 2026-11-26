(() => {
  'use strict';
  // #1958: purchase confirmation. Only the server's state is shown; nothing in the
  // URL or from Wompi's window can mark an order as paid.
  const token = (/^\/compra\/([a-f0-9]{32})\/?$/.exec(location.pathname) || [])[1] || '';
  const $ = id => document.getElementById(id);
  const box = $('purchase');
  const stateEl = $('purchase-state');
  const money = value => new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP',
    maximumFractionDigits: 0 }).format(value).replace(/\s/gu, '');
  const copy = {
    pending: ['Confirmando tu pago…', 'Esto tarda unos segundos. No cierres esta página; se actualiza sola.'],
    approved: ['Pago confirmado', ''],
    failed: ['El pago no fue aprobado', 'No se cobró nada por este pedido. Puedes volver al evento e intentarlo otra vez.'],
    cancelled: ['Pedido cancelado', 'Este pedido no está activo.'],
    expired: ['La reserva expiró', 'Vuelve al evento para empezar una nueva compra.'],
    refunded: ['Pedido reembolsado', 'Este pedido fue reembolsado.'],
    unverified: ['No pudimos verificar este pedido', 'Si pagaste, revisa tu correo o Mi cuenta en unos minutos.'],
    missing: ['No encontramos esta compra', 'El enlace está incompleto. Ábrelo desde el correo o desde Mi cuenta.'],
    offline: ['No pudimos consultar tu pedido', 'Revisa tu conexión; lo intentamos de nuevo en unos segundos.'],
  };
  let polls = 0;
  let confirmed = false;
  function retryDelay(response) {
    const raw = response.headers.get('Retry-After') || '';
    const ms = /^\d+$/.test(raw) ? Number(raw) * 1000 : Date.parse(raw) - Date.now();
    return Number.isFinite(ms) ? Math.max(1000, ms) : 8000;
  }
  function show(state, order) {
    const [title, detail] = copy[state] || copy.unverified;
    stateEl.dataset.state = state;
    $('purchase-state-text').textContent = title;
    $('purchase-detail').textContent = state === 'approved' && order?.email_hint
      ? `Te enviamos las boletas con QR a ${order.email_hint}. También quedan en Mi cuenta.`
      : detail;
    box.setAttribute('aria-busy', state === 'pending' ? 'true' : 'false');
    if (!order) return;
    $('purchase-number').textContent = `Pedido #${order.order_number}`;
    $('purchase-event').textContent = order.event_title;
    $('purchase-items').textContent = order.items.map(item => `${item.quantity} × ${item.name}`).join(' · ');
    $('purchase-total').textContent = money(order.total_cop);
    $('purchase-event-link').href = order.event_url;
    $('purchase-summary').hidden = false;
    $('purchase-actions').hidden = false;
  }
  async function refresh() {
    if (!token) { show('missing'); return; }
    let next = window.BRVTAL_ORDER_POLL_MS || 4000;
    try {
      const response = await fetch('/api/ticketing/order-status.php?t=' + token,
        { credentials: 'same-origin', cache: 'no-store' });
      if (response.status === 429 || response.status === 503) {
        if (!confirmed) show('offline');
        else $('purchase-detail').textContent = 'No pudimos actualizar el estado. Volveremos a consultar.';
        next = retryDelay(response);
      } else {
        if (response.status === 404) { show('missing'); return; }
        const payload = await response.json();
        if (!response.ok || !payload.ok || !payload.order) throw new Error('UNAVAILABLE');
        show(payload.order.state, payload.order);
        confirmed = true;
        if (payload.order.state !== 'pending') return;
      }
    } catch {
      if (!confirmed) show('offline');
      else $('purchase-detail').textContent = 'No pudimos actualizar el estado. Volveremos a consultar.';
      next = 8000;
    }
    // Poll for about three minutes; after that the buyer can reload or check Mi cuenta.
    if (++polls < 45 && next <= 2147483647) setTimeout(() => { void refresh(); }, next);
  }
  void refresh();
})();
