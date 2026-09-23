<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_auth.php';
require_once __DIR__ . '/../config/public_visibility.php';
require_once __DIR__ . '/../config/media.php';

brvtal_admin_require();

function brvtal_content_health_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function brvtal_content_health_table_exists(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
}

function brvtal_content_health_pick(array $row, array $keys): string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $row)) continue;
        $value = trim((string)($row[$key] ?? ''));
        if ($value !== '') return $value;
    }
    return '';
}

function brvtal_content_health_item(string $type, array $row): array
{
    $title = brvtal_content_health_pick($row, ['title','name']);
    $slug = brvtal_content_health_pick($row, ['slug']);
    $description = brvtal_content_health_pick($row, ['description','bio','excerpt','body','content_json']);
    $image = brvtal_content_health_pick($row, ['cover_image','photo','artwork']);
    $hasImage = brvtalMediaImageReferenceUsable($image);
    $status = strtolower(brvtal_content_health_pick($row, ['status']));
    $seoTitleSupported = array_key_exists('seo_title', $row);
    $seoDescriptionSupported = array_key_exists('seo_description', $row);
    $seoTitle = $seoTitleSupported ? trim((string)($row['seo_title'] ?? '')) : '';
    $seoDescription = $seoDescriptionSupported ? trim((string)($row['seo_description'] ?? '')) : '';

    $checks = [];
    $add = static function(string $key, string $label, int $weight, bool $ok) use (&$checks): void {
        $checks[] = ['key'=>$key,'label'=>$label,'weight'=>$weight,'ok'=>$ok];
    };

    $add('identity', 'Title / name', 20, $title !== '');
    if ($type !== 'media') $add('slug', 'Slug', 12, $slug !== '');
    if (!in_array($type, ['media'], true)) $add('description', 'Useful description', 18, mb_strlen(strip_tags($description)) >= 40);
    if (!in_array($type, ['pages'], true)) $add('image', 'Primary visual', 15, $hasImage);

    if ($seoTitleSupported) $add('seo_title', 'SEO title', 12, $seoTitle !== '');
    if ($seoDescriptionSupported) $add('seo_description', 'SEO description', 13, mb_strlen($seoDescription) >= 60);

    if ($type === 'events') {
        $add('event_date', 'Event date', 10, trim((string)($row['event_date'] ?? '')) !== '');
        $add('city', 'City', 8, trim((string)($row['city'] ?? '')) !== '');
    } elseif ($type === 'sets') {
        $add('external_url', 'Listening URL', 15, trim((string)($row['external_url'] ?? '')) !== '');
    } elseif ($type === 'releases') {
        $add('release_date', 'Release date', 10, trim((string)($row['release_date'] ?? '')) !== '');
    } elseif ($type === 'blog') {
        $add('excerpt', 'Excerpt', 10, mb_strlen(trim((string)($row['excerpt'] ?? ''))) >= 40);
    } elseif ($type === 'pages') {
        $add('content', 'Page content', 20, mb_strlen(trim((string)($row['content_json'] ?? ''))) >= 20);
    }

    $totalWeight = array_sum(array_column($checks, 'weight'));
    $earned = 0;
    $issues = [];
    foreach ($checks as $check) {
        if ($check['ok']) $earned += $check['weight'];
        else $issues[] = $check['label'];
    }
    $score = $totalWeight > 0 ? (int)round(($earned / $totalWeight) * 100) : 100;

    if ($type === 'events') {
        $isPublic = brvtal_public_event_is_visible($row);
    } elseif ($type === 'pages') {
        $isPublic = brvtal_public_page_is_visible($row);
    } else {
        $isPublic = $status === 'published';
    }

    return [
        'id' => (int)($row['id'] ?? 0),
        'type' => $type,
        'title' => $title !== '' ? $title : strtoupper($type) . ' #' . (int)($row['id'] ?? 0),
        'status' => $status,
        'score' => $score,
        'issues' => $issues,
        'seo_supported' => $seoTitleSupported || $seoDescriptionSupported,
        'has_image' => $hasImage,
        'is_public' => $isPublic,
        'is_draft' => $status === 'draft',
    ];
}

