<?php
declare(strict_types=1);
// #1945: the QR secret lives only in the URL fragment, which never reaches
// this server. The page renders it locally and loads no third-party scripts.
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
<title>Tu boleta | BRVTAL</title>
<link rel="stylesheet" href="/css/buyer-access.css?v=1">
<link rel="stylesheet" href="/css/public-ticket-view.css?v=1">
</head>
<body class="buyer-access buyer-ticket">
<header class="access-topbar">
  <a class="access-brand" href="/" aria-label="BRVTAL · Volver al inicio">BRVTAL<span>RAVE TILL GRAVE</span></a>
  <span class="access-topnote">TU MÚSICA. TUS ENTRADAS.</span>
</header>
<main class="access-main">
  <div class="access-shell ticket-shell">
    <section class="ticket-pass" id="ticket-pass" aria-labelledby="ticket-heading" hidden>
      <h1 id="ticket-heading" class="ticket-title">Tu boleta</h1>
      <p class="ticket-lead">Muestra este código en la puerta.</p>
      <div class="ticket-code" id="ticket-code" role="img" aria-label="Código QR de ingreso"></div>
      <p class="ticket-ref">Boleta <span id="ticket-ref" class="ticket-ref-id"></span></p>
      <ul class="ticket-tips">
        <li>Sube el brillo de la pantalla antes de llegar a la fila.</li>
        <li>Cada código entra una sola vez. No compartas este enlace ni capturas del QR.</li>
      </ul>
    </section>
    <section class="ticket-error" id="ticket-error" aria-labelledby="ticket-error-heading" hidden>
      <h1 id="ticket-error-heading" class="ticket-title">No pudimos abrir la boleta</h1>
      <p class="ticket-lead">El enlace está incompleto. Ábrelo directamente desde el correo de tu compra, sin copiarlo a mano.</p>
      <p class="ticket-actions"><a class="access-submit" href="/mi-cuenta">Ir a Mi cuenta</a></p>
    </section>
    <noscript><p class="ticket-lead">Activa JavaScript para ver el código QR de tu boleta.</p></noscript>
  </div>
</main>
<footer class="access-footer"><span>RAVE TILL GRAVE</span><a href="/">← Volver al inicio</a></footer>
<script src="/js/vendor/qrcode-generator.js?v=1"></script>
<script src="/js/public-ticket-view.js?v=1"></script>
</body>
</html>
