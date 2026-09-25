<?php
declare(strict_types=1);

function deployment_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "DEPLOYMENT TRACEABILITY CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

function deployment_remove_tree(string $path): void
{
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        $target = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($target)) deployment_remove_tree($target); else @unlink($target);
    }
    @rmdir($path);
}

function deployment_run_scenario(string $runnerPath, ?string $commit): array
{
    $env = getenv();
    if (!is_array($env)) $env = [];
    if ($commit === null) unset($env['BRVTAL_DEPLOY_COMMIT']); else $env['BRVTAL_DEPLOY_COMMIT'] = $commit;
    $pipes = [];
    $process = proc_open([PHP_BINARY, $runnerPath], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname($runnerPath), $env);
    deployment_expect(is_resource($process), 'isolated deployment scenario process must start');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    deployment_expect($status === 0, 'isolated deployment scenario failed: ' . trim((string)$stderr));
    $decoded = json_decode((string)$stdout, true);
    deployment_expect(is_array($decoded), 'isolated deployment scenario must return JSON');
    return $decoded;
}

$root = dirname(__DIR__);
$resolverPath = $root . '/config/deployment.php';
$versionPath = $root . '/config/version.php';
$endpoint = (string)file_get_contents($root . '/api/deployment.php');
$health = (string)file_get_contents($root . '/api/health.php');
$admin = (string)file_get_contents($root . '/discadmin/index-core.php');
$adminShell = (string)file_get_contents($root . '/discadmin/index.php');
$publicEntry = (string)file_get_contents($root . '/index.php');
$package = json_decode((string)file_get_contents($root . '/package.json'), true);
require_once $versionPath;
require_once $root . '/config/public_assets.php';

$sandbox = sys_get_temp_dir() . '/brvtal-deployment-contract-' . bin2hex(random_bytes(6));
deployment_expect(mkdir($sandbox . '/config', 0700, true), 'temporary deployment sandbox must be created');
copy($resolverPath, $sandbox . '/config/deployment.php');
copy($versionPath, $sandbox . '/config/version.php');
$runnerPath = $sandbox . '/runner.php';
file_put_contents($runnerPath, <<<'PHP'
<?php
declare(strict_types=1);
require __DIR__ . '/config/deployment.php';
echo json_encode([
    'sha' => brvtal_deployment_sha(),
    'short' => brvtal_deployment_short_sha(),
    'source' => brvtal_deployment_source(),
    'exact' => brvtalDeploymentIsExact(),
    'release' => brvtalReleaseIdentity(),
    'cache' => brvtalDeploymentCacheKey(),
    'public' => brvtalDeploymentPublicData(),
], JSON_THROW_ON_ERROR);
PHP);

