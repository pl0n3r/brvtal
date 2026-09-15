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
<html lang="en">
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
</head>
<body>
  <a class="skip-link" href="#main-content">SKIP TO CONTENT</a>
  <header class="entity-nav"><a href="/" class="entity-brand">BRVTAL<small>RAVE TILL GRAVE</small></a><a href="/">← BACK HOME</a></header>
  <main id="main-content" tabindex="-1">
    <article class="entity-hero">
      <div class="entity-image"><img src="{$image}" alt="BRVTAL" loading="eager" fetchpriority="high" decoding="async"><span>503 / BRVTAL</span></div>
      <div class="entity-copy">
        <div class="entity-kicker">BRVTAL / TEMPORARY ERROR / 503</div>
        <h1>DATA TEMPORARILY UNAVAILABLE</h1>
        <div class="entity-facts"><div><span>STATUS</span><b>503 / RETRY LATER</b></div></div>
        <div class="entity-actions"><a href="/">BACK HOME ↗</a></div>
      </div>
    </article>
    <section class="entity-statement"><div class="entity-section-label">SERVICE / RECOVERY</div><p>The requested BRVTAL resource exists, but essential public data could not be loaded safely. Please try again shortly.</p></section>
  </main>
  <footer><strong>BRVTAL</strong><span>PEREIRA / COLOMBIA</span><span>RAVE TILL GRAVE</span></footer>
  {$analytics}
</body>
</html>
HTML;
}
