<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/media.php';
require_once __DIR__ . '/../../config/media_dedup.php';

function media_dedup_it_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("MEDIA DEDUP INTEGRATION FAILED: {$message}");
    }
}

if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "BRVTAL media dedup integration skipped. Set BRVTAL_INTEGRATION_TESTS=1 to run.\n";
    exit(0);
}

$baseDb = (string)(getenv('BRVTAL_TEST_DB_NAME') ?: '');
media_dedup_it_assert((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/', $baseDb), 'test database name must start with brvtal_test');

$host = (string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1');
$port = (int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306);
$user = (string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root');
$pass = (string)(getenv('BRVTAL_TEST_DB_PASS') ?: '');
$dbName = $baseDb . '_media_dedup_' . bin2hex(random_bytes(4));
media_dedup_it_assert((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/', $dbName), 'scratch database must stay inside test namespace');

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];
$server = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port), $user, $pass, $options);
$server->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $dbName);
$writer = new PDO($dsn, $user, $pass, $options);
$racer = new PDO($dsn, $user, $pass, $options);

$token = 'dedup-it-' . bin2hex(random_bytes(5));
$dir = brvtal_media_upload_root() . '/media/' . $token;
media_dedup_it_assert(mkdir($dir, 0750, true) || is_dir($dir), 'filesystem fixture directory must be created');

$cleanup = static function () use ($server, $dbName, $dir): void {
    foreach (glob($dir . '/*') ?: [] as $path) {
        @unlink($path);
    }
    @rmdir($dir);
    try {
        $server->exec("DROP DATABASE IF EXISTS `{$dbName}`");
    } catch (Throwable) {
    }
};
register_shutdown_function($cleanup);

$writer->exec(<<<'SQL'
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

$before = brvtal_media_dedup_schema_state($writer);
media_dedup_it_assert($before['ready'] === false, 'legacy schema must be reported as migration-required');

$migration = (string)file_get_contents(__DIR__ . '/../../database/migration_media_content_hash_01.sql');
media_dedup_it_assert(trim($migration) !== '', 'dedup migration must exist');
$writer->exec($migration);
$writer->exec($migration);
$after = brvtal_media_dedup_schema_state($writer);
media_dedup_it_assert($after['ready'] === true, 'migration must be idempotent and leave the schema ready');
media_dedup_it_assert(
    (int)$writer->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='media' AND COLUMN_NAME='content_hash'")->fetchColumn() === 1,
    'content_hash column must exist exactly once'
);
media_dedup_it_assert(
    (int)$writer->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='media' AND INDEX_NAME='uq_media_content_hash'")->fetchColumn() === 1,
    'unique content hash index must exist exactly once'
);

$bytesA = str_repeat('A', 4096);
$bytesB = str_repeat('B', 4096);
$bytesC = str_repeat('C', 4096);
$fileA = $dir . '/legacy-a.pdf';
$fileB = $dir . '/legacy-b.pdf';
file_put_contents($fileA, $bytesA);
file_put_contents($fileB, $bytesB);
$publicA = '/uploads/media/' . $token . '/legacy-a.pdf';
$publicB = '/uploads/media/' . $token . '/legacy-b.pdf';
$mime = 'application/pdf';
$size = strlen($bytesA);

$insert = $writer->prepare(
    'INSERT INTO media(type,title,file_path,mime_type,file_size,alt_text,status) VALUES(?,?,?,?,?,?,?)'
);
$insert->execute(['document', 'Missing legacy', '/uploads/media/' . $token . '/missing.pdf', $mime, $size, '', 'draft']);
$insert->execute(['document', 'External legacy', 'https://example.test/external.pdf', $mime, $size, '', 'draft']);
$insert->execute(['document', 'Legacy A', $publicA, $mime, $size, '', 'draft']);
$legacyAId = (int)$writer->lastInsertId();

$lookupA = brvtal_media_find_duplicate($writer, hash('sha256', $bytesA), $size, $mime);
media_dedup_it_assert((int)($lookupA['duplicate']['id'] ?? 0) === $legacyAId, 'same bytes with a different incoming filename must reuse the legacy canonical row');
media_dedup_it_assert(($lookupA['source'] ?? '') === 'legacy_backfill', 'legacy exact match must report lazy backfill source');
media_dedup_it_assert(($lookupA['scan_complete'] ?? false) === true, 'missing/non-local legacy candidates must not block a bounded completed scan');
media_dedup_it_assert(
    (string)$writer->query("SELECT content_hash FROM media WHERE id={$legacyAId}")->fetchColumn() === hash('sha256', $bytesA),
    'matching legacy row must be backfilled with its SHA-256'
);

$insert->execute(['document', 'Legacy B', $publicB, $mime, $size, '', 'draft']);
$legacyBId = (int)$writer->lastInsertId();
$lookupC = brvtal_media_find_duplicate($writer, hash('sha256', $bytesC), $size, $mime);
media_dedup_it_assert($lookupC['duplicate'] === null, 'different bytes must not dedupe');
media_dedup_it_assert(($lookupC['scan_complete'] ?? false) === true, 'different-byte scan should complete');
media_dedup_it_assert(
    (string)$writer->query("SELECT content_hash FROM media WHERE id={$legacyBId}")->fetchColumn() === hash('sha256', $bytesB),
    'nonmatching legacy candidate should still be lazily backfilled for future indexed lookup'
);

$raceHash = hash('sha256', 'race-content');
$writer->prepare(
    'INSERT INTO media(type,title,file_path,mime_type,file_size,content_hash,alt_text,status) VALUES(?,?,?,?,?,?,?,?)'
)->execute(['document', 'Race winner', '/uploads/media/' . $token . '/winner.pdf', $mime, 12, $raceHash, '', 'draft']);
$winnerId = (int)$writer->lastInsertId();

$raceRejected = false;
try {
    $racer->prepare(
        'INSERT INTO media(type,title,file_path,mime_type,file_size,content_hash,alt_text,status) VALUES(?,?,?,?,?,?,?,?)'
    )->execute(['document', 'Race loser', '/uploads/media/' . $token . '/loser.pdf', $mime, 12, $raceHash, '', 'draft']);
} catch (PDOException $error) {
    $raceRejected = brvtal_media_is_unique_hash_conflict($error);
}
media_dedup_it_assert($raceRejected, 'unique content hash must reject a concurrent duplicate insert');
$winner = brvtal_media_find_by_content_hash($racer, $raceHash);
media_dedup_it_assert((int)($winner['id'] ?? 0) === $winnerId, 'race loser must be able to resolve the canonical winning asset');

$cleanup();
echo "BRVTAL Media exact-dedup MariaDB integration passed.\n";
