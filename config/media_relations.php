<?php
declare(strict_types=1);

/**
 * Structured cultural relationships for reusable Media.
 *
 * The bridge is intentionally optional at runtime so source can deploy before
 * the additive migration is applied. Writes require the table; reads degrade to
 * an empty relation set when the migration is still pending.
 */

function brvtal_media_relations_table_exists(PDO $pdo): bool
{
    try {
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES " .
            "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='media_relations'"
        );
        $st->execute();
        return (int)$st->fetchColumn() === 1;
    } catch (Throwable) {
        return false;
    }
}

/** @return array<string,array{table:string,title:string,slug:string}> */
function brvtal_media_relation_targets(): array
{
    return [
        'event' => ['table'=>'events', 'title'=>'title', 'slug'=>'slug'],
        'artist' => ['table'=>'artists', 'title'=>'name', 'slug'=>'slug'],
        'set' => ['table'=>'sets_media', 'title'=>'title', 'slug'=>'slug'],
        'release' => ['table'=>'releases', 'title'=>'title', 'slug'=>'slug'],
    ];
}

function brvtal_media_relation_id(mixed $value): int
{
    if (is_int($value)) {
        if ($value > 0) return $value;
        throw new InvalidArgumentException('INVALID_MEDIA_RELATION');
    }
    if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value)) {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if (is_int($id) && $id > 0) return $id;
    }
    throw new InvalidArgumentException('INVALID_MEDIA_RELATION');
}

/** @return array<int,array{related_type:string,related_id:int,sort_order:int}> */
function brvtal_media_relations_normalize(mixed $input): array
{
    if (!is_array($input)) {
        throw new InvalidArgumentException('INVALID_MEDIA_RELATIONS');
    }

    $allowed = brvtal_media_relation_targets();
    $rows = [];
    $seen = [];
    foreach ($input as $index => $relation) {
        if (!is_array($relation)) {
            throw new InvalidArgumentException('INVALID_MEDIA_RELATION');
        }
        $type = strtolower(trim((string)($relation['related_type'] ?? '')));
        if (!isset($allowed[$type])) {
            throw new InvalidArgumentException('INVALID_MEDIA_RELATION');
        }
        $id = brvtal_media_relation_id($relation['related_id'] ?? null);
        $key = $type . ':' . $id;
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $sort = array_key_exists('sort_order', $relation)
            ? filter_var($relation['sort_order'], FILTER_VALIDATE_INT)
            : $index;
        if ($sort === false) {
            throw new InvalidArgumentException('INVALID_MEDIA_RELATION');
        }
        $rows[] = [
            'related_type'=>$type,
            'related_id'=>$id,
            'sort_order'=>(int)$sort,
        ];
    }

    usort($rows, static fn(array $a, array $b): int =>
        ($a['sort_order'] <=> $b['sort_order'])
        ?: strcmp($a['related_type'], $b['related_type'])
        ?: ($a['related_id'] <=> $b['related_id'])
    );
    return $rows;
}

/** @return array<int,array{related_type:string,related_id:int,sort_order:int}> */
function brvtal_media_relations_for_media(PDO $pdo, int $mediaId): array
{
    if ($mediaId < 1 || !brvtal_media_relations_table_exists($pdo)) return [];
    $st = $pdo->prepare(
        'SELECT related_type,related_id,sort_order FROM media_relations ' .
        'WHERE media_id=? ORDER BY sort_order,related_type,related_id'
    );
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

/** @return array<int,array{media_id:int,related_type:string,related_id:int,sort_order:int}> */
function brvtal_media_relation_rows(PDO $pdo, array $mediaIds): array
{
    if (!brvtal_media_relations_table_exists($pdo)) return [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $mediaIds), static fn(int $id): bool => $id > 0)));
    if ($ids === []) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare(
        "SELECT media_id,related_type,related_id,sort_order FROM media_relations " .
        "WHERE media_id IN ({$placeholders}) ORDER BY media_id,sort_order,related_type,related_id"
    );
    $st->execute($ids);
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $rows[] = [
            'media_id'=>(int)$row['media_id'],
            'related_type'=>(string)$row['related_type'],
            'related_id'=>(int)$row['related_id'],
            'sort_order'=>(int)$row['sort_order'],
        ];
    }
    return $rows;
}

/**
 * Lock every referenced target so a relation set cannot be committed against a
 * target concurrently deleted by another admin transaction.
 */
function brvtal_media_lock_relation_targets(PDO $pdo, array $relations): void
{
    $targets = brvtal_media_relation_targets();
    foreach (brvtal_media_relations_normalize($relations) as $relation) {
        $definition = $targets[$relation['related_type']];
        $table = $definition['table'];
        $st = $pdo->prepare("SELECT id FROM `{$table}` WHERE id=? FOR UPDATE");
        $st->execute([$relation['related_id']]);
        if ($st->fetchColumn() === false) {
            throw new InvalidArgumentException('MEDIA_RELATION_NOT_FOUND');
        }
    }
}

/**
 * Replace one Media record's full relation set. Caller owns the transaction.
 *
 * @return array<int,array{related_type:string,related_id:int,sort_order:int}>
 */
function brvtal_media_replace_relations(PDO $pdo, int $mediaId, array $relations): array
{
    if ($mediaId < 1) throw new InvalidArgumentException('MEDIA_ID_REQUIRED');
    if (!brvtal_media_relations_table_exists($pdo)) {
        throw new RuntimeException('MEDIA_RELATIONS_MIGRATION_REQUIRED');
    }
    $normalized = brvtal_media_relations_normalize($relations);
    brvtal_media_lock_relation_targets($pdo, $normalized);

    $delete = $pdo->prepare('DELETE FROM media_relations WHERE media_id=?');
    $delete->execute([$mediaId]);
    if ($normalized !== []) {
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
    return $normalized;
}

/** @return array<int,array{related_type:string,related_id:int,title:string,slug:string,status:string}> */
function brvtal_media_relation_options(PDO $pdo): array
{
    $targets = brvtal_media_relation_targets();
    $options = [];
    foreach ($targets as $type => $definition) {
        $table = $definition['table'];
        $title = $definition['title'];
        $slug = $definition['slug'];
        try {
            $st = $pdo->query(
                "SELECT id,`{$title}` AS title,`{$slug}` AS slug,status FROM `{$table}` ORDER BY `{$title}`,id"
            );
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $options[] = [
                    'related_type'=>$type,
                    'related_id'=>(int)$row['id'],
                    'title'=>(string)($row['title'] ?? ''),
                    'slug'=>(string)($row['slug'] ?? ''),
                    'status'=>(string)($row['status'] ?? ''),
                ];
            }
        } catch (Throwable) {
            // Optional/newer entity tables should not make Media Library unusable.
        }
    }
    return $options;
}
