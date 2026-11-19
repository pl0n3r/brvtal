<?php
declare(strict_types=1);

/**
 * #1792: synthetic CLI fixture. No production database, credentials, or SQL.
 * Verifies that real CLI catch paths release only opaque codes and messages.
 */
$root = dirname(__DIR__);
$src = $root . '/scripts/migrations.php';
function diagnostic_expect(bool $ok, string $reason): void {
    if (!$ok) { fwrite(STDERR, "Migration diagnostic contract: {$reason}\n"); exit(1); }
}
function diagnostic_remove(string $path): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') diagnostic_remove($path . '/' . $entry);
        }
        @rmdir($path);
    } else { @unlink($path); }
}
$dir = sys_get_temp_dir() . '/br1792-' . bin2hex(random_bytes(8));
@mkdir($dir . '/scripts', 0700, true);
@mkdir($dir . '/config', 0700, true);
@mkdir($dir . '/database', 0700, true);
copy($src, $dir . '/scripts/migrations.php');
file_put_contents($dir . '/config/bootstrap.php', <<<'PHP'
<?php
class DiagnosticFakePDO extends PDO { public function __construct() {} }
function db(): PDO {
    if (getenv('DIAGNOSTIC_KIND') === 'db_connection') {
        $error = new PDOException('SECRET_SQL DSN token=SECRET_PII');
        $error->errorInfo = ['HY000', 2002, 'SECRET_PII'];
        throw $error;
    }
    return new DiagnosticFakePDO();
}
function brvtal_log(string $kind, string $label, array $context): void {
    file_put_contents(getenv('DIAGNOSTIC_LOG'), json_encode([$kind,$label,$context]));
}
PHP
);
file_put_contents($dir . '/config/deployment.php', <<<'PHP'
<?php
function brvtal_deployment_sha(): string { return str_repeat('a', 40); }
PHP
);
file_put_contents($dir . '/config/migrations.php', <<<'PHP'
<?php
function brvtal_migrations_discover(string $directory): array { return []; }
PHP
);
file_put_contents($dir . '/config/migration_reconcile.php', <<<'PHP'
<?php
function brvtalMigrationReconcileHistorical(PDO $pdo, string $dir, ?string $actor, ?string $sha): array {
    file_put_contents(getenv('DIAGNOSTIC_REACHED'), 'yes');
    $kind = (string)getenv('DIAGNOSTIC_KIND');
    $states = [
        'connection' => '08006',
        'auth' => '28000',
        'ddl' => '42S01',
        'fk_ddl' => 'HY000',
        'unknown_hy000' => 'HY000',
        'constraint' => '23000',
        'serial' => '40001',
        'unknown_db' => 'HY000',
    ];
    if (isset($states[$kind])) {
        $error = new PDOException('SECRET_SQL SELECT personal_email FROM clients');
        $error->errorInfo = [$states[$kind], $kind === 'fk_ddl' ? 1005 : 1234, 'SECRET_PII'];
        throw $error;
    }
    if ($kind === 'partial') {
        throw new RuntimeException('MIGRATION_SCHEMA_PROOF_FAILED_AFTER_APPLY:migration_secret.sql');
    }
    if ($kind === 'policy') {
        throw new RuntimeException('MIGRATION_RECONCILIATION_DRIFT');
    }
    if ($kind === 'checksum') {
        throw new RuntimeException('MIGRATION_CHECKSUM_MISMATCH');
    }
    throw new RuntimeException('SECRET_SQL password=bearer@email.com');
}
PHP
);

$cases = [
    'connection'=>121, 'auth'=>121, 'db_connection'=>121,
    'ddl'=>122, 'fk_ddl'=>122, 'unknown_hy000'=>125, 'constraint'=>123,
    'serial'=>124, 'unknown_db'=>125, 'partial'=>126,
    'policy'=>120, 'checksum'=>120, 'unclassified'=>125,
];

try {
    foreach ($cases as $kind=>$expected) {
        $log = $dir . '/log';
        $reached = $dir . '/reached';
        @unlink($log);
        @unlink($reached);
        $env = array_merge($_ENV, [
            'PATH' => (string)(getenv('PATH') ?: '/usr/bin:/bin'),
            'BRVTAL_MIGRATIONS_ALLOW_WRITE' => '1',
            'BRVTAL_MIGRATION_RECONCILE_BACKUP_READY' => '1',
            'BRVTAL_MIGRATION_ACTOR' => 'test-only',
            'DIAGNOSTIC_KIND' => $kind,
            'DIAGNOSTIC_LOG' => $log,
            'DIAGNOSTIC_REACHED' => $reached,
        ]);
        $proc = proc_open(
            [PHP_BINARY, $dir . '/scripts/migrations.php', 'reconcile', '--confirm', '--json'],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes,
            $dir, $env, ['bypass_shell'=>true]
        );
        diagnostic_expect(is_resource($proc), "spawn {$kind}");
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $code = proc_close($proc);
        diagnostic_expect($code === $expected, "{$kind} exit={$code} expected={$expected}");
        diagnostic_expect($kind === 'db_connection' ? !is_file($reached) : is_file($reached),
            "{$kind}: synthetic reconcile invocation mismatch");
        diagnostic_expect($stdout === '', "{$kind}: stdout must be empty");
        $printed = (string)$stderr . (string)@file_get_contents($log);
        diagnostic_expect(str_contains($printed, 'safe_code') && str_contains($printed, (string)$expected), "{$kind}: safe category absent");
        diagnostic_expect(!preg_match('/SECRET_SQL|SECRET_PII|personal_email|bearer@email|password=|migration_secret\\.sql/', $printed), "{$kind}: secret leaked");
    }

    // Backup evidence gate: no call to reconcile, regardless of write permissions.
    @unlink($dir . '/reached');
    $env = array_merge($_ENV, [
        'PATH' => (string)(getenv('PATH') ?: '/usr/bin:/bin'),
        'BRVTAL_MIGRATIONS_ALLOW_WRITE' => '1',
        'BRVTAL_MIGRATION_RECONCILE_BACKUP_READY' => '0',
        'DIAGNOSTIC_REACHED' => $dir . '/reached',
        'DIAGNOSTIC_LOG' => $dir . '/log',
    ]);
    $p = proc_open([PHP_BINARY, $dir . '/scripts/migrations.php', 'reconcile', '--confirm'],
       [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, $dir, $env,
       ['bypass_shell'=>true]);
    diagnostic_expect(is_resource($p), 'spawn backup gate');
    fclose($pipes[0]);
    stream_get_contents($pipes[1]); fclose($pipes[1]);
    stream_get_contents($pipes[2]); fclose($pipes[2]);
    diagnostic_expect(proc_close($p) !== 0, 'backup gate must fail');
    diagnostic_expect(!is_file($dir . '/reached'), 'reconciliation executed without backup evidence');
} finally {
    diagnostic_remove($dir);
}
echo "BRVTAL migration reconcile sanitized diagnostics contract passed.\n";
