<?php
declare(strict_types=1);

/**
 * Builds BRVTAL's public related-content graph from content that is already
 * eligible for public delivery. This layer never decides publication state;
 * callers must pass only public entities.
 */

function brvtal_public_relation_id_set(array $items): array
{
    $set = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $id = (int)($item['id'] ?? 0);
        if ($id > 0) $set[$id] = true;
    }
    return $set;
}

function brvtal_public_relation_add(array &$items, int $id): void
{
    if ($id < 1 || in_array($id, $items, true)) return;
    $items[] = $id;
}

function brvtal_public_sanitize_set_relations(
    array $sets,
    array $artists,
    array $activeEvents,
    array $archiveEvents
): array {
    $artistMap = [];
    foreach ($artists as $artist) {
        if (!is_array($artist)) continue;
        $id = (int)($artist['id'] ?? 0);
        if ($id > 0) {
            $artistMap[$id] = [
                'name' => (string)($artist['name'] ?? ''),
                'slug' => (string)($artist['slug'] ?? ''),
            ];
        }
    }

    $eventMap = [];
    foreach (array_merge($activeEvents, $archiveEvents) as $event) {
        if (!is_array($event)) continue;
        $id = (int)($event['id'] ?? 0);
        if ($id > 0) {
            $eventMap[$id] = [
                'title' => (string)($event['title'] ?? ''),
                'slug' => (string)($event['slug'] ?? ''),
            ];
        }
    }

    foreach ($sets as &$set) {
        if (!is_array($set)) continue;

        $artistId = (int)($set['artist_id'] ?? 0);
        if ($artistId < 1 || !isset($artistMap[$artistId])) {
            $set['artist_id'] = null;
            $set['artist_name'] = null;
            $set['artist_slug'] = null;
        } else {
            $set['artist_id'] = $artistId;
            $set['artist_name'] = $artistMap[$artistId]['name'];
            $set['artist_slug'] = $artistMap[$artistId]['slug'];
        }

        $eventId = (int)($set['event_id'] ?? 0);
        if ($eventId < 1 || !isset($eventMap[$eventId])) {
            $set['event_id'] = null;
            $set['event_title'] = null;
            $set['event_slug'] = null;
        } else {
            $set['event_id'] = $eventId;
            $set['event_title'] = $eventMap[$eventId]['title'];
            $set['event_slug'] = $eventMap[$eventId]['slug'];
        }
    }
    unset($set);

    return $sets;
}

/**
 * Blog relations carry only type/id references. Keep them only when the
 * target exists in the same final public pools that power the public API.
 * This prevents draft/private/deleted targets from leaking internal IDs.
 */
function brvtal_public_sanitize_blog_relations(
    array $posts,
    array $activeEvents,
    array $archiveEvents,
    array $artists,
    array $sets,
    array $releases
): array {
    $allowed = [
        'event' => brvtal_public_relation_id_set(array_merge($activeEvents, $archiveEvents)),
        'artist' => brvtal_public_relation_id_set($artists),
        'set' => brvtal_public_relation_id_set($sets),
        'release' => brvtal_public_relation_id_set($releases),
    ];

    foreach ($posts as &$post) {
        if (!is_array($post)) continue;
        $relations = is_array($post['relations'] ?? null) ? $post['relations'] : [];
        $post['relations'] = array_values(array_filter(
            $relations,
            static function (mixed $relation) use ($allowed): bool {
                if (!is_array($relation)) return false;
                $type = strtolower(trim((string)($relation['related_type'] ?? '')));
                $id = (int)($relation['related_id'] ?? 0);
                return $id > 0 && isset($allowed[$type][$id]);
            }
        ));
    }
    unset($post);

    return $posts;
}

/** Build display metadata only from entities already admitted to public delivery. */
function brvtal_public_memory_target_maps(
    array $activeEvents,
    array $archiveEvents,
    array $artists,
    array $sets,
    array $releases
): array {
    $maps = ['event'=>[], 'artist'=>[], 'set'=>[], 'release'=>[]];

    foreach (array_merge($activeEvents, $archiveEvents) as $item) {
        if (!is_array($item)) continue;
        $id = (int)($item['id'] ?? 0);
        if ($id > 0) $maps['event'][$id] = [
            'title'=>(string)($item['title'] ?? ''),
            'slug'=>(string)($item['slug'] ?? ''),
            'route_type'=>'events',
            'image'=>(string)($item['cover_image'] ?? ''),
        ];
    }
    foreach ($artists as $item) {
        if (!is_array($item)) continue;
        $id = (int)($item['id'] ?? 0);
        if ($id > 0) $maps['artist'][$id] = [
            'title'=>(string)($item['name'] ?? ''),
            'slug'=>(string)($item['slug'] ?? ''),
            'route_type'=>'artists',
            'image'=>(string)($item['photo'] ?? ''),
        ];
    }
    foreach ($sets as $item) {
        if (!is_array($item)) continue;
        $id = (int)($item['id'] ?? 0);
        if ($id > 0) $maps['set'][$id] = [
            'title'=>(string)($item['title'] ?? ''),
            'slug'=>(string)($item['slug'] ?? ''),
            'route_type'=>'sets',
            'image'=>(string)($item['cover_image'] ?? ''),
        ];
    }
    foreach ($releases as $item) {
        if (!is_array($item)) continue;
        $id = (int)($item['id'] ?? 0);
        if ($id > 0) $maps['release'][$id] = [
            'title'=>(string)($item['title'] ?? ''),
            'slug'=>(string)($item['slug'] ?? ''),
            'route_type'=>'releases',
            'image'=>(string)($item['artwork'] ?? ''),
        ];
    }
    return $maps;
}

