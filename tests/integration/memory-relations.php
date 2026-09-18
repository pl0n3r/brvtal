<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/memory_relations.php';

function memory_rel_it_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('MEMORY RELATIONS INTEGRATION FAILED: ' . $message);
}

if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "BRVTAL Memory relations integration skipped. Set BRVTAL_INTEGRATION_TESTS=1 to run.\n";
    exit(0);
}

$baseDb = (string)(getenv('BRVTAL_TEST_DB_NAME') ?: '');
memory_rel_it_expect((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/', $baseDb), 'test database name must start with brvtal_test');

$host = (string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1');
$port = (int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306);
$user = (string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root');
$pass = (string)(getenv('BRVTAL_TEST_DB_PASS') ?: '');

$server = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port),
    $user,
    $pass,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]
);
$server->exec('DROP DATABASE IF EXISTS `brvtal_test_memory_relations`');
$server->exec('CREATE DATABASE `brvtal_test_memory_relations` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$cleanup = static function () use ($server): void {
    try { $server->exec('DROP DATABASE IF EXISTS `brvtal_test_memory_relations`'); } catch (Throwable) {}
};
register_shutdown_function($cleanup);

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=brvtal_test_memory_relations;charset=utf8mb4', $host, $port),
    $user,
    $pass,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]
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
CREATE TABLE events (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,title VARCHAR(180) NOT NULL,slug VARCHAR(190) NOT NULL UNIQUE,status VARCHAR(30) NOT NULL DEFAULT 'draft') ENGINE=InnoDB;
CREATE TABLE artists (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(180) NOT NULL,slug VARCHAR(190) NOT NULL UNIQUE,status VARCHAR(30) NOT NULL DEFAULT 'draft') ENGINE=InnoDB;
CREATE TABLE sets_media (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,title VARCHAR(180) NOT NULL,slug VARCHAR(190) NOT NULL UNIQUE,status VARCHAR(30) NOT NULL DEFAULT 'draft') ENGINE=InnoDB;
CREATE TABLE releases (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,title VARCHAR(180) NOT NULL,slug VARCHAR(190) NOT NULL UNIQUE,status VARCHAR(30) NOT NULL DEFAULT 'draft') ENGINE=InnoDB;
SQL
);

$memoriesMigration = (string)file_get_contents(dirname(__DIR__, 2) . '/database/migration_memories_01.sql');
$relationsMigration = (string)file_get_contents(dirname(__DIR__, 2) . '/database/migration_memory_relations_01.sql');
$pdo->exec($memoriesMigration); // NOSONAR fixed repository migration under test
$pdo->exec($relationsMigration); // NOSONAR fixed repository migration under test
$pdo->exec($relationsMigration); // NOSONAR verifies idempotency
memory_rel_it_expect(brvtal_memory_relations_ready($pdo), 'memory_relations must be ready after migration');

$pdo->exec("INSERT INTO events(title,slug,status) VALUES('PUBLIC EVENT','public-event','published')");
$eventId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO artists(name,slug,status) VALUES('PUBLIC ARTIST','public-artist','published')");
$artistId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO artists(name,slug,status) VALUES('PRIVATE ARTIST','private-artist','draft')");
$privateArtistId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO sets_media(title,slug,status) VALUES('PUBLIC SET','public-set','published')");
$setId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO releases(title,slug,status) VALUES('PUBLIC RELEASE','public-release','published')");
$releaseId = (int)$pdo->lastInsertId();

$pdo->exec("INSERT INTO media(type,title,file_path,mime_type,status) VALUES('image','SOURCE','/uploads/media/memory-rel.jpg','image/jpeg','published')");
$mediaId = (int)$pdo->lastInsertId();
$insertMemory = $pdo->prepare("INSERT INTO memories(media_id,title,context,status,sort_order) VALUES(?, 'CURATED MEMORY', 'Pereira archive', 'published', 1)");
$insertMemory->execute([$mediaId]);
$memoryId = (int)$pdo->lastInsertId();

$pdo->beginTransaction();
brvtal_memory_replace_relations($pdo, $memoryId, [
    ['related_type'=>'event','related_id'=>$eventId],
    ['related_type'=>'artist','related_id'=>$artistId],
    ['related_type'=>'artist','related_id'=>$privateArtistId],
    ['related_type'=>'event','related_id'=>$eventId],
]);
$pdo->commit();

