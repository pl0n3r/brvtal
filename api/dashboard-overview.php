<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_auth.php';
require_once __DIR__ . '/../config/public_visibility.php';
require_once __DIR__ . '/../config/schema_catalog.php';

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
    return brvtalSchemaTableExists($pdo, $table);
}

function brvtal_dashboard_status_counts(PDO $pdo, string $table, array $publicStatuses = ['published']): array
{
    if (!brvtal_dashboard_table_exists($pdo, $table)) {
        return ['available'=>false,'total'=>0,'public'=>0,'draft'=>0,'archived'=>0];
    }

    $placeholders = $publicStatuses === []
        ? ''
        : implode(',', array_fill(0, count($publicStatuses), '?'));
    $publicExpression = $publicStatuses === []
        ? '0'
        : "COALESCE(SUM(status IN ({$placeholders})),0)";

    $statement = $pdo->prepare(
        "SELECT COUNT(*) total,
                COALESCE(SUM(status='draft'),0) draft,
                {$publicExpression} public,
                COALESCE(SUM(status IN ('archived','finished','cancelled')),0) archived
         FROM `{$table}`"
    );
    $statement->execute($publicStatuses);
    $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'available' => true,
        'total' => (int)($row['total'] ?? 0),
        'public' => (int)($row['public'] ?? 0),
        'draft' => (int)($row['draft'] ?? 0),
        'archived' => (int)($row['archived'] ?? 0),
    ];
}

function brvtal_dashboard_page_status_counts(PDO $pdo): array
{
    if (!brvtal_dashboard_table_exists($pdo, 'pages')) {
        return ['available'=>false,'total'=>0,'public'=>0,'draft'=>0,'archived'=>0];
    }

    $row = $pdo->query(
        "SELECT COUNT(*) total,
                COALESCE(SUM(status='published' AND locale='en'),0) public,
                COALESCE(SUM(status='draft'),0) draft
         FROM pages"
    )->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'available' => true,
        'total' => (int)($row['total'] ?? 0),
        'public' => (int)($row['public'] ?? 0),
        'draft' => (int)($row['draft'] ?? 0),
        'archived' => 0,
    ];
}

function brvtal_dashboard_media_counts(PDO $pdo): array
{
    if (!brvtal_dashboard_table_exists($pdo, 'media')) {
        return ['available'=>false,'total'=>0,'images'=>0,'published'=>0];
    }

    $row = $pdo->query(
        "SELECT COUNT(*) total,
                COALESCE(SUM(type='image'),0) images,
                COALESCE(SUM(status='published'),0) published
         FROM media"
    )->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'available' => true,
        'total' => (int)($row['total'] ?? 0),
        'images' => (int)($row['images'] ?? 0),
        'published' => (int)($row['published'] ?? 0),
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

    // Exactly one information_schema query per dashboard request.
    brvtalSchemaTables($pdo);

    $content = [
        'events' => brvtal_dashboard_status_counts($pdo, 'events', $activeEventStatuses),
        'artists' => brvtal_dashboard_status_counts($pdo, 'artists'),
        'sets' => brvtal_dashboard_status_counts($pdo, 'sets_media'),
        'releases' => brvtal_dashboard_status_counts($pdo, 'releases'),
        'pages' => brvtal_dashboard_page_status_counts($pdo),
        'blog' => brvtal_dashboard_status_counts($pdo, 'blog_posts'),
    ];

    $media = brvtal_dashboard_media_counts($pdo);

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