/**
 * Attach explicit Memory relations without exposing relation targets outside
 * the final public entity pools. Missing migration is a supported state.
 */
function brvtal_public_sanitize_media_relations(
    PDO $pdo,
    array $media,
    array $activeEvents,
    array $archiveEvents,
    array $artists,
    array $sets,
    array $releases
): array {
    foreach ($media as &$item) {
        if (is_array($item)) $item['relations'] = [];
    }
    unset($item);
    if ($media === []) return [];

    try {
        $exists = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='media_relations'"
        );
        if ((int)$exists->fetchColumn() < 1) return $media;
    } catch (Throwable) {
        return $media;
    }

    $mediaIndexes = [];
    foreach ($media as $index => $item) {
        if (!is_array($item)) continue;
        $id = (int)($item['id'] ?? 0);
        if ($id > 0) $mediaIndexes[$id] = $index;
    }
    if ($mediaIndexes === []) return $media;

    $targets = brvtal_public_memory_target_maps($activeEvents, $archiveEvents, $artists, $sets, $releases);
    $rows = $pdo->query(
        "SELECT rel.media_id,rel.related_type,rel.related_id,rel.sort_order
         FROM media_relations rel
         JOIN media m ON m.id=rel.media_id AND m.status='published'
         ORDER BY rel.media_id,rel.sort_order,rel.related_type,rel.related_id"
    )->fetchAll();

    foreach ($rows ?: [] as $row) {
        $mediaId = (int)($row['media_id'] ?? 0);
        $type = strtolower(trim((string)($row['related_type'] ?? '')));
        $targetId = (int)($row['related_id'] ?? 0);
        if (!isset($mediaIndexes[$mediaId], $targets[$type][$targetId])) continue;
        $target = $targets[$type][$targetId];
        $media[$mediaIndexes[$mediaId]]['relations'][] = [
            'related_type' => $type,
            'related_id' => $targetId,
            'sort_order' => (int)($row['sort_order'] ?? 0),
            'title' => $target['title'],
            'slug' => $target['slug'],
            'route_type' => $target['route_type'],
            'image' => $target['image'],
        ];
    }
    return $media;
}

