<?php
declare(strict_types=1);

require_once __DIR__ . '/public_cromo_chrome.php';
require_once __DIR__ . '/media.php';
require_once __DIR__ . '/memory_relations.php';
require_once __DIR__ . '/public_visibility.php';
require_once __DIR__ . '/public_time_format.php';
require_once __DIR__ . '/artist_hero_framing.php';
require_once __DIR__ . '/blog_html.php';
require_once __DIR__ . '/public_text_format.php';
require_once __DIR__ . '/public_ticket_checkout.php';

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
                'class' => $e::class,
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

/**
 * Convert approved timetable storage rows into the only shape allowed across
 * the public boundary. Internal timetable/Event/Artist IDs are intentionally
 * omitted. Malformed or private-linked rows fail closed.
 *
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function brvtalPublicEventTimetableRows(array $rows): array
{
    usort($rows, static function (array $left, array $right): int {
        $byStart = strcmp((string)($left['starts_at_utc'] ?? ''), (string)($right['starts_at_utc'] ?? ''));
        if ($byStart !== 0) return $byStart;
        $byOrder = (int)($left['sort_order'] ?? 0) <=> (int)($right['sort_order'] ?? 0);
        if ($byOrder !== 0) return $byOrder;
        return (int)($left['id'] ?? 0) <=> (int)($right['id'] ?? 0);
    });

    $utc = new DateTimeZone('UTC');
    $out = [];
    foreach ($rows as $row) {
        if (strtolower(trim((string)($row['status'] ?? ''))) !== 'approved') {
            continue;
        }

        $timezoneName = trim((string)($row['timezone'] ?? ''));
        $knownTimezone = $timezoneName === 'UTC'
            || in_array($timezoneName, DateTimeZone::listIdentifiers(), true);
        if (!$knownTimezone) {
            continue;
        }
        $timezone = new DateTimeZone($timezoneName);

        $startsRaw = trim((string)($row['starts_at_utc'] ?? ''));
        $endsRaw = trim((string)($row['ends_at_utc'] ?? ''));
        $startsAt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $startsRaw, $utc);
        $startErrors = DateTimeImmutable::getLastErrors();
        $endsAt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $endsRaw, $utc);
        $endErrors = DateTimeImmutable::getLastErrors();
        if (
            !$startsAt instanceof DateTimeImmutable
            || !$endsAt instanceof DateTimeImmutable
            || ($startErrors !== false && ($startErrors['warning_count'] > 0 || $startErrors['error_count'] > 0))
            || ($endErrors !== false && ($endErrors['warning_count'] > 0 || $endErrors['error_count'] > 0))
            || $startsAt->format('Y-m-d H:i:s') !== $startsRaw
            || $endsAt->format('Y-m-d H:i:s') !== $endsRaw
            || $endsAt <= $startsAt
        ) {
            continue;
        }

        $artistRaw = $row['artist_id'] ?? null;
        $hasArtistReference = $artistRaw !== null && $artistRaw !== '';
        $item = [];
        if ($hasArtistReference) {
            $artistId = filter_var($artistRaw, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            $artistStatus = strtolower(trim((string)($row['artist_status'] ?? '')));
            $artistName = trim((string)($row['artist_name'] ?? ''));
            $artistSlug = trim((string)($row['artist_slug'] ?? ''));
            if ($artistId === false || $artistStatus !== 'published' || $artistName === '' || $artistSlug === '') {
                continue;
            }
            $item = [
                'title'=>$artistName,
                'slug'=>$artistSlug,
                'image'=>(string)($row['artist_image'] ?? ''),
                'route_type'=>'artists',
            ];
        } else {
            $label = trim((string)($row['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $item = ['title'=>$label];
        }

        $localStart = $startsAt->setTimezone($timezone);
        $localEnd = $endsAt->setTimezone($timezone);
        $range = brvtalPublicTimeRange($localStart, $localEnd);

        $item['starts_at'] = $localStart->format('Y-m-d\\TH:i');
        $item['ends_at'] = $localEnd->format('Y-m-d\\TH:i');
        $item['timezone'] = $timezoneName;
        $item['meta'] = $range . ' / ' . $timezoneName;
        $out[] = $item;
    }

    return $out;
}

/**
 * Hydrate one already-public Event timetable without creating another route.
 *
 * @return list<array<string,mixed>>
 */
