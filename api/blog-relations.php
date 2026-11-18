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
 * Parse a Blog related-content ID without PHP's lossy scalar coercions.
 * Only a positive integer or canonical integer string is accepted.
 */
function brvtal_blog_relation_id(mixed $value): int
{
    if (is_bool($value) || is_float($value) || (!is_int($value) && !is_string($value))) {
        throw new InvalidArgumentException('INVALID_BLOG_RELATION');
    }

    $validated = filter_var($value, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    if ($validated === false) {
        throw new InvalidArgumentException('INVALID_BLOG_RELATION');
    }

    return (int)$validated;
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
        if (!isset($tables[$type])) {
            throw new InvalidArgumentException('INVALID_BLOG_RELATION');
        }
        $id = brvtal_blog_relation_id($relation['related_id'] ?? null);
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
