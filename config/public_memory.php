<?php
declare(strict_types=1);

require_once __DIR__ . '/media_relations.php';

/**
 * Return published Memories explicitly related to one already-public entity.
 * Missing migration is a supported pre-deploy state and yields no Memories.
 */
function brvtal_public_memory_rows_for(PDO $pdo, string $relatedType, int $relatedId): array
{
    if ($relatedId < 1 || !in_array($relatedType, ['event','artist','set','release'], true)) return [];
    if (!brvtal_media_relations_ready($pdo)) return [];

    return brvtal_page_rows(
        $pdo,
        "SELECT m.id,m.title,m.file_path AS image,
                CONCAT('MEMORY / ',UPPER(m.type)) AS meta
         FROM media_relations rel
         JOIN media m ON m.id=rel.media_id AND m.status='published'
         WHERE rel.related_type=? AND rel.related_id=?
         ORDER BY rel.sort_order,m.id DESC",
        [$relatedType, $relatedId]
    );
}

/** Add explicit published Memories to canonical Event and Artist pages. */
function brvtal_public_memory_enhance_page(PDO $pdo, array $page): array
{
    $routeType = (string)($page['entity']['route_type'] ?? '');
    $relationType = match ($routeType) {
        'events' => 'event',
        'artists' => 'artist',
        default => '',
    };
    $id = (int)($page['entity']['id'] ?? 0);
    if ($relationType === '' || $id < 1) return $page;

    $memories = brvtal_public_memory_rows_for($pdo, $relationType, $id);
    if ($memories !== []) {
        $page['related']['MEMORIES'] = $memories;
    }
    $page['degraded'] = brvtal_page_query_degraded();
    return $page;
}
