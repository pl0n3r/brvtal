<?php
declare(strict_types=1);

/** Fail closed if the Factory release caller drifts from the approved boundary. */
function factory_release_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FACTORY RELEASE CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

/** @return list<string> */
function factory_release_block_lines(string $yaml, string $key): array
{
    $lines = preg_split('/\R/', $yaml) ?: [];
    $start = null;

    foreach ($lines as $index => $line) {
        if ($line === $key . ':') {
            $start = $index + 1;
            break;
        }
    }

    factory_release_expect($start !== null, "missing top-level {$key} block");

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
function factory_release_immediate_mapping(array $lines): array
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
$path = $root . '/.github/workflows/factory-release.yml';
$workflow = (string) file_get_contents($path);

factory_release_expect(str_contains($workflow, 'name: Factory Release'), 'workflow name must remain stable');

$events = factory_release_immediate_mapping(factory_release_block_lines($workflow, 'on'));
factory_release_expect(array_keys($events) === ['push'], 'release caller must expose only push');
$onBlock = implode("\n", factory_release_block_lines($workflow, 'on'));
factory_release_expect(
    preg_match('/^  push:\n    branches: \[main\]\n    paths:\n      - config\/version\.php$/m', $onBlock) === 1,
    'release caller must run only for config/version.php changes on main'
);

$permissions = factory_release_immediate_mapping(factory_release_block_lines($workflow, 'permissions'));
factory_release_expect(
    $permissions === ['contents' => 'write'],
    'release caller must request only contents: write'
);

factory_release_expect(
    str_contains($workflow, 'uses: pl0n3r/factory/.github/workflows/release.yml@v1'),
    'release caller must use the protected Factory v1 channel'
);
factory_release_expect(
    str_contains($workflow, 'version_source: config/version.php'),
    'release caller must use the canonical BRVTAL version source'
);
factory_release_expect(
    str_contains($workflow, 'version_format: php-const'),
    'release caller must parse version.php without executing it'
);
factory_release_expect(
    str_contains($workflow, 'version_key: BRVTAL_APP_VERSION'),
    'release caller must select BRVTAL_APP_VERSION explicitly'
);

foreach ([
    'pull_request:',
    'pull_request_target:',
    'workflow_dispatch:',
    'schedule:',
    'secrets:',
    'kit_ref:',
    'expected_sha:',
    'factory_bootstrap:',
    'gh release',
    'git tag',
] as $forbidden) {
    factory_release_expect(!str_contains($workflow, $forbidden), "forbidden caller capability: {$forbidden}");
}

$selfAudit = (string) file_get_contents($root . '/scripts/ci_self_audit.py');
factory_release_expect(
    str_contains($selfAudit, 'FACTORY_V1_WORKFLOW'),
    'CI self-audit must continue restricting @v1 to Factory reusable workflows'
);

echo "Factory release caller contract passed.\n";
