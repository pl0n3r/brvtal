<?php
declare(strict_types=1);

/** @return array<string,array{table:string,label:string,slug:string,status:string}> */
function brvtal_media_relation_targets(): array
{
    return [
        'event' => ['table'=>'events','label'=>'title','slug'=>'slug','status'=>'status'],
        'artist' => ['table'=>'artists','label'=>'name','slug'=>'slug','status'=>'status'],
        'set' => ['table'=>'sets_media','label'=>'title','slug'=>'slug','status'=>'status'],
        'release' => ['table'=>'releases','label'=>'title','slug'=>'slug','status'=>'status'],
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

function brvtal_media_relation_id(mixed $value): int
{
    if (is_bool($value) || is_float($value) || (!is_int($value) && !is_string($value))) {
        throw new InvalidArgumentException('INVALID_MEDIA_RELATION');
    }
    $validated = filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if ($validated === false) {
        throw new InvalidArgumentException('INVALID_MEDIA_RELATION');
    }
    return (int)$validated;
}

/** @return array<int,array{related_type:string,related_id:int,sort_order:int}> */
function brvtal_media_relations_normalize(mixed $input): array
{
    if ($input === null) return [];
    if (!is_array($input)) throw new InvalidArgumentException('INVALID_MEDIA_RELATIONS');

    $allowed = brvtal_media_relation_targets();
    $out = [];
    $seen = [];
    foreach (array_values($input) as $index => $relation) {
        if (!is_array($relation)) throw new InvalidArgumentException('INVALID_MEDIA_RELATION');
        $type = strtolower(trim((string)($relation['related_type'] ?? '')));
        if (!isset($allowed[$type])) throw new InvalidArgumentException('INVALID_MEDIA_RELATION');
        $id = brvtal_media_relation_id($relation['related_id'] ?? null);
        $key = $type . ':' . $id;
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = ['related_type'=>$type,'related_id'=>$id,'sort_order'=>$index];
    }
    return $out;
}

/** Verify and lock every relation target inside the caller's transaction. */
function brvtal_media_relations_lock_targets(PDO $pdo, array $relations): void
{
    if ($relations === []) return;
    $targets = brvtal_media_relation_targets();
    $grouped = [];
    foreach ($relations as $relation) {
        if (!is_array($relation)) throw new InvalidArgumentException('INVALID_MEDIA_RELATION');
        $type = strtolower(trim((string)($relation['related_type'] ?? '')));
        if (!isset($targets[$type])) throw new InvalidArgumentException('INVALID_MEDIA_RELATION');
        $id = brvtal_media_relation_id($relation['related_id'] ?? null);
        $grouped[$type][$id] = $id;
    }

    foreach ($targets as $type => $meta) {
        if (empty($grouped[$type])) continue;
        $ids = array_values($grouped[$type]);
        sort($ids, SORT_NUMERIC);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $table = $meta['table'];
        $st = $pdo->prepare("SELECT id FROM {$table} WHERE id IN ({$marks}) ORDER BY id FOR UPDATE");
        $st->execute($ids);
        $found = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        sort($found, SORT_NUMERIC);
        if ($found !== $ids) throw new InvalidArgumentException('MEDIA_RELATION_NOT_FOUND');
    }
}

/** @return array<int,array{related_type:string,related_id:int,sort_order:int}> */
function brvtal_media_relations_list(PDO $pdo, int $mediaId): array
{
    if (!brvtal_media_relations_ready($pdo)) return [];
    $st = $pdo->prepare('SELECT related_type,related_id,sort_order FROM media_relations WHERE media_id=? ORDER BY sort_order,related_type,related_id');
    $st->execute([$mediaId]);
    $rows = $st->fetchAll() ?: [];
    return array_map(static fn(array $row): array => [
        'related_type'=>(string)$row['related_type'],
        'related_id'=>(int)$row['related_id'],
        'sort_order'=>(int)$row['sort_order'],
    ], $rows);
}

function brvtal_media_relations_replace(PDO $pdo, int $mediaId, array $relations): void
{
    if (!brvtal_media_relations_ready($pdo)) throw new RuntimeException('MEDIA_RELATIONS_MIGRATION_REQUIRED');
    brvtal_media_relations_lock_targets($pdo, $relations);
    $delete = $pdo->prepare('DELETE FROM media_relations WHERE media_id=?');
    $delete->execute([$mediaId]);
    if ($relations === []) return;
    $insert = $pdo->prepare('INSERT INTO media_relations(media_id,related_type,related_id,sort_order) VALUES(?,?,?,?)');
    foreach ($relations as $relation) {
        $insert->execute([$mediaId,$relation['related_type'],$relation['related_id'],$relation['sort_order']]);
    }
}

/** Admin-only relation choices; includes draft targets so editors can prepare future archives. */
function brvtal_media_relation_options(PDO $pdo): array
{
    $out = [];
    foreach (brvtal_media_relation_targets() as $type => $meta) {
        $table = $meta['table'];
        $label = $meta['label'];
        $slug = $meta['slug'];
        $status = $meta['status'];
        try {
            $rows = $pdo->query("SELECT id,{$label} AS label,{$slug} AS slug,{$status} AS status FROM {$table} ORDER BY {$label},id")->fetchAll() ?: [];
        } catch (Throwable) {
            $rows = [];
        }
        $out[$type] = array_map(static fn(array $row): array => [
            'id'=>(int)$row['id'],
            'label'=>(string)$row['label'],
            'slug'=>(string)($row['slug'] ?? ''),
            'status'=>(string)($row['status'] ?? ''),
        ], $rows);
    }
    return $out;
}
