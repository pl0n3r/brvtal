<?php
declare(strict_types=1);

function ci_scope_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "CI SCOPE CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

/** @return array<string,string> */
function ci_scope_run(array $files, string $event = 'pull_request'): array
{
    $script = realpath(__DIR__ . '/../scripts/ci-scope.sh');
    ci_scope_expect(is_string($script) && $script !== '', 'shared CI scope classifier must exist');
    $payload = implode("\n", array_map('strval', $files));
    $command = [
        'bash',
        '-c',
        'source "$1"; brvtal_ci_classify_files "$2" "$3"; brvtal_ci_scope_print',
        '_',
        $script,
        $payload,
        $event,
    ];
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    ci_scope_expect(is_resource($process), 'shared CI scope classifier must be executable');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    ci_scope_expect($status === 0, 'shared CI scope classifier failed: ' . trim((string)$stderr));

    $result = [];
    foreach (preg_split('/\R/', trim((string)$stdout)) ?: [] as $line) {
        if (!str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $result[$key] = $value;
    }
    return $result;
}

function ci_scope_expect_flags(array $actual, array $expected, string $label): void
{
    foreach ($expected as $key => $value) {
        ci_scope_expect(($actual[$key] ?? null) === $value, "{$label}: expected {$key}={$value}, got " . ($actual[$key] ?? '<missing>'));
    }
}

$workflow = (string)file_get_contents(__DIR__ . '/../.github/workflows/update-release-metadata.yml');
$package = (string)file_get_contents(__DIR__ . '/../package.json');
$codeRabbit = (string)file_get_contents(__DIR__ . '/../.coderabbit.yaml');
$sonar = (string)file_get_contents(__DIR__ . '/../.sonarcloud.properties');
$performance = (string)file_get_contents(__DIR__ . '/../.github/workflows/production-performance.yml');

ci_scope_expect(str_contains($workflow, 'source scripts/ci-scope.sh'), 'BRVTAL CI must execute the shared changed-file classifier');
ci_scope_expect(str_contains($workflow, 'brvtal_ci_classify_files "$changed_file_list" "$BRVTAL_EVENT"'), 'workflow must pass its actual changed-file list and event to the shared classifier');
ci_scope_expect(str_contains($workflow, 'run: npm run test:integration'), 'CI database gate must call the canonical integration script');
ci_scope_expect(!str_contains($workflow, "php tests/integration/global-search.php\n          php tests/integration/bulk-actions.php"), 'CI must not maintain a second manual integration list');

ci_scope_expect_flags(ci_scope_run(['api/hero-slider.php']), [
    'full' => 'false', 'run_db' => 'true', 'run_browser' => 'true', 'run_realstack' => 'true', 'run_webkit' => 'false', 'run_recovery' => 'false',
], 'public hero API');
ci_scope_expect_flags(ci_scope_run(['config/public_home.php']), [
    'run_db' => 'true', 'run_browser' => 'true', 'run_realstack' => 'true', 'run_webkit' => 'false', 'run_recovery' => 'false',
], 'public runtime config');
ci_scope_expect_flags(ci_scope_run(['package.json']), [
    'run_db' => 'true', 'run_browser' => 'true', 'run_realstack' => 'true', 'run_webkit' => 'true', 'run_recovery' => 'false',
], 'test tooling');
ci_scope_expect_flags(ci_scope_run(['config/totp_auth.php']), [
    'run_db' => 'true', 'run_browser' => 'false', 'run_realstack' => 'true', 'run_webkit' => 'true', 'run_recovery' => 'false',
], 'auth-sensitive runtime config');
ci_scope_expect_flags(ci_scope_run(['config/backups.php']), [
    'run_db' => 'true', 'run_browser' => 'false', 'run_realstack' => 'true', 'run_webkit' => 'false', 'run_recovery' => 'true',
], 'backup runtime config');
ci_scope_expect_flags(ci_scope_run(['README.md', 'api/hero-slider.php', 'config/totp_auth.php']), [
    'run_db' => 'true', 'run_browser' => 'true', 'run_realstack' => 'true', 'run_webkit' => 'true', 'run_recovery' => 'false',
], 'combined changed-file union');
ci_scope_expect_flags(ci_scope_run([], 'workflow_dispatch'), [
    'full' => 'true', 'run_db' => 'true', 'run_browser' => 'true', 'run_realstack' => 'true', 'run_webkit' => 'true', 'run_recovery' => 'true',
], 'manual full matrix');

$packageData = json_decode($package, true);
ci_scope_expect(is_array($packageData), 'package.json must stay valid JSON');
$contracts = (string)($packageData['scripts']['test:contracts'] ?? '');
$integration = (string)($packageData['scripts']['test:integration'] ?? '');
ci_scope_expect($contracts === 'bash scripts/php85-compatibility.sh', 'local contract command must use the same auto-discovery entry point as CI');
foreach (['global-search.php', 'bulk-actions.php', 'public-archive.php', 'related-content.php', 'admin-activity.php', 'backups.php'] as $test) {
    ci_scope_expect(str_contains($integration, $test), "canonical integration command must include {$test}");
}

ci_scope_expect(str_contains($codeRabbit, 'request_changes_workflow: false'), 'CodeRabbit must remain advisory until review noise is calibrated');
ci_scope_expect(str_contains($codeRabbit, 'base_branches:') && str_contains($codeRabbit, '- main'), 'CodeRabbit auto-review must target main PRs');
ci_scope_expect(str_contains($codeRabbit, 'Treat AGENTS.md as canonical'), 'CodeRabbit security review must follow canonical repository guidance');

ci_scope_expect(str_contains($sonar, 'sonar.sources=api,config,css,database,discadmin,js,scripts,index.html,index.php,sitemap.php'), 'SonarQube Cloud automatic analysis must use explicit production source roots');
ci_scope_expect(!str_contains($sonar, 'sonar.sources=.'), 'SonarQube Cloud source scope must not contain the test tree through the repository root');
ci_scope_expect(str_contains($sonar, 'sonar.tests=tests'), 'SonarQube Cloud must classify tests separately from source code');
ci_scope_expect(str_contains($sonar, 'sonar.exclusions=discadmin/qrcode.min.js'), 'SonarQube Cloud must keep the vendored QR bundle out of analysis');
ci_scope_expect(!str_contains($sonar, '/**') && !str_contains($sonar, 'sonar.test.inclusions='), 'automatic-analysis properties must not use unsupported wildcard path patterns');
ci_scope_expect(!str_contains($workflow, 'sonarqube-scan-action') && !str_contains($workflow, 'sonarcloud-github-action'), 'BRVTAL CI must not duplicate SonarQube Cloud automatic analysis');

ci_scope_expect(str_contains($performance, 'INCONCLUSIVE — UNREACHABLE FROM THIS RUNNER'), 'unreachable production performance probes must be labeled inconclusive');
ci_scope_expect(!str_contains($performance, 'failing fast before browser setup'), 'connectivity failures must not be mislabeled as performance failures');
ci_scope_expect(str_contains($performance, "if: steps.connectivity.outputs.reachable == 'true'"), 'performance setup and measurements must be skipped when production is unreachable');

echo "CI scope contract passed.\n";
