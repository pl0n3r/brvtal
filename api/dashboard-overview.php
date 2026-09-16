<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_auth.php';
require_once __DIR__ . '/../config/public_visibility.php';

brvtal_admin_require();

function brvtal_dashboard_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function brvtal_dashboard_table_exists(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
}

function brvtal_dashboard_count(PDO $pdo, string $table, string $where = '', array $params = []): int
{
    if (!brvtal_dashboard_table_exists($pdo, $table)) return 0;
    $sql = 'SELECT COUNT(*) FROM `' . $table . '`' . ($where !== '' ? ' WHERE ' . $where : '');
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (int)$st->fetchColumn();
}

function brvtal_dashboard_status_counts(PDO $pdo, string $table, array $publicStatuses = ['published']): array
{
    if (!brvtal_dashboard_table_exists($pdo, $table)) {
        return ['available'=>false,'total'=>0,'public'=>0,'draft'=>0,'archived'=>0];
    }

    $total = brvtal_dashboard_count($pdo, $table);
    $draft = brvtal_dashboard_count($pdo, $table, "status='draft'");
    $public = 0;
    if ($publicStatuses !== []) {
        $placeholders = implode(',', array_fill(0, count($publicStatuses), '?'));
        $public = brvtal_dashboard_count($pdo, $table, 'status IN (' . $placeholders . ')', $publicStatuses);
    }
    $archived = brvtal_dashboard_count($pdo, $table, "status IN ('archived','finished','cancelled')");

    return ['available'=>true,'total'=>$total,'public'=>$public,'draft'=>$draft,'archived'=>$archived];
}

function brvtal_dashboard_page_status_counts(PDO $pdo): array
{
    if (!brvtal_dashboard_table_exists($pdo, 'pages')) {
        return ['available'=>false,'total'=>0,'public'=>0,'draft'=>0,'archived'=>0];
    }

    return [
        'available' => true,
        'total' => brvtal_dashboard_count($pdo, 'pages'),
        'public' => brvtal_dashboard_count($pdo, 'pages', "status='published' AND locale='en'"),
        'draft' => brvtal_dashboard_count($pdo, 'pages', "status='draft'"),
        'archived' => 0,
    ];
}

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        header('Allow: GET');
        brvtal_dashboard_json(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'], 405);
    }

    $pdo = db();
    $eventGroups = brvtal_public_event_statuses();
    $activeEventStatuses = $eventGroups['active'];

    $content = [
        'events' => brvtal_dashboard_status_counts($pdo, 'events', $activeEventStatuses),
        'artists' => brvtal_dashboard_status_counts($pdo, 'artists'),
        'sets' => brvtal_dashboard_status_counts($pdo, 'sets_media'),
        'releases' => brvtal_dashboard_status_counts($pdo, 'releases'),
        'pages' => brvtal_dashboard_page_status_counts($pdo),
        'blog' => brvtal_dashboard_status_counts($pdo, 'blog_posts'),
    ];

    $media = [
        'available' => brvtal_dashboard_table_exists($pdo, 'media'),
        'total' => brvtal_dashboard_count($pdo, 'media'),
        'images' => brvtal_dashboard_count($pdo, 'media', "type='image'"),
        'published' => brvtal_dashboard_count($pdo, 'media', "status='published'"),
    ];

    $nextEvent = null;
    if (brvtal_dashboard_table_exists($pdo, 'events')) {
        $placeholders = brvtal_public_sql_placeholders($activeEventStatuses);
        $st = $pdo->prepare(
            "SELECT id,title,slug,event_date,venue,city,cover_image,status,ticket_url
             FROM events
             WHERE status IN ({$placeholders})
               AND event_date IS NOT NULL
               AND event_date >= CURDATE()
             ORDER BY event_date ASC,id ASC
             LIMIT 1"
        );
        $st->execute($activeEventStatuses);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row['id'] = (int)$row['id'];
            $nextEvent = $row;
        }
    }

    $publicTotal = 0;
    $draftTotal = 0;
    foreach ($content as $stats) {
        if (!($stats['available'] ?? false)) continue;
        $publicTotal += (int)$stats['public'];
        $draftTotal += (int)$stats['draft'];
    }

    brvtal_dashboard_json([
        'ok' => true,
        'data' => [
            'content' => $content,
            'media' => $media,
            'next_event' => $nextEvent,
            'summary' => [
                'public_records' => $publicTotal,
                'draft_records' => $draftTotal,
                'active_events' => (int)$content['events']['public'],
                'media_assets' => (int)$media['total'],
            ],
            'event_public_statuses' => $activeEventStatuses,
            'generated_at' => date(DATE_ATOM),
        ],
    ]);
} catch (Throwable $e) {
    if (function_exists('brvtal_log')) {
        brvtal_log('DASHBOARD_OVERVIEW_ERROR', 'Dashboard V2 overview failed', [
            'class'=>get_class($e),
            'message'=>$e->getMessage(),
        ]);
    }
    brvtal_dashboard_json(['ok'=>false,'error'=>'INTERNAL_ERROR'], 500);
}
