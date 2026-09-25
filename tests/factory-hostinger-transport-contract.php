<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$transport = $root . '/ops/factory/transport.py';
$dispatcher = $root . '/ops/factory/public_html-dispatcher.htaccess';

function hostinger_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FACTORY HOSTINGER TRANSPORT CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

function hostinger_remove_tree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) return;
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            hostinger_remove_tree($path . DIRECTORY_SEPARATOR . $entry);
        }
        @rmdir($path);
        return;
    }
    @unlink($path);
}

function hostinger_run(array $command, string $cwd, array $env): array
{
    $descriptors = [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
    $process = proc_open($command, $descriptors, $pipes, $cwd, array_merge([
        'PATH'=>(string)(getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
        'HOME'=>(string)(getenv('HOME') ?: sys_get_temp_dir()),
    ], $env), ['bypass_shell'=>true]);
    hostinger_expect(is_resource($process), 'could not start child process');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $code = proc_close($process);
    return ['code'=>$code,'stdout'=>(string)$stdout,'stderr'=>(string)$stderr];
}

hostinger_expect(is_file($transport) && is_executable($transport), 'transport.py must exist and be executable');
hostinger_expect(is_file($dispatcher), 'public_html dispatcher template must exist');

$transportSource = (string)file_get_contents($transport);
$dispatcherSource = (string)file_get_contents($dispatcher);
hostinger_expect(str_contains($transportSource, 'StrictHostKeyChecking=yes'), 'SSH must fail closed on host-key verification');
hostinger_expect(str_contains($transportSource, 'UserKnownHostsFile='), 'SSH must use isolated known_hosts');
hostinger_expect(str_contains($transportSource, 'os.chmod(key, 0o600)'), 'ephemeral private key must be 0600');
hostinger_expect(str_contains($transportSource, '_ALLOWED_FIELDS'), 'descriptor must have an exact field allowlist');
hostinger_expect(str_contains($transportSource, 'factory-releases') && str_contains($transportSource, 'factory-shared'), 'release layout must separate immutable and shared state');
hostinger_expect(str_contains($transportSource, '.factory-release-sha'), 'staged artifact must expose exact SHA identity');
hostinger_expect(str_contains($transportSource, 'verify-plan'), 'remote migration must verify the database registry against the deterministic plan');
hostinger_expect(str_contains($transportSource, 'inspect-migrations'), 'transport must expose bounded read-only migration inspection');
hostinger_expect(str_contains($transportSource, 'reconcile-migrations'), 'transport must expose controlled migration reconciliation');
hostinger_expect(str_contains($transportSource, 'migration-reconcile-backup-'), 'reconciliation must record backup evidence before registry writes');
hostinger_expect(str_contains($transportSource, 'BRVTAL_MIGRATION_RECONCILE_BACKUP_READY=1'), 'reconciliation must pass explicit backup evidence to the CLI');

preg_match_all('/ssh_(?:script|status|capture)\\(config,\\s*(_[A-Z_]+)/', $transportSource, $scriptRefs);
preg_match_all('/^(_[A-Z_]+) = r"""/m', $transportSource, $scriptDefs);
$definedScripts = array_count_values($scriptDefs[1] ?? []);
foreach (array_unique($scriptRefs[1] ?? []) as $scriptRef) {
    hostinger_expect(($definedScripts[$scriptRef] ?? 0) === 1, "transport script {$scriptRef} must be defined exactly once");
}
foreach ($definedScripts as $scriptName => $count) {
    hostinger_expect($count === 1, "transport script {$scriptName} must not be redefined");
}

hostinger_expect(str_contains($dispatcherSource, '# BRVTAL FACTORY DISPATCHER v1'), 'dispatcher template must be versioned');
hostinger_expect(str_contains($dispatcherSource, '.factory-current'), 'dispatcher must route through the bounded release pointer');

$key = "-----BEGIN PRIVATE KEY-----\nTEST-ONLY-KEY-MATERIAL\n-----END PRIVATE KEY-----";
$descriptor = [
    'host'=>'127.0.0.1',
    'user'=>'u123456789',
    'port'=>65002,
    'site_root'=>'/home/u123456789/domains/brvtal.com.co',
    'known_hosts'=>'[127.0.0.1]:65002 ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAITESTONLYHOSTKEY',
];
$token = json_encode($descriptor, JSON_UNESCAPED_SLASHES);
hostinger_expect(is_string($token), 'descriptor must encode');
$valid = hostinger_run(['python3',$transport,'validate'], $root, ['DEPLOY_TOKEN'=>$token,'DEPLOY_SSH_KEY'=>$key]);
hostinger_expect($valid['code'] === 0, 'valid strict descriptor must pass');
hostinger_expect(!str_contains($valid['stdout'] . $valid['stderr'], 'TEST-ONLY-KEY-MATERIAL'), 'private key material must never be logged');

$invalid = [
    array_merge($descriptor, ['host'=>'bad;host']),
    array_merge($descriptor, ['host'=>'999.0.0.1']),
    array_merge($descriptor, ['site_root'=>'/home/u123456789/domains/../escape']),
    array_merge($descriptor, ['site_root'=>'/home/u123456789/domains/brvtal.com.co/public_html']),
    array_merge($descriptor, ['known_hosts'=>'other.example ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAITESTONLYHOSTKEY']),
];
foreach ($invalid as $candidate) {
    $result = hostinger_run(['python3',$transport,'validate'], $root, [
        'DEPLOY_TOKEN'=>(string)json_encode($candidate, JSON_UNESCAPED_SLASHES),
        'DEPLOY_SSH_KEY'=>$key,
    ]);
    hostinger_expect($result['code'] !== 0, 'unsafe descriptor must fail closed');
}
$unknown = $descriptor;
$unknown['extra'] = 'forbidden';
hostinger_expect(hostinger_run(['python3',$transport,'validate'], $root, [
    'DEPLOY_TOKEN'=>(string)json_encode($unknown, JSON_UNESCAPED_SLASHES),
    'DEPLOY_SSH_KEY'=>$key,
])['code'] !== 0, 'unknown descriptor fields must fail closed');
hostinger_expect(hostinger_run(['python3',$transport,'validate'], $root, [
    'DEPLOY_TOKEN'=>$token,
    'DEPLOY_SSH_KEY'=>'',
])['code'] !== 0, 'missing private key must fail closed');

$fixtureBase = $root . '/.factory-fixture';
$remote = $fixtureBase . '/hostinger-remote-' . bin2hex(random_bytes(4));
$fakeBin = $fixtureBase . '/hostinger-bin-' . bin2hex(random_bytes(4));
@mkdir($remote . '/factory-shared/config', 0700, true);
@mkdir($remote . '/factory-shared/uploads', 0700, true);
@mkdir($remote . '/factory-shared/storage/backups', 0700, true);
@mkdir($remote . '/factory-shared/.private', 0700, true);
@mkdir($remote . '/public_html', 0700, true);
@mkdir($fakeBin, 0700, true);
file_put_contents($remote . '/factory-shared/.prepared-v1', "prepared\n");
file_put_contents($remote . '/factory-shared/config/config.php', "<?php return [];\n");
copy($root . '/.private/.htaccess', $remote . '/factory-shared/.private/.htaccess');
copy($root . '/storage/.htaccess', $remote . '/factory-shared/storage/.htaccess');
copy($root . '/storage/backups/.htaccess', $remote . '/factory-shared/storage/backups/.htaccess');
copy($root . '/uploads/.htaccess', $remote . '/factory-shared/uploads/.htaccess');
copy($dispatcher, $remote . '/public_html/.htaccess');

$previousSha = str_repeat('b', 40);
@mkdir($remote . '/factory-releases/' . $previousSha, 0700, true);
file_put_contents($remote . '/factory-releases/' . $previousSha . '/.factory-release-sha', $previousSha . "\n");
@symlink($remote . '/factory-releases/' . $previousSha, $remote . '/public_html/.factory-current');

$fakeSsh = <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
key=""
hosts=""
while (($#)); do
  case "$1" in
    -F|-i|-p)
      [[ "$1" == "-i" ]] && key="$2"
      shift 2
      ;;
    -o)
      [[ "$2" == UserKnownHostsFile=* ]] && hosts="${2#UserKnownHostsFile=}"
      shift 2
      ;;
    *)
      target="$1"
      shift
      break
      ;;
  esac
done
[[ -n "$key" && -n "$hosts" ]]
[[ "$(stat -c '%a' "$key")" == "600" ]]
[[ "$(stat -c '%a' "$hosts")" == "600" ]]
[[ "$1" == "bash" && "$2" == "-s" && "$3" == "--" ]]
shift 3
original_root="$1"
shift
mapped=()
for arg in "$@"; do
  case "$arg" in
    "$original_root"/*) mapped+=("$BRVTAL_FAKE_REMOTE_ROOT${arg#$original_root}") ;;
    *) mapped+=("$arg") ;;
  esac
done
bash -s -- "$BRVTAL_FAKE_REMOTE_ROOT" "${mapped[@]}"
BASH;

$fakeScp = <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
while (($#)); do
  case "$1" in
    -F|-i|-P|-o) shift 2 ;;
    *)
      source="$1"
      destination="$2"
      break
      ;;
  esac
done
remote_path="${destination#*:}"
mkdir -p "$BRVTAL_FAKE_REMOTE_ROOT/factory-artifacts"
cp -- "$source" "$BRVTAL_FAKE_REMOTE_ROOT/factory-artifacts/${remote_path##*/}"
BASH;