function brvtal_content_health_fetch(PDO $pdo, string $type, string $table): array
{
    if (!brvtal_content_health_table_exists($pdo, $table)) return [];
    $rows = $pdo->query("SELECT * FROM `{$table}` ORDER BY id DESC")->fetchAll();
    return array_map(static fn(array $row): array => brvtal_content_health_item($type, $row), $rows ?: []);
}

function brvtal_content_health_summary(array $items): array
{
    usort($items, static function(array $a, array $b): int {
        if ($a['score'] === $b['score']) return strcmp($a['type'] . $a['title'], $b['type'] . $b['title']);
        return $a['score'] <=> $b['score'];
    });

    $total = count($items);
    $score = $total ? (int)round(array_sum(array_column($items, 'score')) / $total) : 100;
    $ready = count(array_filter($items, static fn(array $row): bool => $row['score'] >= 80));
    $missingVisuals = count(array_filter($items, static fn(array $row): bool => !$row['has_image'] && $row['type'] !== 'pages'));
    $seoGaps = 0;
    foreach ($items as $item) {
        if (!$item['seo_supported']) continue;
        if (in_array('SEO title', $item['issues'], true) || in_array('SEO description', $item['issues'], true)) $seoGaps++;
    }

    return [
        'score' => $score,
        'total' => $total,
        'ready' => $ready,
        'needs_attention' => $total - $ready,
        'missing_visuals' => $missingVisuals,
        'seo_gaps' => $seoGaps,
        'items' => array_values($items),
    ];
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        header('Allow: GET');
        brvtal_content_health_json(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'], 405);
    }

    $pdo = db();
    $definitions = [
        'events' => 'events',
        'artists' => 'artists',
        'sets' => 'sets_media',
        'releases' => 'releases',
        'pages' => 'pages',
        'blog' => 'blog_posts',
    ];

    $allItems = [];
    $byType = [];
    foreach ($definitions as $type => $table) {
        $rows = brvtal_content_health_fetch($pdo, $type, $table);
        $publicRows = array_values(array_filter($rows, static fn(array $row): bool => $row['is_public']));
        $draftRows = array_values(array_filter($rows, static fn(array $row): bool => $row['is_draft']));
        $byType[$type] = [
            'total' => count($rows),
            'public' => count($publicRows),
            'drafts' => count($draftRows),
            'public_average_score' => $publicRows ? (int)round(array_sum(array_column($publicRows, 'score')) / count($publicRows)) : null,
            'draft_average_score' => $draftRows ? (int)round(array_sum(array_column($draftRows, 'score')) / count($draftRows)) : null,
        ];
        array_push($allItems, ...$rows);
    }

    $publicItems = array_values(array_filter($allItems, static fn(array $row): bool => $row['is_public']));
    $draftItems = array_values(array_filter($allItems, static fn(array $row): bool => $row['is_draft']));
    $public = brvtal_content_health_summary($publicItems);
    $drafts = brvtal_content_health_summary($draftItems);

    // Compatibility fields now intentionally represent public/publishable health.
    // Draft completeness is exposed separately and no longer lowers the public score.
    brvtal_content_health_json([
        'ok' => true,
        'data' => [
            'score' => $public['score'],
            'total' => $public['total'],
            'ready' => $public['ready'],
            'needs_attention' => $public['needs_attention'],
            'missing_visuals' => $public['missing_visuals'],
            'seo_gaps' => $public['seo_gaps'],
            'by_type' => $byType,
            'items' => $public['items'],
            'public' => $public,
            'drafts' => $drafts,
            'inventory_total' => count($allItems),
        ],
    ]);
} catch (Throwable $e) {
    if (function_exists('brvtal_log')) {
        brvtal_log('CONTENT_HEALTH_ERROR', 'Content Health failed', ['class'=>get_class($e),'message'=>$e->getMessage()]);
    }
    brvtal_content_health_json(['ok'=>false,'error'=>'INTERNAL_ERROR'], 500);
}
