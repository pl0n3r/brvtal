<?php
declare(strict_types=1);
require_once __DIR__ . '/media.php';
require_once __DIR__ . '/public_visibility.php';

function brvtal_public_media_variant(string $image, string $context): string
{
    $path = parse_url($image, PHP_URL_PATH) ?: $image;
    $sidecar = brvtal_media_read_sidecar($path);
    $variant = $sidecar['variants'][$context]['path'] ?? null;
    return is_string($variant) && $variant !== '' ? $variant : $image;
}

function brvtal_page_rows(PDO $pdo, string $sql, array $parameters = []): array
{
    try {
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll();
    } catch (Throwable) {
        return [];
    }
}

function brvtal_page_row(PDO $pdo, string $sql, array $parameters = []): array
{
    return brvtal_page_rows($pdo, $sql, $parameters)[0] ?? [];
}

function brvtal_public_page_data(PDO $pdo, array $entity): array
{
    $id = (int)$entity['id'];
    $type = (string)$entity['route_type'];
    $data = ['entity' => $entity, 'facts' => [], 'links' => [], 'related' => []];
    $eventStatuses = brvtal_public_visible_event_statuses();
    $eventPlaceholders = brvtal_public_sql_placeholders($eventStatuses);

    if ($type === 'events') {
        $detail = brvtal_page_row($pdo, "SELECT event_date,venue,city,status,ticket_url,ticket_instructions FROM events WHERE id=? LIMIT 1", [$id]);
        $data['entity'] += $detail;
        $data['facts'] = array_filter([
            'DATE' => isset($detail['event_date']) ? date('d.m.Y / H:i', strtotime((string)$detail['event_date'])) : '',
            'LOCATION' => implode(' / ', array_filter([$detail['venue'] ?? '', $detail['city'] ?? ''])),
            'STATUS' => strtoupper(str_replace('_', ' ', (string)($detail['status'] ?? ''))),
        ]);
        $data['links'] = array_filter(['TICKETS' => $detail['ticket_url'] ?? '']);
        $data['related']['LINEUP'] = brvtal_page_rows($pdo, "SELECT a.name AS title,a.slug,a.photo AS image,ea.role AS meta,'artists' AS route_type FROM event_artists ea JOIN artists a ON a.id=ea.artist_id AND a.status='published' WHERE ea.event_id=? ORDER BY ea.lineup_order,a.name", [$id]);
        $data['related']['SETS'] = brvtal_page_rows($pdo, "SELECT title,slug,cover_image AS image,platform AS meta,'sets' AS route_type FROM sets_media WHERE event_id=? AND status='published' ORDER BY sort_order,created_at DESC", [$id]);
        $data['related']['TICKETS'] = brvtal_page_rows($pdo, "SELECT name AS title,description,price,currency,external_url AS url,status AS meta FROM event_ticket_types WHERE event_id=? AND status IN ('active','sold_out') ORDER BY sort_order,name", [$id]);
    } elseif ($type === 'artists') {
        $detail = brvtal_page_row($pdo, "SELECT instagram_url,soundcloud_url,website_url,collective_status FROM artists WHERE id=? LIMIT 1", [$id]);
        $data['entity'] += $detail;
        $data['facts'] = array_filter(['COLLECTIVE' => strtoupper(str_replace('_', ' ', (string)($detail['collective_status'] ?? '')))]);
        $data['links'] = array_filter(['INSTAGRAM' => $detail['instagram_url'] ?? '', 'SOUNDCLOUD' => $detail['soundcloud_url'] ?? '', 'WEBSITE' => $detail['website_url'] ?? '']);
        $data['related']['EVENTS'] = brvtal_page_rows(
            $pdo,
            "SELECT e.title,e.slug,e.cover_image AS image,CONCAT_WS(' / ',DATE_FORMAT(e.event_date,'%d.%m.%Y'),e.city) AS meta,'events' AS route_type FROM event_artists ea JOIN events e ON e.id=ea.event_id WHERE ea.artist_id=? AND e.status IN ({$eventPlaceholders}) ORDER BY e.event_date DESC",
            array_merge([$id], $eventStatuses)
        );
        $data['related']['SETS'] = brvtal_page_rows($pdo, "SELECT title,slug,cover_image AS image,platform AS meta,'sets' AS route_type FROM sets_media WHERE artist_id=? AND status='published' ORDER BY sort_order,created_at DESC", [$id]);
        $data['related']['RELEASES'] = brvtal_page_rows($pdo, "SELECT r.title,r.slug,r.artwork AS image,CONCAT_WS(' / ',UPPER(r.release_type),r.catalog_number) AS meta,'releases' AS route_type FROM release_artists ra JOIN releases r ON r.id=ra.release_id AND r.status='published' WHERE ra.artist_id=? ORDER BY r.release_date DESC,r.sort_order", [$id]);
    } elseif ($type === 'releases') {
        $detail = brvtal_page_row($pdo, "SELECT release_type,catalog_number,release_date,spotify_url,soundcloud_url,bandcamp_url,youtube_url,beatport_url FROM releases WHERE id=? LIMIT 1", [$id]);
        $data['entity'] += $detail;
        $data['facts'] = array_filter(['FORMAT' => strtoupper((string)($detail['release_type'] ?? '')), 'CATALOG' => $detail['catalog_number'] ?? '', 'RELEASE DATE' => $detail['release_date'] ?? '']);
        $data['links'] = array_filter(['SPOTIFY' => $detail['spotify_url'] ?? '', 'SOUNDCLOUD' => $detail['soundcloud_url'] ?? '', 'BANDCAMP' => $detail['bandcamp_url'] ?? '', 'YOUTUBE' => $detail['youtube_url'] ?? '', 'BEATPORT' => $detail['beatport_url'] ?? '']);
        $data['related']['ARTISTS'] = brvtal_page_rows($pdo, "SELECT a.name AS title,a.slug,a.photo AS image,ra.role AS meta,'artists' AS route_type FROM release_artists ra JOIN artists a ON a.id=ra.artist_id AND a.status='published' WHERE ra.release_id=? ORDER BY ra.sort_order,a.name", [$id]);
    } elseif ($type === 'blog') {
        $detail = brvtal_page_row($pdo, "SELECT body,published_at FROM blog_posts WHERE id=? LIMIT 1", [$id]);
        $data['entity'] += $detail;
        $data['entity']['description'] = $detail['body'] ?: $entity['description'];
        $data['facts'] = array_filter(['PUBLISHED' => isset($detail['published_at']) ? date('d.m.Y', strtotime((string)$detail['published_at'])) : '']);
        $tags = brvtal_page_rows($pdo, "SELECT t.name AS title FROM blog_post_tags pt JOIN blog_tags t ON t.id=pt.tag_id WHERE pt.post_id=? ORDER BY t.name", [$id]);
        $data['facts']['TAGS'] = implode(' / ', array_column($tags, 'title'));
        $relations = brvtal_page_rows($pdo, "SELECT related_type,related_id FROM blog_post_relations WHERE post_id=? ORDER BY sort_order", [$id]);
        $map = [
            'event' => ['events','title','cover_image','events',"status IN ({$eventPlaceholders})",$eventStatuses],
            'artist' => ['artists','name','photo','artists',"status='published'",[]],
            'set' => ['sets_media','title','cover_image','sets',"status='published'",[]],
            'release' => ['releases','title','artwork','releases',"status='published'",[]],
        ];
        foreach ($relations as $relation) {
            if (!isset($map[$relation['related_type']])) continue;
            [$table,$title,$image,$route,$where,$whereParameters] = $map[$relation['related_type']];
            $item = brvtal_page_row(
                $pdo,
                "SELECT `{$title}` AS title,slug,`{$image}` AS image,'{$route}' AS route_type FROM `{$table}` WHERE id=? AND {$where} LIMIT 1",
                array_merge([(int)$relation['related_id']], $whereParameters)
            );
            if ($item) $data['related']['RELATED'][] = $item;
        }
    } elseif ($type === 'sets') {
        $detail = brvtal_page_row($pdo, "SELECT platform,external_url,embed_url,artist_id,event_id FROM sets_media WHERE id=? LIMIT 1", [$id]);
        $data['entity'] += $detail;
        $data['facts'] = array_filter(['PLATFORM' => strtoupper((string)($detail['platform'] ?? ''))]);
        $data['links'] = array_filter(['LISTEN' => $detail['external_url'] ?? '']);
        if (!empty($detail['artist_id'])) $data['related']['ARTIST'] = brvtal_page_rows($pdo, "SELECT name AS title,slug,photo AS image,'artists' AS route_type FROM artists WHERE id=? AND status='published'", [(int)$detail['artist_id']]);
        if (!empty($detail['event_id'])) {
            $data['related']['EVENT'] = brvtal_page_rows(
                $pdo,
                "SELECT title,slug,cover_image AS image,'events' AS route_type FROM events WHERE id=? AND status IN ({$eventPlaceholders})",
                array_merge([(int)$detail['event_id']], $eventStatuses)
            );
        }
    } elseif ($type === 'pages') {
        $detail = brvtal_page_row($pdo, "SELECT content_json FROM pages WHERE id=? LIMIT 1", [$id]);
        $decoded = json_decode((string)($detail['content_json'] ?? ''), true);
        if (is_array($decoded)) {
            $text = $decoded['body'] ?? $decoded['content'] ?? $decoded['text'] ?? '';
            if (is_string($text)) $data['entity']['description'] = $text;
        }
    }
    return $data;
}

