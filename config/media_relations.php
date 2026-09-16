<?php
declare(strict_types=1);

/** Structured cultural-memory relations. No inference is permitted. */
function brvtal_media_relations_table_exists(PDO $pdo): bool
{
    try {
        $st = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='media_relations'");
        return (int)$st->fetchColumn() === 1;
    } catch (Throwable) {
        return false;
    }
}

/** @return array<string,array{table:string,title:string,status:string}> */
function brvtal_media_relation_targets(): array
{
    return [
        'event' => ['table'=>'events','title'=>'title','status'=>'status'],
        'artist' => ['table'=>'artists','title'=>'name','status'=>'status'],
        'set' => ['table'=>'sets_media','title'=>'title','status'=>'status'],
        'release' => ['table'=>'releases','title'=>'title','status'=>'status'],
    ];
}

function brvtal_media_relation_target_exists(PDO $pdo, string $type, int $id): bool
{
    $targets = brvtal_media_relation_targets();
    if ($id < 1 || !isset($targets[$type])) return false;
    $table = $targets[$type]['table'];
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE id=?");
        $st->execute([$id]);
        return (int)$st->fetchColumn() === 1;
    } catch (Throwable) {
        return false;
    }
}

/** @return array<int,array{related_type:string,related_id:int,sort_order:int}> */
function brvtal_media_relations_for(PDO $pdo, int $mediaId): array
{
    if ($mediaId < 1 || !brvtal_media_relations_table_exists($pdo)) return [];
    $st = $pdo->prepare('SELECT related_type,related_id,sort_order FROM media_relations WHERE media_id=? ORDER BY sort_order,related_type,related_id');
    $st->execute([$mediaId]);
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $rows[] = [
            'related_type'=>(string)$row['related_type'],
            'related_id'=>(int)$row['related_id'],
            'sort_order'=>(int)$row['sort_order'],
        ];
    }
    return $rows;
}

/** Admin-only selectable target labels. Draft records are intentionally available to editors. */
function brvtal_media_relation_options(PDO $pdo): array
{
    $out = [];
    foreach (brvtal_media_relation_targets() as $type => $definition) {
        $table = $definition['table'];
        $title = $definition['title'];
        try {
            $rows = $pdo->query("SELECT id,`{$title}` AS title,`{$definition['status']}` AS status FROM `{$table}` ORDER BY `{$title}` ASC,id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            $rows = [];
        }
        $out[$type] = array_map(static fn(array $row): array => [
            'id'=>(int)$row['id'],
            'title'=>(string)($row['title'] ?? ''),
            'status'=>(string)($row['status'] ?? ''),
        ], $rows);
    }
    return $out;
}

/**
 * Replace one Media row's relation set atomically.
 * @param array<int,array{related_type:mixed,related_id:mixed,sort_order?:mixed}> $relations
 */
function brvtal_media_relations_replace(PDO $pdo, int $mediaId, array $relations): array
{
    if ($mediaId < 1 || !brvtal_media_relations_table_exists($pdo)) {
        throw new RuntimeException('MEDIA_RELATIONS_MIGRATION_REQUIRED');
    }
    if (count($relations) > 64) throw new InvalidArgumentException('TOO_MANY_MEDIA_RELATIONS');

    $normalized = [];
    $seen = [];
    foreach ($relations as $index => $relation) {
        if (!is_array($relation)) throw new InvalidArgumentException('INVALID_MEDIA_RELATION');
        $type = strtolower(trim((string)($relation['related_type'] ?? '')));
        $id = filter_var($relation['related_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if ($id === false || !brvtal_media_relation_target_exists($pdo, $type, (int)$id)) {
            throw new InvalidArgumentException('INVALID_MEDIA_RELATION_TARGET');
        }
        $key = $type . ':' . (int)$id;
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $normalized[] = ['related_type'=>$type,'related_id'=>(int)$id,'sort_order'=>$index];
    }

    $started = !$pdo->inTransaction();
    if ($started) $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT id FROM media WHERE id=? FOR UPDATE');
        $lock->execute([$mediaId]);
        if ($lock->fetchColumn() === false) throw new RuntimeException('MEDIA_NOT_FOUND');
        $delete = $pdo->prepare('DELETE FROM media_relations WHERE media_id=?');
        $delete->execute([$mediaId]);
        $insert = $pdo->prepare('INSERT INTO media_relations(media_id,related_type,related_id,sort_order) VALUES(?,?,?,?)');
        foreach ($normalized as $relation) {
            $insert->execute([$mediaId,$relation['related_type'],$relation['related_id'],$relation['sort_order']]);
        }
        if ($started) $pdo->commit();
    } catch (Throwable $e) {
        if ($started && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $normalized;
}
