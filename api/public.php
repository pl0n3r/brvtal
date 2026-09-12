<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

/**
 * BRVTAL public read-only API.
 *
 * Deliberately exposes only published content and an allowlisted subset of
 * public site configuration. Never expose raw settings, analytics secrets,
 * custom code, admin configuration or credentials here.
 */

function brvtal_public_settings(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT setting_key, setting_value, is_json
         FROM settings
         WHERE setting_key IN ('site','social','appearance','theme.active')
            OR setting_key LIKE 'theme.%'
         ORDER BY setting_key"
    )->fetchAll();

    $raw = [];
    foreach ($rows as $row) {
        $value = (int)$row['is_json'] === 1
            ? json_decode((string)$row['setting_value'], true)
            : (string)$row['setting_value'];
        $raw[(string)$row['setting_key']] = $value;
    }

    $out = [];

    foreach (['site', 'social', 'appearance'] as $key) {
        if (array_key_exists($key, $raw)) {
            $out[$key] = $raw[$key];
        }
    }

    $active = is_string($raw['theme.active'] ?? null)
        ? trim((string)$raw['theme.active'])
        : 'core';

    if (!preg_match('/^[a-z0-9_-]{1,60}$/i', $active)) {
        $active = 'core';
    }

    $theme = $raw['theme.' . $active] ?? null;

    if (is_array($theme)) {
        $allowedThemeKeys = [
            'name',
            'slug',
            'branding',
            'colors',
            'typography',
            'navigation',
            'effects',
            'sound',
            'preloader',
            'responsive',
            'seo',
        ];

        $themeOut = [];
        foreach ($allowedThemeKeys as $key) {
            if (array_key_exists($key, $theme)) {
                $themeOut[$key] = $theme[$key];
            }
        }

        // Never expose tracking IDs, analytics configuration or arbitrary JS/CSS.
        unset($themeOut['analytics'], $themeOut['customCode']);
        $out['theme'] = $themeOut;
    }

    return $out;
}

function brvtal_public_table_exists(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
}

function brvtal_public_releases(PDO $pdo): array
{
    if (!brvtal_public_table_exists($pdo, 'releases') || !brvtal_public_table_exists($pdo, 'release_artists')) {
        return [];
    }

    $releases = $pdo->query(
        "SELECT id,title,slug,release_type,catalog_number,release_date,description,artwork,
                spotify_url,soundcloud_url,bandcamp_url,youtube_url,beatport_url,
                featured,published_at,sort_order
         FROM releases
         WHERE status='published'
         ORDER BY featured DESC, COALESCE(release_date,'9999-12-31') DESC, sort_order ASC, id DESC"
    )->fetchAll();

    if (!$releases) {
        return [];
    }

    $artistRows = $pdo->query(
        "SELECT ra.release_id,ra.artist_id,ra.role,ra.sort_order,a.name,a.slug,a.photo
         FROM release_artists ra
         JOIN releases r ON r.id=ra.release_id
         JOIN artists a ON a.id=ra.artist_id
         WHERE r.status='published' AND a.status='published'
         ORDER BY ra.release_id,ra.sort_order,a.name"
    )->fetchAll();

    $byRelease = [];
    foreach ($artistRows as $artist) {
        $byRelease[(string)$artist['release_id']][] = $artist;
    }

    foreach ($releases as &$release) {
        $release['id'] = (int)$release['id'];
        $release['featured'] = (int)$release['featured'];
        $release['sort_order'] = (int)$release['sort_order'];
        $release['artists'] = $byRelease[(string)$release['id']] ?? [];
    }
    unset($release);

    return $releases;
}

function brvtal_public_blog(PDO $pdo): array
{
    foreach (['blog_posts','blog_tags','blog_post_tags','blog_post_relations'] as $table) {
        if (!brvtal_public_table_exists($pdo, $table)) return [];
    }

    $posts = $pdo->query(
        "SELECT id,title,slug,excerpt,body,cover_image,seo_title,seo_description,
                featured,published_at,sort_order
         FROM blog_posts
         WHERE status='published'
         ORDER BY featured DESC, COALESCE(published_at,updated_at) DESC, sort_order ASC, id DESC"
    )->fetchAll();

    if (!$posts) return [];

    $tagRows = $pdo->query(
        "SELECT pt.post_id,t.id,t.name,t.slug
         FROM blog_post_tags pt
         JOIN blog_posts p ON p.id=pt.post_id
         JOIN blog_tags t ON t.id=pt.tag_id
         WHERE p.status='published'
         ORDER BY pt.post_id,t.name"
    )->fetchAll();
    $relationRows = $pdo->query(
        "SELECT r.post_id,r.related_type,r.related_id,r.sort_order
         FROM blog_post_relations r
         JOIN blog_posts p ON p.id=r.post_id
         WHERE p.status='published'
         ORDER BY r.post_id,r.sort_order,r.related_type,r.related_id"
    )->fetchAll();

    $tagsByPost = [];
    foreach ($tagRows as $tag) $tagsByPost[(string)$tag['post_id']][] = $tag;
    $relationsByPost = [];
    foreach ($relationRows as $relation) {
        $relation['related_id'] = (int)$relation['related_id'];
        $relation['sort_order'] = (int)$relation['sort_order'];
        $relationsByPost[(string)$relation['post_id']][] = $relation;
    }

    foreach ($posts as &$post) {
        $post['id'] = (int)$post['id'];
        $post['featured'] = (int)$post['featured'];
        $post['sort_order'] = (int)$post['sort_order'];
        $key = (string)$post['id'];
        $post['tags'] = $tagsByPost[$key] ?? [];
        $post['relations'] = $relationsByPost[$key] ?? [];
    }
    unset($post);
    return $posts;
}

