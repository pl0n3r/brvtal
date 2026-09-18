<?php
declare(strict_types=1);

/** @return array<string,array{table:string,label:string,route_type:string}> */
function brvtal_memory_relation_targets(): array
{
    return [
        'event' => ['table'=>'events', 'label'=>'title', 'route_type'=>'events'],
        'artist' => ['table'=>'artists', 'label'=>'name', 'route_type'=>'artists'],
        'set' => ['table'=>'sets_media', 'label'=>'title', 'route_type'=>'sets'],
        'release' => ['table'=>'releases', 'label'=>'title', 'route_type'=>'releases'],
    ];
}

function brvtal_memory_relations_ready(PDO $pdo): bool
{
    static $ready = [];
    $key = spl_object_id($pdo);
    if (($ready[$key] ?? false) === true) {
        return true;
    }

    try {
        $pdo->query('SELECT 1 FROM memory_relations LIMIT 1');
        $ready[$key] = true;
        return true;
    } catch (Throwable) {
        return false;
    }
}

function brvtal_memory_relation_id(mixed $value): int
{
    if (is_bool($value) || is_float($value) || (!is_int($value) && !is_string($value))) {
        throw new InvalidArgumentException('INVALID_MEMORY_RELATION');
    }

    $validated = filter_var(
        $value,
        FILTER_VALIDATE_INT,
        ['options'=>['min_range'=>1]]
    );
    if ($validated === false) {
        throw new InvalidArgumentException('INVALID_MEMORY_RELATION');
    }

    return (int)$validated;
}

/** @return array<int,array{related_type:string,related_id:int,sort_order:int}> */
function brvtal_memory_normalize_relations(mixed $value): array
{
    if (!is_array($value)) {
        throw new InvalidArgumentException('INVALID_MEMORY_RELATIONS');
    }
    if (count($value) > 100) {
        throw new InvalidArgumentException('TOO_MANY_MEMORY_RELATIONS');
    }

    $targets = brvtal_memory_relation_targets();
    $seen = [];
    $out = [];

    foreach ($value as $relation) {
        if (!is_array($relation)) {
            throw new InvalidArgumentException('INVALID_MEMORY_RELATION');
        }

        $type = strtolower(trim((string)($relation['related_type'] ?? '')));
        if (!isset($targets[$type])) {
            throw new InvalidArgumentException('INVALID_MEMORY_RELATION');
        }

        $id = brvtal_memory_relation_id($relation['related_id'] ?? null);
        $key = $type . ':' . $id;
        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $out[] = [
            'related_type'=>$type,
            'related_id'=>$id,
            'sort_order'=>count($out),
        ];
    }

    return $out;
}

function brvtal_memory_lock_relation_targets(PDO $pdo, array $relations): void
{
    if ($relations === []) {
        return;
    }

    $targets = brvtal_memory_relation_targets();
    $grouped = [];
    foreach ($relations as $relation) {
        $type = strtolower(trim((string)($relation['related_type'] ?? '')));
        if (!isset($targets[$type])) {
            throw new InvalidArgumentException('INVALID_MEMORY_RELATION');
        }

        $id = brvtal_memory_relation_id($relation['related_id'] ?? null);
        $grouped[$type][$id] = $id;
    }

    foreach ($targets as $type => $definition) {
        if (empty($grouped[$type])) {
            continue;
        }

        $ids = array_values($grouped[$type]);
        sort($ids, SORT_NUMERIC);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $table = $definition['table'];
        $statement = $pdo->prepare(
            "SELECT id FROM `{$table}` WHERE id IN ({$marks}) ORDER BY id FOR UPDATE"
        );
        $statement->execute($ids);

        $found = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        sort($found, SORT_NUMERIC);
        if ($found !== $ids) {
            throw new InvalidArgumentException('MEMORY_RELATION_NOT_FOUND');
        }
    }
}

/**
 * @return array<int,array<int,array{related_type:string,related_id:int,sort_order:int}>>
 */
function brvtal_memory_load_relations_batch(PDO $pdo, array $memoryIds): array
{
    $ids = array_values(array_unique(array_filter(
        array_map('intval', $memoryIds),
        static fn(int $id): bool => $id > 0
    )));
    if ($ids === [] || !brvtal_memory_relations_ready($pdo)) {
        return [];
    }

    $marks = implode(',', array_fill(0, count($ids), '?'));
    $statement = $pdo->prepare(
        "SELECT memory_id,related_type,related_id,sort_order
         FROM memory_relations
         WHERE memory_id IN ({$marks})
         ORDER BY memory_id,sort_order,related_type,related_id"
    );
    $statement->execute($ids);

    $grouped = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $memoryId = (int)$row['memory_id'];
        $grouped[$memoryId][] = [
            'related_type'=>(string)$row['related_type'],
            'related_id'=>(int)$row['related_id'],
            'sort_order'=>(int)$row['sort_order'],
        ];
    }

    return $grouped;
}

