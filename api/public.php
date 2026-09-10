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
        "SELECT id,title,slug,event_date,venue,city,description,skin,accent,
                cover_image,ticket_url,status,sort_order
         FROM events
         WHERE status='published'
         ORDER BY event_date ASC, sort_order ASC, id ASC"
    )->fetchAll();

    $artists = $pdo->query(
        "SELECT id,name,slug,bio,photo,instagram_url,soundcloud_url,website_url,
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
        $event['lineup'] = $lineupByEvent[(string)$event['id']] ?? [];
    }
    unset($event);

    $settings = brvtal_public_settings($pdo);

    $payload = [
        'events' => $events,
        'artists' => $artists,
        'sets' => $sets,
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
