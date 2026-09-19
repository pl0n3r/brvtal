<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$workflow = (string)file_get_contents($root . '/.github/workflows/ci-throughput-telemetry.yml');
$script = $root . '/scripts/ci-throughput-report.py';

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "CI throughput report contract failed: {$message}\n");
        exit(1);
    }
};

$expect(is_file($script), 'report processor must exist');
$expect(str_contains($workflow, 'python scripts/ci-throughput-report.py report'), 'telemetry workflow must use the tested report processor');
$expect(str_contains($workflow, 'python scripts/ci-throughput-report.py summary'), 'telemetry workflow must generate the Job Summary through the same processor');
$expect(str_contains($workflow, 'artifacts/ci-throughput-summary.md'), 'telemetry workflow must publish the generated browser/setup summary');
$expect(str_contains($workflow, 'ref: ${{ github.event.workflow_run.head_sha }}'), 'telemetry must execute the processor from the observed exact source SHA');
$expect(str_contains($workflow, 'jobs?per_page=100') && str_contains($workflow, '--paginate'), 'telemetry must preserve paginated GitHub job collection');
$expect(!str_contains($workflow, 'sleep '), 'post-CI telemetry must not add polling or serialization delays');

$sha = str_repeat('a', 40);
$pages = [[
    'jobs' => [
        [
            'name' => 'preflight', 'conclusion' => 'success',
            'started_at' => '2026-09-19T16:00:00Z', 'completed_at' => '2026-09-19T16:00:02Z',
            'steps' => [],
        ],
        [
            'name' => 'chromium', 'conclusion' => 'success',
            'started_at' => '2026-09-19T16:00:03Z', 'completed_at' => '2026-09-19T16:00:20Z',
            'steps' => [
                ['name' => 'Run actions/checkout@v5', 'conclusion' => 'success', 'started_at' => '2026-09-19T16:00:03Z', 'completed_at' => '2026-09-19T16:00:04Z'],
                ['name' => 'Cache npm downloads', 'conclusion' => 'success', 'started_at' => '2026-09-19T16:00:04Z', 'completed_at' => '2026-09-19T16:00:05Z'],
                ['name' => 'Install Node test dependencies', 'conclusion' => 'success', 'started_at' => '2026-09-19T16:00:05Z', 'completed_at' => '2026-09-19T16:00:07Z'],
                ['name' => 'Cache Chromium', 'conclusion' => 'success', 'started_at' => '2026-09-19T16:00:07Z', 'completed_at' => '2026-09-19T16:00:08Z'],
                ['name' => 'Install Chromium system dependencies', 'conclusion' => 'success', 'started_at' => '2026-09-19T16:00:08Z', 'completed_at' => '2026-09-19T16:00:11Z'],
                ['name' => 'Install Chromium browser', 'conclusion' => 'success', 'started_at' => '2026-09-19T16:00:11Z', 'completed_at' => '2026-09-19T16:00:13Z'],
                ['name' => 'Run Chromium browser tests', 'conclusion' => 'success', 'started_at' => '2026-09-19T16:00:13Z', 'completed_at' => '2026-09-19T16:00:18Z'],
            ],
        ],
        [
            'name' => 'real-stack', 'conclusion' => 'success',
            'started_at' => '2026-09-19T16:00:03Z', 'completed_at' => '2026-09-19T16:00:18Z',
            'steps' => [
                ['name' => 'Initialize containers', 'conclusion' => 'success', 'started_at' => '2026-09-19T16:00:03Z', 'completed_at' => '2026-09-19T16:00:07Z'],
                ['name' => 'Set up PHP 8.5 with database extension', 'conclusion' => 'success', 'started_at' => '2026-09-19T16:00:07Z', 'completed_at' => '2026-09-19T16:00:10Z'],
                ['name' => 'Install Node test dependencies', 'conclusion' => 'success', 'started_at' => '2026-09-19T16:00:10Z', 'completed_at' => '2026-09-19T16:00:11Z'],
                ['name' => 'Install Chromium system dependencies', 'conclusion' => 'success', 'started_at' => '2026-09-19T16:00:11Z', 'completed_at' => '2026-09-19T16:00:13Z'],
                ['name' => 'Install Chromium browser', 'conclusion' => 'success', 'started_at' => '2026-09-19T16:00:13Z', 'completed_at' => '2026-09-19T16:00:14Z'],
                ['name' => 'Run authenticated PHP/MariaDB browser smoke', 'conclusion' => 'success', 'started_at' => '2026-09-19T16:00:14Z', 'completed_at' => '2026-09-19T16:00:16Z'],
            ],
        ],
        [
            'name' => 'webkit-totp', 'conclusion' => 'success',
            'started_at' => '2026-09-19T16:00:03Z', 'completed_at' => '2026-09-19T16:00:15Z',
            'steps' => [
                ['name' => 'Install WebKit system dependencies', 'conclusion' => 'success', 'started_at' => '2026-09-19T16:00:04Z', 'completed_at' => '2026-09-19T16:00:09Z'],
                ['name' => 'Install WebKit browser', 'conclusion' => 'skipped', 'started_at' => null, 'completed_at' => null],
                ['name' => 'Run targeted WebKit TOTP regression', 'conclusion' => 'success', 'started_at' => '2026-09-19T16:00:09Z', 'completed_at' => '2026-09-19T16:00:12Z'],
            ],
        ],
        [
            'name' => 'validate', 'conclusion' => 'success',
            'started_at' => '2026-09-19T16:00:21Z', 'completed_at' => '2026-09-19T16:00:22Z',
            'steps' => [],
        ],
    ],
]];