try {
    $exactSha = '0123456789abcdef0123456789abcdef01234567';
    $exact = deployment_run_scenario($runnerPath, $exactSha);
    deployment_expect($exact['sha'] === $exactSha, 'explicit exact SHA must be preserved');
    deployment_expect($exact['short'] === '0123456', 'exact short SHA must derive from exact source');
    deployment_expect($exact['source'] === 'environment', 'explicit deployment SHA must report environment source');
    deployment_expect($exact['exact'] === true, 'explicit deployment SHA must be exact');
    deployment_expect($exact['release'] === 'v' . BRVTAL_APP_VERSION, 'release identity must be product-version based');
    deployment_expect($exact['cache'] === '0123456', 'exact deployments must use short SHA cache key');
    deployment_expect($exact['public']['exact'] === true, 'public data must expose exact=true');
    deployment_expect($exact['public']['commit'] === $exactSha, 'public data must expose exact commit');
    deployment_expect($exact['public']['cache_key'] === '0123456', 'public data must expose exact cache key');

    $gitSha = '89abcdef0123456789abcdef0123456789abcdef';
    deployment_expect(mkdir($sandbox . '/.git', 0700, true), 'temporary Git metadata directory must be created');
    file_put_contents($sandbox . '/.git/HEAD', $gitSha . "\n");
    $git = deployment_run_scenario($runnerPath, null);
    deployment_expect($git['sha'] === $gitSha, 'Git HEAD exact SHA must be preserved');
    deployment_expect($git['short'] === substr($gitSha, 0, 7), 'Git HEAD short SHA must derive from exact source');
    deployment_expect($git['source'] === 'git_checkout', 'Git metadata fallback must report git_checkout source');
    deployment_expect($git['exact'] === true, 'Git metadata fallback must remain exact');
    deployment_expect($git['cache'] === substr($gitSha, 0, 7), 'Git metadata fallback must use short SHA cache key');
    deployment_expect($git['public']['commit'] === $gitSha, 'public Git metadata data must expose exact commit');
    @unlink($sandbox . '/.git/HEAD');
    @rmdir($sandbox . '/.git');

    $fallback = deployment_run_scenario($runnerPath, null);
    deployment_expect($fallback['source'] === 'release_fallback', 'missing env/git metadata must use release fallback');
    deployment_expect($fallback['exact'] === false, 'release fallback must not be exact');
    deployment_expect($fallback['release'] === 'v' . BRVTAL_APP_VERSION, 'fallback release identity must remain canonical');
    deployment_expect($fallback['cache'] === 'release-' . BRVTAL_APP_VERSION, 'fallback cache key must use product version');
    deployment_expect($fallback['public']['exact'] === false, 'public fallback data must expose exact=false');
    deployment_expect($fallback['public']['commit'] === null, 'public fallback data must hide compatibility build metadata as commit');
    deployment_expect($fallback['public']['short_commit'] === null, 'public fallback data must hide compatibility short build metadata');
    deployment_expect($fallback['public']['cache_key'] === 'release-' . BRVTAL_APP_VERSION, 'public fallback data must expose release cache key');

    $exactVersioned = brvtal_public_version_assets('<link href="css/style.css"><script src="js/app.js"></script>', (string)$exact['cache']);
    deployment_expect(str_contains($exactVersioned, 'href="css/style.css?v=0123456"'), 'public CSS must use exact cache key');
    deployment_expect(str_contains($exactVersioned, 'src="js/app.js?v=0123456"'), 'public JavaScript must use exact cache key');
    $fallbackVersioned = brvtal_public_version_assets('<link href="css/style.css"><script src="js/app.js"></script>', (string)$fallback['cache']);
    deployment_expect(str_contains($fallbackVersioned, 'href="css/style.css?v=release-' . BRVTAL_APP_VERSION . '"'), 'public CSS must use release fallback cache key');
    deployment_expect(str_contains($fallbackVersioned, 'src="js/app.js?v=release-' . BRVTAL_APP_VERSION . '"'), 'public JavaScript must use release fallback cache key');
} finally {
    deployment_remove_tree($sandbox);
}

deployment_expect(str_contains($endpoint, "'data' => brvtalDeploymentPublicData()"), 'deployment endpoint must publish the executable deployment data contract');
deployment_expect(
    str_contains($health, "'source' => brvtal_deployment_source()")
        && str_contains($health, "'deployment' => \$deployment"),
    'health response must identify deployed source'
);
deployment_expect(is_array($package), 'package.json must remain valid JSON');
deployment_expect(($package['version'] ?? null) === BRVTAL_APP_VERSION, 'package.json version must match canonical BRVTAL_APP_VERSION');
deployment_expect(str_contains($admin, 'data-testid="admin-product-version"'), 'DISCADMIN must display the human product version');
deployment_expect(str_contains($admin, 'data-testid="admin-deploy-source"'), 'DISCADMIN must retain deployed source as secondary detail');
deployment_expect(str_contains($admin, 'SOURCE UNAVAILABLE'), 'DISCADMIN must not present fallback metadata as exact SHA');
deployment_expect(str_contains($adminShell, 'brvtalDeploymentCacheKey()'), 'DISCADMIN assets must use resilient cache key');
deployment_expect(str_contains($publicEntry, 'brvtal_public_version_assets($html, brvtalDeploymentCacheKey())'), 'public entrypoint must feed resolved cache key into asset rendering');

echo "BRVTAL deployment traceability contract tests passed.\n";
