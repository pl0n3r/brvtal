<?php
declare(strict_types=1);

if ((string)getenv('BRVTAL_INTEGRATION_TESTS') !== '1' || (string)getenv('BRVTAL_BACKUP_RECOVERY_REHEARSAL') !== '1') {
    fwrite(STDOUT, "BRVTAL backup recovery rehearsal skipped.\n");
    exit(0);
}

require_once __DIR__ . '/../../config/backups.php';

function recovery_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function recovery_remove_tree(string $path): void
{
    if (!file_exists($path)) return;
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            recovery_remove_tree($path . DIRECTORY_SEPARATOR . $name);
        }
        @rmdir($path);
        return;
    }
    @unlink($path);
}

function recovery_write_evidence(string $path, array $evidence): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0770, true);
    $json = json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if (is_string($json)) @file_put_contents($path, $json . "\n", LOCK_EX);
}

function recovery_mysql_restore(string $host, int $port, string $user, string $password, string $database, string $dumpPath): void
{
    recovery_expect((bool)preg_match('/^brvtal_test_recovery_[a-f0-9]{8}$/', $database), 'Recovery database name escaped the guarded namespace.');
    recovery_expect(is_file($dumpPath), 'Database dump is missing before restore.');

    $command = [
        'mysql',
        '-h' . $host,
        '-P' . (string)$port,
        '-u' . $user,
        '--default-character-set=utf8mb4',
        $database,
    ];
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $previousPassword = getenv('MYSQL_PWD');
    putenv('MYSQL_PWD=' . $password);
    try {
        $process = proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) throw new RuntimeException('Could not start isolated mysql restore process.');

        $dump = file_get_contents($dumpPath);
        if (!is_string($dump)) {
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            proc_terminate($process);
            proc_close($process);
            throw new RuntimeException('Could not read generated database dump.');
        }

        fwrite($pipes[0], $dump);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            $safeError = trim((string)$stderr);
            throw new RuntimeException('Isolated mysql restore failed' . ($safeError !== '' ? ': ' . mb_substr($safeError, 0, 500) : '.'));
        }
        unset($stdout);
    } finally {
        if ($previousPassword === false) putenv('MYSQL_PWD');
        else putenv('MYSQL_PWD=' . $previousPassword);
    }
}

$host = (string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1');
$port = (int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306);
$sourceDb = (string)(getenv('BRVTAL_TEST_DB_NAME') ?: 'brvtal_test_backup_source');
$user = (string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root');
$password = (string)(getenv('BRVTAL_TEST_DB_PASS') ?: '');
$outputPath = (string)(getenv('BRVTAL_BACKUP_RECOVERY_OUTPUT') ?: 'artifacts/backup-recovery-rehearsal.json');

if (!preg_match('/^brvtal_test(?:_[a-z0-9]+)*$/', $sourceDb)) {
    fwrite(STDERR, "BACKUP RECOVERY REHEARSAL REFUSED: source DB must use the brvtal_test_* namespace.\n");
    exit(2);
}

$recoveryDb = 'brvtal_test_recovery_' . bin2hex(random_bytes(4));
$tmpRoot = sys_get_temp_dir() . '/brvtal-recovery-' . bin2hex(random_bytes(6));
$backupDir = $tmpRoot . '/private-backup';
$uploadsDir = $tmpRoot . '/uploads';
$recoveredMediaDir = $tmpRoot . '/recovered-media';
@mkdir($uploadsDir . '/2026/09', 0700, true);

$sampleNested = "BRVTAL RECOVERY SAMPLE — áéí\n";
$sampleRoot = "ROOT RECOVERY SAMPLE\n";
file_put_contents($uploadsDir . '/2026/09/sample.txt', $sampleNested);
file_put_contents($uploadsDir . '/root.txt', $sampleRoot);
$expectedNestedHash = hash('sha256', $sampleNested);
$expectedRootHash = hash('sha256', $sampleRoot);

$dsnServer = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port);
$dsnSource = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $sourceDb);
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_STRINGIFY_FETCHES => false,
];
$server = new PDO($dsnServer, $user, $password, $options);
$source = new PDO($dsnSource, $user, $password, $options);

$config = ['backups' => ['media_archive_max_bytes' => 1024 * 1024]];
$manifest = null;
$recoveryCreated = false;
$sourceFixturesCreated = false;
$failed = false;
$evidence = [
    'checkedAt' => gmdate(DATE_ATOM),
    'mode' => 'isolated-ci-recovery-rehearsal',
    'sourceDatabase' => $sourceDb,
    'recoveryDatabase' => $recoveryDb,
    'productionRestoreEnabled' => false,
    'checks' => [
        'databaseChecksum' => false,
        'snapshotRows' => false,
        'postBackupMutationAbsent' => false,
        'foreignKey' => false,
        'view' => false,
        'mediaArchive' => false,
    ],
    'status' => 'running',
];