function brvtalPublicEventTimetableForEvent(PDO $pdo, int $eventId): array
{
    if ($eventId < 1) {
        return [];
    }

    $rows = brvtal_page_rows(
        $pdo,
        "SELECT t.id,t.artist_id,t.label,t.starts_at_utc,t.ends_at_utc,t.timezone,t.status,t.sort_order,"
            . "a.name AS artist_name,a.slug AS artist_slug,a.photo AS artist_image,a.status AS artist_status "
            . "FROM event_timetable_items t "
            . "LEFT JOIN artists a ON a.id=t.artist_id AND a.status='published' "
            . "WHERE t.event_id=? AND t.status='approved' "
            . "ORDER BY t.starts_at_utc,t.sort_order,t.id",
        [$eventId]
    );

    return brvtalPublicEventTimetableRows($rows);
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

/**
 * Enrich only public Artist cards. A single parameterized settings read serves
 * all card groups; malformed/absent framing keeps the 50% 20% CSS fallback.
 * Internal artist IDs are stripped before returning public page data.
 */
function brvtalPublicEntityArtistCardFraming(PDO $pdo, array $related): array
{
    $artistIds = [];
    foreach ($related as $items) {
        foreach ($items as $item) {
            if (($item['route_type'] ?? '') !== 'artists') continue;
            $id = (int)($item['artist_card_id'] ?? 0);
            if ($id > 0) $artistIds[$id] = true;
        }
    }
    if (!$artistIds) return $related;

    $keys = array_map(brvtalArtistHeroSettingKey(...), array_keys($artistIds));
    $marks = implode(',', array_fill(0, count($keys), '?'));
    $rows = brvtal_page_rows(
        $pdo,
        "SELECT setting_key,setting_value FROM settings WHERE is_json=1 AND setting_key IN ({$marks})",
        $keys
    );
    $framingByKey = [];
    foreach ($rows as $row) {
        $key = (string)($row['setting_key'] ?? '');
        if (!in_array($key, $keys, true)) continue;
        $framing = brvtalArtistHeroValidate(json_decode((string)($row['setting_value'] ?? ''), true));
        if ($framing !== null) $framingByKey[$key] = $framing;
    }

    foreach ($related as &$items) {
        foreach ($items as &$item) {
            if (($item['route_type'] ?? '') !== 'artists') continue;
            $id = (int)($item['artist_card_id'] ?? 0);
            unset($item['artist_card_id']);
            if ($id < 1) continue;
            $key = brvtalArtistHeroSettingKey($id);
            if (isset($framingByKey[$key])) $item['card_framing'] = $framingByKey[$key];
        }
        unset($item);
    }
    unset($items);
    return $related;
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
        brvtal_public_ticket_checkout_http($pdo, $id);
        $data['checkout'] = brvtal_public_ticket_checkout_offers($pdo, $id);
        $detail = brvtal_page_row($pdo, "SELECT event_date,venue,venue_address,city,status,skin,accent,ticket_url,ticket_instructions,published_at,finished_at,cancelled_at FROM events WHERE id=? LIMIT 1", [$id], true);
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
            'DATE' => isset($detail['event_date']) ? brvtalPublicDateTimeFormat((int)strtotime((string)$detail['event_date'])) : '',
            'LOCATION' => implode(' / ', array_filter([$detail['venue'] ?? '', $detail['city'] ?? ''])),
            'RECORD' => $isHistorical && $recordYear !== '' ? 'ARCHIVE / ' . $recordYear : '',
        ]);
        $data['links'] = $allowsTicketing ? array_filter(['TICKETS' => $detail['ticket_url'] ?? '']) : [];
        $data['related']['LINEUP'] = brvtal_page_rows($pdo, "SELECT a.id AS artist_card_id,a.name AS title,a.slug,a.photo AS image,ea.role AS meta,'artists' AS route_type FROM event_artists ea JOIN artists a ON a.id=ea.artist_id AND a.status='published' WHERE ea.event_id=? ORDER BY ea.lineup_order,a.name", [$id]);
        if (brvtal_public_event_is_visible($detail)) {
            $timetable = brvtalPublicEventTimetableForEvent($pdo, $id);
            if ($timetable !== []) {
                $data['related']['TIMETABLE'] = $timetable;
            }
        }
        $data['related']['SETS'] = brvtal_page_rows($pdo, "SELECT title,slug,cover_image AS image,platform AS meta,'sets' AS route_type FROM sets_media WHERE event_id=? AND status='published' ORDER BY sort_order,created_at DESC", [$id]);
        if ($allowsTicketing) {
            $ticketRows = brvtal_page_rows($pdo, "SELECT id AS ticket_type_id,name AS title,description,price,currency,external_url AS url,payment_instructions,qr_image,status,status AS meta,available_from,available_until FROM event_ticket_types WHERE event_id=? AND status IN ('active','sold_out') ORDER BY sort_order,name", [$id]);
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
    } elseif ($type === 'releases') {
        $detail = brvtal_page_row($pdo, "SELECT release_type,catalog_number,release_date,spotify_url,soundcloud_url,bandcamp_url,youtube_url,beatport_url FROM releases WHERE id=? LIMIT 1", [$id], true);
        $data['entity'] += $detail;
        $data['facts'] = array_filter(['FORMAT' => strtoupper((string)($detail['release_type'] ?? '')), 'CATALOG' => $detail['catalog_number'] ?? '', 'RELEASE DATE' => $detail['release_date'] ?? '']);
        $data['links'] = array_filter(['SPOTIFY' => $detail['spotify_url'] ?? '', 'SOUNDCLOUD' => $detail['soundcloud_url'] ?? '', 'BANDCAMP' => $detail['bandcamp_url'] ?? '', 'YOUTUBE' => $detail['youtube_url'] ?? '', 'BEATPORT' => $detail['beatport_url'] ?? '']);
        $data['related']['ARTISTS'] = brvtal_page_rows($pdo, "SELECT a.id AS artist_card_id,a.name AS title,a.slug,a.photo AS image,ra.role AS meta,'artists' AS route_type FROM release_artists ra JOIN artists a ON a.id=ra.artist_id AND a.status='published' WHERE ra.release_id=? ORDER BY ra.sort_order,a.name", [$id]);
    } elseif ($type === 'blog') {
        $detail = brvtal_page_row($pdo, "SELECT body,published_at FROM blog_posts WHERE id=? LIMIT 1", [$id], true);
        $data['entity'] += $detail;
        $bodyResult = brvtal_blog_sanitize_html((string)($detail['body'] ?? ''));
        $data['record']['body_html'] = $bodyResult['html'];
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
            $artistIdSelect = $route === 'artists' ? ',id AS artist_card_id' : '';
            $item = brvtal_page_row(
                $pdo,
                "SELECT `{$title}` AS title,slug,`{$image}` AS image,'{$route}' AS route_type{$lifecycleSelect}{$artistIdSelect} FROM `{$table}` WHERE id=? AND {$where} LIMIT 1",
                array_merge([(int)$relation['related_id']], $whereParameters)
            );
            if ($item && ($route !== 'events' || brvtal_public_event_is_visible($item))) $data['related']['RELATED'][] = $item;
        }
    } elseif ($type === 'sets') {
        $detail = brvtal_page_row($pdo, "SELECT platform,external_url,embed_url,artist_id,event_id FROM sets_media WHERE id=? LIMIT 1", [$id], true);
        $data['entity'] += $detail;
        $data['facts'] = array_filter(['PLATFORM' => strtoupper((string)($detail['platform'] ?? ''))]);
        $data['links'] = array_filter(['LISTEN' => $detail['external_url'] ?? '']);
        if (!empty($detail['artist_id'])) $data['related']['ARTIST'] = brvtal_page_rows($pdo, "SELECT id AS artist_card_id,name AS title,slug,photo AS image,'artists' AS route_type FROM artists WHERE id=? AND status='published'", [(int)$detail['artist_id']]);
        if (!empty($detail['event_id'])) {
            $data['related']['EVENT'] = brvtal_page_public_event_rows(
                $pdo,
                "SELECT title,slug,cover_image AS image,'events' AS route_type,status,event_date,published_at FROM events WHERE id=? AND status IN ({$eventPlaceholders})",
                array_merge([(int)$detail['event_id']], $eventStatuses)
            );
        }
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
        $transmissions = brvtalPublicTransmissionsForEntity($pdo, $type, $id);
        if ($transmissions) {
            $data['related']['TRANSMISSIONS'] = $transmissions;
        }

        $memoryItems = brvtal_public_memories_for_entity($pdo, $type, $id);
        if ($memoryItems) {
            $data['related']['MEMORIES'] = $memoryItems;
        }
    }

    $data['related'] = brvtalPublicEntityArtistCardFraming($pdo, $data['related']);
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
/**
 * Spanish display text for the fixed UI labels of the public entity pages. Data keys such as
 * 'DATE' or 'LINEUP' stay as they are (other code and tests rely on them); only the rendered
 * label changes. Each " / " segment is translated on its own; unknown text passes through.
 */