function brvtal_public_entity_page(array $page, array $seo, string $analytics = ''): string
{
    $entity = $page['entity'];
    $escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeUrl = static function (mixed $value) use ($escape): string {
        $url = trim((string)$value);
        return preg_match('#^https?://#i', $url) ? $escape($url) : '';
    };
    $kind = strtoupper(rtrim((string)$entity['route_type'], 'S'));
    $canonicalParts = parse_url((string)$seo['canonical']);
    $base = (($canonicalParts['scheme'] ?? 'https') . '://' . ($canonicalParts['host'] ?? 'www.brvtal.com.co'));
    $image = $escape(brvtal_public_absolute_url(brvtal_public_media_variant((string)$seo['image'], 'hero'), $base));
    $facts = '';
    foreach ($page['facts'] as $label => $value) {
        if (trim((string)$value) !== '') $facts .= '<div><span>' . $escape($label) . '</span><b>' . $escape($value) . '</b></div>';
    }
    $links = '';
    foreach ($page['links'] as $label => $url) {
        $href = $safeUrl($url);
        if ($href !== '') $links .= '<a href="' . $href . '" target="_blank" rel="noopener noreferrer">' . $escape($label) . ' ↗</a>';
    }
    $related = '';
    foreach ($page['related'] as $heading => $items) {
        if (!$items) continue;
        $cards = '';
        foreach ($items as $item) {
            $href = !empty($item['route_type']) && !empty($item['slug']) ? '/' . rawurlencode((string)$item['route_type']) . '/' . rawurlencode((string)$item['slug']) : $safeUrl($item['url'] ?? '');
            $visual = !empty($item['image']) ? '<img src="' . $escape(brvtal_public_absolute_url(brvtal_public_media_variant((string)$item['image'], 'card'), $base)) . '" alt="" loading="lazy" decoding="async">' : '<span class="entity-card-mark">BRVTAL</span>';
            $price = isset($item['price']) && $item['price'] !== null ? number_format((float)$item['price'], 0) . ' ' . $escape($item['currency'] ?? '') : '';
            $content = $visual . '<span><small>' . $escape($item['meta'] ?? $price) . '</small><strong>' . $escape($item['title'] ?? '') . '</strong></span>';
            $cards .= $href ? '<a class="entity-card" href="' . $href . '">' . $content . '</a>' : '<div class="entity-card">' . $content . '</div>';
        }
        $related .= '<section class="entity-related"><div class="entity-section-label">' . $escape($heading) . ' / ' . str_pad((string)count($items), 2, '0', STR_PAD_LEFT) . '</div><div class="entity-grid">' . $cards . '</div></section>';
    }
    $body = nl2br($escape(trim((string)($entity['description'] ?? ''))));
    if ($body === '') $body = 'BRVTAL / RAVE TILL GRAVE';
    $title = $escape($seo['title']);
    $description = $escape($seo['description']);
    $entityTitle = $escape($entity['title']);
    $entityId = $escape(str_pad((string)$entity['id'], 3, '0', STR_PAD_LEFT));
    $routeType = $escape($entity['route_type']);
    $tags = brvtal_public_seo_tags($seo);

    return <<<HTML
<!doctype html>
<html lang="en">
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
</head>
<body>
  <a class="skip-link" href="#main-content">SKIP TO CONTENT</a>
  <header class="entity-nav"><a href="/" class="entity-brand">BRVTAL<small>RAVE TILL GRAVE</small></a><a href="/#{$routeType}">← BACK TO ARCHIVE</a></header>
  <main id="main-content" tabindex="-1">
    <article class="entity-hero">
      <div class="entity-image"><img src="{$image}" alt="{$entityTitle}" loading="eager" fetchpriority="high" decoding="async"><span>{$kind} / BRVTAL</span></div>
      <div class="entity-copy"><div class="entity-kicker">BRVTAL / {$kind} / {$entityId}</div><h1>{$entityTitle}</h1><div class="entity-facts">{$facts}</div><div class="entity-actions">{$links}</div></div>
    </article>
    <section class="entity-statement"><div class="entity-section-label">ABOUT / INFORMATION</div><p>{$body}</p></section>
    {$related}
  </main>
  <footer><strong>BRVTAL</strong><span>PEREIRA / COLOMBIA</span><span>RAVE TILL GRAVE</span></footer>
  {$analytics}
</body>
</html>
HTML;
}
