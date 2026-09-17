<?php
declare(strict_types=1);

function brvtal_public_media_relations_ready(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM media_relations LIMIT 1');
        return true;
    } catch (Throwable) {
        return false;
    }
}

/** @return array<int,array<string,mixed>> */
function brvtal_public_entity_map(array $items, string $titleField = 'title'): array
{
    $map = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $id = (int)($item['id'] ?? 0);
        if ($id < 1) continue;
        $map[$id] = [
            'id' => $id,
            'label' => (string)($item[$titleField] ?? ''),
            'slug' => (string)($item['slug'] ?? ''),
        ];
    }
    return $map;
}

/**
 * Attach only relations whose targets survived the final public pools.
 * No draft/private target ID or label is copied into the public payload.
 */
function brvtal_public_attach_memory_relations(
    PDO $pdo,
    array $media,
    array $activeEvents,
    array $archiveEvents,
    array $artists,
    array $sets,
    array $releases
): array {
    if ($media === []) return [];
    foreach ($media as &$item) {
        if (is_array($item)) $item['relations'] = [];
    }
    unset($item);
    if (!brvtal_public_media_relations_ready($pdo)) return $media;

    $mediaIndex = [];
    $ids = [];
    foreach ($media as $index => $item) {
        $id = (int)($item['id'] ?? 0);
        if ($id < 1) continue;
        $mediaIndex[$id] = $index;
        $ids[] = $id;
    }
    if ($ids === []) return $media;

    $events = brvtal_public_entity_map(array_merge($activeEvents, $archiveEvents));
    $pools = [
        'event' => ['route_type' => 'events', 'items' => $events],
        'artist' => ['route_type' => 'artists', 'items' => brvtal_public_entity_map($artists, 'name')],
        'set' => ['route_type' => 'sets', 'items' => brvtal_public_entity_map($sets)],
        'release' => ['route_type' => 'releases', 'items' => brvtal_public_entity_map($releases)],
    ];

    $marks = implode(',', array_fill(0, count($ids), '?'));
    $statement = $pdo->prepare(
        "SELECT media_id,related_type,related_id,sort_order FROM media_relations WHERE media_id IN ({$marks}) ORDER BY media_id,sort_order,related_type,related_id"
    );
    $statement->execute($ids);
    foreach ($statement->fetchAll() ?: [] as $row) {
        $mediaId = (int)($row['media_id'] ?? 0);
        $type = (string)($row['related_type'] ?? '');
        $relatedId = (int)($row['related_id'] ?? 0);
        if (!isset($mediaIndex[$mediaId], $pools[$type]['items'][$relatedId])) continue;
        $target = $pools[$type]['items'][$relatedId];
        $media[$mediaIndex[$mediaId]]['relations'][] = [
            'related_type' => $type,
            'related_id' => $relatedId,
            'sort_order' => (int)($row['sort_order'] ?? 0),
            'route_type' => $pools[$type]['route_type'],
            'slug' => (string)($target['slug'] ?? ''),
            'label' => (string)($target['label'] ?? ''),
        ];
    }

    return $media;
}

/** Decorate the existing four-layer graph with Memory edges without adding a fifth selector layer. */
function brvtal_public_add_memory_edges(array $graph, array $media): array
{
    $graph['media'] = [];
    $graph['counts'] = is_array($graph['counts'] ?? null) ? $graph['counts'] : [];
    foreach (['event_memory','artist_memory','set_memory','release_memory'] as $key) {
        $graph['counts'][$key] = 0;
    }

    foreach ($media as $item) {
        if (!is_array($item)) continue;
        $mediaId = (int)($item['id'] ?? 0);
        if ($mediaId < 1) continue;
        $bucket = ['events'=>[], 'artists'=>[], 'sets'=>[], 'releases'=>[]];
        foreach (is_array($item['relations'] ?? null) ? $item['relations'] : [] as $relation) {
            $type = (string)($relation['related_type'] ?? '');
            $relatedId = (int)($relation['related_id'] ?? 0);
            if ($relatedId < 1) continue;
            $plural = match ($type) {
                'event' => 'events',
                'artist' => 'artists',
                'set' => 'sets',
                'release' => 'releases',
                default => '',
            };
            if ($plural === '' || !isset($graph[$plural][(string)$relatedId])) continue;
            if (!in_array($relatedId, $bucket[$plural], true)) $bucket[$plural][] = $relatedId;
            $target =& $graph[$plural][(string)$relatedId];
            if (!isset($target['memories']) || !is_array($target['memories'])) $target['memories'] = [];
            if (!in_array($mediaId, $target['memories'], true)) {
                $target['memories'][] = $mediaId;
                $countKey = $type . '_memory';
                $graph['counts'][$countKey] = (int)($graph['counts'][$countKey] ?? 0) + 1;
            }
            unset($target);
        }
        if (array_filter($bucket, static fn(array $ids): bool => $ids !== [])) {
            $graph['media'][(string)$mediaId] = $bucket;
        }
    }
    return $graph;
}

/** Return published Memories explicitly related to one canonical public entity. */
function brvtal_public_memories_for_entity(PDO $pdo, string $type, int $id, int $limit = 12): array
{
    if ($id < 1 || !in_array($type, ['event','artist','set','release'], true) || !brvtal_public_media_relations_ready($pdo)) return [];
    $limit = max(1, min(24, $limit));
    $statement = $pdo->prepare(
        "SELECT m.id,m.type,m.title,m.file_path,m.alt_text,m.created_at,mr.sort_order
         FROM media_relations mr
         JOIN media m ON m.id=mr.media_id AND m.status='published'
         WHERE mr.related_type=? AND mr.related_id=?
         ORDER BY mr.sort_order,m.created_at DESC,m.id DESC
         LIMIT {$limit}"
    );
    $statement->execute([$type, $id]);
    $items = [];
    foreach ($statement->fetchAll() ?: [] as $row) {
        $items[] = [
            'id' => (int)$row['id'],
            'title' => (string)($row['title'] ?? 'Memory'),
            'image' => (string)($row['type'] ?? '') === 'image' ? (string)($row['file_path'] ?? '') : '',
            'url' => '/#media',
            'meta' => 'MEMORY / ' . strtoupper((string)($row['type'] ?? 'MEDIA')),
        ];
    }
    return $items;
}
