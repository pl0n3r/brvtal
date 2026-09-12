<?php
declare(strict_types=1);

function backups_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "BACKUPS CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$engine = (string)file_get_contents(__DIR__ . '/../config/backups.php');
$endpoint = (string)file_get_contents(__DIR__ . '/../discadmin/backups.php');
$script = (string)file_get_contents(__DIR__ . '/../discadmin/backups.js');
$styles = (string)file_get_contents(__DIR__ . '/../discadmin/backups.css');
$shell = (string)file_get_contents(__DIR__ . '/../discadmin/index.php');
$deny = (string)file_get_contents(__DIR__ . '/../storage/backups/.htaccess');
$ignore = (string)file_get_contents(__DIR__ . '/../.gitignore');

backups_expect(str_contains($endpoint, 'brvtal_admin_require();'), 'backup endpoint must require authenticated admin access');
backups_expect(str_contains($endpoint, 'brvtal_admin_require_csrf();'), 'backup creation must require CSRF');
backups_expect(str_contains($endpoint, "\$action === 'download'"), 'protected download action must exist');
backups_expect(str_contains($endpoint, "['database','media_manifest','media_archive','manifest']"), 'download component allowlist must be explicit');
backups_expect(str_contains($endpoint, "'restore' => false"), 'list capability must advertise restore as disabled');
backups_expect(str_contains($endpoint, "RESTORE_NOT_SUPPORTED"), 'restore action must be explicitly rejected');
backups_expect(!str_contains($endpoint, "\$action === 'delete'"), 'backup deletion must not exist in v1');
backups_expect(str_contains($endpoint, 'brvtal_activity_record('), 'backup creation/download must be activity audited');
backups_expect(str_contains($endpoint, "'X-Robots-Tag: noindex, nofollow'"), 'downloads must be noindex/nofollow');
backups_expect(str_contains($endpoint, "'Cache-Control'=>'no-store"), 'backup JSON responses must be no-store');

backups_expect(str_contains($engine, "SHOW FULL TABLES"), 'database backup must enumerate schema');
backups_expect(str_contains($engine, "SHOW CREATE TABLE"), 'database backup must preserve CREATE TABLE statements');
backups_expect(str_contains($engine, 'REPEATABLE READ'), 'database backup must use a consistent transaction isolation level');
backups_expect(str_contains($engine, 'FOREIGN_KEY_CHECKS=0'), 'database dump must guard FK ordering');
backups_expect(str_contains($engine, "hash_file('sha256'"), 'backup artifacts must receive SHA-256 checksums');
backups_expect(str_contains($engine, "'restore_supported' => false"), 'manifest must explicitly disable restore');
backups_expect(str_contains($engine, 'brvtal_deployment_sha()'), 'manifest must record deployment identity');
backups_expect(str_contains($engine, 'realpath('), 'download resolution must use real paths');
backups_expect(str_contains($engine, 'str_starts_with($candidate, $prefix)'), 'download resolution must prevent escaping private backup directory');
backups_expect(str_contains($engine, "['database', 'media_manifest', 'media_archive']"), 'engine component names must be allowlisted');
backups_expect(str_contains($engine, 'ZipArchive'), 'optional media archive must use native ZIP support');
backups_expect(str_contains($engine, 'MEDIA_SOURCE_EXCEEDS_V1_LIMIT'), 'media ZIP must have a source-size guardrail');

backups_expect(str_contains($deny, 'Require all denied'), 'private backup directory must deny direct web access');
backups_expect(str_contains($ignore, '/storage/backups/*'), 'generated backup artifacts must be gitignored');
backups_expect(str_contains($ignore, '!/storage/backups/.htaccess'), 'private access-control file must remain tracked');

backups_expect(str_contains($script, "'/discadmin/backups.php'"), 'System Status enhancement must use protected backup endpoint');
backups_expect(str_contains($script, 'X-CSRF-Token'), 'browser backup creation must send CSRF');
backups_expect(str_contains($script, 'CREATE BACKUP'), 'manual backup creation control must exist');
backups_expect(str_contains($script, 'CREATE + MEDIA ZIP'), 'optional media ZIP control must exist');
backups_expect(str_contains($script, 'NO DELETE / NO RESTORE IN V1'), 'v1 safety boundary must be visible');
backups_expect(str_contains($script, "document.getElementById('system-status-v2')"), 'backup UI must mount inside canonical System Status');
backups_expect(str_contains($styles, '.backup-summary'), 'backup UI must include visual summary styles');
backups_expect(str_contains($shell, 'backups.css'), 'canonical shell must load backup styles');
backups_expect(str_contains($shell, 'backups.js'), 'canonical shell must load backup enhancement');

require_once __DIR__ . '/../config/backups.php';
backups_expect(brvtal_backup_valid_id('brvtal-20260912T070000Z-abcdef12'), 'valid backup IDs must be accepted');
backups_expect(!brvtal_backup_valid_id('../config/config.php'), 'path-like backup IDs must be rejected');
backups_expect(!brvtal_backup_valid_id('brvtal-20260912T070000Z-ABCDEF12'), 'backup IDs are strict lowercase tokens');
backups_expect(brvtal_backup_component_filename(['id'=>'brvtal-20260912T070000Z-abcdef12'], '../database') === null, 'unknown/path-like components must be rejected');

$fake = [
    'id'=>'brvtal-20260912T070000Z-abcdef12',
    'components'=>[
        'database'=>['file'=>'../config.php'],
    ],
];
backups_expect(brvtal_backup_component_filename($fake, 'database') === null, 'manifest filenames with directory traversal must be rejected');

echo "BRVTAL Backups Foundation contract tests passed.\n";
