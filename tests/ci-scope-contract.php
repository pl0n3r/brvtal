<?php
declare(strict_types=1);

function ci_scope_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "CI SCOPE CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$workflow = (string)file_get_contents(__DIR__ . '/../.github/workflows/update-release-metadata.yml');
$package = (string)file_get_contents(__DIR__ . '/../package.json');
$codeRabbit = (string)file_get_contents(__DIR__ . '/../.coderabbit.yaml');
$performance = (string)file_get_contents(__DIR__ . '/../.github/workflows/production-performance.yml');

ci_scope_expect(str_contains($workflow, 'api/contact.php|api/public*.php)'), 'public API changes must have an explicit validation scope');
ci_scope_expect(str_contains($workflow, 'add_area "Public API"; run_db=true; run_browser=true; run_realstack=true'), 'public API changes must run DB, browser and real-stack gates');
ci_scope_expect(str_contains($workflow, 'config/public_*.php)'), 'public runtime config must have an explicit validation scope');
ci_scope_expect(str_contains($workflow, 'add_area "Public runtime config"; run_db=true; run_browser=true; run_realstack=true'), 'public runtime config must run browser coverage in addition to backend gates');
ci_scope_expect(str_contains($workflow, 'playwright.config.mjs|package.json|package-lock.json)'), 'test tooling scope must remain explicit');
ci_scope_expect(str_contains($workflow, 'add_area "Test tooling"; run_db=true; run_browser=true; run_realstack=true; run_webkit=true'), 'test tooling changes must include database coverage');
ci_scope_expect(str_contains($workflow, 'run: npm run test:integration'), 'CI database gate must call the canonical integration script');
ci_scope_expect(!str_contains($workflow, "php tests/integration/global-search.php\n          php tests/integration/bulk-actions.php"), 'CI must not maintain a second manual integration list');

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

ci_scope_expect(str_contains($performance, 'INCONCLUSIVE — UNREACHABLE FROM THIS RUNNER'), 'unreachable production performance probes must be labeled inconclusive');
ci_scope_expect(!str_contains($performance, 'failing fast before browser setup'), 'connectivity failures must not be mislabeled as performance failures');
ci_scope_expect(str_contains($performance, "if: steps.connectivity.outputs.reachable == 'true'"), 'performance setup and measurements must be skipped when production is unreachable');

echo "CI scope contract passed.\n";
