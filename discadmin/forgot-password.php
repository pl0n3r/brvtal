<?php
declare(strict_types=1);
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><title>Recuperar acceso · BRVTAL</title><link rel="stylesheet" href="/discadmin/password-recovery.css"></head><body data-recovery-page="forgot"><main class="recovery"><div class="eyebrow">BRVTAL / DISCADMIN</div><h1>Recuperar acceso</h1><p>Ingresa el correo del administrador. La respuesta será la misma exista o no la cuenta.</p><form id="forgot-form"><label for="recovery-email">EMAIL</label><input id="recovery-email" type="email" autocomplete="email" required maxlength="190"><button type="submit">ENVIAR INSTRUCCIONES</button></form><div id="recovery-message" class="message" aria-live="polite"></div><a class="back" href="/discadmin/">Volver al login</a></main><script src="/discadmin/password-recovery.js" defer></script></body></html>
