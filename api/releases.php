<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_activity.php';
require_once __DIR__ . '/../config/indexnow.php';

brvtal_admin_require();

function brvtal_releases_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function brvtal_releases_body(): array
{
    $raw = (string)file_get_contents('php://input');
    if ($raw === '') {
        return $_POST;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function brvtal_releases_schema_ready(PDO $pdo): bool
{
    $st = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('releases','release_artists')");
    return (int)$st->fetchColumn() === 2;
}

function brvtal_release_slug(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($converted) && $converted !== '') {
            $value = $converted;
        }
    }
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    return trim($value, '-');
}

function brvtal_release_url(mixed $value, string $field): string
{
    $url = trim((string)$value);
    if ($url === '') {
        return '';
    }
    if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $url)) {
        throw new InvalidArgumentException('INVALID_' . strtoupper($field));
    }
    return $url;
}

function brvtal_release_artwork(mixed $value): string
{
    $path = trim((string)$value);
    if ($path === '') {
        return '';
    }
    if (preg_match('/^https?:\/\//i', $path)) {
        return brvtal_release_url($path, 'artwork');
    }
    if (!str_starts_with($path, '/')) {
        $path = '/' . ltrim($path, '/');
    }
    if (!str_starts_with($path, '/uploads/')) {
        throw new InvalidArgumentException('INVALID_ARTWORK');
    }
    return $path;
}

function brvtal_release_payload(array $input): array
{
    $title = trim((string)($input['title'] ?? ''));
    if ($title === '') {
        throw new InvalidArgumentException('TITLE_REQUIRED');
    }

    $slug = brvtal_release_slug((string)($input['slug'] ?? $title));
    if ($slug === '') {
        throw new InvalidArgumentException('SLUG_REQUIRED');
    }

    $type = strtolower(trim((string)($input['release_type'] ?? 'single')));
    if (!in_array($type, ['single', 'ep', 'album', 'compilation', 'other'], true)) {
        throw new InvalidArgumentException('INVALID_RELEASE_TYPE');
    }

    $status = strtolower(trim((string)($input['status'] ?? 'draft')));
    if (!in_array($status, ['draft', 'published', 'archived'], true)) {
        throw new InvalidArgumentException('INVALID_STATUS');
    }

    $releaseDate = trim((string)($input['release_date'] ?? ''));
    if ($releaseDate !== '') {
        $date = DateTime::createFromFormat('Y-m-d', $releaseDate);
        if (!$date || $date->format('Y-m-d') !== $releaseDate) {
            throw new InvalidArgumentException('INVALID_RELEASE_DATE');
        }
    }

    $artists = [];
    foreach ((array)($input['artists'] ?? []) as $index => $artist) {
        if (!is_array($artist)) {
            continue;
        }
        $artistId = (int)($artist['artist_id'] ?? 0);
        if ($artistId <= 0) {
            continue;
        }
        $artists[$artistId] = [
            'artist_id' => $artistId,
            'role' => mb_substr(trim((string)($artist['role'] ?? 'Primary')), 0, 80),
            'sort_order' => (int)($artist['sort_order'] ?? $index),
        ];
    }

    return [
        'title' => mb_substr($title, 0, 180),
        'slug' => mb_substr($slug, 0, 190),
        'release_type' => $type,
        'catalog_number' => mb_substr(trim((string)($input['catalog_number'] ?? '')), 0, 80),
        'release_date' => $releaseDate !== '' ? $releaseDate : null,
        'description' => trim((string)($input['description'] ?? '')),
        'artwork' => brvtal_release_artwork($input['artwork'] ?? ''),
        'spotify_url' => brvtal_release_url($input['spotify_url'] ?? '', 'spotify_url'),
        'soundcloud_url' => brvtal_release_url($input['soundcloud_url'] ?? '', 'soundcloud_url'),
        'bandcamp_url' => brvtal_release_url($input['bandcamp_url'] ?? '', 'bandcamp_url'),
        'youtube_url' => brvtal_release_url($input['youtube_url'] ?? '', 'youtube_url'),
        'beatport_url' => brvtal_release_url($input['beatport_url'] ?? '', 'beatport_url'),
        'status' => $status,
        'featured' => !empty($input['featured']) ? 1 : 0,
        'sort_order' => (int)($input['sort_order'] ?? 0),
        'artists' => array_values($artists),
    ];
}

