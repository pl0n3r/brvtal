<?php
declare(strict_types=1);

require_once __DIR__ . '/../../api/media-relations.php';
require_once __DIR__ . '/../../api/public-memory-relations.php';

function media_rel_it_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "MEDIA RELATIONS INTEGRATION FAILED: {$message}\n");
        exit(1);
    }
}

if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "BRVTAL Media relations integration skipped. Set BRVTAL_INTEGRATION_TESTS=1 to run.\n";
    exit(0);
}

$baseDb = (string)(getenv('BRVTAL_TEST_DB_NAME') ?: '');
media_rel_it_expect((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/', $baseDb), 'test database name must start with brvtal_test');

$host = (string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1');
$port = (int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306);
$user = (string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root');
$pass = (string)(getenv('BRVTAL_TEST_DB_PASS') ?: '');
$dbName = $baseDb . '_media_rel_' . strtolower(bin2hex(random_bytes(4)));
media_rel_it_expect((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/', $dbName), 'scratch database must stay inside the test namespace');

$server = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port),
    $user,
    $pass,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]
);
$server->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$cleanup = static function () use ($server, $dbName): void {
    try {
        $server->exec("DROP DATABASE IF EXISTS `{$dbName}`");
    } catch (Throwable) {
    }
};
register_shutdown_function($cleanup);

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $dbName),
    $user,
    $pass,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

$pdo->exec(<<<'SQL'
CREATE TABLE media (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type ENUM('image','video','audio','document') NOT NULL,
  title VARCHAR(180) NOT NULL,
  file_path VARCHAR(500) NOT NULL,
  mime_type VARCHAR(120) NULL,
  file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  alt_text VARCHAR(255) NULL,
  status ENUM('draft','published') NOT NULL DEFAULT 'published',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(180) NOT NULL,
  slug VARCHAR(190) NOT NULL UNIQUE,
  status VARCHAR(30) NOT NULL DEFAULT 'draft'
) ENGINE=InnoDB;
CREATE TABLE artists (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(180) NOT NULL,
  slug VARCHAR(190) NOT NULL UNIQUE,
  status VARCHAR(30) NOT NULL DEFAULT 'draft'
) ENGINE=InnoDB;
CREATE TABLE sets_media (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(180) NOT NULL,
  slug VARCHAR(190) NOT NULL UNIQUE,
  status VARCHAR(30) NOT NULL DEFAULT 'draft'
) ENGINE=InnoDB;
CREATE TABLE releases (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(180) NOT NULL,
  slug VARCHAR(190) NOT NULL UNIQUE,
  status VARCHAR(30) NOT NULL DEFAULT 'draft'
) ENGINE=InnoDB;
SQL);

$migration = (string)file_get_contents(__DIR__ . '/../../database/migration_media_relations_01.sql');
$pdo->exec($migration);
$pdo->exec($migration);
media_rel_it_expect(brvtal_media_relations_ready($pdo), 'media_relations must be queryable after additive migration');

$suffix = strtolower(bin2hex(random_bytes(4)));
$eventSlug = 'memory-event-' . $suffix;
$artistSlug = 'memory-artist-' . $suffix;
$privateArtistSlug = 'memory-private-' . $suffix;

$eventStmt = $pdo->prepare("INSERT INTO events(title,slug,status) VALUES(?,?,'published')");
$eventStmt->execute(['MEMORY PUBLIC EVENT', $eventSlug]);
$eventId = (int)$pdo->lastInsertId();

$artistStmt = $pdo->prepare("INSERT INTO artists(name,slug,status) VALUES(?,?,'published')");
$artistStmt->execute(['MEMORY PUBLIC ARTIST', $artistSlug]);
$artistId = (int)$pdo->lastInsertId();
$artistStmt = $pdo->prepare("INSERT INTO artists(name,slug,status) VALUES(?,?,'draft')");
$artistStmt->execute(['MEMORY PRIVATE ARTIST', $privateArtistSlug]);
$privateArtistId = (int)$pdo->lastInsertId();

$mediaStmt = $pdo->prepare("INSERT INTO media(type,title,file_path,mime_type,file_size,alt_text,status) VALUES('image',?,?,'image/jpeg',10,'Memory fixture',?)");
$mediaStmt->execute(['MEMORY FIXTURE ' . $suffix, '/uploads/media/test-memory-' . $suffix . '.jpg', 'published']);
$mediaId = (int)$pdo->lastInsertId();
$mediaStmt->execute(['PRIVATE MEMORY ' . $suffix, '/uploads/media/private-memory-' . $suffix . '.jpg', 'draft']);
$draftMediaId = (int)$pdo->lastInsertId();

$pdo->beginTransaction();
brvtal_media_replace_relations($pdo, $mediaId, [
    ['related_type'=>'event','related_id'=>$eventId],
    ['related_type'=>'artist','related_id'=>$artistId],
    ['related_type'=>'artist','related_id'=>$privateArtistId],
    ['related_type'=>'event','related_id'=>$eventId],
]);
brvtal_media_replace_relations($pdo, $draftMediaId, [
    ['related_type'=>'event','related_id'=>$eventId],
]);
$pdo->commit();

$stored = brvtal_media_load_relations($pdo, $mediaId);
media_rel_it_expect(count($stored) === 3, 'duplicate type/id input must be persisted once');
media_rel_it_expect(array_column($stored, 'sort_order') === [0,1,2], 'server-normalized relation order must persist');

$missingRejected = false;
$pdo->beginTransaction();
try {
    brvtal_media_replace_relations($pdo, $mediaId, [['related_type'=>'event','related_id'=>999999999]]);
    $pdo->commit();
} catch (InvalidArgumentException $e) {
    $missingRejected = $e->getMessage() === 'MEDIA_RELATION_NOT_FOUND';
    if ($pdo->inTransaction()) $pdo->rollBack();
}
media_rel_it_expect($missingRejected, 'missing polymorphic target must reject the mutation');
media_rel_it_expect(count(brvtal_media_load_relations($pdo, $mediaId)) === 3, 'failed replacement must leave prior relation set intact after rollback');

$publicMedia = brvtal_public_attach_memory_relations(
    $pdo,
    [[
        'id'=>$mediaId,
        'type'=>'image',
        'title'=>'MEMORY FIXTURE',
        'file_path'=>'/uploads/media/test-memory-' . $suffix . '.jpg',
        'status'=>'published',
    ]],
    [['id'=>$eventId,'title'=>'MEMORY PUBLIC EVENT','slug'=>$eventSlug]],
    [],
    [['id'=>$artistId,'name'=>'MEMORY PUBLIC ARTIST','slug'=>$artistSlug]],
    [],
    []
);
$relations = $publicMedia[0]['relations'] ?? [];
media_rel_it_expect(count($relations) === 2, 'public payload must omit relation whose target is absent from final public pools');
media_rel_it_expect(!in_array($privateArtistId, array_column($relations, 'related_id'), true), 'private target ID must never leak into Memory payload');
$labels = array_column($relations, 'label');
media_rel_it_expect(in_array('MEMORY PUBLIC EVENT', $labels, true) && in_array('MEMORY PUBLIC ARTIST', $labels, true), 'public relations must decorate from final public entities');
media_rel_it_expect(!in_array('MEMORY PRIVATE ARTIST', $labels, true), 'private target label must never leak into Memory payload');

$entityMemories = brvtal_public_memories_for_entity($pdo, 'event', $eventId);
media_rel_it_expect(count($entityMemories) === 1, 'canonical entity page lookup must include only published Memories');
media_rel_it_expect((int)$entityMemories[0]['id'] === $mediaId, 'canonical entity page lookup must exclude draft Memory relation');
media_rel_it_expect(!str_contains(json_encode($entityMemories), 'PRIVATE MEMORY'), 'draft Memory title must not leak through entity lookup');

$graph = brvtal_public_add_memory_edges([
    'events'=>[(string)$eventId=>['artists'=>[],'sets'=>[]]],
    'artists'=>[(string)$artistId=>['events'=>[],'sets'=>[],'releases'=>[]]],
    'sets'=>[],
    'releases'=>[],
    'counts'=>[],
], $publicMedia);
media_rel_it_expect($graph['events'][(string)$eventId]['memories'] === [$mediaId], 'Event graph must expose explicit Memory edge');
media_rel_it_expect($graph['artists'][(string)$artistId]['memories'] === [$mediaId], 'Artist graph must expose explicit Memory edge');

$deleteMedia = $pdo->prepare('DELETE FROM media WHERE id=?');
$deleteMedia->execute([$mediaId]);
$count = $pdo->prepare('SELECT COUNT(*) FROM media_relations WHERE media_id=?');
$count->execute([$mediaId]);
media_rel_it_expect((int)$count->fetchColumn() === 0, 'Media deletion must cascade semantic relation rows');
$deleteMedia->execute([$draftMediaId]);

$cleanup();
echo "BRVTAL Media relations MariaDB integration tests passed.\n";
