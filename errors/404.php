<?php
declare(strict_types=1);

/**
 * Standalone Apache/LiteSpeed ErrorDocument. Do not bootstrap the CMS: even
 * database failures and missing public routes need a reliable branded exit.
 */
http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, follow');
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#050505">
  <meta name="robots" content="noindex, follow">
  <meta name="description" content="Esta página de BRVTAL no existe. Regresa al inicio, descubre las noches o conoce a los artistas.">
  <title>Página no encontrada — BRVTAL</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;500;600;700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
  <link href="/css/public-cromo.css" rel="stylesheet">
  <style>
    :root{--bg:#050505;--fg:#f4f1e8;--red:#e12e34;color-scheme:dark}
    *,*::before,*::after{box-sizing:border-box}
    html{min-height:100%;scroll-behavior:smooth}
    body{min-height:100dvh;margin:0;background:var(--bg);color:var(--fg);
      font-family:"Barlow Condensed",Arial,sans-serif;-webkit-font-smoothing:antialiased}
    a{color:inherit;text-underline-offset:.24em}
    a:focus-visible{outline:3px solid var(--fg);outline-offset:5px}
    ::selection{background:var(--red);color:#fff}
    .skip{position:absolute;z-index:10;top:-90px;left:12px;padding:12px;background:var(--fg);color:#050505}
    .skip:focus{top:12px}
    .error-shell{position:relative;display:grid;grid-template-rows:auto 1fr auto;
      min-height:100dvh;isolation:isolate;overflow:hidden;padding:0 clamp(20px,4.5vw,72px)}
    .error-shell::before{content:"";position:absolute;inset:0;z-index:-1;pointer-events:none;
      opacity:.24;background:repeating-linear-gradient(0deg,transparent 0 9px,rgba(244,241,232,.035) 10px 11px)}
    .error-top{display:flex;justify-content:space-between;align-items:center;gap:20px;
      min-height:82px;border-bottom:1px solid rgba(244,241,232,.34)}
    .error-brand{text-decoration:none;font:400 clamp(28px,3.4vw,44px)/1 "Broken Glass","Barlow Condensed",sans-serif}
    .error-tag{font:700 11px/1.5 "Space Mono",monospace;letter-spacing:.08em;color:#c9c5bc}
    .error-main{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(300px,.8fr);
      align-items:center;gap:clamp(18px,5vw,90px);padding:48px 0 66px}
    .error-mark{position:relative;min-width:0;display:flex;align-items:center;justify-content:center;
      min-height:290px;border-bottom:8px solid var(--red)}
    .error-code{display:block;font:400 clamp(175px,29vw,420px)/.8 "Broken Glass","Barlow Condensed",sans-serif;
      letter-spacing:-.035em;color:var(--fg);text-shadow:.025em .035em 0 var(--red);white-space:nowrap}
    .error-copy{max-width:540px;padding-block:32px}
    .error-copy h1{margin:0;font:700 clamp(48px,6.1vw,100px)/.94 "Barlow Condensed",sans-serif;
      letter-spacing:-.02em;text-transform:uppercase;text-wrap:balance}
    .error-copy p{max-width:38ch;margin:24px 0 36px;font:500 clamp(21px,2vw,27px)/1.35 "Barlow Condensed",sans-serif;
      color:#e1ddd3;text-wrap:pretty}
    .error-links{display:flex;flex-wrap:wrap;align-items:stretch;gap:10px}
    .error-links a{display:inline-flex;align-items:center;justify-content:center;min-height:52px;
      padding:12px 22px;border:1px solid var(--fg);font:700 19px/1.1 "Barlow Condensed",sans-serif;
      text-transform:uppercase;letter-spacing:.02em;text-decoration:none;
      transition:background-color 150ms ease-out,color 150ms ease-out,border-color 150ms ease-out}
    .error-links a:first-child{background:var(--red);color:#fff;border-color:var(--red)}
    .error-links a:hover{background:var(--fg);color:#050505;border-color:var(--fg)}
    .error-bottom{display:flex;flex-wrap:wrap;justify-content:space-between;gap:12px;
      padding:18px 0;border-top:1px solid rgba(244,241,232,.34);
      font:700 11px/1.45 "Space Mono",monospace;letter-spacing:.06em;color:#c9c5bc}
    @media(max-width:760px){
      .error-top{min-height:66px}
      .error-tag{font-size:10px}
      .error-main{grid-template-columns:minmax(0,1fr);gap:16px;padding:28px 0 56px}
      .error-mark{min-height:200px;padding-bottom:16px}
      .error-code{font-size:clamp(150px,43vw,300px)}
      .error-copy{padding:0}
      .error-copy h1{font-size:clamp(46px,11vw,75px)}
      .error-copy p{margin:18px 0 28px}
      .error-links{display:grid;grid-template-columns:1fr;gap:10px}
      .error-links a{justify-content:flex-start;min-height:48px}
    }
    @media(prefers-reduced-motion:reduce){
      html{scroll-behavior:auto}.error-links a{transition:none}
    }
  </style>
</head>
<body class="brvtal-error">
<a class="skip" href="#contenido">Saltar al contenido</a>
<div class="error-shell">
  <header class="error-top">
    <a class="error-brand" href="/" aria-label="BRVTAL — inicio">BRVTAL</a>
    <span class="error-tag">RAVE TILL GRAVE</span>
  </header>
  <main class="error-main" id="contenido" tabindex="-1">
    <div class="error-mark" aria-hidden="true"><span class="error-code">404</span></div>
    <div class="error-copy">
      <h1>Esta ruta no existe.</h1>
      <p>El enlace cambió o la página ya no está disponible. La escena sigue en BRVTAL.</p>
      <nav class="error-links" aria-label="Volver a BRVTAL">
        <a href="/">Ir al inicio</a>
        <a href="/#noches">Ver noches</a>
        <a href="/#artistas">Conocer artistas</a>
      </nav>
    </div>
  </main>
  <footer class="error-bottom">
    <span>BRVTAL / PEREIRA, COLOMBIA</span>
    <span>NO ENCONTRADO / 404</span>
  </footer>
</div>
</body>
</html>
