<?php
declare(strict_types=1);
require_once __DIR__ . '/media.php';
require_once __DIR__ . '/memory_relations.php';
require_once __DIR__ . '/public_visibility.php';

function brvtal_public_media_variant(string $image, string $context): string
{
    $path = parse_url($image, PHP_URL_PATH) ?: $image;
    $sidecar = brvtal_media_read_sidecar($path);
    $variant = $sidecar['variants'][$context]['path'] ?? null;
    return is_string($variant) && $variant !== '' ? $variant : $image;
}

function brvtal_page_query_degraded(?bool $set = null): bool
{
    static $degraded = false;
    if ($set !== null) {
        $degraded = $set;
    }
    return $degraded;
}

function brvtal_page_rows(PDO $pdo, string $sql, array $parameters = [], bool $required = false): array
{
    try {
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll();
    } catch (Throwable $e) {
        brvtal_page_query_degraded(true);
        if (function_exists('brvtal_log')) {
            brvtal_log('PUBLIC_ENTITY_QUERY_ERROR', 'Canonical entity query failed', [
                'required' => $required,
                'class' => get_class($e),
                'message' => $e->getMessage(),
            ]);
        }
        if ($required) {
            throw $e;
        }
        return [];
    }
}

function brvtal_page_row(PDO $pdo, string $sql, array $parameters = [], bool $required = false): array
{
    return brvtal_page_rows($pdo, $sql, $parameters, $required)[0] ?? [];
}

function brvtal_page_public_event_rows(PDO $pdo, string $sql, array $parameters = []): array
{
    return array_values(array_filter(
        brvtal_page_rows($pdo, $sql, $parameters),
        static fn(array $event): bool => brvtal_public_event_is_visible($event)
    ));
}

function brvtalPublicTransmissionRelationType(string $routeType): ?string
{
    return [
        'events' => 'event',
        'artists' => 'artist',
        'sets' => 'set',
        'releases' => 'release',
    ][$routeType] ?? null;
}

function brvtalPublicTransmissionsForEntity(PDO $pdo, string $routeType, int $entityId): array
{
    $relationType = brvtalPublicTransmissionRelationType($routeType);
    if ($relationType === null || $entityId < 1) {
        return [];
    }

    return brvtal_page_rows(
        $pdo,
        "SELECT bp.title,bp.slug,bp.cover_image AS image,"
            . "DATE_FORMAT(COALESCE(bp.published_at,bp.updated_at),'%d.%m.%Y') AS meta,"
            . "'blog' AS route_type "
            . "FROM blog_post_relations rel "
            . "JOIN blog_posts bp ON bp.id=rel.post_id AND bp.status='published' "
            . "WHERE rel.related_type=? AND rel.related_id=? "
            . "ORDER BY rel.sort_order,COALESCE(bp.published_at,bp.updated_at) DESC,bp.id DESC",
        [$relationType, $entityId]
    );
}