$run = static function (array $command, string $stdin) use ($expect): string {
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $expect(is_resource($process), 'report processor must execute');
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    $expect($status === 0, 'report processor failed: ' . trim((string)$stderr));
    return (string)$stdout;
};

$reportJson = $run([
    'python3', $script, 'report',
    '--run-id', '42', '--source-sha', $sha, '--event', 'push', '--conclusion', 'success',
    '--started-at', '2026-09-19T16:00:00Z', '--updated-at', '2026-09-19T16:00:22Z',
], json_encode($pages, JSON_THROW_ON_ERROR));
$report = json_decode($reportJson, true, flags: JSON_THROW_ON_ERROR);
$expect(($report['workflow_wall_seconds'] ?? null) === 22, 'workflow wall time must be preserved');
$expect(($report['critical_path']['jobs'] ?? []) === ['preflight', 'chromium', 'validate'], 'critical path must still select the slowest successful parallel gate');
$expect(($report['critical_path']['duration_seconds'] ?? null) === 20, 'critical path duration must preserve previous semantics');
$breakdown = [];
foreach (($report['browser_breakdown'] ?? []) as $item) $breakdown[$item['name']] = $item;
$expect(array_keys($breakdown) === ['chromium', 'real-stack', 'webkit-totp'], 'browser breakdown must include canonical browser jobs in job order');
$expect(($breakdown['chromium']['phase_seconds'] ?? null) === [
    'browser_system_setup' => 5, 'dependency_cache' => 4, 'infrastructure' => 0, 'other' => 3, 'test' => 5,
], 'Chromium phases must distinguish dependency, browser setup, test and overhead');
$expect(($breakdown['real-stack']['phase_seconds']['infrastructure'] ?? null) === 7, 'real-stack must expose container/PHP infrastructure time');
$expect(($breakdown['real-stack']['phase_seconds']['test'] ?? null) === 2, 'real-stack must expose actual smoke runtime');
$expect(($breakdown['webkit-totp']['phase_seconds']['browser_system_setup'] ?? null) === 5, 'WebKit must expose system setup independently');
$expect(($breakdown['webkit-totp']['phase_seconds']['test'] ?? null) === 3, 'WebKit must expose actual TOTP runtime');
$webkitInstall = array_values(array_filter($breakdown['webkit-totp']['steps'], static fn(array $step): bool => $step['name'] === 'Install WebKit browser'));
$expect(isset($webkitInstall[0]) && array_key_exists('duration_seconds', $webkitInstall[0]) && $webkitInstall[0]['duration_seconds'] === null, 'skipped browser-install steps must remain null instead of fabricating time');
$summaryText = $run(['python3', $script, 'summary'], $reportJson);
$expect(str_contains($summaryText, '## Browser setup vs test'), 'summary must expose the browser phase table');
$expect(str_contains($summaryText, '| chromium | 4s | 5s | 0s | 5s | 3s | 17s |'), 'summary must expose Chromium phase totals');

fwrite(STDOUT, "CI throughput report contract passed.\n");