function brvtal_public_json(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=60, stale-while-revalidate=300');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(
        ['ok' => true, 'data' => $data],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        header('Allow: GET');
        brvtal_public_json(['error' => 'METHOD_NOT_ALLOWED'], 405);
    }

    $pdo = db();

    $events = $pdo->query(
        "SELECT id,title,slug,event_date,archive_year,venue,city,description,skin,accent,
                cover_image,ticket_url,ticket_instructions,ticket_qr,featured,published_at,
                cancelled_at,finished_at,status,sort_order
         FROM events
         WHERE status='published'
         ORDER BY event_date ASC, sort_order ASC, id ASC"
    )->fetchAll();

    $artists = $pdo->query(
        "SELECT id,name,slug,bio,photo,instagram_url,soundcloud_url,website_url,
                collective_status,collective_order,collective_joined_at,collective_left_at,
                status,sort_order
         FROM artists
         WHERE status='published'
         ORDER BY sort_order ASC, name ASC"
    )->fetchAll();

    $sets = $pdo->query(
        "SELECT s.id,s.title,s.slug,s.artist_id,s.event_id,s.platform,
                s.external_url,s.embed_url,s.cover_image,s.description,
                a.name AS artist_name,e.title AS event_title
         FROM sets_media s
         LEFT JOIN artists a ON a.id=s.artist_id
         LEFT JOIN events e ON e.id=s.event_id
         WHERE s.status='published'
         ORDER BY s.sort_order ASC,s.created_at DESC"
    )->fetchAll();

    // The public site is English-first. Spanish/private drafts are not exposed.
    $pages = $pdo->query(
        "SELECT id,title,slug,locale,content_json,seo_title,seo_description
         FROM pages
         WHERE status='published' AND locale='en'
         ORDER BY id DESC"
    )->fetchAll();

    $media = $pdo->query(
        "SELECT id,type,title,file_path,mime_type,file_size,alt_text,status,created_at
         FROM media
         WHERE status='published'
         ORDER BY id DESC"
    )->fetchAll();

    $ticketTypes = $pdo->query(
        "SELECT id,event_id,name,description,price,currency,external_url,
                payment_instructions,qr_image,status,available_from,available_until,sort_order
         FROM event_ticket_types
         WHERE status IN ('active','sold_out')
         ORDER BY event_id,sort_order,name"
    )->fetchAll();

    $ticketsByEvent = [];
    foreach ($ticketTypes as $ticket) {
        $ticketsByEvent[(string)$ticket['event_id']][] = $ticket;
    }

    $lineup = $pdo->query(
        "SELECT ea.event_id,ea.artist_id,ea.lineup_order,ea.role,
                a.name,a.slug,a.photo
         FROM event_artists ea
         JOIN artists a ON a.id=ea.artist_id
         JOIN events e ON e.id=ea.event_id
         WHERE e.status='published' AND a.status='published'
         ORDER BY ea.event_id,ea.lineup_order,a.name"
    )->fetchAll();

    $lineupByEvent = [];
    foreach ($lineup as $item) {
        $lineupByEvent[(string)$item['event_id']][] = $item;
    }

    foreach ($events as &$event) {
        $eventKey = (string)$event['id'];
        $event['ticket_types'] = $ticketsByEvent[$eventKey] ?? [];
        $event['lineup'] = $lineupByEvent[$eventKey] ?? [];
    }
    unset($event);

    $settings = brvtal_public_settings($pdo);
    $releases = brvtal_public_releases($pdo);
    $blog = brvtal_public_blog($pdo);

    $payload = [
        'events' => $events,
        'artists' => $artists,
        'sets' => $sets,
        'releases' => $releases,
        'blog' => $blog,
        'media' => $media,
        'pages' => $pages,
        'settings' => $settings,
        'generated_at' => date(DATE_ATOM),
    ];

    $etag = '"' . sha1(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"';
    header('ETag: ' . $etag);

    if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        exit;
    }

    brvtal_public_json($payload);
} catch (Throwable $e) {
    if (function_exists('brvtal_log')) {
        brvtal_log('PUBLIC_API_ERROR', 'Public API failure', [
            'class' => get_class($e),
            'message' => $e->getMessage(),
        ]);
    }

    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => 'INTERNAL_ERROR',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}