function brvtalPublicEntityEs(string $text): string
{
    static $dictionary = [
        'DATE' => 'FECHA', 'LOCATION' => 'LUGAR', 'STATUS' => 'ESTADO', 'RECORD' => 'REGISTRO',
        'ARCHIVE' => 'ARCHIVO', 'PUBLISHED' => 'PUBLICADO', 'COLLECTIVE' => 'COLECTIVO',
        'MEMBER' => 'MIEMBRO', 'COLLECTIVE MEMBER' => 'MIEMBRO DEL COLECTIVO', 'FORMAT' => 'FORMATO',
        'CATALOG' => 'CATÁLOGO', 'RELEASE DATE' => 'LANZAMIENTO', 'TAGS' => 'ETIQUETAS',
        'PLATFORM' => 'PLATAFORMA', 'TICKETS' => 'ENTRADAS', 'LISTEN' => 'ESCUCHAR',
        'WEBSITE' => 'SITIO WEB', 'EXPLORE CONNECTIONS' => 'EXPLORAR CONEXIONES',
        'LINEUP' => 'CARTEL', 'TIMETABLE' => 'HORARIOS', 'EVENTS' => 'EVENTOS', 'EVENT' => 'EVENTO',
        'RELEASES' => 'LANZAMIENTOS', 'RELEASE' => 'LANZAMIENTO', 'ARTISTS' => 'ARTISTAS',
        'ARTIST' => 'ARTISTA', 'TRANSMISSIONS' => 'TRANSMISIONES', 'MEMORIES' => 'MEMORIAS',
        'RELATED' => 'RELACIONADO', 'RECORDED SETS' => 'SETS GRABADOS', 'PAGE' => 'PÁGINA',
        'PAGES' => 'PÁGINAS', 'EVENT RECORD' => 'REGISTRO DE EVENTO', 'EVENT SIGNAL' => 'SEÑAL DEL EVENTO',
        'ACTIVE EXPERIENCE' => 'EXPERIENCIA ACTIVA', 'ABOUT' => 'SOBRE', 'INFORMATION' => 'INFORMACIÓN',
        'EXPERIENCE' => 'EXPERIENCIA', 'ACTIVE' => 'ACTIVO', 'HISTORICAL' => 'HISTÓRICO',
        'UPCOMING' => 'PRÓXIMO', 'SOLD OUT' => 'AGOTADO', 'CANCELLED' => 'CANCELADO',
        'CANCELED' => 'CANCELADO', 'RESIDENT' => 'RESIDENTE', 'GUEST' => 'INVITADO',
        'FINISHED' => 'FINALIZADO',
        'TICKETS AVAILABLE' => 'ENTRADAS DISPONIBLES', 'LAST TICKETS' => 'ÚLTIMAS ENTRADAS',
        'UPCOMING EVENTS' => 'PRÓXIMOS EVENTOS', 'PAST EVENTS' => 'EVENTOS PASADOS',
        'NOT FOUND' => 'NO ENCONTRADO', 'DATA STATUS' => 'ESTADO DE LOS DATOS',
        'DEGRADED' => 'DEGRADADO',
    ];
    $segments = explode(' / ', $text);
    foreach ($segments as $i => $segment) {
        $key = strtoupper(trim($segment));
        if (isset($dictionary[$key])) {
            $segments[$i] = $dictionary[$key];
        }
    }
    return implode(' / ', $segments);
}

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
    $kindLabel = brvtalPublicEntityEs($isEvent && $recordState === 'historical' ? 'EVENT RECORD' : $kind);
    $canonicalParts = parse_url((string)$seo['canonical']);
    $base = (($canonicalParts['scheme'] ?? 'https') . '://' . ($canonicalParts['host'] ?? 'www.brvtal.com.co'));
    $image = $escape(brvtal_public_absolute_url(brvtal_public_media_variant((string)$seo['image'], 'hero'), $base));
    $facts = '';
    foreach ($page['facts'] as $label => $value) {
        // Editorial status stays in DISCADMIN even if legacy page data supplies it as a fact.
        if ($isEvent && in_array(strtoupper(trim((string)$label)), ['STATUS', 'ESTADO'], true)) {
            continue;
        }
        if (trim((string)$value) !== '') $facts .= '<div><span>' . $escape(brvtalPublicEntityEs((string)$label)) . '</span><b>' . $escape(in_array((string)$label, ['DATE', 'LOCATION', 'TAGS', 'CATALOG', 'RELEASE DATE'], true) ? $value : brvtalPublicEntityEs((string)$value)) . '</b></div>';
    }
    // Never fabricate ticket prices, availability or scarcity.
    $offers = $isEvent && $recordState === 'active' ? (array)($page['related']['TICKETS'] ?? []) : [];
    $ticketUrl = '';
    $primaryTicketType = 'evento';
    $minimumPrice = null;
    $minimumCurrency = '';
    $hasPurchasableTicket = false;
    foreach ($offers as $ticket) {
        if (!is_array($ticket) || strtolower((string)($ticket['status'] ?? '')) !== 'active'
            || !brvtal_public_ticket_type_is_available($ticket)) continue;
        $url = $safeUrl($ticket['url'] ?? '');
        $hasInstructions = trim((string)($ticket['payment_instructions'] ?? '')) !== ''
            || trim((string)($ticket['qr_image'] ?? '')) !== '';
        if ($url === '' && !$hasInstructions) continue;
        $hasPurchasableTicket = true;
        if ($ticketUrl === '' && $url !== '') {
            $ticketUrl = $url;
            $primaryTicketType = (string)($ticket['title'] ?? 'evento');
        }
        if (isset($ticket['price']) && is_numeric($ticket['price']) && (float)$ticket['price'] >= 0
            && ($minimumPrice === null || (float)$ticket['price'] < $minimumPrice)) {
            $minimumPrice = (float)$ticket['price'];
            $minimumCurrency = strtoupper(trim((string)($ticket['currency'] ?? '')));
        }
    }
    // The internal CTA is enabled only with server-backed stock and sandbox/mock configuration.
    $internal = $isEvent && $recordState === 'active'
        && !in_array(strtoupper($recordStatus),['SOLD OUT','CANCELLED','CANCELED','FINISHED'],true)
        ? (array)($page['checkout']['offers'] ?? []) : [];
    $internalMethods = (array)($page['checkout']['methods'] ?? []);
    $internalEnabled = $internal !== [] && $internalMethods !== [];
    $checkoutPanel = '';
    $cartForm = '';
    $money = static fn(int|float $value): string => '$' . number_format((float)$value, 0, ',', '.');
    // Editorial details (description, sale deadline) come from the public ticket rows.
    $ticketInfo = [];
    foreach ($offers as $ticket) {
        if (is_array($ticket) && isset($ticket['ticket_type_id'])) $ticketInfo[(int)$ticket['ticket_type_id']] = $ticket;
    }
    $icon = static fn(string $path): string => '<svg viewBox="0 0 20 20" width="20" height="20" aria-hidden="true" focusable="false">'
        . '<path d="' . $path . '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="square"/></svg>';
    if ($internalEnabled) {
        $rows = '';
        foreach ($internal as $option) {
            $id = (int)($option['id'] ?? 0);
            $price = (int)($option['price_cop'] ?? 0);
            $available = max(0, min(20, (int)($option['available'] ?? 0)));
            $inviteOnly = !empty($option['invite_only']);
            if ($id < 1 || $price < 1 || ($available < 1 && !$inviteOnly)) continue;
            $name = $escape($option['title'] ?? '');
            $info = $ticketInfo[$id] ?? [];
            $until = !empty($info['available_until']) && strtotime((string)$info['available_until']) !== false
                ? '<small>Venta hasta ' . $escape(date('d/m/Y', strtotime((string)$info['available_until']))) . '</small>' : '';
            $description = !empty($info['description']) ? '<p>' . $escape($info['description']) . '</p>' : '';
            $state = $inviteOnly ? '<small class="event-cart-flag">Agotado · solo con invitación</small>' : '';
            $rows .= '<li class="event-cart-type" data-cart-type="' . $id . '" data-price="' . $price
                . '" data-available="' . ($inviteOnly ? 0 : $available) . '" data-name="' . $name . '">'
                . '<div class="event-cart-type-info"><h3>' . $name . '</h3>' . $description . $until . $state . '</div>'
                . '<strong class="event-cart-price">' . $escape($money($price)) . '</strong>'
                . '<div class="event-cart-stepper" role="group" aria-label="Cantidad de ' . $name . '">'
                . '<button type="button" data-cart-step="-1" aria-label="Quitar una boleta ' . $name . '" disabled>' . $icon('M4 10h12') . '</button>'
                . '<output data-cart-qty aria-live="polite">0</output>'
                . '<button type="button" data-cart-step="1" aria-label="Añadir una boleta ' . $name . '"' . ($inviteOnly ? ' disabled' : '') . '>' . $icon('M4 10h12M10 4v12') . '</button>'
                . '</div></li>';
        }
        $methodNames = ['CARD' => 'Tarjeta', 'NEQUI' => 'Nequi', 'PSE' => 'PSE'];
        $methods = '';
        foreach ($internalMethods as $method) {
            if (!isset($methodNames[$method])) continue;
            $methods .= '<label class="event-cart-method"><input type="radio" name="method" value="' . $method . '"'
                . ($methods === '' ? ' checked' : '') . '><span>' . $methodNames[$method] . '</span></label>';
        }
        $internalEnabled = $rows !== '' && $methods !== '';
        if ($internalEnabled) {
            $cartForm = '<form id="brvtal-ticket-cart" class="event-cart" novalidate>'
                . '<ul class="event-cart-types">' . $rows . '</ul>'
                . '<div class="event-cart-summary"><ul class="event-cart-lines" data-cart-lines></ul>'
                . '<p class="event-cart-total"><span>Total</span><strong data-cart-total>' . $escape($money(0)) . '</strong></p>'
                . '<small>Sin recargos BRVTAL. El cupo y el precio se confirman al reservar.</small></div>'
                . '<fieldset class="event-cart-methods"><legend>Método de pago</legend>' . $methods . '</fieldset>'
                . '<button type="submit" class="event-cart-pay" data-cart-pay disabled>Elige tus boletas</button>'
                . '<p class="event-cart-account">Para pagar necesitas tu cuenta BRVTAL. <a href="/mi-cuenta/acceso">Inicia sesión o créala</a>.</p>'
                . '</form>';
            $checkoutPanel = '<section class="event-ticket-checkout" id="checkout-boletas" aria-label="Estado de compra">'
                . '<p id="ticket-checkout-status" role="status" aria-live="polite"></p></section>';
        }
    }
    // A prior order remains inspectable after the last unit is reserved or sold.
    if ($isEvent && $checkoutPanel === '') {
        $checkoutPanel = '<section class="event-ticket-checkout" id="checkout-boletas" aria-label="Estado de compra" hidden>'
            . '<h2>Estado de tu compra</h2>'
            . '<p id="ticket-checkout-status" role="status" aria-live="polite"></p></section>';
    }
    $fallbackTicketUrl = $isEvent ? $safeUrl($page['links']['TICKETS'] ?? '') : '';
    $fallbackOffer = $offers === [] && $fallbackTicketUrl !== ''
        && !in_array(strtoupper($recordStatus), ['SOLD OUT', 'CANCELLED', 'CANCELED'], true);
    $saleableRecord = !in_array(strtoupper($recordStatus), ['SOLD OUT','CANCELLED','CANCELED','FINISHED'], true);
    $canBuy = $isEvent && $recordState === 'active' && $saleableRecord
        && ($hasPurchasableTicket || $fallbackOffer || $internalEnabled);
    $buyHref = $internalEnabled ? '#boletas' : ($ticketUrl !== '' ? $ticketUrl : ($fallbackTicketUrl !== '' && $canBuy ? $fallbackTicketUrl : '#boletas'));
    $buyTarget = preg_match('#^https?://#i', $buyHref) ? ' target="_blank" rel="noopener noreferrer"' : '';
    if ($internalEnabled) {
        $internalPrices = array_map(static fn(array $item): int => (int)$item['price_cop'], $internal);
        $minimumPrice = min($internalPrices);
        $minimumCurrency = 'COP';
    }
    $buyPrice = $minimumPrice === null ? '' : ' · desde '
        . ($minimumCurrency === 'COP' ? '$' : ($minimumCurrency !== '' ? $minimumCurrency . ' ' : ''))
        . number_format($minimumPrice, 0, ',', '.');
    $buyText = 'Comprar boletas' . $buyPrice;
    $buyButton = $canBuy ? '<a class="event-buy-button" href="' . $buyHref . '"' . $buyTarget . ' data-ticket-type="' . $escape($primaryTicketType) . '">' . $escape($buyText) . ' <span aria-hidden="true">→</span></a>' : '';
    // Una consulta voluntaria sustituye a una compra cuando todavía no hay
    // enlace/checkout habilitado; nunca se presenta como venta efectiva.
    $notifyButton = $isEvent && $recordState === 'active' && !$canBuy
        && !in_array(strtoupper($recordStatus), ['CANCELLED', 'CANCELED', 'FINISHED'], true)
        ? '<a class="event-notify" href="/contact">Avísame <span aria-hidden="true">→</span></a>'
        : '';
    $links = '';
    if ($isEvent) {
        // Visible tickets without online sale: the hero leads to the panel, which explains it.
        $hasTicketRows = array_filter($offers, static fn($ticket): bool => is_array($ticket)
            && brvtal_public_ticket_type_is_available($ticket)) !== [];
        $links = $canBuy ? $buyButton : ($hasTicketRows
            ? '<a class="event-buy-button" href="#boletas">Ver boletas</a>' : $notifyButton);
        if ($recordState === 'active' && !empty($page['related']['LINEUP'])) {
            $links .= '<a class="event-secondary" href="#lineup">Ver line-up <span aria-hidden="true">→</span></a>';
        }
    } else {
        foreach ($page['links'] as $label => $url) {
            $href = $safeUrl($url);
            if ($href !== '') $links .= '<a href="' . $href . '" target="_blank" rel="noopener noreferrer">' . $escape(brvtalPublicEntityEs((string)$label)) . ' ↗</a>';
        }
        $connectedUrl = brvtal_public_connected_url($entity);
        if ($connectedUrl !== '') $links .= '<a href="' . $escape($connectedUrl) . '">EXPLORAR CONEXIONES ↗</a>';
    }
    $repeatBuy = $canBuy ? '<div class="event-repeat-buy">' . $buyButton . '</div>' : '';
    $mobileBuy = $canBuy ? '<div class="event-sticky-buy" id="event-sticky-buy" hidden>' . ($internalEnabled
        ? '<p class="event-sticky-summary" data-cart-sticky-summary>' . $escape(ucfirst(trim($buyPrice, ' ·'))) . '</p>'
          . '<a class="event-buy-button" href="#boletas" data-cart-sticky-choose>Elegir boletas</a>'
          . '<button type="submit" form="brvtal-ticket-cart" class="event-buy-button" data-cart-sticky-pay hidden>Pagar</button>'
        : $buyButton) . '</div>' : '';
    $purchaseScript = '';
    if ($canBuy) {
        $purchaseScript = <<<'JS'
<script>
(function () {
  const bar = document.getElementById('event-sticky-buy');
  const hero = document.querySelector('.entity-hero');
  const footer = document.querySelector('body > footer');
  const pay = document.querySelector('[data-cart-pay]');
  if (!bar || !hero || !('IntersectionObserver' in window)) return;
  let heroVisible = true, footerVisible = false, payVisible = false;
  const refresh = () => { bar.hidden = heroVisible || footerVisible || payVisible; };
  if (pay) new IntersectionObserver(entries => {
    payVisible = entries[0]?.isIntersecting ?? false; refresh();
  }, { threshold: 0 }).observe(pay);
  new IntersectionObserver(entries => {
    heroVisible = entries[0]?.isIntersecting ?? true; refresh();
  }, { threshold: 0 }).observe(hero);
  if (footer) new IntersectionObserver(entries => {
    footerVisible = entries[0]?.isIntersecting ?? false; refresh();
  }, { threshold: 0 }).observe(footer);
  document.addEventListener('click', function (event) {
    const link = event.target.closest('a.event-buy-button, a.event-ticket-card a[data-ticket-type]');
    if (!link || !Array.isArray(window.dataLayer)) return;
    const type = link.getAttribute('data-ticket-type') || 'evento';
    window.dataLayer.push({event:'brvtal_ticket_click', ticket_type:type});
  });
}());
</script>
JS;
    }
    $related = '';
    $ticketList = '';
    $historicalHeadings = [
        'LINEUP' => 'LINEUP / RECORD',
        'TIMETABLE' => 'TIMETABLE / RECORD',
        'SETS' => 'RECORDED SETS',
    ];
    foreach ($page['related'] as $heading => $items) {
        if (!$items) continue;
        if ($isEvent && $heading === 'TICKETS') {
            if ($recordState === 'historical') continue;
            $ticketCards = '';
            foreach ($items as $ticket) {
                if (!is_array($ticket) || !brvtal_public_ticket_type_is_available($ticket)) continue;
                $soldOut = strtolower((string)($ticket['status'] ?? '')) === 'sold_out';
                $closedSale = !$saleableRecord;
                $purchaseUnavailable = $soldOut || $closedSale;
                $name = $escape($ticket['title'] ?? '');
                $ticketCurrency = strtoupper((string)($ticket['currency'] ?? ''));
                $price = isset($ticket['price']) && is_numeric($ticket['price'])
                    ? ($ticketCurrency === 'COP' ? $escape($money((float)$ticket['price']))
                        : number_format((float)$ticket['price'], 0, '.', ',') . ' ' . $escape($ticketCurrency))
                    : '';
                $typeUrl = $purchaseUnavailable ? '' : $safeUrl($ticket['url'] ?? '');
                $instructions = $purchaseUnavailable ? '' : trim((string)($ticket['payment_instructions'] ?? ''));
                $qr = $purchaseUnavailable ? '' : trim((string)($ticket['qr_image'] ?? ''));
                $qrHref = $safeInternalUrl($qr) ?: $safeUrl($qr);
                $status = $closedSale ? 'Venta cerrada' : ($soldOut ? 'Agotado'
                    : (strtoupper($recordStatus) === 'LAST TICKETS' ? 'Últimas boletas' : 'Disponible'));
                $ticketCards .= '<article class="event-ticket-card' . ($purchaseUnavailable ? ' is-sold-out' : '') . '">'
                    . '<span class="event-ticket-status">' . $status . '</span><h3>' . $name . '</h3>'
                    . ($price !== '' ? '<strong class="event-ticket-price">' . $price . '</strong>' : '')
                    . (!empty($ticket['description']) ? '<p>' . $escape($ticket['description']) . '</p>' : '')
                    . (!empty($ticket['available_until']) && strtotime((string)$ticket['available_until']) !== false
                        ? '<small>Venta hasta ' . $escape(date('d/m/Y', strtotime((string)$ticket['available_until']))) . '</small>' : '')
                    . ($purchaseUnavailable ? '<span class="event-ticket-disabled" aria-disabled="true">' . $status . '</span>'
                        : ($typeUrl !== '' ? '<a href="' . $typeUrl . '" target="_blank" rel="noopener noreferrer" data-ticket-type="' . $name . '">Comprar boletas →</a>' : ''))
                    . ($instructions !== '' ? '<p class="event-ticket-instructions">' . nl2br($escape($instructions)) . '</p>' : '')
                    . ($qrHref !== '' ? '<img class="event-ticket-qr" src="' . $qrHref . '" alt="Código QR para ' . $name . '" loading="lazy">' : '')
                    . '</article>';
            }
            $ticketList = $ticketCards;
            continue;
        }
        $cards = '';
        foreach ($items as $item) {
            $href = !empty($item['route_type']) && !empty($item['slug'])
                ? '/' . rawurlencode((string)$item['route_type']) . '/' . rawurlencode((string)$item['slug'])
                : ($safeInternalUrl($item['url'] ?? '') ?: $safeUrl($item['url'] ?? ''));
            $artistCard = ($item['route_type'] ?? '') === 'artists';
            // Artist portraits require the uncropped display variant; other entities keep card variants.
            $mediaSource = $artistCard
                ? brvtal_public_media_variant((string)$item['image'], 'display')
                : brvtal_public_media_variant((string)$item['image'], 'card');
            $visual = !empty($item['image']) ? '<img src="' . $escape(brvtal_public_absolute_url($mediaSource, $base)) . '" alt="" loading="lazy" decoding="async">' : '<span class="entity-card-mark">BRVTAL</span>';
            $cardStyle = '';
            $cardFraming = $artistCard ? brvtalArtistHeroValidate($item['card_framing'] ?? null) : null;
            if ($cardFraming !== null) {
                foreach (['desktop', 'mobile'] as $viewport) {
                    foreach (['x', 'y'] as $axis) {
                        $cardStyle .= '--artist-card-' . $viewport . '-' . $axis . ':' . $cardFraming[$viewport][$axis] . '%;';
                    }
                }
            }
            $cardAttributes = 'class="entity-card' . ($artistCard ? ' entity-card--artist' : '') . '"'
                . ($cardStyle !== '' ? ' style="' . $escape($cardStyle) . '"' : '');
            $price = isset($item['price']) && $item['price'] !== null
                ? number_format((float)$item['price'], 0) . ' ' . trim((string)($item['currency'] ?? ''))
                : '';
            $meta = trim((string)($item['meta'] ?? ''));
            $cardMeta = implode(' / ', array_filter([$price, $meta]));
            $content = $visual . '<span><small>' . $escape($cardMeta) . '</small><strong>'
                . $escape($item['title'] ?? '') . '</strong></span>';
            $cards .= $href ? '<a ' . $cardAttributes . ' href="' . $href . '">' . $content . '</a>' : '<div ' . $cardAttributes . '>' . $content . '</div>';
        }
        $displayHeading = $isEvent && $recordState === 'historical' ? ($historicalHeadings[$heading] ?? $heading) : $heading;
        $relatedCount = $isEvent && $heading === 'LINEUP'
            ? count($items) . (count($items) === 1 ? ' artista' : ' artistas')
            : str_pad((string)count($items), 2, '0', STR_PAD_LEFT);
        $related .= '<section class="entity-related"' . ($isEvent && $heading === 'LINEUP' ? ' id="lineup"' : '') . '><div class="entity-section-label" data-text="' . $escape(brvtalPublicEntityEs((string)$displayHeading)) . '">' . $escape(brvtalPublicEntityEs((string)$displayHeading)) . ' / ' . $escape($relatedCount) . '</div><div class="entity-grid">' . $cards . '</div></section>';
        // The purchase panel replaces the repeated CTA after the line-up.
        if ($isEvent && $heading === 'LINEUP' && !$internalEnabled && $ticketList === '') $related .= $repeatBuy;
    }
    $body = brvtalPublicTextFormat((string)($entity['description'] ?? ''));
    $statementBody = '<div class="entity-rich-body">' . ($body !== '' ? $body : '<p>BRVTAL / RAVE TILL GRAVE</p>') . '</div>';
    if ($routeTypeRaw === 'blog') {
        $richBody = trim((string)($page['record']['body_html'] ?? ''));
        if ($richBody !== '') {
            $statementBody = '<div class="entity-rich-body">' . $richBody . '</div>';
        }
    }
    $title = $escape($seo['title']);
    $seo['description'] = brvtalPublicTextPlain((string)($seo['description'] ?? ''));
    if (is_array($seo['schema'] ?? null)) {
        $seo['schema']['description'] = brvtalPublicTextPlain((string)($seo['schema']['description'] ?? $seo['description']));
    }
    $description = $escape($seo['description']);
    $entityTitle = $escape($entity['title']);
    $entityId = $escape(str_pad((string)$entity['id'], 3, '0', STR_PAD_LEFT));
    $routeType = $escape($routeTypeRaw);
    $backToSection = in_array($routeTypeRaw, ['events', 'artists', 'sets'], true);
    $backHref = $escape($backToSection ? '/#' . $routeTypeRaw : '/');
    if ($isEvent) {
        $backLabel = $escape($recordState === 'historical' ? '← VOLVER AL ARCHIVO' : '← VOLVER A EVENTOS');
    } else {
        $backLabel = $escape($backToSection ? '← VOLVER AL ARCHIVO' : '← VOLVER AL INICIO');
    }
    $tags = brvtal_public_seo_tags($seo);
    $robots = $degraded ? '<meta name="robots" content="noindex, follow">' : '';
    $degradedNotice = $degraded
        ? '<section class="entity-statement" role="status"><div class="entity-section-label">ESTADO DE LOS DATOS / DEGRADADO</div><p>Parte de la información conectada no está disponible por ahora. Esta página volverá a mostrarse completa automáticamente cuando el servicio de datos se recupere.</p></section>'
        : '';

    $eventStylesheet = $isEvent ? '<link rel="stylesheet" href="/css/public-event-record.css">' : '';
    if ($isEvent) {
        $eventStylesheet .= '<link rel="stylesheet" href="/css/public-ticket-checkout.css">';
    }
    $checkoutScript = $isEvent ? '<script src="/js/public-ticket-checkout.js" defer></script>' : '';
    $eventPalette = brvtalPublicEventPalette((string)($entity['accent'] ?? ''));
    $bodyClass = $isEvent ? 'entity-page entity-page--event entity-page--event-' . $recordState . ' cromo-page' : 'entity-page cromo-page';
    $bodyAttributes = 'class="' . $escape($bodyClass) . '"';
    if ($isEvent) {
        $bodyAttributes .= ' data-event-record-state="' . $escape($recordState) . '" style="--event-signal:' . $eventPalette['signal'] . ';--event-ink:' . $eventPalette['ink'] . '"';
    }
    $statementLabel = $isEvent ? 'SOBRE / ' . $entityTitle : 'SOBRE / INFORMACIÓN';
    $statementSection = (!$isEvent || $body !== '')
        ? '<section class="entity-statement"><div class="entity-section-label" data-text="' . $statementLabel . '">' . $statementLabel . '</div>' . $statementBody . '</section>'
        : '';
    // #1929: one purchase panel beside the event (desktop) or right after the hero (mobile).
    $buyPanel = '';
    if ($isEvent && $recordState === 'active' && ($internalEnabled || $ticketList !== '')) {
        $buyNote = '';
        if (!$internalEnabled && !$hasPurchasableTicket && !$fallbackOffer) {
            $buyNote = '<p class="event-buy-note">La venta en línea de este evento todavía no está abierta.'
                . ($notifyButton !== '' ? ' <a href="/contact">Avísame cuando abra</a>.' : '') . '</p>';
        }
        $buyPanel = '<aside class="event-buy-panel" id="boletas" aria-labelledby="event-buy-title">'
            . '<h2 id="event-buy-title">Boletas</h2>'
            . ($internalEnabled ? $cartForm : '<div class="event-tickets-grid">' . $ticketList . '</div>' . $buyNote)
            . $checkoutPanel . '</aside>';
        $checkoutPanel = '';
        if ($repeatBuy !== '') $related = str_replace($repeatBuy, '', $related);
    }
    $recordBand = '';
    if ($isEvent) {
        $bandLabel = $recordState === 'historical' ? 'EVENTO PASADO' : 'PRÓXIMO EVENTO';
        $bandValue = $recordState === 'historical'
            ? ($recordYear !== '' ? 'Archivo · ' . $recordYear : 'Archivo')
            : match (strtoupper($recordStatus)) {
                'LAST TICKETS' => 'Últimas boletas',
                'TICKETS AVAILABLE' => 'Boletas disponibles',
                'SOLD OUT' => 'Agotado',
                'CANCELLED', 'CANCELED' => 'Cancelado',
                default => 'Próxima noche',
            };
        $recordBand = '<section class="event-record-band" aria-label="Estado del evento"><div><span>' . $escape($bandLabel) . '</span><strong>' . $escape($bandValue) . '</strong></div><p>BRVTAL / ' . $entityTitle . '</p></section>';
    }

    $layoutOpen = $buyPanel !== '' ? '<div class="event-buy-layout"><div class="event-buy-main">' : '';
    $layoutClose = $buyPanel !== '' ? '</div>' . $buyPanel . '</div>' : '';
    $repeatBuyTail = $buyPanel !== '' ? '' : $repeatBuy;
    $mapSection = '';
    $mapScript = '';
    if ($isEvent) {
        $address = trim((string)($entity['venue_address'] ?? ''));
        if ($address !== '') {
            $query = rawurlencode($address);
            $embedUrl = $escape('https://www.google.com/maps?q=' . $query . '&output=embed');
            $mapsUrl = $escape('https://www.google.com/maps/search/?api=1&query=' . $query);
            $place = $escape(implode(' · ', array_filter([
                trim((string)($entity['venue'] ?? '')),
                trim((string)($entity['city'] ?? '')),
            ])));
            $mapSection = '<section class="event-map" id="como-llegar" aria-label="Cómo llegar">'
                . '<div class="event-map-details"><p class="entity-section-label">TU DESTINO</p>'
                . '<h2>Cómo llegar</h2><strong>' . $place . '</strong>'
                . '<address>' . $escape($address) . '</address>'
                . '<div class="event-map-actions"><button type="button" data-event-map-embed="' . $embedUrl . '">Ver mapa</button>'
                . '<a href="' . $mapsUrl . '" target="_blank" rel="noopener noreferrer">Abrir en Google Maps ↗</a></div></div>'
                . '<div class="event-map-frame" aria-live="polite"><p>El mapa se mostrará aquí al pulsar Ver mapa.</p></div></section>';
            $mapScript = <<<'JS'
<script>
document.addEventListener('click', function (event) {
  const button = event.target.closest('[data-event-map-embed]');
  if (!button) return;
  const url = button.dataset.eventMapEmbed || '';
  if (!url.startsWith('https://www.google.com/maps?q=')) return;
  const frameContainer = button.closest('.event-map')?.querySelector('.event-map-frame');
  if (!frameContainer) return;
  const iframe = document.createElement('iframe');
  iframe.src = url;
  iframe.title = 'Mapa de Google Maps para llegar al evento';
  iframe.loading = 'lazy';
  iframe.referrerPolicy = 'no-referrer-when-downgrade';
  iframe.setAttribute('allowfullscreen', '');
  frameContainer.replaceChildren(iframe);
  button.hidden = true;
});
</script>
JS;
        }
    }

    $topbar = brvtalPublicCromoTopbar($backHref, $backLabel);
    // La navegación móvil del evento reutiliza los enlaces públicos canónicos.
    // <details> funciona sin JS, mantiene una sola fila en la cabecera y no
    // altera el chrome compartido por otras fichas.
    if ($isEvent && preg_match('~<nav class="cp-nav" aria-label="Secciones">(.*?)</nav>~s', $topbar, $parts)) {
        $mobileMenu = '<details class="event-mobile-menu"><summary>Menú</summary>'
            . '<nav aria-label="Navegación del evento">' . $parts[1]
            . '<a href="/mi-cuenta/acceso" data-buyer-account-link>Mi cuenta</a>'
            . '<a href="' . $backHref . '">' . $backLabel . '</a></nav></details>';
        $topbar = str_replace('<div class="cp-tools">', $mobileMenu . '<div class="cp-tools">', $topbar);
    }

    return <<<HTML
