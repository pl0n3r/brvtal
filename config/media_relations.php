<?php
declare(strict_types=1);

/** @return array<string,string> */
function brvtal_media_relation_tables(): array
{
    return [
        'event' => 'events',
        'artist' => 'artists',
        'set' => 'sets_media',
        'release' => 'releases',
    ];
}

function brvtal_media_relations_table_exists(PDO $pdo): bool
{
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='media_relations'"
    );
    $st->execute();
    return (int)$st->fetchColumn() === 1;
}

function brvtal_media_relation_id(mixed $value): int
{
    if (is_bool($value) || is_float($value) || (!is_int($value) && !is_string($value))) {
        throw new InvalidArgumentException('INVALID_MEDIA_RELATION');
    }
    $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($validated === false) {
        throw new InvalidArgumentException('INVALID_MEDIA_RELATION');
    }
    return (int)$validated;
}

/** @return list<array{related_type:string,related_id:int,sort_order:int}> */
function brvtal_media_normalize_relations(mixed $value): array
{
    if ($value === null) return [];
    if (!is_array($value)) throw new InvalidArgumentException('INVALID_MEDIA_RELATION');

    $tables = brvtal_media_relation_tables();
    $out = [];
    $seen = [];
    foreach ($value as $index => $relation) {
        if (!is_array($relation)) throw new InvalidArgumentException('INVALID_MEDIA_RELATION');
        $type = strtolower(trim((string)($relation['related_type'] ?? '')));
        if (!isset($tables[$type])) throw new InvalidArgumentException('INVALID_MEDIA_RELATION');
        $id = brvtal_media_relation_id($relation['related_id'] ?? null);
        $key = $type . ':' . $id;
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = ['related_type' => $type, 'related_id' => $id, 'sort_order' => (int)$index];
    }
    return $out;
}

/** Lock every referenced target in deterministic order before persistence. */
function brvtal_media_lock_relation_targets(PDO $pdo, array $relations): void
{
    if ($relations === []) return;
    $tables = brvtal_media_relation_tables();
    $grouped = [];
    foreach ($relations as $relation) {
        $type = (string)($relation['related_type'] ?? '');
        if (!isset($tables[$type])) throw new InvalidArgumentException('INVALID_MEDIA_RELATION');
        $id = brvtal_media_relation_id($relation['related_id'] ?? null);
        $grouped[$type][$id] = $id;
    }

    foreach ($tables as $type => $table) {
        if (empty($grouped[$type])) continue;
        $ids = array_values($grouped[$type]);
        sort($ids, SORT_NUMERIC);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT id FROM {$table} WHERE id IN ({$marks}) ORDER BY id FOR UPDATE");
        $st->execute($ids);
        $found = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        sort($found, SORT_NUMERIC);
        if ($found !== $ids) throw new InvalidArgumentException('MEDIA_RELATION_NOT_FOUND');
    }
}

