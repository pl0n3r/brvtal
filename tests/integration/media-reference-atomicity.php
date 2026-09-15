<?php
declare(strict_types=1);

function media_atomicity_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("MEDIA ATOMICITY INTEGRATION FAILED: {$message}");
    }
}

if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "BRVTAL media atomicity integration skipped. Set BRVTAL_INTEGRATION_TESTS=1 to run.\n";
    exit(0);
}

$baseDb = (string)(getenv('BRVTAL_TEST_DB_NAME') ?: '');
media_atomicity_assert((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/', $baseDb), 'test database name must start with brvtal_test');

$host = (string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1');
$port = (int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306);
$user = (string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root');
$pass = (string)(getenv('BRVTAL_TEST_DB_PASS') ?: '');
$dbName = $baseDb . '_media_atomicity_' . bin2hex(random_bytes(4));
media_atomicity_assert((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/', $dbName), 'scratch database name must stay inside test namespace');

$serverDsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port);
$server = new PDO($serverDsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$server->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $dbName);
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];
$writer = new PDO($dsn, $user, $pass, $options);
$deleter = new PDO($dsn, $user, $pass, $options);

$cleanup = static function () use ($server, $dbName): void {
    try {
        $server->exec("DROP DATABASE IF EXISTS `{$dbName}`");
    } catch (Throwable) {
    }
};
register_shutdown_function($cleanup);

$schema = <<<'SQL'
CREATE TABLE media (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  file_path VARCHAR(500) NOT NULL,
  title VARCHAR(180) NOT NULL DEFAULT 'Media'
) ENGINE=InnoDB;
CREATE TABLE events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(180) NOT NULL DEFAULT 'Event',
  cover_image VARCHAR(500) NULL,
  ticket_qr VARCHAR(500) NULL
) ENGINE=InnoDB;
CREATE TABLE artists (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(180) NOT NULL DEFAULT 'Artist',
  photo VARCHAR(500) NULL
) ENGINE=InnoDB;
CREATE TABLE sets_media (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(180) NOT NULL DEFAULT 'Set',
  cover_image VARCHAR(500) NULL
) ENGINE=InnoDB;
CREATE TABLE event_ticket_types (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(180) NOT NULL DEFAULT 'Ticket',
  qr_image VARCHAR(500) NULL
) ENGINE=InnoDB;
CREATE TABLE releases (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(180) NOT NULL DEFAULT 'Release',
  artwork VARCHAR(500) NULL
) ENGINE=InnoDB;
CREATE TABLE blog_posts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(220) NOT NULL DEFAULT 'Post',
  cover_image VARCHAR(500) NULL
) ENGINE=InnoDB;
CREATE TABLE pages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(180) NOT NULL DEFAULT 'Page',
  content_json LONGTEXT NULL
) ENGINE=InnoDB;
CREATE TABLE settings (
  setting_key VARCHAR(120) PRIMARY KEY,
  setting_value LONGTEXT NULL
) ENGINE=InnoDB;
SQL;
$writer->exec($schema);

$migration = file_get_contents(__DIR__ . '/../../database/migration_zz_media_reference_guard_01.sql');
media_atomicity_assert(is_string($migration) && trim($migration) !== '', 'guard migration must exist');
$writer->exec($migration);

$path = '/uploads/media/ci/atomic-race.jpg';
$writer->prepare('INSERT INTO media(file_path,title) VALUES(?,?)')->execute([$path, 'Atomic race']);
$mediaId = (int)$writer->lastInsertId();

// Interleaving A: writer gets the mutex first and keeps its transaction open.
$writer->beginTransaction();
$writer->prepare('INSERT INTO events(title,cover_image) VALUES(?,?)')->execute(['Writer first', $path]);
$deleter->exec('SET SESSION innodb_lock_wait_timeout=1');
$blockedByMutex = false;
try {
    $deleter->prepare('DELETE FROM media WHERE id=?')->execute([$mediaId]);
} catch (PDOException $exception) {
    $blockedByMutex = ((int)($exception->errorInfo[1] ?? 0) === 1205)
        || str_contains(strtolower($exception->getMessage()), 'lock wait timeout');
}
media_atomicity_assert($blockedByMutex, 'DELETE must wait behind a concurrent reference writer');
$writer->commit();

$blockedByUsageRecheck = false;
try {
    $deleter->prepare('DELETE FROM media WHERE id=?')->execute([$mediaId]);
} catch (PDOException $exception) {
    $blockedByUsageRecheck = true;
}
media_atomicity_assert($blockedByUsageRecheck, 'DELETE must fail after the concurrent reference commits');
media_atomicity_assert((int)$deleter->query("SELECT COUNT(*) FROM media WHERE id={$mediaId}")->fetchColumn() === 1, 'referenced media row must remain');

// Remove the reference, then let DELETE win and write the tombstone.
$writer->exec('DELETE FROM events');
$deleter->prepare('DELETE FROM media WHERE id=?')->execute([$mediaId]);
media_atomicity_assert((int)$deleter->query("SELECT COUNT(*) FROM media WHERE id={$mediaId}")->fetchColumn() === 0, 'unreferenced media should delete');
$quotedPath = $deleter->quote($path);
media_atomicity_assert((int)$deleter->query("SELECT COUNT(*) FROM media_deleted_paths WHERE file_path={$quotedPath}")->fetchColumn() === 1, 'deleted path must be tombstoned');

// Interleaving B: after DELETE commits, exact-path and embedded-path writers fail closed.
$artistRejected = false;
try {
    $writer->prepare('INSERT INTO artists(name,photo) VALUES(?,?)')->execute(['Too late', $path]);
} catch (PDOException) {
    $artistRejected = true;
}
media_atomicity_assert($artistRejected, 'exact media reference must be rejected after deletion');
media_atomicity_assert((int)$writer->query("SELECT COUNT(*) FROM artists WHERE name='Too late'")->fetchColumn() === 0, 'rejected exact reference must not persist');

$pageRejected = false;
try {
    $writer->prepare('INSERT INTO pages(title,content_json) VALUES(?,?)')->execute([
        'Too late page',
        json_encode(['body' => '<img src="' . $path . '">'], JSON_UNESCAPED_SLASHES),
    ]);
} catch (PDOException) {
    $pageRejected = true;
}
media_atomicity_assert($pageRejected, 'embedded Page reference must be rejected after deletion');

$settingsRejected = false;
try {
    $writer->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?)')->execute([
        'hero.atomicity',
        json_encode(['image' => $path], JSON_UNESCAPED_SLASHES),
    ]);
} catch (PDOException) {
    $settingsRejected = true;
}
media_atomicity_assert($settingsRejected, 'embedded Settings reference must be rejected after deletion');

$cleanup();
echo "BRVTAL media reference/delete atomicity integration passed.\n";
