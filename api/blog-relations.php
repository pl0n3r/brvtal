<?php
declare(strict_types=1);

/** @return array<string,string> */
function brvtal_blog_relation_tables(): array
{
    return [
        'event' => 'events',
        'artist' => 'artists',
        'set' => 'sets_media',
        'release' => 'releases',
    ];
}

/**
 * Verify and lock every polymorphic Blog relation target for the current
 * transaction. Fixed table names plus sorted IDs keep the query safe and the
 * lock order deterministic. Missing targets reject the whole Blog mutation.
 */
function brvtal_blog_lock_relation_targets(PDO $pdo, array $relations): void
{
    if ($relations === []) return;

    $tables = brvtal_blog_relation_tables();
    $grouped = [];
    foreach ($relations as $relation) {
        if (!is_array($relation)) {
            throw new InvalidArgumentException('INVALID_BLOG_RELATION');
        }
        $type = strtolower(trim((string)($relation['related_type'] ?? '')));
        $id = (int)($relation['related_id'] ?? 0);
        if (!isset($tables[$type]) || $id < 1) {
            throw new InvalidArgumentException('INVALID_BLOG_RELATION');
        }
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
        if ($found !== $ids) {
            throw new InvalidArgumentException('BLOG_RELATION_NOT_FOUND');
        }
    }
}