file_put_contents($fakeBin . '/ssh', $fakeSsh . "\n");
file_put_contents($fakeBin . '/scp', $fakeScp . "\n");
$realPhp = escapeshellarg(PHP_BINARY);
$fakePhp = <<<BASH
#!/usr/bin/env bash
set -euo pipefail
if [[ "\${1:-}" == "scripts/migrations.php" && "\${2:-}" == "verify-plan" ]]; then
  [[ "\${BRVTAL_FAKE_MIGRATION_VERIFY:-ok}" == "ok" ]] || exit 65
  exit 0
fi
exec {$realPhp} "\$@"
BASH;
file_put_contents($fakeBin . '/php', $fakePhp . "\n");
@chmod($fakeBin . '/ssh', 0700);
@chmod($fakeBin . '/scp', 0700);
@chmod($fakeBin . '/php', 0700);

$head = strtolower(trim((string)shell_exec('git rev-parse HEAD')));
hostinger_expect((bool)preg_match('/^[a-f0-9]{40}$/', $head), 'test checkout must expose an exact HEAD');
$remoteEnv = [
    'PATH'=>$fakeBin . ':' . (string)(getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
    'HOME'=>(string)(getenv('HOME') ?: sys_get_temp_dir()),
    'DEPLOY_TOKEN'=>$token,
    'DEPLOY_SSH_KEY'=>$key,
    'BRVTAL_FAKE_REMOTE_ROOT'=>$remote,
    'GITHUB_SHA'=>$head,
    'VERSION'=>'0.1.53',
    'MIGRATION_MODE'=>'none',
    'PHASE'=>'construccion',
];

try {
    $build = hostinger_run([$root . '/ops/factory/build'], $root, $remoteEnv);
    hostinger_expect($build['code'] === 0, 'fake-SSH build/upload must succeed: ' . trim($build['stderr']));
    $candidate = $remote . '/factory-releases/' . $head;
    hostinger_expect(is_dir($candidate), 'release must be staged below factory-releases/<sha>');
    hostinger_expect(trim((string)file_get_contents($candidate . '/.git/HEAD')) === $head, 'staged release must expose exact SHA');
    hostinger_expect(is_link($candidate . '/uploads') && readlink($candidate . '/uploads') === $remote . '/factory-shared/uploads', 'uploads must be shared between releases');
    hostinger_expect(is_link($candidate . '/storage') && readlink($candidate . '/storage') === $remote . '/factory-shared/storage', 'storage must be shared between releases');
    hostinger_expect(is_link($candidate . '/config/config.php') && readlink($candidate . '/config/config.php') === $remote . '/factory-shared/config/config.php', 'production config must be shared between releases');
    hostinger_expect(is_file($remote . '/factory-shared/.private/.htaccess'), 'shared private state must retain a web deny rule');
    hostinger_expect(is_file($remote . '/factory-shared/storage/backups/.htaccess'), 'shared backups must retain a web deny rule');

    @mkdir($remote . '/factory-releases/' . $previousSha . '/database', 0700, true);
    foreach (glob($candidate . '/database/migration_*.sql') ?: [] as $migrationPath) {
        copy($migrationPath, $remote . '/factory-releases/' . $previousSha . '/database/' . basename($migrationPath));
    }
    $migrationNoop = hostinger_run(
        [$root . '/ops/factory/migrate'],
        $root,
        array_merge($remoteEnv, ['MIGRATION_MODE'=>'additive'])
    );
    hostinger_expect($migrationNoop['code'] === 0, 'fake-SSH deterministic no-op migration must succeed: ' . trim($migrationNoop['stderr']));

    $migrationDrift = hostinger_run(
        [$root . '/ops/factory/migrate'],
        $root,
        array_merge($remoteEnv, [
            'MIGRATION_MODE'=>'additive',
            'BRVTAL_FAKE_MIGRATION_VERIFY'=>'fail',
        ])
    );
    hostinger_expect($migrationDrift['code'] !== 0, 'fake-SSH migration must fail when database registry does not match the file plan');

    $deploy = hostinger_run([$root . '/ops/factory/deploy'], $root, $remoteEnv);
    hostinger_expect($deploy['code'] === 0, 'fake-SSH activation must succeed: ' . trim($deploy['stderr']));
    hostinger_expect(readlink($remote . '/public_html/.factory-current') === $candidate, 'dispatcher pointer must switch atomically to candidate');

    $rollback = hostinger_run([$root . '/ops/factory/rollback'], $root, $remoteEnv);
    hostinger_expect($rollback['code'] === 0, 'fake-SSH rollback must succeed: ' . trim($rollback['stderr']));
    hostinger_expect(readlink($remote . '/public_html/.factory-current') === $remote . '/factory-releases/' . $previousSha, 'rollback must restore the previous artifact pointer');
} finally {
    hostinger_remove_tree($remote);
    hostinger_remove_tree($fakeBin);
}

$cutoverUnit = hostinger_run(['python3','-m','unittest','tests/test_hostinger_cutover.py'], $root, []);
hostinger_expect(
    $cutoverUnit['code'] === 0,
    'Hostinger cutover unittest failed: ' . trim($cutoverUnit['stderr'])
);

echo "BRVTAL Hostinger SSH transport contract passed.\n";
