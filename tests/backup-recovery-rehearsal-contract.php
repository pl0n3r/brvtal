<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$workflowPath = $root . '/.github/workflows/backup-recovery-rehearsal.yml';
$rehearsalPath = $root . '/tests/integration/backup-recovery-rehearsal.php';
$backupsPath = $root . '/config/backups.php';
$endpointPath = $root . '/discadmin/backups.php';
$testingPath = $root . '/docs/TESTING.md';

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "BACKUP RECOVERY CONTRACT FAILED: {$message}\n");
        exit(1);
    }
};

$expect(is_file($workflowPath), 'isolated recovery rehearsal workflow must exist');
$expect(is_file($rehearsalPath), 'isolated recovery rehearsal integration must exist');

$workflow = (string)file_get_contents($workflowPath);
$rehearsal = (string)file_get_contents($rehearsalPath);
$backups = (string)file_get_contents($backupsPath);
$endpoint = (string)file_get_contents($endpointPath);
$testing = (string)file_get_contents($testingPath);

// The rehearsal is CI/test-only. It may never gain production credentials or a
// production target, and it must not become a scheduled/background restore.
$expect(str_contains($workflow, 'name: Backup Recovery Rehearsal'), 'workflow name must remain explicit');
$expect(str_contains($workflow, 'mariadb:11.4'), 'workflow must use an isolated MariaDB service');
$expect(str_contains($workflow, 'MARIADB_DATABASE: brvtal_test_backup_source'), 'workflow source DB must be a fixed test namespace');
$expect(str_contains($workflow, "BRVTAL_INTEGRATION_TESTS: '1'"), 'integration guard must be enabled explicitly');
$expect(str_contains($workflow, "BRVTAL_BACKUP_RECOVERY_REHEARSAL: '1'"), 'recovery guard must be enabled explicitly');
$expect(str_contains($workflow, 'BRVTAL_TEST_DB_NAME: brvtal_test_backup_source'), 'rehearsal must target only its disposable source DB');
$expect(str_contains($workflow, 'actions/upload-artifact@v4'), 'recovery evidence must be retained');
$expect(!preg_match('/^\s{2}(?:schedule|workflow_run):/m', $workflow), 'workflow must not run on schedule or workflow_run');
$expect(!str_contains($workflow, 'production-smoke'), 'workflow must not use the production-smoke environment');
$expect(!str_contains($workflow, 'secrets.'), 'workflow must not consume production or repository secrets');
$expect(!str_contains($workflow, 'brvtal.com.co'), 'workflow must not target production URLs');

// Two independent guards are required before any destructive fixture/recovery
// SQL can execute. Both source and recovery databases stay in brvtal_test_*.
$expect(str_contains($rehearsal, "getenv('BRVTAL_INTEGRATION_TESTS') !== '1'"), 'integration guard must be checked in code');
$expect(str_contains($rehearsal, "getenv('BRVTAL_BACKUP_RECOVERY_REHEARSAL') !== '1'"), 'recovery-specific guard must be checked in code');
$expect(str_contains($rehearsal, "'/^brvtal_test(?:_[a-z0-9]+)*$/'"), 'source DB name must be guarded by test namespace regex');
$expect(str_contains($rehearsal, "'brvtal_test_recovery_' . bin2hex(random_bytes(4))"), 'recovery DB must use a unique guarded namespace');
$expect(str_contains($rehearsal, "'/^brvtal_test_recovery_[a-f0-9]{8}$/'"), 'recovery DB destructive operations must use a strict prefix regex');
$expect(str_contains($rehearsal, "'CREATE DATABASE ' . brvtal_backup_identifier(\$recoveryDb)"), 'rehearsal must restore into a newly created recovery DB');
$expect(str_contains($rehearsal, "'DROP DATABASE IF EXISTS ' . brvtal_backup_identifier(\$recoveryDb)"), 'recovery DB must be destroyed in cleanup');
$expect(str_contains($rehearsal, 'proc_open($command'), 'generated SQL must be exercised by the mysql client');
$expect(str_contains($rehearsal, "putenv('MYSQL_PWD=' . \$password)"), 'mysql password must be passed out-of-band rather than placed in command arguments');
$expect(!str_contains($rehearsal, "'-p' . \$password"), 'mysql password must never be embedded in process arguments');

// The rehearsal must prove snapshot semantics, deterministic multi-chunk row
// coverage, schema integrity and media recoverability while restore stays off.
$expect(str_contains($rehearsal, "(\$manifest['restore_supported'] ?? true) === false"), 'rehearsal must assert production restore remains disabled');
$expect(str_contains($backups, "'restore_supported' => false"), 'backup manifest must still disable restore');
$expect(str_contains($endpoint, "RESTORE_NOT_SUPPORTED"), 'DISCADMIN restore endpoint must remain rejected');
$expect(str_contains($rehearsal, 'POST BACKUP ROW'), 'rehearsal must mutate source after the backup');
$expect(str_contains($rehearsal, 'postBackupMutationAbsent'), 'rehearsal must prove later mutations are absent from recovery');
$expect(str_contains($rehearsal, 'backup_recovery_chunked'), 'rehearsal must create a dedicated multi-chunk fixture table');
$expect(str_contains($rehearsal, '$rowNumber <= 620'), 'multi-chunk fixture must exceed the 250-row export chunk size');
$expect(str_contains($rehearsal, "'multiChunkRows' => false"), 'recovery evidence must track multi-chunk coverage');
$expect(str_contains($rehearsal, "=== 620, 'Multi-chunk recovery must contain all 620 snapshot rows.'"), 'rehearsal must verify exact restored row count across chunks');
$expect(str_contains($rehearsal, "id IN (1,250,251,500,501,620)"), 'rehearsal must verify rows on both sides of pagination boundaries');
$expect(str_contains($rehearsal, 'foreignKey'), 'rehearsal must verify restored foreign-key behavior');
$expect(str_contains($rehearsal, 'backup_recovery_view'), 'rehearsal must verify view recovery');
$expect(str_contains($rehearsal, 'ZipArchive'), 'rehearsal must extract and verify the media archive');
$expect(str_contains($rehearsal, 'mediaArchive'), 'media archive result must be recorded in evidence');
$expect(!str_contains($rehearsal, "'password' =>"), 'evidence must not serialize database passwords');

$expect(str_contains($testing, 'Backup recovery rehearsal'), 'testing docs must explain the recovery rehearsal');
$expect(str_contains($testing, 'restore_supported=false'), 'testing docs must preserve disabled production restore boundary');
$expect(str_contains($testing, 'brvtal_test_recovery_'), 'testing docs must name the isolated recovery namespace');

echo "BRVTAL backup recovery rehearsal safety contract passed.\n";