function brvtal_release_sync_artists(PDO $pdo, int $releaseId, array $artists): void
{
    $pdo->prepare('DELETE FROM release_artists WHERE release_id=?')->execute([$releaseId]);
    if (!$artists) {
        return;
    }
    $st = $pdo->prepare('INSERT INTO release_artists(release_id,artist_id,role,sort_order) VALUES(?,?,?,?)');
    foreach ($artists as $artist) {
        $st->execute([
            $releaseId,
            (int)$artist['artist_id'],
            (string)$artist['role'],
            (int)$artist['sort_order'],
        ]);
    }
}

function brvtal_release_fetch(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM releases WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $release = $st->fetch();
    if (!$release) {
        return null;
    }
    $artists = $pdo->prepare(
        "SELECT ra.artist_id,ra.role,ra.sort_order,a.name,a.slug,a.photo
         FROM release_artists ra
         JOIN artists a ON a.id=ra.artist_id
         WHERE ra.release_id=?
         ORDER BY ra.sort_order,a.name"
    );
    $artists->execute([$id]);
    $release['id'] = (int)$release['id'];
    $release['featured'] = (int)$release['featured'];
    $release['sort_order'] = (int)$release['sort_order'];
    $release['artists'] = $artists->fetchAll();
    return $release;
}

function brvtal_release_list(PDO $pdo): array
{
    $rows = $pdo->query('SELECT * FROM releases ORDER BY COALESCE(release_date,\'9999-12-31\') DESC, sort_order ASC, id DESC')->fetchAll();
    if (!$rows) {
        return [];
    }
    $artistRows = $pdo->query(
        "SELECT ra.release_id,ra.artist_id,ra.role,ra.sort_order,a.name,a.slug,a.photo
         FROM release_artists ra
         JOIN artists a ON a.id=ra.artist_id
         ORDER BY ra.release_id,ra.sort_order,a.name"
    )->fetchAll();
    $byRelease = [];
    foreach ($artistRows as $artist) {
        $byRelease[(int)$artist['release_id']][] = $artist;
    }
    foreach ($rows as &$row) {
        $id = (int)$row['id'];
        $row['id'] = $id;
        $row['featured'] = (int)$row['featured'];
        $row['sort_order'] = (int)$row['sort_order'];
        $row['artists'] = $byRelease[$id] ?? [];
    }
    unset($row);
    return $rows;
}

