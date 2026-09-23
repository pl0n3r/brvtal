<?php
declare(strict_types=1);

require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/public_page.php';
require_once __DIR__ . '/public_routes.php';
require_once __DIR__ . '/public_visibility.php';
require_once __DIR__ . '/page_content.php';

const BRVTAL_PUBLIC_PREVIEW_TTL = 600;
const BRVTAL_PUBLIC_PREVIEW_MAX_SNAPSHOTS = 12;

/** @return list<string> */
function brvtal_public_preview_types(): array
{
    return ['events', 'artists', 'sets', 'releases', 'blog', 'pages'];
}

function brvtal_public_preview_text(mixed $value, int $max = 1000): string
{
    return mb_substr(trim((string)$value), 0, $max);
}

function brvtal_public_preview_int(mixed $value): int
{
    $number = filter_var($value, FILTER_VALIDATE_INT);
    return $number === false ? 0 : max(0, (int)$number);
}

/** @return list<array<string,mixed>> */
function brvtal_public_preview_list(string $type, mixed $value): array
{
    if (!is_array($value)) return [];
    $rows = array_slice(array_values($value), 0, 100);
    $out = [];

    foreach ($rows as $index => $row) {
        if (!is_array($row)) continue;
        if ($type === 'ticket_types') {
            $out[] = [
                'name' => brvtal_public_preview_text($row['name'] ?? '', 180),
                'description' => brvtal_public_preview_text($row['description'] ?? '', 1000),
                'price' => is_numeric($row['price'] ?? null) ? (float)$row['price'] : null,
                'currency' => brvtal_public_preview_text($row['currency'] ?? 'COP', 8) ?: 'COP',
                'external_url' => brvtal_public_preview_text($row['external_url'] ?? '', 700),
                'status' => brvtal_public_preview_text($row['status'] ?? 'draft', 24),
                'available_from' => brvtal_public_preview_text($row['available_from'] ?? '', 32),
                'available_until' => brvtal_public_preview_text($row['available_until'] ?? '', 32),
                'sort_order' => brvtal_public_preview_int($row['sort_order'] ?? $index),
            ];
            continue;
        }
        if ($type === 'lineup' || $type === 'artists') {
            $artistId = brvtal_public_preview_int($row['artist_id'] ?? $row['id'] ?? 0);
            if ($artistId < 1) continue;
            $out[] = [
                'artist_id' => $artistId,
                'role' => brvtal_public_preview_text($row['role'] ?? '', 120),
                'sort_order' => brvtal_public_preview_int(
                    $row['lineup_order'] ?? $row['sort_order'] ?? $index
                ),
            ];
            continue;
        }
        if ($type === 'relations') {
            $relatedType = brvtal_public_preview_text($row['related_type'] ?? '', 20);
            $relatedId = brvtal_public_preview_int($row['related_id'] ?? 0);
            if (!in_array($relatedType, ['event', 'artist', 'set', 'release'], true) || $relatedId < 1) {
                continue;
            }
            $out[] = [
                'related_type' => $relatedType,
                'related_id' => $relatedId,
                'sort_order' => brvtal_public_preview_int($row['sort_order'] ?? $index),
            ];
        }
    }

    return $out;
}

/** @return array{type:string,payload:array<string,mixed>} */
function brvtal_public_preview_snapshot(string $type, array $payload): array
{
    if (!in_array($type, brvtal_public_preview_types(), true)) {
        throw new InvalidArgumentException('PREVIEW_TYPE_NOT_ALLOWED');
    }

    $allowed = [
        'events' => [
            'id','title','slug','description','cover_image','accent','status','event_date',
            'venue','city','ticket_url','ticket_instructions','seo_title','seo_description',
            'ticket_types','lineup',
        ],
        'artists' => [
            'id','name','slug','bio','photo','status','instagram_url','soundcloud_url',
            'website_url','collective_status','seo_title','seo_description',
        ],
        'sets' => [
            'id','title','slug','description','cover_image','status','platform','external_url',
            'embed_url','artist_id','event_id','seo_title','seo_description',
        ],
        'releases' => [
            'id','title','slug','release_type','catalog_number','release_date','description',
            'artwork','spotify_url','soundcloud_url','bandcamp_url','youtube_url','beatport_url',
            'status','seo_title','seo_description','artists',
        ],
        'blog' => [
            'id','title','slug','excerpt','body','cover_image','status','published_at',
            'seo_title','seo_description','relations',
        ],
        'pages' => [
            'id','title','slug','locale','status','content_json','seo_title','seo_description',
        ],
    ][$type];

    $clean = [];
    foreach ($allowed as $field) {
        if (!array_key_exists($field, $payload)) continue;
        if ($field === 'id' || in_array($field, ['artist_id','event_id'], true)) {
            $clean[$field] = brvtal_public_preview_int($payload[$field]);
        } elseif (in_array($field, ['ticket_types','lineup','artists','relations'], true)) {
            $clean[$field] = brvtal_public_preview_list($field, $payload[$field]);
        } else {
            $max = in_array($field, ['body','content_json'], true) ? 50000 : 4000;
            $clean[$field] = brvtal_public_preview_text($payload[$field], $max);
        }
    }

    return ['type' => $type, 'payload' => $clean];
}

