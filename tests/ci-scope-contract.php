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
/** @return array<string,mixed> */
function ci_scope_performance_prerequisite(array $payload, string $sha, int $sourceRunId, string $sourceUpdatedAt): array
{
    $script = realpath(__DIR__ . '/../scripts/production-performance-prerequisite.py');
    ci_scope_expect(is_string($script) && $script !== '', 'production performance prerequisite helper must exist');
    $command = [
        'python3',
        $script,
        '--expected-sha',
        $sha,
        '--source-run-id',
        (string)$sourceRunId,
        '--source-updated-at',
        $sourceUpdatedAt,
    ];
    $pipes = [];
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    ci_scope_expect(is_resource($process), 'production performance prerequisite helper must be executable');
    fwrite($pipes[0], json_encode($payload, JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    ci_scope_expect($status === 0, 'production performance prerequisite helper failed: ' . trim((string)$stderr));
    $decoded = json_decode((string)$stdout, true);
    ci_scope_expect(is_array($decoded), 'production performance prerequisite helper must return JSON');
    return $decoded;
}

$workflow = (string)file_get_contents(__DIR__ . '/../.github/workflows/update-release-metadata.yml');
$package = (string)file_get_contents(__DIR__ . '/../package.json');
$codeRabbit = (string)file_get_contents(__DIR__ . '/../.coderabbit.yaml');
$sonar = (string)file_get_contents(__DIR__ . '/../.sonarcloud.properties');
$performance = (string)file_get_contents(__DIR__ . '/../.github/workflows/production-performance.yml');
$deployObserver = (string)file_get_contents(__DIR__ . '/../.github/workflows/production-deploy-observer.yml');
$sonarRelay = (string)file_get_contents(__DIR__ . '/../.github/workflows/sonar-annotation-relay.yml');

ci_scope_expect(str_contains($workflow, 'preflight:'), 'BRVTAL CI must expose a dedicated preflight job');
ci_scope_expect(str_contains($workflow, 'source scripts/ci-scope.sh'), 'BRVTAL CI must execute the shared changed-file classifier');
ci_scope_expect(str_contains($workflow, 'needs: preflight'), 'selected heavy gates must fan out from preflight instead of waiting for fast');
ci_scope_expect(!str_contains($workflow, "    needs: fast\n"), 'heavy gates must not serialize behind the fast job');
ci_scope_expect(str_contains($workflow, 'needs: [preflight, coordination, fast, database, browser, realstack, webkit, recovery]'), 'validate must aggregate preflight plus every canonical gate');
ci_scope_expect(str_contains($workflow, "if: needs.preflight.outputs.run_php == 'true'"), 'PHP fast suite must be scope-aware');
ci_scope_expect(str_contains($workflow, "if: needs.preflight.outputs.run_js == 'true'"), 'JavaScript syntax validation must be scope-aware');
ci_scope_expect(str_contains($workflow, 'python scripts/readme-dashboard.py --check'), 'README deploy facts must be checked by the reusable dashboard validator');
ci_scope_expect(str_contains($workflow, 'brvtal_ci_classify_files "$changed_file_list" "$BRVTAL_EVENT"'), 'workflow must pass its actual changed-file list and event to the shared classifier');
ci_scope_expect(str_contains($workflow, 'deploy_bound: ${{ steps.scope.outputs.deploy_bound }}'), 'preflight must publish deploy-bound classification');
ci_scope_expect(str_contains($workflow, 'BRVTAL_DEPLOY_BOUND: ${{ steps.scope.outputs.deploy_bound }}'), 'version validation must consume deploy-bound classification');
ci_scope_expect(str_contains($workflow, 'Repository-only maintenance: product version may remain unchanged.'), 'repository-only maintenance must allow a stable product version');
ci_scope_expect(str_contains($workflow, 'run: npm run test:integration'), 'CI database gate must call the canonical integration script');
ci_scope_expect(!str_contains($workflow, "php tests/integration/global-search.php\n          php tests/integration/bulk-actions.php"), 'CI must not maintain a second manual integration list');

ci_scope_expect_flags(ci_scope_run(['api/hero-slider.php']), [
    'full' => 'false', 'run_db' => 'true', 'run_browser' => 'true', 'run_realstack' => 'true', 'run_webkit' => 'false', 'run_recovery' => 'false',
    'run_php' => 'true', 'run_js' => 'false', 'deploy_bound' => 'true',
], 'public hero API');
ci_scope_expect_flags(ci_scope_run(['README.md', 'AGENTS.md']), [
    'full' => 'false', 'run_db' => 'false', 'run_browser' => 'false', 'run_realstack' => 'false', 'run_webkit' => 'false', 'run_recovery' => 'false',
    'run_php' => 'false', 'run_js' => 'false', 'deploy_bound' => 'false',
], 'docs only');
ci_scope_expect_flags(ci_scope_run(['config/public_home.php']), [
    'run_db' => 'true', 'run_browser' => 'true', 'run_realstack' => 'true', 'run_webkit' => 'false', 'run_recovery' => 'false', 'deploy_bound' => 'true',
], 'public runtime config');
ci_scope_expect_flags(ci_scope_run(['package.json']), [
    'run_db' => 'true', 'run_browser' => 'true', 'run_realstack' => 'true', 'run_webkit' => 'true', 'run_recovery' => 'false',
    'run_php' => 'true', 'run_js' => 'true', 'deploy_bound' => 'false',
], 'test tooling');
ci_scope_expect_flags(ci_scope_run(['config/version.php']), [
    'run_db' => 'false', 'run_browser' => 'false', 'run_realstack' => 'false', 'run_webkit' => 'false', 'run_recovery' => 'false',
    'run_php' => 'false', 'run_js' => 'false', 'deploy_bound' => 'true',
], 'product version');

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
    'run_php' => 'true', 'run_js' => 'true', 'deploy_bound' => 'false',
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
ci_scope_expect(str_contains($performance, 'workflows: ["BRVTAL CI", "Production Deploy Observer"]'), 'automatic performance must wait for both source validation and canonical deployment observation');
ci_scope_expect(str_contains($performance, 'python scripts/production-performance-prerequisite.py'), 'automatic performance must use the deterministic prerequisite helper');
ci_scope_expect(str_contains($performance, 'steps.prerequisites.outputs.ready'), 'automatic performance must gate measurement on the same-SHA counterpart workflow');
ci_scope_expect(str_contains($performance, 'group: brvtal-production-performance-${{ github.event.workflow_run.head_sha || github.sha }}'), 'automatic performance concurrency must be scoped to the source SHA');
ci_scope_expect(str_contains($performance, 'cancel-in-progress: false'), 'coordination-only runs must not cancel the unique measurement owner');
ci_scope_expect(!str_contains($performance, '?v=$short_sha') && !str_contains($performance, 'Wait for exact Hostinger deploy'), 'performance must not maintain a competing short Hostinger deploy detector');

$perfSha = str_repeat('a', 40);
$perfSourceId = 200;
$perfSourceUpdatedAt = '2026-09-19T16:00:10Z';
$perfSuccess = [
    'workflow_runs' => [[
        'id' => 100,
        'head_sha' => $perfSha,
        'event' => 'push',
        'status' => 'completed',
        'conclusion' => 'success',
        'updated_at' => '2026-09-19T16:00:00Z',
    ]],
];
$perfMissing = ci_scope_performance_prerequisite(['workflow_runs' => []], $perfSha, $perfSourceId, $perfSourceUpdatedAt);
ci_scope_expect(($perfMissing['ready'] ?? null) === false && ($perfMissing['reason'] ?? '') === 'counterpart_missing_for_sha', 'performance prerequisite must reject a missing counterpart');
$perfPendingPayload = $perfSuccess;
$perfPendingPayload['workflow_runs'][0]['status'] = 'in_progress';
$perfPendingPayload['workflow_runs'][0]['conclusion'] = null;
$perfPending = ci_scope_performance_prerequisite($perfPendingPayload, $perfSha, $perfSourceId, $perfSourceUpdatedAt);
ci_scope_expect(($perfPending['ready'] ?? null) === false && ($perfPending['reason'] ?? '') === 'counterpart_not_completed', 'performance prerequisite must reject a pending counterpart');
$perfFailedPayload = $perfSuccess;
$perfFailedPayload['workflow_runs'][0]['conclusion'] = 'failure';
$perfFailed = ci_scope_performance_prerequisite($perfFailedPayload, $perfSha, $perfSourceId, $perfSourceUpdatedAt);
ci_scope_expect(($perfFailed['ready'] ?? null) === false && ($perfFailed['reason'] ?? '') === 'counterpart_not_successful', 'performance prerequisite must reject a failed counterpart');
$perfReady = ci_scope_performance_prerequisite($perfSuccess, $perfSha, $perfSourceId, $perfSourceUpdatedAt);
ci_scope_expect(($perfReady['ready'] ?? null) === true && ($perfReady['reason'] ?? '') === 'ready', 'performance prerequisite must accept a successful earlier same-SHA counterpart');
$perfOtherShaPayload = $perfSuccess;
$perfOtherShaPayload['workflow_runs'][0]['head_sha'] = str_repeat('b', 40);
$perfOtherSha = ci_scope_performance_prerequisite($perfOtherShaPayload, $perfSha, $perfSourceId, $perfSourceUpdatedAt);
ci_scope_expect(($perfOtherSha['ready'] ?? null) === false && ($perfOtherSha['reason'] ?? '') === 'counterpart_missing_for_sha', 'performance prerequisite must reject a successful different-SHA counterpart');
$perfLaterCounterpart = $perfSuccess;
$perfLaterCounterpart['workflow_runs'][0]['id'] = 300;
$perfLaterCounterpart['workflow_runs'][0]['updated_at'] = '2026-09-19T16:00:20Z';
$perfNotOwner = ci_scope_performance_prerequisite($perfLaterCounterpart, $perfSha, $perfSourceId, $perfSourceUpdatedAt);
ci_scope_expect(($perfNotOwner['ready'] ?? null) === false && ($perfNotOwner['reason'] ?? '') === 'source_not_later_completion', 'only the later prerequisite completion may own automatic performance measurement');

ci_scope_expect(str_contains($deployObserver, 'push:') && str_contains($deployObserver, 'branches: [main]'), 'deploy observer must start directly from main pushes');
ci_scope_expect(str_contains($deployObserver, 'EXPECTED_SHA: ${{ github.sha }}'), 'deploy observer must track the exact pushed main SHA');
ci_scope_expect(str_contains($deployObserver, 'DEPLOYED release observed'), 'deploy observer must report canonical release observation');
ci_scope_expect(!str_contains($deployObserver, 'VALIDATED IN PRODUCTION'), 'deploy marker observation must not be mislabeled as production validation');
ci_scope_expect(str_contains($deployObserver, 'timeout-minutes: 12'), 'deploy observer must allow Hostinger propagation beyond the original short window');
ci_scope_expect(str_contains($deployObserver, 'max_attempts=50'), 'deploy observer must fit its calibrated polling budget inside the workflow deadline');
ci_scope_expect(str_contains($deployObserver, 'sleep_seconds=8'), 'deploy observer must use bounded polling instead of tight loops');
ci_scope_expect(str_contains($deployObserver, 'Hostinger hPanel Git auto-deployment'), 'deploy observer failure evidence must point to the external configuration boundary');
ci_scope_expect(str_contains($deployObserver, 'RELEASE OBSERVED; EXACT SOURCE MISMATCH'), 'deploy observer must distinguish exact-source mismatch from a missing release');
ci_scope_expect(str_contains($deployObserver, 'echo "- Expected release: \\`v$EXPECTED_VERSION\\`"'), 'deploy observer summary must escape Markdown backticks from shell substitution');

ci_scope_expect(str_contains($sonarRelay, 'body = "\\n".join(lines).strip() + "\\n"'), 'Sonar relay must build Markdown with newline escape sequences interpreted by Python');
ci_scope_expect(!str_contains($sonarRelay, 'body = "\\\\n".join(lines).strip() + "\\\\n"'), 'Sonar relay must never publish literal escaped newline markers');

echo "CI scope contract passed.\n";
