<?php
declare(strict_types=1);

require_once __DIR__ . '/media_relations.php';

/**
 * Add published Memories to Event/Artist pages only through explicit relations.
 * Missing migration is a valid transitional state and leaves the page unchanged.
 */
function brvtal_public_memories_enhance_page(PDO $pdo, array $page): array
{
    $routeType = (string)($page['entity']['route_type'] ?? '');
    $typeMap = ['events'=>'event', 'artists'=>'artist'];
    if (!isset($typeMap[$routeType]) || !brvtal_media_relations_table_exists($pdo)) {
        return $page;
    }

    $id = (int)($page['entity']['id'] ?? 0);
    if ($id < 1) return $page;

    $rows = brvtal_page_rows(
        $pdo,
        "SELECT m.id,m.title,m.type,m.file_path,m.alt_text,m.mime_type,m.created_at,rel.sort_order
         FROM media_relations rel
         JOIN media m ON m.id=rel.media_id AND m.status='published'
         WHERE rel.related_type=? AND rel.related_id=?
         ORDER BY rel.sort_order,m.created_at DESC,m.id DESC",
        [$typeMap[$routeType], $id]
    );

    if ($rows === []) {
        $page['degraded'] = brvtal_page_query_degraded();
        return $page;
    }

    $memories = [];
    foreach ($rows as $row) {
        $title = trim((string)($row['title'] ?? '')) ?: 'BRVTAL MEMORY';
        $isImage = strtolower((string)($row['type'] ?? '')) === 'image';
        $memories[] = [
            'title'=>$title,
            'image'=>$isImage ? (string)($row['file_path'] ?? '') : '',
            'meta'=>strtoupper((string)($row['type'] ?? 'memory')),
            'url'=>'https://www.brvtal.com.co/?media_q=' . rawurlencode($title) . '#media',
        ];
    }

    $page['related']['MEMORIES'] = $memories;
    $page['degraded'] = brvtal_page_query_degraded();
    return $page;
}
