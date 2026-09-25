<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$adapterDir = $root . '/ops/factory';

function factory_adapter_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FACTORY DEPLOY ADAPTER CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

function factory_adapter_remove_tree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) return;
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            factory_adapter_remove_tree($path . DIRECTORY_SEPARATOR . $entry);
        }
        @rmdir($path);
        return;
    }
    @unlink($path);
}

function factory_adapter_run(string $root, string $name, array $env): array
{
    $path = $root . '/ops/factory/' . $name;
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $baseEnv = [
        'PATH' => (string)(getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
        'HOME' => (string)(getenv('HOME') ?: sys_get_temp_dir()),
    ];
    $process = proc_open([$path], $descriptors, $pipes, $root, array_merge($baseEnv, $env), ['bypass_shell' => true]);
    factory_adapter_expect(is_resource($process), "could not start adapter {$name}");
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $code = proc_close($process);
    return ['code' => $code, 'stdout' => (string)$stdout, 'stderr' => (string)$stderr];
}

foreach (['build', 'backup', 'migrate', 'deploy', 'rollback'] as $adapter) {
    $path = $adapterDir . '/' . $adapter;
    factory_adapter_expect(is_file($path), "{$adapter} adapter must exist");
    factory_adapter_expect(is_executable($path), "{$adapter} adapter must be executable");
}
factory_adapter_expect(is_file($adapterDir . '/common.sh'), 'common adapter library must exist');

$backupSource = (string)file_get_contents($adapterDir . '/backup');
$migrateSource = (string)file_get_contents($adapterDir . '/migrate');
$deploySource = (string)file_get_contents($adapterDir . '/deploy');
$rollbackSource = (string)file_get_contents($adapterDir . '/rollback');
$commonSource = (string)file_get_contents($adapterDir . '/common.sh');

factory_adapter_expect(str_contains($backupSource, 'config/backups.php'), 'production backup must reuse canonical backup engine');
factory_adapter_expect(str_contains($backupSource, 'brvtal_backup_create(db()'), 'production backup must call canonical backup creation');
factory_adapter_expect(str_contains($migrateSource, 'scripts/migrations.php apply'), 'production migration must reuse canonical migration CLI');
factory_adapter_expect(str_contains($migrateSource, 'BRVTAL_FACTORY_MIGRATION'), 'production migration must name one explicit migration');
factory_adapter_expect(!str_contains($migrateSource, 'DROP '), 'migration adapter must not contain destructive SQL');
factory_adapter_expect(str_contains($rollbackSource, 'previous-release'), 'rollback must use artifact release state');
factory_adapter_expect(!str_contains($rollbackSource, 'mysql') && !str_contains($rollbackSource, 'database'), 'rollback must never restore database state');
factory_adapter_expect(str_contains($commonSource, 'remote activation is not enabled'), 'remote mode must fail closed until a later slice');
factory_adapter_expect(str_contains($commonSource, '/.factory-fixture/'), 'fixture writes must stay inside repository guard');
factory_adapter_expect(str_contains($deploySource, 'backup marker missing before deploy'), 'deploy must require backup evidence');
factory_adapter_expect(str_contains($deploySource, 'migration marker missing before deploy'), 'additive deploy must require migration evidence');

$sha = str_repeat('a', 40);
$fixture = $root . '/.factory-fixture/contract-' . bin2hex(random_bytes(4));
$previous = $fixture . '/releases/' . str_repeat('b', 40);
$baseEnv = [
    'BRVTAL_FACTORY_ADAPTER_MODE' => 'fixture',
    'BRVTAL_FACTORY_FIXTURE_ROOT' => $fixture,
    'GITHUB_SHA' => $sha,
    'VERSION' => '0.1.53',
    'MIGRATION_MODE' => 'additive',
    'PHASE' => 'construccion',
];

try {
    @mkdir($previous, 0700, true);
    file_put_contents($previous . '/release.env', "version=0.1.52\nsha=" . str_repeat('b', 40) . "\n");
    @symlink($previous, $fixture . '/current');

    foreach (['build', 'backup', 'migrate', 'deploy'] as $stage) {
        $result = factory_adapter_run($root, $stage, $baseEnv);
        factory_adapter_expect($result['code'] === 0, "{$stage} fixture adapter failed: " . trim($result['stderr']));
    }

    factory_adapter_expect(is_link($fixture . '/current'), 'deploy must leave an atomic current symlink');
    factory_adapter_expect(realpath($fixture . '/current') === realpath($fixture . '/releases/' . $sha), 'deploy must activate candidate release');

    $rollback = factory_adapter_run($root, 'rollback', $baseEnv);
    factory_adapter_expect($rollback['code'] === 0, 'rollback fixture adapter must succeed');
    factory_adapter_expect(realpath($fixture . '/current') === realpath($previous), 'rollback must restore previous artifact pointer');

    $outside = factory_adapter_run($root, 'build', array_merge($baseEnv, [
        'BRVTAL_FACTORY_FIXTURE_ROOT' => sys_get_temp_dir() . '/brvtal-factory-outside',
    ]));
    factory_adapter_expect($outside['code'] !== 0, 'fixture root outside repository must fail closed');

    $backupFailureRoot = $root . '/.factory-fixture/backup-failure-' . bin2hex(random_bytes(4));
    $backupEnv = array_merge($baseEnv, ['BRVTAL_FACTORY_FIXTURE_ROOT' => $backupFailureRoot]);
    factory_adapter_expect(factory_adapter_run($root, 'build', $backupEnv)['code'] === 0, 'backup-failure fixture build must succeed');
    $failedBackup = factory_adapter_run($root, 'backup', array_merge($backupEnv, ['BRVTAL_FACTORY_FAIL_STAGE' => 'backup']));
    factory_adapter_expect($failedBackup['code'] !== 0, 'simulated backup failure must be observable');
    factory_adapter_expect(factory_adapter_run($root, 'deploy', $backupEnv)['code'] !== 0, 'deploy must refuse to proceed without backup evidence');
    factory_adapter_remove_tree($backupFailureRoot);

    $migrationFailureRoot = $root . '/.factory-fixture/migration-failure-' . bin2hex(random_bytes(4));
    $migrationEnv = array_merge($baseEnv, ['BRVTAL_FACTORY_FIXTURE_ROOT' => $migrationFailureRoot]);
    factory_adapter_expect(factory_adapter_run($root, 'build', $migrationEnv)['code'] === 0, 'migration-failure fixture build must succeed');
    factory_adapter_expect(factory_adapter_run($root, 'backup', $migrationEnv)['code'] === 0, 'migration-failure fixture backup must succeed');
    $failedMigration = factory_adapter_run($root, 'migrate', array_merge($migrationEnv, ['BRVTAL_FACTORY_FAIL_STAGE' => 'migrate']));
    factory_adapter_expect($failedMigration['code'] !== 0, 'simulated migration failure must be observable');
    factory_adapter_expect(factory_adapter_run($root, 'deploy', $migrationEnv)['code'] !== 0, 'deploy must refuse to proceed without migration evidence');
    factory_adapter_remove_tree($migrationFailureRoot);

    $disabled = factory_adapter_run($root, 'deploy', array_merge($baseEnv, ['BRVTAL_FACTORY_ADAPTER_MODE' => 'disabled']));
    factory_adapter_expect($disabled['code'] !== 0, 'remote deploy must stay disabled in this slice');
} finally {
    factory_adapter_remove_tree($fixture);
}

echo "BRVTAL Factory deploy adapter contract passed.\n";
