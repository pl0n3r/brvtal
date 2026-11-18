<?php
declare(strict_types=1);

/** Fail closed if the parallel Factory CI caller drifts from the approved boundary. */
function factory_ci_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FACTORY CI CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

/** @return list<string> */
function factory_ci_block_lines(string $yaml, string $key): array
{
    $lines = preg_split('/\R/', $yaml) ?: [];
    $start = null;

    foreach ($lines as $index => $line) {
        if ($line === $key . ':') {
            $start = $index + 1;
            break;
        }
    }

    factory_ci_expect($start !== null, "missing top-level {$key} block");

    $block = [];
    for ($index = $start, $count = count($lines); $index < $count; $index++) {
        $line = $lines[$index];
        if ($line !== '' && !str_starts_with($line, ' ')) {
            break;
        }
        $block[] = $line;
    }

    return $block;
}

/** @param list<string> $lines
 *  @return array<string, string>
 */
function factory_ci_immediate_mapping(array $lines): array
{
    $mapping = [];
    foreach ($lines as $line) {
        if (preg_match('/^  ([A-Za-z0-9_-]+):(?:\s*(.*))?$/', $line, $match) !== 1) {
            continue;
        }
        $mapping[$match[1]] = trim($match[2] ?? '');
    }

    return $mapping;
}

$root = dirname(__DIR__);
$path = $root . '/.github/workflows/factory-ci.yml';
$workflow = (string) file_get_contents($path);

factory_ci_expect(str_contains($workflow, 'name: Factory CI'), 'workflow name must remain stable');

$events = factory_ci_immediate_mapping(factory_ci_block_lines($workflow, 'on'));
factory_ci_expect(
    array_keys($events) === ['pull_request'],
    'caller must expose only the pull_request event'
);
$onBlock = implode("\n", factory_ci_block_lines($workflow, 'on'));
factory_ci_expect(
    preg_match('/^  pull_request:\n    branches: \[main\]$/m', $onBlock) === 1,
    'pull_request must target only main'
);

$permissions = factory_ci_immediate_mapping(factory_ci_block_lines($workflow, 'permissions'));
factory_ci_expect(
    $permissions === ['contents' => 'read'],
    'caller top-level permissions must be exactly contents: read'
);

factory_ci_expect(str_contains($workflow, 'uses: pl0n3r/factory/.github/workflows/ci.yml@v1'), 'caller must use protected Factory v1 channel');
factory_ci_expect(str_contains($workflow, 'stack: php'), 'BRVTAL maps to generic PHP baseline');
factory_ci_expect(str_contains($workflow, 'domain: https://www.brvtal.com.co'), 'canonical HTTPS domain must be explicit');
factory_ci_expect(str_contains($workflow, 'version_source: config/version.php'), 'version source must remain canonical');
factory_ci_expect(str_contains($workflow, 'label_language: en'), 'BRVTAL Factory labels use English');
factory_ci_expect(str_contains($workflow, 'phase: live'), 'BRVTAL is a live product');
factory_ci_expect(str_contains($workflow, "php_version: '8.5'"), 'Factory PHP baseline must match BRVTAL PHP 8.5');
factory_ci_expect(str_contains($workflow, 'node_enabled: false'), 'generic Factory Node job must stay disabled until service/browser parity exists');
factory_ci_expect(str_contains($workflow, 'working_directory: .'), 'caller must validate repository root');

foreach ([
    'pull_request_target',
    'workflow_dispatch',
    'secrets: inherit',
    'kit_ref:',
    'permissions: write-all',
    'contents: write',
    'issues: write',
    'pull-requests: write',
] as $forbidden) {
    factory_ci_expect(!str_contains($workflow, $forbidden), "forbidden caller capability: {$forbidden}");
}

$selfAudit = (string) file_get_contents($root . '/scripts/ci_self_audit.py');
factory_ci_expect(
    str_contains($selfAudit, 'FACTORY_V1_WORKFLOW'),
    'CI self-audit must continue restricting @v1 to Factory reusable workflows'
);

echo "Factory CI caller contract passed.\n";