try {
    $pdo = db();
    if (!brvtal_releases_schema_ready($pdo)) {
        brvtal_releases_json(['ok' => false, 'error' => 'RELEASES_SCHEMA_MISSING'], 503);
    }

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($method === 'GET') {
        if ($id > 0) {
            $release = brvtal_release_fetch($pdo, $id);
            if (!$release) {
                brvtal_releases_json(['ok' => false, 'error' => 'RELEASE_NOT_FOUND'], 404);
            }
            brvtal_releases_json(['ok' => true, 'data' => $release]);
        }
        brvtal_releases_json(['ok' => true, 'data' => brvtal_release_list($pdo)]);
    }

    if (!in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
        header('Allow: GET, POST, PUT, DELETE');
        brvtal_releases_json(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED'], 405);
    }

    brvtal_admin_require_csrf();

    if ($method === 'DELETE') {
        if ($id <= 0) {
            brvtal_releases_json(['ok' => false, 'error' => 'RELEASE_ID_REQUIRED'], 422);
        }
        $before = brvtal_release_fetch($pdo, $id);
        if (!$before) {
            brvtal_releases_json(['ok' => false, 'error' => 'RELEASE_NOT_FOUND'], 404);
        }
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('DELETE FROM releases WHERE id=?');
            $st->execute([$id]);
            if ($st->rowCount() < 1) throw new RuntimeException('RELEASE_NOT_FOUND', 404);
            brvtal_activity_record($pdo, 'delete', 'releases', $id, $before, null, ['source'=>'releases_api'], (string)$before['title']);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        brvtal_indexnow_notify_transition('releases', $before, null);
        brvtal_releases_json(['ok' => true, 'deleted' => $id]);
    }

    $data = brvtal_release_payload(brvtal_releases_body());
    $before = null;
    $pdo->beginTransaction();
    try {
        if ($method === 'POST') {
            $st = $pdo->prepare(
                "INSERT INTO releases(title,slug,release_type,catalog_number,release_date,description,artwork,spotify_url,soundcloud_url,bandcamp_url,youtube_url,beatport_url,status,featured,sort_order,published_at)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            $st->execute([
                $data['title'],$data['slug'],$data['release_type'],$data['catalog_number'] ?: null,$data['release_date'],$data['description'] ?: null,
                $data['artwork'] ?: null,$data['spotify_url'] ?: null,$data['soundcloud_url'] ?: null,$data['bandcamp_url'] ?: null,$data['youtube_url'] ?: null,$data['beatport_url'] ?: null,
                $data['status'],$data['featured'],$data['sort_order'],$data['status'] === 'published' ? date('Y-m-d H:i:s') : null,
            ]);
            $id = (int)$pdo->lastInsertId();
        } else {
            if ($id <= 0) throw new RuntimeException('RELEASE_NOT_FOUND', 404);
            $before = brvtal_release_fetch($pdo, $id);
            if (!$before) throw new RuntimeException('RELEASE_NOT_FOUND', 404);
            $st = $pdo->prepare(
                "UPDATE releases SET title=?,slug=?,release_type=?,catalog_number=?,release_date=?,description=?,artwork=?,spotify_url=?,soundcloud_url=?,bandcamp_url=?,youtube_url=?,beatport_url=?,status=?,featured=?,sort_order=?,published_at=CASE WHEN ?='published' THEN COALESCE(published_at,CURRENT_TIMESTAMP) ELSE published_at END WHERE id=?"
            );
            $st->execute([
                $data['title'],$data['slug'],$data['release_type'],$data['catalog_number'] ?: null,$data['release_date'],$data['description'] ?: null,
                $data['artwork'] ?: null,$data['spotify_url'] ?: null,$data['soundcloud_url'] ?: null,$data['bandcamp_url'] ?: null,$data['youtube_url'] ?: null,$data['beatport_url'] ?: null,
                $data['status'],$data['featured'],$data['sort_order'],$data['status'],$id,
            ]);
        }
        brvtal_release_sync_artists($pdo, $id, $data['artists']);
        $after = brvtal_release_fetch($pdo, $id);
        if (!$after) throw new RuntimeException('RELEASE_NOT_FOUND', 404);
        brvtal_activity_record(
            $pdo,
            $method === 'POST' ? 'create' : 'update',
            'releases',
            $id,
            $before,
            $after,
            ['source'=>'releases_api'],
            (string)$after['title']
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    brvtal_indexnow_notify_transition('releases', $before, $after);
    brvtal_releases_json(['ok' => true, 'data' => $after], $method === 'POST' ? 201 : 200);
} catch (InvalidArgumentException $e) {
    brvtal_releases_json(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (PDOException $e) {
    if ((string)$e->getCode() === '23000') {
        brvtal_releases_json(['ok' => false, 'error' => 'RELEASE_CONFLICT'], 409);
    }
    if (function_exists('brvtal_log')) {
        brvtal_log('RELEASES_API_ERROR', 'Releases database failure', ['message' => $e->getMessage()]);
    }
    brvtal_releases_json(['ok' => false, 'error' => 'INTERNAL_ERROR'], 500);
} catch (RuntimeException $e) {
    if ($e->getMessage() === 'ACTIVITY_SCHEMA_MISSING') {
        brvtal_releases_json(['ok'=>false,'error'=>'ACTIVITY_SCHEMA_MISSING'], 503);
    }
    $status = $e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : 500;
    brvtal_releases_json(['ok' => false, 'error' => $e->getMessage()], $status);
} catch (Throwable $e) {
    if (function_exists('brvtal_log')) {
        brvtal_log('RELEASES_API_ERROR', 'Releases API failure', ['class' => get_class($e), 'message' => $e->getMessage()]);
    }
    brvtal_releases_json(['ok' => false, 'error' => 'INTERNAL_ERROR'], 500);
}
