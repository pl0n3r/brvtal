<?php
declare(strict_types=1);

/** @return array<string,array{table:string,label:string}> */
function brvtal_media_relation_targets(): array
{
    return [
        'event' => ['table' => 'events', 'label' => 'title'],
        'artist' => ['table' => 'artists', 'label' => 'name'],
        'set' => ['table' => 'sets_media', 'label' => 'title'],
        'release' => ['table' => 'releases', 'label' => 'title'],
    ];
}

function brvtal_media_relations_ready(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM media_relations LIMIT 1');
        return true;
    } catch (Throwable) {
        return false;
    }
}

/** Parse a relation ID without lossy scalar coercion. */
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

/**
 * Normalize client relation input into a bounded, de-duplicated ordered list.
 * Client-provided sort indexes are ignored; input order is authoritative.
 *
 * @return array<int,array{related_type:string,related_id:int,sort_order:int}>
 */
function brvtal_media_normalize_relations(mixed $value): array
{
    if (!is_array($value)) {
        throw new InvalidArgumentException('INVALID_MEDIA_RELATIONS');
    }
    if (count($value) > 100) {
        throw new InvalidArgumentException('TOO_MANY_MEDIA_RELATIONS');
    }

    $targets = brvtal_media_relation_targets();
    $seen = [];
    $out = [];
    foreach ($value as $relation) {
        if (!is_array($relation)) {
            throw new InvalidArgumentException('INVALID_MEDIA_RELATION');
        }
        $type = strtolower(trim((string)($relation['related_type'] ?? '')));
        if (!isset($targets[$type])) {
            throw new InvalidArgumentException('INVALID_MEDIA_RELATION');
        }
        $id = brvtal_media_relation_id($relation['related_id'] ?? null);
        $key = $type . ':' . $id;
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = [
            'related_type' => $type,
            'related_id' => $id,
            'sort_order' => count($out),
        ];
    }
    return $out;
}

/** Verify and lock every polymorphic target in deterministic order. */
function brvtal_media_lock_relation_targets(PDO $pdo, array $relations): void
{
    if ($relations === []) return;

    $targets = brvtal_media_relation_targets();
    $grouped = [];
    foreach ($relations as $relation) {
        if (!is_array($relation)) {
            throw new InvalidArgumentException('INVALID_MEDIA_RELATION');
        }
        $type = strtolower(trim((string)($relation['related_type'] ?? '')));
        if (!isset($targets[$type])) {
            throw new InvalidArgumentException('INVALID_MEDIA_RELATION');
        }
        $id = brvtal_media_relation_id($relation['related_id'] ?? null);
        $grouped[$type][$id] = $id;
    }

    foreach ($targets as $type => $definition) {
        if (empty($grouped[$type])) continue;
        $ids = array_values($grouped[$type]);
        sort($ids, SORT_NUMERIC);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $table = $definition['table'];
        $statement = $pdo->prepare("SELECT id FROM {$table} WHERE id IN ({$marks}) ORDER BY id FOR UPDATE");
        $statement->execute($ids);
        $found = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        sort($found, SORT_NUMERIC);
        if ($found !== $ids) {
            throw new InvalidArgumentException('MEDIA_RELATION_NOT_FOUND');
        }
    }
}

/** @return array<int,array{related_type:string,related_id:int,sort_order:int}> */
function brvtal_media_load_relations(PDO $pdo, int $mediaId): array
{
    if ($mediaId < 1 || !brvtal_media_relations_ready($pdo)) return [];
    $statement = $pdo->prepare(
        'SELECT related_type,related_id,sort_order FROM media_relations WHERE media_id=? ORDER BY sort_order,related_type,related_id'
    );
    $statement->execute([$mediaId]);
    $rows = $statement->fetchAll() ?: [];
    foreach ($rows as &$row) {
        $row['related_id'] = (int)$row['related_id'];
        $row['sort_order'] = (int)$row['sort_order'];
    }
    unset($row);
    return $rows;
}

/**
 * Return a compact admin-only catalog for the relation editor.
 * Draft targets remain editable in DISCADMIN; public filtering happens separately.
 */
function brvtal_media_relation_catalog(PDO $pdo): array
{
    $catalog = [];
    foreach (brvtal_media_relation_targets() as $type => $definition) {
        $table = $definition['table'];
        $label = $definition['label'];
        $rows = $pdo->query("SELECT id,`{$label}` AS label,status FROM `{$table}` ORDER BY `{$label}`,id")->fetchAll() ?: [];
        $catalog[$type] = array_map(static fn(array $row): array => [
            'id' => (int)$row['id'],
            'label' => (string)($row['label'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
        ], $rows);
    }
    return $catalog;
}

/** Replace all semantic relations for one Media asset inside the caller's transaction. */
function brvtal_media_replace_relations(PDO $pdo, int $mediaId, array $relations): void
{
    if ($mediaId < 1) {
        throw new InvalidArgumentException('INVALID_MEDIA_ID');
    }
    if (!brvtal_media_relations_ready($pdo)) {
        throw new RuntimeException('MEDIA_RELATIONS_NOT_READY');
    }

    $normalized = brvtal_media_normalize_relations($relations);
    brvtal_media_lock_relation_targets($pdo, $normalized);

    $delete = $pdo->prepare('DELETE FROM media_relations WHERE media_id=?');
    $delete->execute([$mediaId]);
    if ($normalized === []) return;

    $insert = $pdo->prepare(
        'INSERT INTO media_relations(media_id,related_type,related_id,sort_order) VALUES(?,?,?,?)'
    );
    foreach ($normalized as $relation) {
        $insert->execute([
            $mediaId,
            $relation['related_type'],
            $relation['related_id'],
            $relation['sort_order'],
        ]);
    }
}
