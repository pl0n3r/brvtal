<?php
declare(strict_types=1);

/**
 * Decorate public Media with explicit, privacy-safe cultural relations.
 * Missing migration remains backwards-compatible: Media is returned unchanged.
 */
function brvtal_public_media_relations(
    PDO $pdo,
    array $media,
    array $events,
    array $archiveEvents,
    array $artists,
    array $sets,
    array $releases
): array {
    try {
        $exists = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='media_relations'");
        if ((int)$exists->fetchColumn() !== 1) return $media;
    } catch (Throwable) {
        return $media;
    }

    $pools = [
        'event' => ['route_type'=>'events','items'=>array_merge($events, $archiveEvents)],
        'artist' => ['route_type'=>'artists','items'=>$artists],
        'set' => ['route_type'=>'sets','items'=>$sets],
        'release' => ['route_type'=>'releases','items'=>$releases],
    ];
    $lookup = [];
    foreach ($pools as $type => $pool) {
        foreach ($pool['items'] as $item) {
            $id = (int)($item['id'] ?? 0);
            if ($id < 1) continue;
            $title = (string)($item['title'] ?? $item['name'] ?? '');
            $meta = '';
            if ($type === 'event') {
                $date = trim((string)($item['event_date'] ?? ''));
                $city = trim((string)($item['city'] ?? ''));
                $meta = implode(' / ', array_filter([
                    $date !== '' ? date('d.m.Y', strtotime($date)) : '',
                    $city,
                ]));
            } elseif ($type === 'set') {
                $meta = strtoupper(trim((string)($item['platform'] ?? '')));
            } elseif ($type === 'release') {
                $meta = implode(' / ', array_filter([
                    strtoupper(trim((string)($item['release_type'] ?? ''))),
                    trim((string)($item['release_date'] ?? '')),
                ]));
            }
            $lookup[$type . ':' . $id] = [
                'related_type'=>$type,
                'related_id'=>$id,
                'route_type'=>$pool['route_type'],
                'title'=>$title,
                'slug'=>(string)($item['slug'] ?? ''),
                'meta'=>$meta,
            ];
        }
    }

    $mediaIds = [];
    foreach ($media as $item) {
        $id = (int)($item['id'] ?? 0);
        if ($id > 0) $mediaIds[$id] = true;
    }
    if ($mediaIds === []) return $media;

    $placeholders = implode(',', array_fill(0, count($mediaIds), '?'));
    $st = $pdo->prepare("SELECT media_id,related_type,related_id,sort_order FROM media_relations WHERE media_id IN ({$placeholders}) ORDER BY media_id,sort_order,related_type,related_id");
    $st->execute(array_keys($mediaIds));
    $byMedia = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $key = (string)$row['related_type'] . ':' . (int)$row['related_id'];
        if (!isset($lookup[$key])) continue;
        $relation = $lookup[$key];
        if ($relation['slug'] === '' || $relation['title'] === '') continue;
        $relation['sort_order'] = (int)$row['sort_order'];
        $byMedia[(int)$row['media_id']][] = $relation;
    }

    foreach ($media as &$item) {
        $item['relations'] = $byMedia[(int)($item['id'] ?? 0)] ?? [];
    }
    unset($item);
    return $media;
}
