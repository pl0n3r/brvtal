<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$transport = $root . '/ops/factory/transport.py';
$bootstrap = $root . '/ops/factory/bootstrap';

function bootstrap_expect(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FACTORY HOSTINGER BOOTSTRAP CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}
function bootstrap_rm(string $path): void {
    if (!file_exists($path) && !is_link($path)) return;
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') bootstrap_rm($path . DIRECTORY_SEPARATOR . $entry);
        }
        @rmdir($path);
        return;
    }
    @unlink($path);
}
function bootstrap_run(array $command, string $cwd, array $env): array {
    $pipes = [];
    $process = proc_open($command, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, $cwd, array_merge([
        'PATH'=>(string)(getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
        'HOME'=>(string)(getenv('HOME') ?: sys_get_temp_dir()),
    ], $env), ['bypass_shell'=>true]);
    bootstrap_expect(is_resource($process), 'could not start child process');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return ['code'=>proc_close($process),'stdout'=>(string)$stdout,'stderr'=>(string)$stderr];
}
function bootstrap_fixture(string $root, string $remote): void {
    @mkdir($remote . '/public_html/config', 0700, true);
    @mkdir($remote . '/public_html/uploads', 0700, true);
    @mkdir($remote . '/public_html/storage/backups', 0700, true);
    @mkdir($remote . '/public_html/.private', 0700, true);
    file_put_contents($remote . '/public_html/index.php', "<?php echo 'BRVTAL';\n");
    file_put_contents($remote . '/public_html/.htaccess', "Options -Indexes\n# legacy-dispatch\n");
    file_put_contents($remote . '/public_html/config/config.php', "<?php return [];\n");
    file_put_contents($remote . '/public_html/config/bootstrap.php', "<?php\n");
    file_put_contents($remote . '/public_html/config/backups.php', "<?php\n");
    copy($root . '/uploads/.htaccess', $remote . '/public_html/uploads/.htaccess');
    copy($root . '/storage/.htaccess', $remote . '/public_html/storage/.htaccess');
    copy($root . '/storage/backups/.htaccess', $remote . '/public_html/storage/backups/.htaccess');
    copy($root . '/.private/.htaccess', $remote . '/public_html/.private/.htaccess');
}

bootstrap_expect(is_file($transport) && is_executable($transport), 'transport must be executable');
bootstrap_expect(is_file($bootstrap) && is_executable($bootstrap), 'bootstrap wrapper must be executable');
$source = (string)file_get_contents($transport);
bootstrap_expect(str_contains($source, 'BRVTAL_HOSTINGER_GIT_AUTODEPLOY_DISABLED'), 'auto-deploy gate missing');
bootstrap_expect(str_contains($source, '_BOOTSTRAP_PROBE_CREATE'), 'HTTP symlink proof missing');
bootstrap_expect(str_contains($source, 'bootstrap-backup-$sha.ok'), 'backup evidence missing');
bootstrap_expect(str_contains($source, '_BOOTSTRAP_RESTORE'), 'legacy dispatcher restore missing');
bootstrap_expect(!str_contains($source, 'DROP DATABASE'), 'database rollback is forbidden');

$key = "-----BEGIN PRIVATE KEY-----\nTEST-ONLY-KEY-MATERIAL\n-----END PRIVATE KEY-----";
$descriptor = [
    'host'=>'127.0.0.1',
    'user'=>'u123456789',
    'port'=>65002,
    'site_root'=>'/home/u123456789/domains/brvtal.com.co',
    'known_hosts'=>'[127.0.0.1]:65002 ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAITESTONLYHOSTKEY',
];
$token = json_encode($descriptor, JSON_UNESCAPED_SLASHES);
bootstrap_expect(is_string($token), 'descriptor must encode');
$head = strtolower(trim((string)shell_exec('git rev-parse HEAD')));
bootstrap_expect((bool)preg_match('/^[a-f0-9]{40}$/', $head), 'exact checkout SHA required');
$versionSource = (string)file_get_contents($root . '/config/version.php');
bootstrap_expect((bool)preg_match("/BRVTAL_APP_VERSION',\\s*'([^']+)'/", $versionSource, $match), 'version must be readable');
$version = $match[1];

$fixtureBase = $root . '/.factory-fixture';
$remote = $fixtureBase . '/bootstrap-remote-' . bin2hex(random_bytes(4));
$fakeBin = $fixtureBase . '/bootstrap-bin-' . bin2hex(random_bytes(4));
bootstrap_fixture($root, $remote);
@mkdir($fakeBin, 0700, true);

$fakes = [
'ssh'=><<<'PY'
#!/usr/bin/env python3
import os, subprocess, sys
args=sys.argv[1:]
i=0
while i < len(args):
    if args[i] in {"-F","-i","-p","-o"}:
        i += 2
        continue
    i += 1
    break
rest=args[i:]
if rest[:3] != ["bash","-s","--"]:
    raise SystemExit(90)
orig=rest[3]
remote=os.environ["BRVTAL_FAKE_REMOTE_ROOT"]
mapped=[remote + arg[len(orig):] if arg.startswith(orig + "/") else arg for arg in rest[4:]]
env=os.environ.copy()
env["PATH"]=env["BRVTAL_FAKE_BIN"] + os.pathsep + env.get("PATH","")
p=subprocess.run(["bash","-s","--",remote,*mapped],input=sys.stdin.read(),text=True,env=env)
raise SystemExit(p.returncode)
PY,
'scp'=><<<'PY'
#!/usr/bin/env python3
import os, pathlib, shutil, sys
args=sys.argv[1:]
i=0
while i < len(args):
    if args[i] in {"-F","-i","-P","-o"}:
        i += 2
        continue
    break
source=args[i]
destination=args[i+1]
remote_path=destination.split(":",1)[1]
site=os.environ["BRVTAL_FAKE_SITE_ROOT"]
target=pathlib.Path(os.environ["BRVTAL_FAKE_REMOTE_ROOT"] + remote_path[len(site):])
target.parent.mkdir(parents=True,exist_ok=True)
shutil.copyfile(source,target)
PY,
'curl'=><<<'PY'
#!/usr/bin/env python3
import json, os, pathlib, sys
from urllib.parse import urlsplit
url=sys.argv[-1]
path=urlsplit(url).path
if path == "/api/deployment.php":
    print(json.dumps({"ok":True,"data":{"exact":True,"commit":os.environ.get("BRVTAL_FAKE_HTTP_SHA",os.environ["GITHUB_SHA"]),"version":os.environ.get("BRVTAL_FAKE_HTTP_VERSION",os.environ["VERSION"])}}),end="")
elif path.startswith("/factory-symlink-probe-") and path.endswith(".txt"):
    print((pathlib.Path(os.environ["BRVTAL_FAKE_REMOTE_ROOT"] + "/public_html" + path)).read_text(),end="")
elif path in {"/","/discadmin"}:
    if os.environ.get("BRVTAL_FAKE_HTTP_FAIL_PATH") == path:
        raise SystemExit(22)
    print("ok",end="")
else:
    raise SystemExit(22)
PY,
'php'=><<<'PY'
#!/usr/bin/env python3
import sys
sys.stdin.read()
PY,
'ln'=><<<'PY'
#!/usr/bin/env python3
import os, sys
if os.environ.get("BRVTAL_FAKE_DISABLE_SYMLINK") == "1":
    raise SystemExit(95)
os.execv("/usr/bin/ln",["ln",*sys.argv[1:]])
PY,
];
foreach ($fakes as $name=>$body) {
    file_put_contents($fakeBin . '/' . $name, $body . "\n");
    @chmod($fakeBin . '/' . $name, 0700);
}

$baseEnv = [
    'PATH'=>$fakeBin . ':' . (string)(getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
    'HOME'=>(string)(getenv('HOME') ?: sys_get_temp_dir()),
    'DEPLOY_TOKEN'=>$token,
    'DEPLOY_SSH_KEY'=>$key,
    'BRVTAL_FAKE_REMOTE_ROOT'=>$remote,
    'BRVTAL_FAKE_SITE_ROOT'=>$descriptor['site_root'],
    'BRVTAL_FAKE_BIN'=>$fakeBin,
    'GITHUB_SHA'=>$head,
    'VERSION'=>$version,
    'DOMAIN'=>'https://fake.example',
    'BRVTAL_HOSTINGER_GIT_AUTODEPLOY_DISABLED'=>'1',
];

try {
    $blocked = bootstrap_run([$bootstrap], $root, array_merge($baseEnv, ['BRVTAL_HOSTINGER_GIT_AUTODEPLOY_DISABLED'=>'0']));
    bootstrap_expect($blocked['code'] !== 0, 'active Hostinger Git auto-deploy must block bootstrap');

    $mismatch = bootstrap_run([$bootstrap], $root, array_merge($baseEnv, ['BRVTAL_FAKE_HTTP_SHA'=>str_repeat('f',40)]));
    bootstrap_expect($mismatch['code'] !== 0, 'identity mismatch must fail');
    bootstrap_expect(!is_dir($remote . '/factory-shared'), 'identity mismatch must not mutate layout');

    $noSymlink = bootstrap_run([$bootstrap], $root, array_merge($baseEnv, ['BRVTAL_FAKE_DISABLE_SYMLINK'=>'1']));
    bootstrap_expect($noSymlink['code'] !== 0, 'no-symlink environment must fail');
    bootstrap_expect(str_contains((string)file_get_contents($remote . '/public_html/.htaccess'), 'legacy-dispatch'), 'failed probe must preserve legacy dispatch');

    $success = bootstrap_run([$bootstrap], $root, $baseEnv);
    bootstrap_expect($success['code'] === 0, 'happy bootstrap failed: ' . trim($success['stderr']));
    $candidate = $remote . '/factory-releases/' . $head;
    bootstrap_expect(is_dir($candidate), 'exact release snapshot missing');
    bootstrap_expect(trim((string)file_get_contents($candidate . '/.factory-release-sha')) === $head, 'release SHA marker mismatch');
    bootstrap_expect(is_link($remote . '/factory-shared/uploads'), 'shared uploads alias missing');
    bootstrap_expect(readlink($remote . '/factory-shared/uploads') === $remote . '/public_html/uploads', 'uploads must not be copied or moved');
    bootstrap_expect(is_link($remote . '/public_html/.factory-current'), 'current pointer missing');
    bootstrap_expect(readlink($remote . '/public_html/.factory-current') === $candidate, 'current pointer target mismatch');
    bootstrap_expect(str_contains((string)file_get_contents($remote . '/public_html/.htaccess'), '# BRVTAL FACTORY DISPATCHER v1'), 'dispatcher not installed');
    bootstrap_expect(is_file($remote . '/factory-state/bootstrap-backup-' . $head . '.ok'), 'backup marker missing');

    $again = bootstrap_run([$bootstrap], $root, $baseEnv);
    bootstrap_expect($again['code'] === 0, 'bootstrap must be idempotent');

    $restoreRemote = $fixtureBase . '/bootstrap-restore-' . bin2hex(random_bytes(4));
    bootstrap_fixture($root, $restoreRemote);
    $failedSmoke = bootstrap_run([$bootstrap], $root, array_merge($baseEnv, [
        'BRVTAL_FAKE_REMOTE_ROOT'=>$restoreRemote,
        'BRVTAL_FAKE_HTTP_FAIL_PATH'=>'/',
    ]));
    bootstrap_expect($failedSmoke['code'] !== 0, 'failed public smoke must fail bootstrap');
    bootstrap_expect(str_contains((string)file_get_contents($restoreRemote . '/public_html/.htaccess'), 'legacy-dispatch'), 'failed smoke must restore legacy .htaccess');
    bootstrap_expect(!is_link($restoreRemote . '/public_html/.factory-current'), 'failed smoke must remove current pointer');
    bootstrap_rm($restoreRemote);

    $unsafeRemote = $fixtureBase . '/bootstrap-unsafe-' . bin2hex(random_bytes(4));
    bootstrap_fixture($root, $unsafeRemote);
    @unlink($unsafeRemote . '/public_html/storage/backups/.htaccess');
    $unsafe = bootstrap_run([$bootstrap], $root, array_merge($baseEnv, ['BRVTAL_FAKE_REMOTE_ROOT'=>$unsafeRemote]));
    bootstrap_expect($unsafe['code'] !== 0, 'missing deny guard must fail');
    bootstrap_expect(str_contains((string)file_get_contents($unsafeRemote . '/public_html/.htaccess'), 'legacy-dispatch'), 'unsafe state must preserve legacy dispatch');
    bootstrap_rm($unsafeRemote);
} finally {
    bootstrap_rm($remote);
    bootstrap_rm($fakeBin);
}

echo "BRVTAL Hostinger bootstrap contract passed.\n";