function brvtal_public_page_data(PDO $pdo, array $entity): array
{
    brvtal_page_query_degraded(false);
    $id = (int)$entity['id'];
    $type = (string)$entity['route_type'];
    $data = ['entity' => $entity, 'facts' => [], 'links' => [], 'related' => [], 'record' => [], 'degraded' => false];
    $eventStatuses = brvtal_public_visible_event_statuses();
    $eventPlaceholders = brvtal_public_sql_placeholders($eventStatuses);

    if ($type === 'events') {
        $detail = brvtal_page_row($pdo, "SELECT event_date,venue,city,status,skin,accent,ticket_url,ticket_instructions,published_at,finished_at,cancelled_at FROM events WHERE id=? LIMIT 1", [$id], true);
        $isHistorical = brvtal_public_event_is_historical($detail);
        $allowsTicketing = brvtal_public_event_allows_ticketing($detail);
        if (!$allowsTicketing) {
            $detail['ticket_url'] = null;
            $detail['ticket_instructions'] = null;
        }
        $data['entity'] += $detail;
        $recordYear = !empty($detail['event_date']) ? date('Y', strtotime((string)$detail['event_date'])) : '';
        $recordStatus = strtoupper(str_replace('_', ' ', (string)($detail['status'] ?? '')));
        $data['record'] = [
            'state' => $isHistorical ? 'historical' : 'active',
            'year' => $recordYear,
            'status' => $recordStatus,
        ];
        $data['facts'] = array_filter([
            'DATE' => isset($detail['event_date']) ? date('d.m.Y / H:i', strtotime((string)$detail['event_date'])) : '',
            'LOCATION' => implode(' / ', array_filter([$detail['venue'] ?? '', $detail['city'] ?? ''])),
            'STATUS' => $recordStatus,
            'RECORD' => $isHistorical && $recordYear !== '' ? 'ARCHIVE / ' . $recordYear : '',
        ]);
        $data['links'] = $allowsTicketing ? array_filter(['TICKETS' => $detail['ticket_url'] ?? '']) : [];
        $data['related']['LINEUP'] = brvtal_page_rows($pdo, "SELECT a.name AS title,a.slug,a.photo AS image,ea.role AS meta,'artists' AS route_type FROM event_artists ea JOIN artists a ON a.id=ea.artist_id AND a.status='published' WHERE ea.event_id=? ORDER BY ea.lineup_order,a.name", [$id]);
        $data['related']['SETS'] = brvtal_page_rows($pdo, "SELECT title,slug,cover_image AS image,platform AS meta,'sets' AS route_type FROM sets_media WHERE event_id=? AND status='published' ORDER BY sort_order,created_at DESC", [$id]);
        $data['related']['TRANSMISSIONS'] = brvtalPublicTransmissionsForEntity(
            $pdo,
            $type,
            $id
        );
        if ($allowsTicketing) {
            $ticketRows = brvtal_page_rows($pdo, "SELECT name AS title,description,price,currency,external_url AS url,status,status AS meta,available_from,available_until FROM event_ticket_types WHERE event_id=? AND status IN ('active','sold_out') ORDER BY sort_order,name", [$id]);
            $data['related']['TICKETS'] = array_values(array_filter(
                $ticketRows,
                static fn(array $ticket): bool => brvtal_public_ticket_type_is_available($ticket)
            ));
        }
    } elseif ($type === 'artists') {
        $detail = brvtal_page_row($pdo, "SELECT instagram_url,soundcloud_url,website_url,collective_status FROM artists WHERE id=? LIMIT 1", [$id], true);
        $data['entity'] += $detail;
        $data['facts'] = array_filter(['COLLECTIVE' => strtoupper(str_replace('_', ' ', (string)($detail['collective_status'] ?? '')))]);
        $data['links'] = array_filter(['INSTAGRAM' => $detail['instagram_url'] ?? '', 'SOUNDCLOUD' => $detail['soundcloud_url'] ?? '', 'WEBSITE' => $detail['website_url'] ?? '']);
        $data['related']['EVENTS'] = brvtal_page_public_event_rows(
            $pdo,
            "SELECT e.title,e.slug,e.cover_image AS image,CONCAT_WS(' / ',DATE_FORMAT(e.event_date,'%d.%m.%Y'),e.city) AS meta,'events' AS route_type,e.status,e.event_date,e.published_at FROM event_artists ea JOIN events e ON e.id=ea.event_id WHERE ea.artist_id=? AND e.status IN ({$eventPlaceholders}) ORDER BY e.event_date DESC",
            array_merge([$id], $eventStatuses)
        );
        $data['related']['SETS'] = brvtal_page_rows($pdo, "SELECT title,slug,cover_image AS image,platform AS meta,'sets' AS route_type FROM sets_media WHERE artist_id=? AND status='published' ORDER BY sort_order,created_at DESC", [$id]);
        $data['related']['RELEASES'] = brvtal_page_rows($pdo, "SELECT r.title,r.slug,r.artwork AS image,CONCAT_WS(' / ',UPPER(r.release_type),r.catalog_number) AS meta,'releases' AS route_type FROM release_artists ra JOIN releases r ON r.id=ra.release_id AND r.status='published' WHERE ra.artist_id=? ORDER BY r.release_date DESC,r.sort_order", [$id]);
        $data['related']['TRANSMISSIONS'] = brvtalPublicTransmissionsForEntity($pdo, $type, $id);
    } elseif ($type === 'releases') {
        $detail = brvtal_page_row($pdo, "SELECT release_type,catalog_number,release_date,spotify_url,soundcloud_url,bandcamp_url,youtube_url,beatport_url FROM releases WHERE id=? LIMIT 1", [$id], true);
        $data['entity'] += $detail;
        $data['facts'] = array_filter(['FORMAT' => strtoupper((string)($detail['release_type'] ?? '')), 'CATALOG' => $detail['catalog_number'] ?? '', 'RELEASE DATE' => $detail['release_date'] ?? '']);
        $data['links'] = array_filter(['SPOTIFY' => $detail['spotify_url'] ?? '', 'SOUNDCLOUD' => $detail['soundcloud_url'] ?? '', 'BANDCAMP' => $detail['bandcamp_url'] ?? '', 'YOUTUBE' => $detail['youtube_url'] ?? '', 'BEATPORT' => $detail['beatport_url'] ?? '']);
        $data['related']['ARTISTS'] = brvtal_page_rows($pdo, "SELECT a.name AS title,a.slug,a.photo AS image,ra.role AS meta,'artists' AS route_type FROM release_artists ra JOIN artists a ON a.id=ra.artist_id AND a.status='published' WHERE ra.release_id=? ORDER BY ra.sort_order,a.name", [$id]);
        $data['related']['TRANSMISSIONS'] = brvtalPublicTransmissionsForEntity($pdo, $type, $id);
    } elseif ($type === 'blog') {
        $detail = brvtal_page_row($pdo, "SELECT body,published_at FROM blog_posts WHERE id=? LIMIT 1", [$id], true);
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
            $lifecycleSelect = $route === 'events' ? ',status,event_date,published_at' : '';
            $item = brvtal_page_row(
                $pdo,
                "SELECT `{$title}` AS title,slug,`{$image}` AS image,'{$route}' AS route_type{$lifecycleSelect} FROM `{$table}` WHERE id=? AND {$where} LIMIT 1",
                array_merge([(int)$relation['related_id']], $whereParameters)
            );
            if ($item && ($route !== 'events' || brvtal_public_event_is_visible($item))) $data['related']['RELATED'][] = $item;
        }
    } elseif ($type === 'sets') {
        $detail = brvtal_page_row($pdo, "SELECT platform,external_url,embed_url,artist_id,event_id FROM sets_media WHERE id=? LIMIT 1", [$id], true);
        $data['entity'] += $detail;
        $data['facts'] = array_filter(['PLATFORM' => strtoupper((string)($detail['platform'] ?? ''))]);
        $data['links'] = array_filter(['LISTEN' => $detail['external_url'] ?? '']);
        if (!empty($detail['artist_id'])) $data['related']['ARTIST'] = brvtal_page_rows($pdo, "SELECT name AS title,slug,photo AS image,'artists' AS route_type FROM artists WHERE id=? AND status='published'", [(int)$detail['artist_id']]);
        if (!empty($detail['event_id'])) {
            $data['related']['EVENT'] = brvtal_page_public_event_rows(
                $pdo,
                "SELECT title,slug,cover_image AS image,'events' AS route_type,status,event_date,published_at FROM events WHERE id=? AND status IN ({$eventPlaceholders})",
                array_merge([(int)$detail['event_id']], $eventStatuses)
            );
        }
        $data['related']['TRANSMISSIONS'] = brvtalPublicTransmissionsForEntity($pdo, $type, $id);
    } elseif ($type === 'pages') {
        $detail = brvtal_page_row($pdo, "SELECT content_json FROM pages WHERE id=? LIMIT 1", [$id], true);
        $decoded = json_decode((string)($detail['content_json'] ?? ''), true);
        if (is_array($decoded)) {
            $text = $decoded['body'] ?? $decoded['content'] ?? $decoded['text'] ?? '';
            if (is_string($text)) {
                $data['entity']['description'] = $text;
            }
        }
    }

    if (in_array($type, ['events','artists','sets','releases'], true)) {
        $memoryItems = brvtal_public_memories_for_entity($pdo, $type, $id);
        if ($memoryItems) {
            $data['related']['MEMORIES'] = $memoryItems;
        }
    }

    $data['degraded'] = brvtal_page_query_degraded();
    return $data;
}