<!doctype html>
<html lang="es">
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
  <link rel="stylesheet" href="/css/public-cromo.css">
  <link rel="stylesheet" href="/css/public-cromo-pages.css">
</head>
<body {$bodyAttributes}>
  <a class="skip-link" href="#main-content">IR AL CONTENIDO</a>
  {$topbar}
  <main id="main-content" tabindex="-1">
    <article class="entity-hero">
      <div class="entity-image"><img src="{$image}" alt="{$entityTitle}" loading="eager" fetchpriority="high" decoding="async"><span>{$kindLabel} / BRVTAL</span></div>
      <div class="entity-copy"><div class="entity-kicker">BRVTAL / {$kindLabel} / {$entityId}</div><h1 data-text="{$entityTitle}">{$entityTitle}</h1><div class="entity-facts">{$facts}</div><div class="entity-actions">{$links}</div></div>
    </article>
    {$recordBand}
    {$layoutOpen}
    {$statementSection}
    {$degradedNotice}
    {$related}
    {$checkoutPanel}
    {$mapSection}
    {$repeatBuyTail}
    {$layoutClose}
  </main>
  {$mobileBuy}
  <footer><strong>BRVTAL</strong><span>PEREIRA / COLOMBIA</span><span>RAVE TILL GRAVE</span></footer>
  {$mapScript}
  {$purchaseScript}
  {$checkoutScript}
  <script src="/js/public-cromo.js" defer></script>
  <script src="/js/public-cromo-sound.js" defer></script>
  <script src="/js/public-cromo-pages.js" defer></script>
  {$analytics}
</body>
</html>
HTML;
}