/** @return list<array{related_type:string,related_id:int,sort_order:int}> */
function brvtal_media_relations_for(PDO $pdo, int $mediaId): array
{
    if (!brvtal_media_relations_table_exists($pdo)) return [];
    $st = $pdo->prepare(
        'SELECT related_type,related_id,sort_order FROM media_relations WHERE media_id=? ORDER BY sort_order,related_type,related_id'
    );
    $st->execute([$mediaId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        $row['related_id'] = (int)$row['related_id'];
        $row['sort_order'] = (int)$row['sort_order'];
    }
    unset($row);
    return $rows;
}

function brvtal_media_replace_relations(PDO $pdo, int $mediaId, array $relations): void
{
    if (!brvtal_media_relations_table_exists($pdo)) {
        if ($relations !== []) throw new RuntimeException('MEDIA_RELATIONS_UNAVAILABLE');
        return;
    }
    brvtal_media_lock_relation_targets($pdo, $relations);
    $delete = $pdo->prepare('DELETE FROM media_relations WHERE media_id=?');
    $delete->execute([$mediaId]);
    if ($relations === []) return;
    $insert = $pdo->prepare(
        'INSERT INTO media_relations(media_id,related_type,related_id,sort_order) VALUES(?,?,?,?)'
    );
    foreach ($relations as $relation) {
        $insert->execute([$mediaId, $relation['related_type'], $relation['related_id'], $relation['sort_order']]);
    }
}

/** Admin relation targets. Draft targets are allowed editorially; public delivery filters them later. */
function brvtal_media_relation_options(PDO $pdo): array
{
    $out = ['event'=>[], 'artist'=>[], 'set'=>[], 'release'=>[]];
    foreach (brvtal_media_relation_tables() as $type => $table) {
        $exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $exists->execute([$table]);
        if ((int)$exists->fetchColumn() !== 1) continue;
        $label = $type === 'artist' ? 'name' : 'title';
        $rows = $pdo->query("SELECT id,{$label} AS label,status FROM {$table} ORDER BY {$label},id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) $row['id'] = (int)$row['id'];
        unset($row);
        $out[$type] = $rows;
    }
    return $out;
}

/**
 * Attach only explicit relations whose target is public. Missing migration is
 * intentionally non-fatal and returns the media rows unchanged with no links.
 */
function brvtal_media_attach_public_relations(PDO $pdo, array $mediaRows, array $publicEventStatuses): array
{
    foreach ($mediaRows as &$media) $media['relations'] = [];
    unset($media);
    if ($mediaRows === [] || !brvtal_media_relations_table_exists($pdo)) return $mediaRows;

    $mediaIndex = [];
    foreach ($mediaRows as $i => $media) $mediaIndex[(int)$media['id']] = $i;
    $ids = array_keys($mediaIndex);
    if ($ids === []) return $mediaRows;
    $marks = implode(',', array_fill(0, count($ids), '?'));

    $queries = [];
    $eventMarks = implode(',', array_fill(0, count($publicEventStatuses), '?'));
    if ($publicEventStatuses !== []) {
        $queries[] = [
            "SELECT mr.media_id,mr.related_type,mr.related_id,mr.sort_order,e.title AS label,e.slug
             FROM media_relations mr JOIN media m ON m.id=mr.media_id AND m.status='published'
             JOIN events e ON e.id=mr.related_id
             WHERE mr.related_type='event' AND mr.media_id IN ({$marks}) AND e.status IN ({$eventMarks})",
            array_merge($ids, $publicEventStatuses),
        ];
    }
    $queries[] = [
        "SELECT mr.media_id,mr.related_type,mr.related_id,mr.sort_order,a.name AS label,a.slug
         FROM media_relations mr JOIN media m ON m.id=mr.media_id AND m.status='published'
         JOIN artists a ON a.id=mr.related_id AND a.status='published'
         WHERE mr.related_type='artist' AND mr.media_id IN ({$marks})",
        $ids,
    ];
    $queries[] = [
        "SELECT mr.media_id,mr.related_type,mr.related_id,mr.sort_order,s.title AS label,s.slug
         FROM media_relations mr JOIN media m ON m.id=mr.media_id AND m.status='published'
         JOIN sets_media s ON s.id=mr.related_id AND s.status='published'
         WHERE mr.related_type='set' AND mr.media_id IN ({$marks})",
        $ids,
    ];

    $releaseExists = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='releases'"
    );
    if ((int)$releaseExists->fetchColumn() === 1) {
        $queries[] = [
            "SELECT mr.media_id,mr.related_type,mr.related_id,mr.sort_order,r.title AS label,r.slug
             FROM media_relations mr JOIN media m ON m.id=mr.media_id AND m.status='published'
             JOIN releases r ON r.id=mr.related_id AND r.status='published'
             WHERE mr.related_type='release' AND mr.media_id IN ({$marks})",
            $ids,
        ];
    }

    $relations = [];
    foreach ($queries as [$sql, $params]) {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $row['media_id'] = (int)$row['media_id'];
            $row['related_id'] = (int)$row['related_id'];
            $row['sort_order'] = (int)$row['sort_order'];
            $relations[] = $row;
        }
    }
    usort($relations, static fn(array $a, array $b): int =>
        [$a['media_id'],$a['sort_order'],$a['related_type'],$a['related_id']] <=>
        [$b['media_id'],$b['sort_order'],$b['related_type'],$b['related_id']]
    );
    foreach ($relations as $relation) {
        $mediaId = (int)$relation['media_id'];
        if (!isset($mediaIndex[$mediaId])) continue;
        unset($relation['media_id']);
        $mediaRows[$mediaIndex[$mediaId]]['relations'][] = $relation;
    }
    return $mediaRows;
}
