<?php
declare(strict_types=1);
// #1958: purchase confirmation. The order is identified by its opaque token in the
// URL (/compra/<token>); data comes from /api/ticketing/order-status.php.
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="referrer" content="no-referrer">
<meta name="theme-color" content="#050505">
<meta name="robots" content="noindex,nofollow">
<title>Tu compra | BRVTAL</title>
<link rel="stylesheet" href="/css/buyer-access.css?v=1">
<link rel="stylesheet" href="/css/public-ticket-view.css?v=2">
</head>
<body class="buyer-access buyer-ticket buyer-purchase">
<header class="access-topbar">
  <a class="access-brand" href="/" aria-label="BRVTAL · Volver al inicio">BRVTAL<span>RAVE TILL GRAVE</span></a>
  <span class="access-topnote">TU MÚSICA. TUS ENTRADAS.</span>
</header>
<main class="access-main">
  <div class="access-shell ticket-shell purchase-shell">
    <section class="purchase" id="purchase" aria-labelledby="purchase-heading" aria-busy="true">
      <p class="purchase-number" id="purchase-number"></p>
      <h1 id="purchase-heading" class="ticket-title">Tu compra</h1>
      <p class="purchase-state" id="purchase-state" role="status" aria-live="polite" data-state="loading">
        <span class="purchase-state-icon" aria-hidden="true"></span><span id="purchase-state-text">Consultando tu pedido…</span>
      </p>
      <p class="ticket-lead" id="purchase-detail"></p>
      <dl class="purchase-summary" id="purchase-summary" hidden>
        <dt>Evento</dt><dd id="purchase-event"></dd>
        <dt>Boletas</dt><dd id="purchase-items"></dd>
        <dt>Total</dt><dd id="purchase-total" class="purchase-total"></dd>
      </dl>
      <p class="ticket-actions" id="purchase-actions" hidden>
        <a class="access-submit" id="purchase-event-link" href="/">Volver al evento</a>
        <a class="purchase-secondary" href="/mi-cuenta">Ir a Mi cuenta</a>
      </p>
    </section>
  </div>
</main>
<footer class="access-footer"><span>RAVE TILL GRAVE</span><a href="/">← Volver al inicio</a></footer>
<script src="/js/public-order-status.js?v=1"></script>
</body>
</html>
