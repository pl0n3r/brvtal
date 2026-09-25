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

$root = dirname(__DIR__);
$path = $root . '/.github/workflows/factory-ci.yml';
$workflow = (string) file_get_contents($path);

factory_ci_expect(str_contains($workflow, 'name: Factory CI'), 'workflow name must remain stable');
factory_ci_expect(str_contains($workflow, 'pull_request:'), 'caller must be PR-only');
factory_ci_expect(str_contains($workflow, 'branches: [main]'), 'caller must target main');
factory_ci_expect(str_contains($workflow, 'permissions:'), 'explicit permissions are required');
factory_ci_expect(substr_count($workflow, 'contents: read') === 1, 'caller must request only contents: read');
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