/** @return array<int,array{related_type:string,related_id:int,sort_order:int}> */
function brvtal_memory_load_relations(PDO $pdo, int $memoryId): array
{
    if ($memoryId < 1) {
        return [];
    }

    $grouped = brvtal_memory_load_relations_batch($pdo, [$memoryId]);
    return $grouped[$memoryId] ?? [];
}

function brvtal_memory_replace_relations(PDO $pdo, int $memoryId, mixed $relations): void
{
    if ($memoryId < 1) {
        throw new InvalidArgumentException('INVALID_MEMORY_ID');
    }
    if (!brvtal_memory_relations_ready($pdo)) {
        throw new RuntimeException('MEMORY_RELATIONS_SCHEMA_MISSING');
    }

    $normalized = brvtal_memory_normalize_relations($relations);
    brvtal_memory_lock_relation_targets($pdo, $normalized);
    $pdo->prepare('DELETE FROM memory_relations WHERE memory_id=?')->execute([$memoryId]);

    if ($normalized === []) {
        return;
    }

    $insert = $pdo->prepare(
        'INSERT INTO memory_relations(memory_id,related_type,related_id,sort_order)
         VALUES(?,?,?,?)'
    );
    foreach ($normalized as $relation) {
        $insert->execute([
            $memoryId,
            $relation['related_type'],
            $relation['related_id'],
            $relation['sort_order'],
        ]);
    }
}

/** Admin-only target catalog; a failed target source is represented honestly. */
function brvtal_memory_relation_catalog(PDO $pdo): array
{
    $catalog = [];

    foreach (brvtal_memory_relation_targets() as $type => $definition) {
        try {
            $table = $definition['table'];
            $label = $definition['label'];
            $rows = $pdo->query(
                "SELECT id,`{$label}` AS label,status
                 FROM `{$table}`
                 ORDER BY `{$label}`,id"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $catalog[$type] = [
                'state'=>'ready',
                'items'=>array_map(
                    static fn(array $row): array => [
                        'id'=>(int)$row['id'],
                        'label'=>(string)($row['label'] ?? ''),
                        'status'=>(string)($row['status'] ?? ''),
                    ],
                    $rows
                ),
            ];
        } catch (Throwable) {
            $catalog[$type] = [
                'state'=>'error',
                'items'=>[],
            ];
        }
    }

    return $catalog;
}

/** @return array<int,array{id:int,label:string,slug:string}> */
function brvtal_public_memory_entity_map(array $items, string $titleField = 'title'): array
{
    $map = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $id = (int)($item['id'] ?? 0);
        if ($id < 1) {
            continue;
        }

        $map[$id] = [
            'id'=>$id,
            'label'=>(string)($item[$titleField] ?? ''),
            'slug'=>(string)($item['slug'] ?? ''),
        ];
    }

    return $map;
}

/** Attach only relations whose targets survived the final public entity pools. */
function brvtal_public_attach_memory_relations(
    PDO $pdo,
    array $memories,
    array $activeEvents,
    array $archiveEvents,
    array $artists,
    array $sets,
    array $releases
): array
{
    foreach ($memories as &$memory) {
        if (is_array($memory)) {
            $memory['relations'] = [];
        }
    }
    unset($memory);

    if ($memories === [] || !brvtal_memory_relations_ready($pdo)) {
        return $memories;
    }

    $memoryIndex = [];
    $ids = [];
    foreach ($memories as $index => $memory) {
        $id = (int)($memory['id'] ?? 0);
        if ($id < 1) {
            continue;
        }

        $memoryIndex[$id] = $index;
        $ids[] = $id;
    }

    if ($ids === []) {
        return $memories;
    }

    $eventMap = brvtal_public_memory_entity_map(
        array_merge($activeEvents, $archiveEvents)
    );
    $pools = [
        'event'=>[
            'route_type'=>'events',
            'items'=>$eventMap,
        ],
        'artist'=>[
            'route_type'=>'artists',
            'items'=>brvtal_public_memory_entity_map($artists, 'name'),
        ],
        'set'=>[
            'route_type'=>'sets',
            'items'=>brvtal_public_memory_entity_map($sets),
        ],
        'release'=>[
            'route_type'=>'releases',
            'items'=>brvtal_public_memory_entity_map($releases),
        ],
    ];

    $marks = implode(',', array_fill(0, count($ids), '?'));
    $statement = $pdo->prepare(
        "SELECT memory_id,related_type,related_id,sort_order
         FROM memory_relations
         WHERE memory_id IN ({$marks})
         ORDER BY memory_id,sort_order,related_type,related_id"
    );
    $statement->execute($ids);

    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $memoryId = (int)($row['memory_id'] ?? 0);
        $type = (string)($row['related_type'] ?? '');
        $relatedId = (int)($row['related_id'] ?? 0);
        if (!isset($memoryIndex[$memoryId], $pools[$type]['items'][$relatedId])) {
            continue;
        }

        $target = $pools[$type]['items'][$relatedId];
        $memories[$memoryIndex[$memoryId]]['relations'][] = [
            'related_type'=>$type,
            'related_id'=>$relatedId,
            'sort_order'=>(int)($row['sort_order'] ?? 0),
            'route_type'=>$pools[$type]['route_type'],
            'slug'=>(string)($target['slug'] ?? ''),
            'label'=>(string)($target['label'] ?? ''),
        ];
    }

    return $memories;
}

