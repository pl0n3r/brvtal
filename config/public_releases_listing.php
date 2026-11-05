<?php
declare(strict_types=1);

require_once __DIR__ . '/public_cromo_chrome.php';

/** Read only real, published catalog rows. A broken query is not an empty catalog. */
function brvtalPublicReleasesIndexRows(PDO $pdo): array
{
    $statement = $pdo->query(
        "SELECT title,slug,artwork,release_type,catalog_number,release_date
         FROM releases WHERE status='published'
         ORDER BY sort_order ASC,featured DESC,COALESCE(release_date,'9999-12-31') DESC,id ASC
         LIMIT 96"
    );
    if ($statement === false) {
        throw new RuntimeException('No se pudo consultar el catálogo público');
    }
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    return array_values(array_filter($rows, static fn(mixed $row): bool =>
        is_array($row)
        && trim((string)($row['title'] ?? '')) !== ''
        && preg_match('/^[a-z0-9-]{1,190}$/D', (string)($row['slug'] ?? '')) === 1
    ));
}

function brvtalPublicReleasesIndexSeo(string $base): array
{
    $base = rtrim($base, '/');
    $canonical = $base . '/releases';
    $title = 'Discos y lanzamientos — BRVTAL';
    $description = 'Catálogo oficial de discos y lanzamientos publicados por BRVTAL.';
    $image = $base . '/assets/brvtal-logo.jpeg';
    return [
        'title' => $title, 'description' => $description, 'canonical' => $canonical,
        'image' => $image,
        'schema' => [
            '@context' => 'https://schema.org', '@type' => 'CollectionPage',
            'name' => $title, 'description' => $description, 'url' => $canonical,
            'image' => $image,
        ],
    ];
}

/** Render genuine public rows; no synthetic records, draft data or untrusted URLs. */
function brvtalPublicReleasesIndexPage(array $releases, array $seo, string $analytics = ''): string
{
    $escape = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $base = rtrim((string)($seo['canonical'] ?? ''), '/');
    $base = preg_replace('~/releases$~', '', $base) ?? $base;
    $cards = '';
    $count = 0;
    foreach ($releases as $release) {
        if (!is_array($release)) continue;
        $slug = (string)($release['slug'] ?? '');
        $name = trim((string)($release['title'] ?? ''));
        if ($name === '' || preg_match('/^[a-z0-9-]{1,190}$/D', $slug) !== 1) continue;

        $href = '/releases/' . rawurlencode($slug);
        $rawArt = trim((string)($release['artwork'] ?? ''));
        $artAllowed = preg_match('~^https://[^\s<>"\']+$~i', $rawArt) === 1
            || preg_match('~^/(?:uploads|assets)/[a-zA-Z0-9_./%+-]+$~D', $rawArt) === 1;
        $art = $artAllowed
            ? '<img src="' . $escape(str_starts_with($rawArt, '/') ? $base . $rawArt : $rawArt) . '" alt="" loading="lazy" decoding="async">'
            : '<span class="entity-card-mark">BRVTAL</span>';
        $releaseDate = trim((string)($release['release_date'] ?? ''));
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $releaseDate) === 1 && $releaseDate !== '0000-00-00'
            ? substr($releaseDate, 8, 2) . '.' . substr($releaseDate, 5, 2) . '.' . substr($releaseDate, 0, 4)
            : '';
        $meta = implode(' / ', array_filter([
            strtoupper(trim((string)($release['release_type'] ?? ''))),
            trim((string)($release['catalog_number'] ?? '')),
            $date,
        ], static fn(string $v): bool => $v !== ''));
        $cards .= '<a class="entity-card" href="' . $escape($href) . '">' . $art
            . '<span><small>' . $escape($meta) . '</small><strong>' . $escape($name) . '</strong></span></a>';
        $count++;
    }
    $isEmpty = $count === 0;
    $listing = $isEmpty
        ? '<section class="entity-statement" role="status"><div class="entity-section-label">CATÁLOGO / SIN PUBLICACIONES</div><p>Aún no hay discos publicados. Explora las noches y el sonido de BRVTAL.</p></section>'
        : '<section id="catalogo" class="entity-related" aria-labelledby="catalogo-title"><h2 id="catalogo-title" class="entity-section-label">DISCOS PUBLICADOS / ' . str_pad((string)$count, 2, '0', STR_PAD_LEFT) . '</h2><div class="entity-grid">' . $cards . '</div></section>';
    $action = $isEmpty
        ? '<a href="/#sets">EXPLORAR SONIDO ↗</a>'
        : '<a href="#catalogo">VER CATÁLOGO ↘</a>';
    $title = $escape($seo['title'] ?? 'Discos — BRVTAL');
    $description = $escape($seo['description'] ?? 'Catálogo de BRVTAL');
    $tags = brvtal_public_seo_tags($seo);
    $topbar = brvtalPublicCromoTopbar('/', '← VOLVER AL INICIO');
    $heroImage = $escape($seo['image'] ?? '/assets/brvtal-logo.jpeg');

    return <<<HTML
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#050505">
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
<body class="entity-page cromo-page">
  <a class="skip-link" href="#main-content">IR AL CONTENIDO</a>
  {$topbar}
  <main id="main-content" tabindex="-1">
    <article class="entity-hero">
      <div class="entity-image"><img src="{$heroImage}" alt="BRVTAL" loading="eager" fetchpriority="high" decoding="async"><span>DISCOS / BRVTAL</span></div>
      <div class="entity-copy"><div class="entity-kicker">BRVTAL / ARCHIVO SONORO</div><h1 data-text="DISCOS">DISCOS</h1>
        <div class="entity-facts"><div><span>CATÁLOGO</span><b>LANZAMIENTOS PUBLICADOS</b></div></div>
        <div class="entity-actions">{$action}</div>
      </div>
    </article>
    {$listing}
  </main>
  <footer><strong>BRVTAL</strong><span>PEREIRA / COLOMBIA</span><span>RAVE TILL GRAVE</span></footer>
  <script src="/js/public-cromo.js" defer></script>
  <script src="/js/public-cromo-sound.js" defer></script>
  <script src="/js/public-cromo-pages.js" defer></script>
  {$analytics}
</body>
</html>
HTML;
}
