<?php
declare(strict_types=1);

function brvtal_public_unavailable_seo(array $seo): array
{
    $canonical = (string)($seo['canonical'] ?? 'https://www.brvtal.com.co/');
    $image = (string)($seo['image'] ?? 'https://www.brvtal.com.co/assets/brvtal-logo.jpeg');
    $title = 'Temporarily Unavailable — BRVTAL';
    $description = 'Some BRVTAL public data is temporarily unavailable. Please try again shortly.';
    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'WebPage',
        'name' => 'Temporarily Unavailable',
        'url' => $canonical,
        'image' => $image,
        'description' => $description,
    ];
    return compact('title', 'description', 'canonical', 'image', 'schema');
}

function brvtal_public_unavailable_page(array $seo, string $analytics = ''): string
{
    $escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $title = $escape($seo['title'] ?? 'Temporarily Unavailable — BRVTAL');
    $description = $escape($seo['description'] ?? 'Some BRVTAL public data is temporarily unavailable.');
    $image = $escape($seo['image'] ?? '/assets/brvtal-logo.jpeg');
    $tags = brvtal_public_seo_tags($seo);

    return <<<HTML
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#050505">
  <meta name="robots" content="noindex, follow">
  <meta name="description" content="{$description}">
  <title>{$title}</title>
  <base href="/">
  {$tags}
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;600;700;800;900&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/public-entity.css">
  <link rel="stylesheet" href="/css/public-cromo.css">
  <link rel="stylesheet" href="/css/public-cromo-pages.css">
</head>
<body class="cromo-page">
  <a class="skip-link" href="#main-content">IR AL CONTENIDO</a>
  <header class="entity-nav"><a href="/" class="entity-brand">BRVTAL<small>RAVE TILL GRAVE</small></a><a href="/">← VOLVER AL INICIO</a></header>
  <main id="main-content" tabindex="-1">
    <article class="entity-hero">
      <div class="entity-image"><img src="{$image}" alt="BRVTAL" loading="eager" fetchpriority="high" decoding="async"><span>503 / BRVTAL</span></div>
      <div class="entity-copy">
        <div class="entity-kicker">BRVTAL / ERROR TEMPORAL / 503</div>
        <h1>DATOS NO DISPONIBLES POR AHORA</h1>
        <div class="entity-facts"><div><span>ESTADO</span><b>503 / REINTENTA MÁS TARDE</b></div></div>
        <div class="entity-actions"><a href="/">VOLVER AL INICIO ↗</a></div>
      </div>
    </article>
    <section class="entity-statement"><div class="entity-section-label">SERVICIO / RECUPERACIÓN</div><p>El recurso de BRVTAL existe, pero no se pudieron cargar de forma segura los datos públicos esenciales. Inténtalo de nuevo en unos minutos.</p></section>
  </main>
  <footer><strong>BRVTAL</strong><span>PEREIRA / COLOMBIA</span><span>RAVE TILL GRAVE</span></footer>
  {$analytics}
  <script src="/js/public-cromo.js" defer></script>
</body>
</html>
HTML;
}