try {
    recovery_expect(str_starts_with($recoveryDb, 'brvtal_test_recovery_'), 'Recovery DB prefix guard failed.');
    recovery_expect($recoveryDb !== $sourceDb, 'Recovery DB must differ from source DB.');

    $source->exec('DROP VIEW IF EXISTS backup_recovery_view');
    $source->exec('DROP TABLE IF EXISTS backup_recovery_child');
    $source->exec('DROP TABLE IF EXISTS backup_recovery_parent');
    $source->exec(
        'CREATE TABLE backup_recovery_parent (' .
        'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,' .
        'label VARCHAR(190) NOT NULL,' .
        'nullable_value VARCHAR(80) NULL,' .
        'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' .
        ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $source->exec(
        'CREATE TABLE backup_recovery_child (' .
        'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,' .
        'parent_id INT UNSIGNED NOT NULL,' .
        'note VARCHAR(190) NOT NULL,' .
        'CONSTRAINT fk_backup_recovery_parent FOREIGN KEY (parent_id) REFERENCES backup_recovery_parent(id)' .
        ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $parentInsert = $source->prepare('INSERT INTO backup_recovery_parent(label,nullable_value) VALUES(?,?)');
    $parentInsert->execute(["O'Reilly / BRVTAL — áéí", null]);
    $parentInsert->execute(['Second snapshot row', 'preserved']);
    $childInsert = $source->prepare('INSERT INTO backup_recovery_child(parent_id,note) VALUES(?,?)');
    $childInsert->execute([1, 'linked before backup']);
    $source->exec(
        'CREATE VIEW backup_recovery_view AS ' .
        'SELECT p.id,p.label,COUNT(c.id) AS child_count ' .
        'FROM backup_recovery_parent p LEFT JOIN backup_recovery_child c ON c.parent_id=p.id ' .
        'GROUP BY p.id,p.label'
    );
    $sourceFixturesCreated = true;

    $manifest = brvtal_backup_create($source, [
        'base_dir' => $backupDir,
        'uploads_dir' => $uploadsDir,
        'include_media_archive' => true,
        'created_by' => ['id' => 0, 'name' => 'CI Recovery Rehearsal'],
        'deployment' => [
            'commit' => str_repeat('b', 40),
            'short_commit' => 'bbbbbbb',
            'source' => 'backup_recovery_rehearsal',
            'version' => 'rehearsal',
            'environment' => 'CI',
        ],
    ]);

    recovery_expect(($manifest['status'] ?? null) === 'ready', 'Backup with media archive must be ready for rehearsal.');
    recovery_expect(($manifest['restore_supported'] ?? true) === false, 'Product restore_supported flag must remain false.');
    $evidence['backupId'] = (string)($manifest['id'] ?? '');

    $databasePath = brvtal_backup_resolve_component($manifest, 'database', $backupDir);
    $mediaArchivePath = brvtal_backup_resolve_component($manifest, 'media_archive', $backupDir);
    recovery_expect(is_string($databasePath) && is_file($databasePath), 'Database artifact missing.');
    recovery_expect(is_string($mediaArchivePath) && is_file($mediaArchivePath), 'Media archive missing.');

    $databaseHash = hash_file('sha256', $databasePath);
    recovery_expect(is_string($databaseHash) && hash_equals((string)$manifest['components']['database']['sha256'], $databaseHash), 'Database checksum mismatch.');
    $evidence['checks']['databaseChecksum'] = true;

    // Mutate the source after the snapshot. Recovery must reproduce the backup,
    // not these later changes.
    $source->exec("UPDATE backup_recovery_parent SET label='MUTATED AFTER BACKUP' WHERE id=1");
    $source->exec("INSERT INTO backup_recovery_parent(label,nullable_value) VALUES('POST BACKUP ROW','must-not-restore')");
    file_put_contents($uploadsDir . '/2026/09/sample.txt', "MUTATED AFTER BACKUP\n");

    $server->exec('CREATE DATABASE ' . brvtal_backup_identifier($recoveryDb) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $recoveryCreated = true;
    recovery_mysql_restore($host, $port, $user, $password, $recoveryDb, $databasePath);

    $recovery = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $recoveryDb),
        $user,
        $password,
        $options
    );

    $parents = $recovery->query('SELECT id,label,nullable_value FROM backup_recovery_parent ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    recovery_expect(count($parents) === 2, 'Recovered snapshot must contain exactly the two pre-backup parent rows.');
    recovery_expect(($parents[0]['label'] ?? null) === "O'Reilly / BRVTAL — áéí", 'Recovered Unicode/apostrophe value differs from snapshot.');
    recovery_expect(($parents[0]['nullable_value'] ?? 'not-null') === null, 'Recovered NULL value differs from snapshot.');
    recovery_expect(($parents[1]['label'] ?? null) === 'Second snapshot row', 'Second snapshot row missing after recovery.');
    $evidence['checks']['snapshotRows'] = true;

    $postBackupCount = (int)$recovery->query("SELECT COUNT(*) FROM backup_recovery_parent WHERE label='POST BACKUP ROW' OR label='MUTATED AFTER BACKUP'")->fetchColumn();
    recovery_expect($postBackupCount === 0, 'Post-backup source mutation leaked into recovered snapshot.');
    $evidence['checks']['postBackupMutationAbsent'] = true;

    $child = $recovery->query('SELECT parent_id,note FROM backup_recovery_child ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    recovery_expect((int)($child['parent_id'] ?? 0) === 1 && ($child['note'] ?? null) === 'linked before backup', 'Recovered FK child row is incorrect.');
    $fkRejected = false;
    try {
        $recovery->exec("INSERT INTO backup_recovery_child(parent_id,note) VALUES(999999,'must fail')");
    } catch (PDOException) {
        $fkRejected = true;
    }
    recovery_expect($fkRejected, 'Recovered foreign-key constraint did not reject an invalid parent.');
    $evidence['checks']['foreignKey'] = true;

    $view = $recovery->query('SELECT id,label,child_count FROM backup_recovery_view WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    recovery_expect(($view['label'] ?? null) === "O'Reilly / BRVTAL — áéí" && (int)($view['child_count'] ?? 0) === 1, 'Recovered view is missing or returns incorrect snapshot data.');
    $evidence['checks']['view'] = true;

    @mkdir($recoveredMediaDir, 0700, true);
    $zip = new ZipArchive();
    recovery_expect($zip->open($mediaArchivePath) === true, 'Could not open generated media archive.');
    recovery_expect($zip->extractTo($recoveredMediaDir), 'Could not extract generated media archive.');
    $zip->close();
    $recoveredNested = $recoveredMediaDir . '/uploads/2026/09/sample.txt';
    $recoveredRoot = $recoveredMediaDir . '/uploads/root.txt';
    recovery_expect(is_file($recoveredNested) && is_file($recoveredRoot), 'Recovered media paths are incomplete.');
    recovery_expect(hash_equals($expectedNestedHash, (string)hash_file('sha256', $recoveredNested)), 'Recovered nested media differs from backup snapshot.');
    recovery_expect(hash_equals($expectedRootHash, (string)hash_file('sha256', $recoveredRoot)), 'Recovered root media differs from backup snapshot.');
    $evidence['checks']['mediaArchive'] = true;

    $evidence['status'] = 'passed';
    recovery_write_evidence($outputPath, $evidence);
    fwrite(STDOUT, "BRVTAL isolated backup recovery rehearsal passed.\n");
} catch (Throwable $error) {
    $failed = true;
    $evidence['status'] = 'failed';
    $evidence['error'] = mb_substr($error->getMessage(), 0, 800);
    recovery_write_evidence($outputPath, $evidence);
    fwrite(STDERR, "BACKUP RECOVERY REHEARSAL FAILED: {$error->getMessage()}\n");
} finally {
    if ($sourceFixturesCreated) {
        try { $source->exec('DROP VIEW IF EXISTS backup_recovery_view'); } catch (Throwable) {}
        try { $source->exec('DROP TABLE IF EXISTS backup_recovery_child'); } catch (Throwable) {}
        try { $source->exec('DROP TABLE IF EXISTS backup_recovery_parent'); } catch (Throwable) {}
    }
    if ($recoveryCreated && preg_match('/^brvtal_test_recovery_[a-f0-9]{8}$/', $recoveryDb)) {
        try { $server->exec('DROP DATABASE IF EXISTS ' . brvtal_backup_identifier($recoveryDb)); } catch (Throwable) {}
    }
    if (is_array($manifest)) {
        try { brvtal_backup_cleanup($manifest, $backupDir); } catch (Throwable) {}
    }
    recovery_remove_tree($tmpRoot);
}

if ($failed) exit(1);