/** @return array{token:string,url:string,expires_at:int} */
function brvtal_public_preview_store(array $snapshot): array
{
    brvtal_admin_session_start();
    $now = time();
    $previews = is_array($_SESSION['public_previews'] ?? null)
        ? $_SESSION['public_previews']
        : [];

    foreach ($previews as $token => $entry) {
        if (!is_array($entry) || (int)($entry['expires_at'] ?? 0) <= $now) {
            unset($previews[$token]);
        }
    }

    if (count($previews) >= BRVTAL_PUBLIC_PREVIEW_MAX_SNAPSHOTS) {
        uasort($previews, static fn(array $a, array $b): int =>
            (int)($a['created_at'] ?? 0) <=> (int)($b['created_at'] ?? 0)
        );
        while (count($previews) >= BRVTAL_PUBLIC_PREVIEW_MAX_SNAPSHOTS) {
            array_shift($previews);
        }
    }

    $token = bin2hex(random_bytes(24));
    $expiresAt = $now + BRVTAL_PUBLIC_PREVIEW_TTL;
    $previews[$token] = [
        'created_at' => $now,
        'expires_at' => $expiresAt,
        'snapshot' => $snapshot,
    ];
    $_SESSION['public_previews'] = $previews;

    return [
        'token' => $token,
        'url' => '/preview/' . $token,
        'expires_at' => $expiresAt,
    ];
}

/** @return array{type:string,payload:array<string,mixed>}|null */
function brvtal_public_preview_load(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{48}$/D', $token)) return null;
    brvtal_admin_session_start();
    $entry = $_SESSION['public_previews'][$token] ?? null;
    if (!is_array($entry)) return null;
    if ((int)($entry['expires_at'] ?? 0) <= time()) {
        unset($_SESSION['public_previews'][$token]);
        return null;
    }
    $snapshot = $entry['snapshot'] ?? null;
    return is_array($snapshot) ? $snapshot : null;
}