$stored = brvtal_memory_load_relations($pdo, $memoryId);
memory_rel_it_expect(count($stored) === 3, 'admin storage must deduplicate explicit edges and may retain draft targets');
memory_rel_it_expect(array_column($stored, 'sort_order') === [0,1,2], 'stored order must be server-normalized');

$missingRejected = false;
$pdo->beginTransaction();
try {
    brvtal_memory_replace_relations($pdo, $memoryId, [['related_type'=>'event','related_id'=>999999]]);
    $pdo->commit();
} catch (InvalidArgumentException $e) {
    $missingRejected = $e->getMessage() === 'MEMORY_RELATION_NOT_FOUND';
    if ($pdo->inTransaction()) $pdo->rollBack();
}
memory_rel_it_expect($missingRejected, 'missing target must reject the relation mutation');
memory_rel_it_expect(count(brvtal_memory_load_relations($pdo, $memoryId)) === 3, 'failed replacement must preserve previous links after rollback');

$public = brvtal_public_attach_memory_relations(
    $pdo,
    [[
        'id'=>$memoryId,'media_id'=>$mediaId,'type'=>'image','title'=>'CURATED MEMORY',
        'context'=>'Pereira archive','file_path'=>'/uploads/media/memory-rel.jpg','alt_text'=>'',
    ]],
    [['id'=>$eventId,'title'=>'PUBLIC EVENT','slug'=>'public-event']],
    [],
    [['id'=>$artistId,'name'=>'PUBLIC ARTIST','slug'=>'public-artist']],
    [['id'=>$setId,'title'=>'PUBLIC SET','slug'=>'public-set']],
    [['id'=>$releaseId,'title'=>'PUBLIC RELEASE','slug'=>'public-release']]
);
$publicRelations = $public[0]['relations'] ?? [];
memory_rel_it_expect(count($publicRelations) === 2, 'public delivery must remove relations whose target is absent from public pools');
memory_rel_it_expect(!in_array($privateArtistId, array_column($publicRelations, 'related_id'), true), 'private target ID must not leak');
memory_rel_it_expect(in_array('PUBLIC EVENT', array_column($publicRelations, 'label'), true), 'public target label must be decorated from final entity pools');

$entityMemories = brvtal_public_memories_for_entity($pdo, 'events', $eventId);
memory_rel_it_expect(count($entityMemories) === 1 && ($entityMemories[0]['title'] ?? '') === 'CURATED MEMORY', 'canonical Event must receive explicit published Memory');

$graph = brvtal_public_add_memory_edges([
    'events'=>[(string)$eventId=>['artists'=>[],'sets'=>[]]],
    'artists'=>[(string)$artistId=>['events'=>[],'sets'=>[],'releases'=>[]]],
    'sets'=>[(string)$setId=>['artist'=>null,'event'=>null]],
    'releases'=>[(string)$releaseId=>['artists'=>[]]],
    'counts'=>[],
], $public);
memory_rel_it_expect($graph['events'][(string)$eventId]['memories'] === [$memoryId], 'Event graph must expose explicit Memory edge');
memory_rel_it_expect($graph['artists'][(string)$artistId]['memories'] === [$memoryId], 'Artist graph must expose explicit Memory edge');
memory_rel_it_expect(count($graph['memories']) === 1, 'Memory graph bucket must only contain sanitized public edges');

$pdo->prepare('DELETE FROM memories WHERE id=?')->execute([$memoryId]);
$count = $pdo->prepare('SELECT COUNT(*) FROM memory_relations WHERE memory_id=?');
$count->execute([$memoryId]);
memory_rel_it_expect((int)$count->fetchColumn() === 0, 'removing curation must cascade relation rows');
$source = $pdo->prepare('SELECT COUNT(*) FROM media WHERE id=?');
$source->execute([$mediaId]);
memory_rel_it_expect((int)$source->fetchColumn() === 1, 'removing Memory relations/curation must not delete source Media');

$cleanup();
echo "BRVTAL Memory relations MariaDB integration passed.\n";
