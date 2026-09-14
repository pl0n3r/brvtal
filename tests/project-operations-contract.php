<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$readme = (string) file_get_contents($root . '/README.md');
$workflow = (string) file_get_contents($root . '/.github/workflows/update-release-metadata.yml');
$agents = (string) file_get_contents($root . '/AGENTS.md');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "Project operations contract failed: {$message}\n");
        exit(1);
    }
};

$assert(str_contains($readme, '## 3. Arquitectura general'), 'README must describe the system architecture');
$assert(str_contains($readme, '## 17. CI / deploy'), 'README must document CI/deploy behavior');
$assert(str_contains($readme, '## 18. Checklist de características solicitadas vs estado actual'), 'README must keep the product feature checklist');
$assert(str_contains($readme, 'VALIDATED IN PRODUCTION'), 'README must distinguish production validation from CI');
$assert(str_contains($readme, 'Build / Deploy Summary'), 'README must explain the per-run build/deploy summary');
$assert(str_contains($workflow, 'GITHUB_STEP_SUMMARY'), 'BRVTAL CI must publish GitHub Actions job summaries');
$assert(str_contains($workflow, 'Deploy eligibility'), 'CI summary must state whether a run is deploy-eligible');
$assert(str_contains($workflow, 'Changed files'), 'CI summary must expose changed-file context');
$assert(!preg_match('/git\s+(?:add|commit)[^\n]*config\/version\.php/i', $workflow), 'CI must not commit config/version.php');
$assert(!preg_match('/(?:>|>>|tee\s+)[^\n]*config\/version\.php/i', $workflow), 'CI must not rewrite config/version.php');
$assert(str_contains($agents, 'README.md'), 'AGENTS must point future sessions to README');

fwrite(STDOUT, "Project operations contract OK\n");