function brvtal_public_connected_url(array $entity): string
{
    $type = (string)($entity['route_type'] ?? '');
    $id = (int)($entity['id'] ?? 0);
    if ($id < 1 || !in_array($type, ['artists', 'events', 'sets', 'releases'], true)) {
        return '';
    }
    return '/?network_type=' . rawurlencode($type) . '&network_id=' . $id . '#network';
}

/** Render a canonical public entity page, including lifecycle-aware Event Record framing. */
function brvtal_public_entity_page(array $page, array $seo, string $analytics = ''): string
{
    $entity = $page['entity'];
    $degraded = !empty($page['degraded']);
    $escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeUrl = static function (mixed $value) use ($escape): string {
        $url = trim((string)$value);
        return preg_match('#^https?://#i', $url) ? $escape($url) : '';
    };
    $safeInternalUrl = static function (mixed $value) use ($escape): string {
        $url = trim((string)$value);
        if ($url === '' || str_starts_with($url, '//')) return '';
        return preg_match('@^/[A-Za-z0-9_./?%=&~#-]*(?:#[A-Za-z0-9_-]*)?$@', $url) ? $escape($url) : '';
    };
    $routeTypeRaw = (string)$entity['route_type'];
    $isEvent = $routeTypeRaw === 'events';
    $recordState = $isEvent && (($page['record']['state'] ?? '') === 'historical') ? 'historical' : 'active';
    $recordStatus = trim((string)($page['record']['status'] ?? $entity['status'] ?? ''));
    $recordYear = trim((string)($page['record']['year'] ?? ''));
    $kind = strtoupper(rtrim($routeTypeRaw, 'S'));
    $kindLabel = $isEvent && $recordState === 'historical' ? 'EVENT RECORD' : $kind;
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
    $connectedUrl = brvtal_public_connected_url($entity);
    if ($connectedUrl !== '') {
        $links .= '<a href="' . $escape($connectedUrl) . '">EXPLORE CONNECTIONS ↗</a>';
    }
    $related = '';
    $historicalHeadings = [
        'LINEUP' => 'LINEUP / RECORD',
        'SETS' => 'RECORDED SETS',
    ];
    foreach ($page['related'] as $heading => $items) {
        if (!$items) continue;
        $cards = '';
        foreach ($items as $item) {
            $href = !empty($item['route_type']) && !empty($item['slug'])
                ? '/' . rawurlencode((string)$item['route_type']) . '/' . rawurlencode((string)$item['slug'])
                : ($safeInternalUrl($item['url'] ?? '') ?: $safeUrl($item['url'] ?? ''));
            $visual = !empty($item['image']) ? '<img src="' . $escape(brvtal_public_absolute_url(brvtal_public_media_variant((string)$item['image'], 'card'), $base)) . '" alt="" loading="lazy" decoding="async">' : '<span class="entity-card-mark">BRVTAL</span>';
            $price = isset($item['price']) && $item['price'] !== null ? number_format((float)$item['price'], 0) . ' ' . $escape($item['currency'] ?? '') : '';
            $content = $visual . '<span><small>' . $escape($item['meta'] ?? $price) . '</small><strong>' . $escape($item['title'] ?? '') . '</strong></span>';
            $cards .= $href ? '<a class="entity-card" href="' . $href . '">' . $content . '</a>' : '<div class="entity-card">' . $content . '</div>';
        }
        $displayHeading = $isEvent && $recordState === 'historical' ? ($historicalHeadings[$heading] ?? $heading) : $heading;
        $related .= '<section class="entity-related"><div class="entity-section-label">' . $escape($displayHeading) . ' / ' . str_pad((string)count($items), 2, '0', STR_PAD_LEFT) . '</div><div class="entity-grid">' . $cards . '</div></section>';
    }
    $body = nl2br($escape(trim((string)($entity['description'] ?? ''))));
    if ($body === '') $body = 'BRVTAL / RAVE TILL GRAVE';
    $title = $escape($seo['title']);
    $description = $escape($seo['description']);
    $entityTitle = $escape($entity['title']);
    $entityId = $escape(str_pad((string)$entity['id'], 3, '0', STR_PAD_LEFT));
    $routeType = $escape($routeTypeRaw);
    $backToSection = in_array($routeTypeRaw, ['events', 'artists', 'sets'], true);
    $backHref = $escape($backToSection ? '/#' . $routeTypeRaw : '/');
    if ($isEvent) {
        $backLabel = $escape($recordState === 'historical' ? '← BACK TO ARCHIVE' : '← BACK TO EVENTS');
    } else {
        $backLabel = $escape($backToSection ? '← BACK TO ARCHIVE' : '← BACK HOME');
    }
    $tags = brvtal_public_seo_tags($seo);
    $robots = $degraded ? '<meta name="robots" content="noindex, follow">' : '';
    $degradedNotice = $degraded
        ? '<section class="entity-statement" role="status"><div class="entity-section-label">DATA STATUS / DEGRADED</div><p>Some connected information is temporarily unavailable. This page will return to its complete state automatically when the data service recovers.</p></section>'
        : '';

    $eventStylesheet = $isEvent ? '<link rel="stylesheet" href="/css/public-event-record.css">' : '';
    $eventSignalRaw = trim((string)($entity['accent'] ?? ''));
    $eventSignal = $isEvent && preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $eventSignalRaw) ? $eventSignalRaw : '#b6ff00';
    $bodyClass = $isEvent ? 'entity-page entity-page--event entity-page--event-' . $recordState : 'entity-page';
    $bodyAttributes = 'class="' . $escape($bodyClass) . '"';
    if ($isEvent) {
        $bodyAttributes .= ' data-event-record-state="' . $escape($recordState) . '" style="--event-signal:' . $escape($eventSignal) . '"';
    }
    $statementLabel = $isEvent ? ($recordState === 'historical' ? 'RECORD / INFORMATION' : 'ABOUT / EXPERIENCE') : 'ABOUT / INFORMATION';
    $recordBand = '';
    if ($isEvent) {
        $bandLabel = $recordState === 'historical' ? 'EVENT RECORD' : 'EVENT SIGNAL';
        $bandValue = $recordState === 'historical' ? 'ARCHIVE' : 'ACTIVE EXPERIENCE';
        if ($recordStatus !== '') $bandLabel .= ' / ' . $recordStatus;
        if ($recordYear !== '') $bandValue .= ' / ' . $recordYear;
        $recordBand = '<section class="event-record-band" aria-label="Event lifecycle"><div><span>' . $escape($bandLabel) . '</span><strong>' . $escape($bandValue) . '</strong></div><p>CANONICAL EVENT FILE / BRVTAL</p></section>';
    }

    return <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#050505">
  {$robots}
  <meta name="description" content="{$description}">
  <title>{$title}</title>
  <base href="/">
  {$tags}
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;600;700;800;900&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/public-entity.css">
  {$eventStylesheet}
</head>
<body {$bodyAttributes}>
  <a class="skip-link" href="#main-content">SKIP TO CONTENT</a>
  <header class="entity-nav"><a href="/" class="entity-brand">BRVTAL<small>RAVE TILL GRAVE</small></a><a href="{$backHref}">{$backLabel}</a></header>
  <main id="main-content" tabindex="-1">
    <article class="entity-hero">
      <div class="entity-image"><img src="{$image}" alt="{$entityTitle}" loading="eager" fetchpriority="high" decoding="async"><span>{$kindLabel} / BRVTAL</span></div>
      <div class="entity-copy"><div class="entity-kicker">BRVTAL / {$kindLabel} / {$entityId}</div><h1 data-text="{$entityTitle}">{$entityTitle}</h1><div class="entity-facts">{$facts}</div><div class="entity-actions">{$links}</div></div>
    </article>
    {$recordBand}
    <section class="entity-statement"><div class="entity-section-label">{$statementLabel}</div><p>{$body}</p></section>
    {$degradedNotice}
    {$related}
  </main>
  <footer><strong>BRVTAL</strong><span>PEREIRA / COLOMBIA</span><span>RAVE TILL GRAVE</span></footer>
  {$analytics}
</body>
</html>
HTML;
}