/** Decorate the existing four-layer graph with Memory edges, without adding a fifth selector layer. */
function brvtal_public_add_memory_edges(array $graph, array $memories): array
{
    $graph['memories'] = [];
    $graph['counts'] = is_array($graph['counts'] ?? null)
        ? $graph['counts']
        : [];

    foreach (['event_memory','artist_memory','set_memory','release_memory'] as $key) {
        $graph['counts'][$key] = 0;
    }

    foreach ($memories as $memory) {
        if (!is_array($memory)) {
            continue;
        }

        $memoryId = (int)($memory['id'] ?? 0);
        if ($memoryId < 1) {
            continue;
        }

        $bucket = [
            'events'=>[],
            'artists'=>[],
            'sets'=>[],
            'releases'=>[],
        ];
        $relations = is_array($memory['relations'] ?? null)
            ? $memory['relations']
            : [];

        foreach ($relations as $relation) {
            $type = (string)($relation['related_type'] ?? '');
            $relatedId = (int)($relation['related_id'] ?? 0);
            $plural = match ($type) {
                'event'=>'events',
                'artist'=>'artists',
                'set'=>'sets',
                'release'=>'releases',
                default=>'',
            };

            if (
                $relatedId < 1
                || $plural === ''
                || !isset($graph[$plural][(string)$relatedId])
            ) {
                continue;
            }

            if (!in_array($relatedId, $bucket[$plural], true)) {
                $bucket[$plural][] = $relatedId;
            }

            if (!isset($graph[$plural][(string)$relatedId]['memories'])) {
                $graph[$plural][(string)$relatedId]['memories'] = [];
            }

            if (!in_array($memoryId, $graph[$plural][(string)$relatedId]['memories'], true)) {
                $graph[$plural][(string)$relatedId]['memories'][] = $memoryId;
                $graph['counts'][$type . '_memory']++;
            }
        }

        if (array_filter($bucket, static fn(array $ids): bool => $ids !== [])) {
            $graph['memories'][(string)$memoryId] = $bucket;
        }
    }

    return $graph;
}

/** Published curated Memories explicitly related to one canonical public entity. */
function brvtal_public_memories_for_entity(
    PDO $pdo,
    string $routeType,
    int $id,
    int $limit = 12
): array
{
    $type = [
        'events'=>'event',
        'artists'=>'artist',
        'sets'=>'set',
        'releases'=>'release',
    ][$routeType] ?? '';

    if ($type === '' || $id < 1 || !brvtal_memory_relations_ready($pdo)) {
        return [];
    }

    $limit = max(1, min(24, $limit));
    $statement = $pdo->prepare(
        "SELECT m.id,m.title,m.context,media.type,media.file_path,
                media.alt_text,mr.sort_order
         FROM memory_relations mr
         JOIN memories m
           ON m.id=mr.memory_id
          AND m.status='published'
         JOIN media
           ON media.id=m.media_id
          AND media.status='published'
          AND media.type IN ('image','video','audio')
         WHERE mr.related_type=?
           AND mr.related_id=?
         ORDER BY mr.sort_order,m.sort_order,m.id
         LIMIT {$limit}"
    );
    $statement->execute([$type,$id]);

    $out = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $mediaType = (string)($row['type'] ?? '');
        $out[] = [
            'title'=>(string)($row['title'] ?? 'Memory'),
            'image'=>$mediaType === 'image'
                ? (string)($row['file_path'] ?? '')
                : '',
            'url'=>'/#media',
            'meta'=>'MEMORY / ' . strtoupper($mediaType ?: 'MEDIA'),
        ];
    }

    return $out;
}