function brvtal_public_preview_reference(
    PDO $pdo,
    string $type,
    int $id,
    string $meta = ''
): ?array {
    if ($id < 1) return null;

    $map = [
        'artist' => ['artists', 'name', 'photo', 'artists', "status='published'"],
        'event' => ['events', 'title', 'cover_image', 'events', "status IN ('published','sold_out','finished')"],
        'set' => ['sets_media', 'title', 'cover_image', 'sets', "status='published'"],
        'release' => ['releases', 'title', 'artwork', 'releases', "status='published'"],
    ];
    if (!isset($map[$type])) return null;

    [$table, $titleField, $imageField, $routeType, $where] = $map[$type];
    $statement = $pdo->prepare(
        "SELECT id,slug,`{$titleField}` AS title,`{$imageField}` AS image "
        . "FROM `{$table}` WHERE id=? AND {$where} LIMIT 1"
    );
    $statement->execute([$id]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;

    $row['route_type'] = $routeType;
    $row['meta'] = $meta;
    return $row;
}

/** @return list<array<string,mixed>> */
function brvtal_public_preview_artist_cards(PDO $pdo, array $rows): array
{
    $cards = [];
    foreach ($rows as $row) {
        $card = brvtal_public_preview_reference(
            $pdo,
            'artist',
            (int)($row['artist_id'] ?? 0),
            (string)($row['role'] ?? '')
        );
        if ($card) $cards[] = $card;
    }
    return $cards;
}

/** @return list<array<string,mixed>> */
function brvtal_public_preview_relation_cards(PDO $pdo, array $rows): array
{
    $cards = [];
    foreach ($rows as $row) {
        $card = brvtal_public_preview_reference(
            $pdo,
            (string)($row['related_type'] ?? ''),
            (int)($row['related_id'] ?? 0)
        );
        if ($card) $cards[] = $card;
    }
    return $cards;
}

/** @return array<string,mixed> */
function brvtal_public_preview_entity(array $snapshot): array
{
    $type = (string)$snapshot['type'];
    $payload = $snapshot['payload'];
    $definition = brvtal_public_content_definitions()[$type] ?? null;
    if (!is_array($definition)) {
        throw new InvalidArgumentException('PREVIEW_TYPE_NOT_ALLOWED');
    }

    $title = match ($type) {
        'artists' => (string)($payload['name'] ?? ''),
        default => (string)($payload['title'] ?? ''),
    };
    if ($title === '') $title = 'UNTITLED PREVIEW';

    $description = match ($type) {
        'artists' => (string)($payload['bio'] ?? ''),
        'blog' => (string)($payload['excerpt'] ?? ''),
        'pages' => brvtal_page_content_plain_text($payload['content_json'] ?? ''),
        default => (string)($payload['description'] ?? ''),
    };
    $image = match ($type) {
        'events' => (string)($payload['cover_image'] ?? ''),
        'artists' => (string)($payload['photo'] ?? ''),
        'sets' => (string)($payload['cover_image'] ?? ''),
        'releases' => (string)($payload['artwork'] ?? ''),
        'blog' => (string)($payload['cover_image'] ?? ''),
        default => '',
    };

    $entity = [
        'id' => (int)($payload['id'] ?? 0),
        'route_type' => $type,
        'slug' => (string)($payload['slug'] ?? '') ?: 'preview',
        'title' => $title,
        'description' => $description,
        'image' => $image,
        'seo_title' => (string)($payload['seo_title'] ?? ''),
        'seo_description' => (string)($payload['seo_description'] ?? ''),
        'schema_type' => (string)$definition['schema_type'],
    ];

    foreach ([
        'status','event_date','venue','city','accent','release_date','catalog_number',
        'published_at','instagram_url','soundcloud_url','website_url','external_url',
    ] as $field) {
        if (array_key_exists($field, $payload)) $entity[$field] = $payload[$field];
    }

    return $entity;
}

/** @return array<string,mixed> */
function brvtal_public_preview_page(PDO $pdo, array $snapshot): array
{
    $type = (string)$snapshot['type'];
    $payload = $snapshot['payload'];
    $entity = brvtal_public_preview_entity($snapshot);
    $page = [
        'entity' => $entity,
        'facts' => [],
        'links' => [],
        'related' => [],
        'record' => [],
        'degraded' => false,
    ];

    if ((int)$entity['id'] > 0) {
        try {
            $page = brvtal_public_page_data($pdo, $entity);
        } catch (Throwable) {
            // A draft preview remains available even when optional saved detail is incomplete.
        }
    }
    $page['entity'] = array_replace($page['entity'] ?? [], $entity);
    $page['facts'] = is_array($page['facts'] ?? null) ? $page['facts'] : [];
    $page['links'] = is_array($page['links'] ?? null) ? $page['links'] : [];
    $page['related'] = is_array($page['related'] ?? null) ? $page['related'] : [];
    $page['record'] = is_array($page['record'] ?? null) ? $page['record'] : [];
    $page['degraded'] = false;

    if ($type === 'events') {
        $date = (string)($payload['event_date'] ?? '');
        $status = strtoupper(str_replace('_', ' ', (string)($payload['status'] ?? 'draft')));
        $page['entity'] = array_replace($page['entity'], [
            'event_date' => $date,
            'venue' => (string)($payload['venue'] ?? ''),
            'city' => (string)($payload['city'] ?? ''),
            'status' => (string)($payload['status'] ?? 'draft'),
            'accent' => (string)($payload['accent'] ?? ''),
            'ticket_url' => (string)($payload['ticket_url'] ?? ''),
            'ticket_instructions' => (string)($payload['ticket_instructions'] ?? ''),
        ]);
        $page['facts'] = array_filter([
            'DATE' => $date !== '' ? date('d.m.Y / H:i', strtotime($date)) : '',
            'LOCATION' => implode(' / ', array_filter([
                $payload['venue'] ?? '',
                $payload['city'] ?? '',
            ])),
            'STATUS' => $status,
        ]);
        $historical = in_array(
            (string)($payload['status'] ?? ''),
            ['finished','cancelled','archived'],
            true
        );
        $page['record'] = [
            'state' => $historical ? 'historical' : 'active',
            'year' => $date !== '' ? date('Y', strtotime($date)) : '',
            'status' => $status,
        ];
        $ticketUrl = trim((string)($payload['ticket_url'] ?? ''));
        $page['links'] = !$historical && preg_match('#^https?://#i', $ticketUrl)
            ? ['TICKETS' => $ticketUrl]
            : [];

        if (array_key_exists('lineup', $payload)) {
            $page['related']['LINEUP'] = brvtal_public_preview_artist_cards(
                $pdo,
                $payload['lineup']
            );
        }
        if (array_key_exists('ticket_types', $payload)) {
            $tickets = [];
            foreach ($payload['ticket_types'] as $ticket) {
                if (!in_array((string)($ticket['status'] ?? ''), ['active','sold_out'], true)) {
                    continue;
                }
                if (!brvtal_public_ticket_type_is_available($ticket)) continue;
                $tickets[] = [
                    'title' => (string)($ticket['name'] ?? ''),
                    'description' => (string)($ticket['description'] ?? ''),
                    'price' => $ticket['price'] ?? null,
                    'currency' => (string)($ticket['currency'] ?? 'COP'),
                    'url' => preg_match('#^https?://#i', (string)($ticket['external_url'] ?? ''))
                        ? (string)$ticket['external_url']
                        : '',
                    'status' => (string)($ticket['status'] ?? ''),
                    'meta' => (string)($ticket['status'] ?? ''),
                ];
            }
            $page['related']['TICKETS'] = $tickets;
        }
    } elseif ($type === 'artists') {
        $collective = strtoupper(str_replace('_', ' ', (string)($payload['collective_status'] ?? '')));
        $page['facts'] = array_filter(['COLLECTIVE' => $collective]);
        $page['links'] = array_filter([
            'INSTAGRAM' => $payload['instagram_url'] ?? '',
            'SOUNDCLOUD' => $payload['soundcloud_url'] ?? '',
            'WEBSITE' => $payload['website_url'] ?? '',
        ]);
    } elseif ($type === 'sets') {
        $page['facts'] = array_filter([
            'PLATFORM' => strtoupper((string)($payload['platform'] ?? '')),
        ]);
        $page['links'] = array_filter(['LISTEN' => $payload['external_url'] ?? '']);
        if (array_key_exists('artist_id', $payload)) {
            $artist = brvtal_public_preview_reference(
                $pdo,
                'artist',
                (int)$payload['artist_id']
            );
            $page['related']['ARTIST'] = $artist ? [$artist] : [];
        }
        if (array_key_exists('event_id', $payload)) {
            $event = brvtal_public_preview_reference(
                $pdo,
                'event',
                (int)$payload['event_id']
            );
            $page['related']['EVENT'] = $event ? [$event] : [];
        }
    } elseif ($type === 'releases') {
        $page['facts'] = array_filter([
            'FORMAT' => strtoupper((string)($payload['release_type'] ?? '')),
            'CATALOG' => $payload['catalog_number'] ?? '',
            'RELEASE DATE' => $payload['release_date'] ?? '',
        ]);
        $page['links'] = array_filter([
            'SPOTIFY' => $payload['spotify_url'] ?? '',
            'SOUNDCLOUD' => $payload['soundcloud_url'] ?? '',
            'BANDCAMP' => $payload['bandcamp_url'] ?? '',
            'YOUTUBE' => $payload['youtube_url'] ?? '',
            'BEATPORT' => $payload['beatport_url'] ?? '',
        ]);
        if (array_key_exists('artists', $payload)) {
            $page['related']['ARTISTS'] = brvtal_public_preview_artist_cards(
                $pdo,
                $payload['artists']
            );
        }
    } elseif ($type === 'blog') {
        $body = brvtal_blog_sanitize_html((string)($payload['body'] ?? ''));
        $page['record']['body_html'] = $body['html'];
        $page['facts'] = [];
        if (!empty($payload['published_at'])) {
            $page['facts']['PUBLISHED'] = date(
                'd.m.Y',
                strtotime((string)$payload['published_at'])
            );
        }
        if (array_key_exists('relations', $payload)) {
            $page['related']['RELATED'] = brvtal_public_preview_relation_cards(
                $pdo,
                $payload['relations']
            );
        }
    } elseif ($type === 'pages') {
        $page['entity']['description'] = brvtal_page_content_plain_text(
            $payload['content_json'] ?? ''
        );
    }

    return $page;
}
