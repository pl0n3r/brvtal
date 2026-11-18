<?php
declare(strict_types=1);

function memories_it_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('MEMORIES INTEGRATION FAILED: ' . $message);
    }
}

if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "BRVTAL Memories integration skipped. Set BRVTAL_INTEGRATION_TESTS=1 to run.\n";
    exit(0);
}

$baseDb = (string)(getenv('BRVTAL_TEST_DB_NAME') ?: '');
memories_it_assert((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/', $baseDb), 'test database name must start with brvtal_test');

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
// Each CI job receives its own MariaDB service container, so a fixed literal test
// database is both isolated and avoids constructing SQL identifiers dynamically.
$server->exec('DROP DATABASE IF EXISTS `brvtal_test_memories`');
$server->exec('CREATE DATABASE `brvtal_test_memories` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

$cleanup = static function () use ($server): void {
    try { $server->exec('DROP DATABASE IF EXISTS `brvtal_test_memories`'); } catch (Throwable) {}
};
register_shutdown_function($cleanup);

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=brvtal_test_memories;charset=utf8mb4', $host, $port),
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
SQL
);

$migrationPath = dirname(__DIR__, 2) . '/database/migration_memories_01.sql';
$memorySchema = file_get_contents($migrationPath);
memories_it_assert(is_string($memorySchema) && trim($memorySchema) !== '', 'deploy migration must be readable');
$pdo->exec($memorySchema); // NOSONAR executes the fixed repository migration artifact under test
$pdo->exec($memorySchema); // NOSONAR verifies the deploy migration remains idempotent
memories_it_assert(
    (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='memories'")->fetchColumn() === 1,
    'schema must be idempotent'
);

$mediaInsert = $pdo->prepare('INSERT INTO media(type,title,file_path,mime_type,status) VALUES(?,?,?,?,?)');
$mediaInsert->execute(['image','Published image','/uploads/media/test/image.jpg','image/jpeg','published']);
$imageId = (int)$pdo->lastInsertId();
$mediaInsert->execute(['video','Published video','/uploads/media/test/video.mp4','video/mp4','published']);
$videoId = (int)$pdo->lastInsertId();
$mediaInsert->execute(['audio','Draft audio','/uploads/media/test/audio.mp3','audio/mpeg','draft']);
$draftAudioId = (int)$pdo->lastInsertId();

$memoryInsert = $pdo->prepare('INSERT INTO memories(media_id,title,context,status,sort_order) VALUES(?,?,?,?,?)');
$memoryInsert->execute([$videoId,'Later memory','Video context','published',20]);
$memoryInsert->execute([$imageId,'First memory','Image context','published',10]);
$memoryInsert->execute([$draftAudioId,'Private source','Must never reach public delivery','draft',5]);

$public = $pdo->query(
    "SELECT m.title,media.type
     FROM memories m
     JOIN media ON media.id=m.media_id
     WHERE m.status='published' AND media.status='published' AND media.type IN ('image','video','audio')
     ORDER BY m.sort_order ASC,m.id ASC"
)->fetchAll();
memories_it_assert(count($public) === 2, 'public query must exclude draft Memory/source combinations');
memories_it_assert(($public[0]['title'] ?? '') === 'First memory', 'public query must preserve explicit editorial ordering');
memories_it_assert(($public[1]['title'] ?? '') === 'Later memory', 'public ordering must remain stable');

$duplicateBlocked = false;
try {
    $memoryInsert->execute([$imageId,'Duplicate','','draft',99]);
} catch (PDOException $e) {
    $duplicateBlocked = (int)($e->errorInfo[1] ?? 0) === 1062;
}
memories_it_assert($duplicateBlocked, 'same Media asset must not be curated twice');

$deleteBlocked = false;
try {
    $pdo->prepare('DELETE FROM media WHERE id=?')->execute([$imageId]);
} catch (PDOException $e) {
    $deleteBlocked = (int)($e->errorInfo[1] ?? 0) === 1451;
}
memories_it_assert($deleteBlocked, 'curated source Media deletion must be restricted');

$mediaCount = $pdo->prepare('SELECT COUNT(*) FROM media WHERE id=?');
$pdo->prepare('DELETE FROM memories WHERE media_id=?')->execute([$imageId]);
$mediaCount->execute([$imageId]);
memories_it_assert((int)$mediaCount->fetchColumn() === 1, 'removing a Memory must not delete its source Media asset');
$pdo->prepare('DELETE FROM media WHERE id=?')->execute([$imageId]);
$mediaCount->execute([$imageId]);
memories_it_assert((int)$mediaCount->fetchColumn() === 0, 'source Media must remain independently manageable after curation removal');

$cleanup();
echo "BRVTAL curated Memories MariaDB integration passed.\n";