function brvtal_public_related_graph(
    array $activeEvents,
    array $archiveEvents,
    array $artists,
    array $sets,
    array $releases,
    array $media = []
): array {
    $events = array_merge($activeEvents, $archiveEvents);
    $eventIds = brvtal_public_relation_id_set($events);
    $artistIds = brvtal_public_relation_id_set($artists);
    $setIds = brvtal_public_relation_id_set($sets);
    $releaseIds = brvtal_public_relation_id_set($releases);
    $memoryIds = brvtal_public_relation_id_set($media);

    $graph = [
        'events' => [],
        'artists' => [],
        'sets' => [],
        'releases' => [],
        'memories' => [],
        'counts' => [
            'event_artist' => 0,
            'event_set' => 0,
            'artist_set' => 0,
            'artist_release' => 0,
            'event_memory' => 0,
            'artist_memory' => 0,
            'set_memory' => 0,
            'release_memory' => 0,
        ],
    ];

    foreach (array_keys($eventIds) as $id) {
        $graph['events'][(string)$id] = ['artists' => [], 'sets' => [], 'memories' => []];
    }
    foreach (array_keys($artistIds) as $id) {
        $graph['artists'][(string)$id] = ['events' => [], 'sets' => [], 'releases' => [], 'memories' => []];
    }
    foreach (array_keys($setIds) as $id) {
        $graph['sets'][(string)$id] = ['artist' => null, 'event' => null, 'memories' => []];
    }
    foreach (array_keys($releaseIds) as $id) {
        $graph['releases'][(string)$id] = ['artists' => [], 'memories' => []];
    }
    foreach (array_keys($memoryIds) as $id) {
        $graph['memories'][(string)$id] = ['events'=>[], 'artists'=>[], 'sets'=>[], 'releases'=>[]];
    }

    foreach ($events as $event) {
        if (!is_array($event)) continue;
        $eventId = (int)($event['id'] ?? 0);
        if ($eventId < 1 || !isset($eventIds[$eventId])) continue;

        $lineup = is_array($event['lineup'] ?? null) ? $event['lineup'] : [];
        foreach ($lineup as $participant) {
            if (!is_array($participant)) continue;
            $artistId = (int)($participant['artist_id'] ?? 0);
            if ($artistId < 1 || !isset($artistIds[$artistId])) continue;

            $beforeEvent = count($graph['events'][(string)$eventId]['artists']);
            brvtal_public_relation_add($graph['events'][(string)$eventId]['artists'], $artistId);
            brvtal_public_relation_add($graph['artists'][(string)$artistId]['events'], $eventId);
            if (count($graph['events'][(string)$eventId]['artists']) > $beforeEvent) {
                $graph['counts']['event_artist']++;
            }
        }
    }

    foreach ($sets as $set) {
        if (!is_array($set)) continue;
        $setId = (int)($set['id'] ?? 0);
        if ($setId < 1 || !isset($setIds[$setId])) continue;

        $artistId = (int)($set['artist_id'] ?? 0);
        if ($artistId > 0 && isset($artistIds[$artistId])) {
            $graph['sets'][(string)$setId]['artist'] = $artistId;
            $beforeArtistSet = count($graph['artists'][(string)$artistId]['sets']);
            brvtal_public_relation_add($graph['artists'][(string)$artistId]['sets'], $setId);
            if (count($graph['artists'][(string)$artistId]['sets']) > $beforeArtistSet) {
                $graph['counts']['artist_set']++;
            }
        }

        $eventId = (int)($set['event_id'] ?? 0);
        if ($eventId > 0 && isset($eventIds[$eventId])) {
            $graph['sets'][(string)$setId]['event'] = $eventId;
            $beforeEventSet = count($graph['events'][(string)$eventId]['sets']);
            brvtal_public_relation_add($graph['events'][(string)$eventId]['sets'], $setId);
            if (count($graph['events'][(string)$eventId]['sets']) > $beforeEventSet) {
                $graph['counts']['event_set']++;
            }
        }
    }

    foreach ($releases as $release) {
        if (!is_array($release)) continue;
        $releaseId = (int)($release['id'] ?? 0);
        if ($releaseId < 1 || !isset($releaseIds[$releaseId])) continue;

        $releaseArtists = is_array($release['artists'] ?? null) ? $release['artists'] : [];
        foreach ($releaseArtists as $artist) {
            if (!is_array($artist)) continue;
            $artistId = (int)($artist['artist_id'] ?? 0);
            if ($artistId < 1 || !isset($artistIds[$artistId])) continue;

            $beforeRelease = count($graph['releases'][(string)$releaseId]['artists']);
            brvtal_public_relation_add($graph['releases'][(string)$releaseId]['artists'], $artistId);
            brvtal_public_relation_add($graph['artists'][(string)$artistId]['releases'], $releaseId);
            if (count($graph['releases'][(string)$releaseId]['artists']) > $beforeRelease) {
                $graph['counts']['artist_release']++;
            }
        }
    }

    $memoryCountKeys = [
        'event' => 'event_memory',
        'artist' => 'artist_memory',
        'set' => 'set_memory',
        'release' => 'release_memory',
    ];
    $memoryGraphBuckets = [
        'event' => 'events',
        'artist' => 'artists',
        'set' => 'sets',
        'release' => 'releases',
    ];
    foreach ($media as $memory) {
        if (!is_array($memory)) continue;
        $memoryId = (int)($memory['id'] ?? 0);
        if ($memoryId < 1 || !isset($memoryIds[$memoryId])) continue;
        foreach (is_array($memory['relations'] ?? null) ? $memory['relations'] : [] as $relation) {
            if (!is_array($relation)) continue;
            $type = strtolower(trim((string)($relation['related_type'] ?? '')));
            $targetId = (int)($relation['related_id'] ?? 0);
            $bucket = $memoryGraphBuckets[$type] ?? '';
            if ($targetId < 1 || $bucket === '' || !isset($graph[$bucket][(string)$targetId])) continue;

            $before = count($graph[$bucket][(string)$targetId]['memories']);
            brvtal_public_relation_add($graph[$bucket][(string)$targetId]['memories'], $memoryId);
            brvtal_public_relation_add($graph['memories'][(string)$memoryId][$bucket], $targetId);
            if (count($graph[$bucket][(string)$targetId]['memories']) > $before) {
                $graph['counts'][$memoryCountKeys[$type]]++;
            }
        }
    }

    return $graph;
}
