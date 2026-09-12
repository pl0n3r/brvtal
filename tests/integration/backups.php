<?php
declare(strict_types=1);

if ((string)getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    fwrite(STDOUT, "BRVTAL backups integration skipped.\n");
    exit(0);
}

require_once __DIR__ . '/../../config/backups.php';

function backups_integration_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "BACKUPS INTEGRATION FAILED: {$message}\n");
        exit(1);
    }
}

$host = (string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1');
$port = (int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306);
$name = (string)(getenv('BRVTAL_TEST_DB_NAME') ?: 'brvtal_test_ci');
$user = (string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root');
$pass = (string)(getenv('BRVTAL_TEST_DB_PASS') ?: '');

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name),
    $user,
    $pass,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ]
);

$config = ['backups'=>['media_archive_max_bytes'=>1024 * 1024]];
$tmpRoot = sys_get_temp_dir() . '/brvtal-backups-' . bin2hex(random_bytes(5));
$backupDir = $tmpRoot . '/private';
$uploadsDir = $tmpRoot . '/uploads';
@mkdir($uploadsDir . '/2026/09', 0700, true);
file_put_contents($uploadsDir . '/2026/09/sample.txt', "BRVTAL MEDIA SAMPLE\n");
file_put_contents($uploadsDir . '/root.txt', "ROOT SAMPLE\n");

$pdo->exec('DROP TABLE IF EXISTS backup_fixture');
$pdo->exec('CREATE TABLE backup_fixture (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, label VARCHAR(190) NOT NULL, payload TEXT NULL, nullable_value VARCHAR(50) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$insert = $pdo->prepare('INSERT INTO backup_fixture(label,payload,nullable_value) VALUES(?,?,?)');
$insert->execute(["O'Reilly / BRVTAL", '{"line":"one\\ntwo","accent":"áéí"}', null]);
$insert->execute(['Second row', 'normal', 'value']);

try {
    $manifest = brvtal_backup_create($pdo, [
        'base_dir'=>$backupDir,
        'uploads_dir'=>$uploadsDir,
        'include_media_archive'=>false,
        'created_by'=>['id'=>99,'name'=>'CI Admin'],
        'deployment'=>[
            'commit'=>str_repeat('a', 40),
            'short_commit'=>'aaaaaaa',
            'source'=>'integration_test',
            'version'=>'0.1.0-test',
            'environment'=>'CI',
        ],
    ]);

    backups_integration_expect(($manifest['status'] ?? null) === 'ready', 'database + media manifest backup should be ready');
    backups_integration_expect(($manifest['restore_supported'] ?? true) === false, 'restore must remain disabled');
    backups_integration_expect(($manifest['created_by']['name'] ?? null) === 'CI Admin', 'manifest must preserve creator snapshot');
    backups_integration_expect(($manifest['deployment']['short_commit'] ?? null) === 'aaaaaaa', 'manifest must preserve deployment identity');
    backups_integration_expect(($manifest['components']['media_archive']['status'] ?? null) === 'not_requested', 'media ZIP must remain optional');

    $id = (string)$manifest['id'];
    backups_integration_expect(brvtal_backup_valid_id($id), 'generated backup id must satisfy strict format');

    $databasePath = brvtal_backup_resolve_component($manifest, 'database', $backupDir);
    $mediaManifestPath = brvtal_backup_resolve_component($manifest, 'media_manifest', $backupDir);
    $manifestPath = brvtal_backup_resolve_component($manifest, 'manifest', $backupDir);
    backups_integration_expect(is_string($databasePath) && is_file($databasePath), 'database artifact must exist in private directory');
    backups_integration_expect(is_string($mediaManifestPath) && is_file($mediaManifestPath), 'media inventory artifact must exist');
    backups_integration_expect(is_string($manifestPath) && is_file($manifestPath), 'backup manifest artifact must exist');
    backups_integration_expect(brvtal_backup_resolve_component($manifest, '../database', $backupDir) === null, 'component traversal must be rejected');

    $sql = (string)file_get_contents($databasePath);
    backups_integration_expect(str_contains($sql, 'CREATE TABLE `backup_fixture`'), 'dump must preserve fixture schema');
    backups_integration_expect(str_contains($sql, 'INSERT INTO `backup_fixture`'), 'dump must contain fixture rows');
    backups_integration_expect(str_contains($sql, "O\\'Reilly / BRVTAL") || str_contains($sql, "O''Reilly / BRVTAL"), 'dump must safely quote apostrophes');
    backups_integration_expect(str_contains($sql, 'NULL'), 'dump must preserve NULL values');
    backups_integration_expect(str_contains($sql, 'SET FOREIGN_KEY_CHECKS=0'), 'dump must disable FK checks for portability');
    backups_integration_expect(str_contains($sql, 'SET FOREIGN_KEY_CHECKS=1'), 'dump must restore FK checks');

    $databaseHash = hash_file('sha256', $databasePath);
    backups_integration_expect(hash_equals((string)$manifest['components']['database']['sha256'], (string)$databaseHash), 'database checksum must match artifact');

    $mediaPayload = json_decode((string)file_get_contents($mediaManifestPath), true);
    backups_integration_expect(is_array($mediaPayload), 'media inventory must be valid JSON');
    backups_integration_expect((int)($mediaPayload['files'] ?? 0) === 2, 'media inventory must contain both fixture uploads');
    $paths = array_map(static fn(array $entry): string => (string)($entry['path'] ?? ''), $mediaPayload['entries'] ?? []);
    backups_integration_expect(in_array('uploads/2026/09/sample.txt', $paths, true), 'media inventory must preserve nested relative path');
    backups_integration_expect(in_array('uploads/root.txt', $paths, true), 'media inventory must preserve root relative path');

    $listed = brvtal_backup_list($backupDir);
    backups_integration_expect(count($listed) === 1, 'backup history must discover manifest');
    backups_integration_expect(($listed[0]['id'] ?? null) === $id, 'backup history must return created backup');
    $loaded = brvtal_backup_get($id, $backupDir);
    backups_integration_expect(is_array($loaded) && ($loaded['id'] ?? null) === $id, 'backup lookup by strict id must work');
    backups_integration_expect(brvtal_backup_get('../config', $backupDir) === null, 'invalid backup id must never resolve outside private directory');

    $deny = (string)file_get_contents($backupDir . '/.htaccess');
    backups_integration_expect(str_contains($deny, 'Require all denied'), 'runtime-created backup directory must be web denied');

    brvtal_backup_cleanup($manifest, $backupDir);
    backups_integration_expect(brvtal_backup_list($backupDir) === [], 'test cleanup must remove backup artifacts');

    fwrite(STDOUT, "BRVTAL backups MariaDB integration passed.\n");
} finally {
    $pdo->exec('DROP TABLE IF EXISTS backup_fixture');
    $removeTree = static function (string $path) use (&$removeTree): void {
        if (!file_exists($path)) return;
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $name) {
                if ($name === '.' || $name === '..') continue;
                $removeTree($path . DIRECTORY_SEPARATOR . $name);
            }
            @rmdir($path);
        } else {
            @unlink($path);
        }
    };
    $removeTree($tmpRoot);
}
