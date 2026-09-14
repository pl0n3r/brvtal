<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$readme = (string) file_get_contents($root . '/README.md');
$workflow = (string) file_get_contents($root . '/.github/workflows/update-release-metadata.yml');
$phpstanWorkflow = (string) file_get_contents($root . '/.github/workflows/phpstan.yml');
$phpstanConfig = (string) file_get_contents($root . '/phpstan.neon');
$composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
$agents = (string) file_get_contents($root . '/AGENTS.md');
$privateHtaccess = (string) file_get_contents($root . '/.private/.htaccess');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "Project operations contract failed: {$message}\n");
        exit(1);
    }
};

// Human technical dashboard contract.
$assert(str_contains($readme, '## 3. Arquitectura general'), 'README must describe the system architecture');
$assert(str_contains($readme, '## 17. CI / deploy'), 'README must document CI/deploy behavior');
$assert(str_contains($readme, '## 18. Checklist de características solicitadas vs estado actual'), 'README must keep the product feature checklist');
$assert(str_contains($readme, 'VALIDATED IN PRODUCTION'), 'README must distinguish production validation from CI');
$assert(str_contains($readme, 'Build / Deploy Summary'), 'README must explain the per-run build/deploy summary');

// Single-source AI continuity contract.
$assert(str_contains($agents, 'CANONICAL BOOTSTRAP FOR CHATGPT, WORK, CODEX OR ANY CODING AGENT'), 'AGENTS must identify itself as the canonical AI bootstrap');
$assert(str_contains($agents, 'No previous chat, saved memory, prompt history or human recap is required'), 'AGENTS must explicitly avoid chat-memory dependency');
$assert(str_contains($agents, '## 0. AI start protocol'), 'AGENTS must contain a deterministic startup protocol');
$assert(str_contains($agents, '## 2. Non-negotiable DISCADMIN architecture'), 'AGENTS must carry core architecture invariants');
$assert(str_contains($agents, 'ONE SHELL / ONE SIDEBAR / ONE SESSION / ONE CENTRAL WORKSPACE'), 'AGENTS must preserve canonical DISCADMIN architecture');
$assert(str_contains($agents, '## 3. Current implemented product state'), 'AGENTS must describe current implemented state');
$assert(str_contains($agents, '## 4. Recent product decisions that must not be lost'), 'AGENTS must persist important product decisions');
$assert(str_contains($agents, 'Dark / Light / Glass'), 'AGENTS must retain the current admin appearance decision');
$assert(str_contains($agents, '7 days') && str_contains($agents, '30 days'), 'AGENTS must retain the extended admin-session policy');
$assert(str_contains($agents, 'Hero / Slider Manager'), 'AGENTS must retain Hero Slider context');
$assert(str_contains($agents, 'shareable URL query parameters'), 'AGENTS must retain public discovery URL-state context');
$assert(str_contains($agents, '## 9. Current priorities'), 'AGENTS must expose the next autonomous work priorities');
$assert(str_contains($agents, '## 12. State-maintenance contract for future AI work'), 'AGENTS must require future sessions to maintain durable state');
$assert(str_contains($agents, 'Read AGENTS.md and continue the project autonomously'), 'AGENTS must define a minimal future-session prompt');
$assert(str_contains($agents, 'AI sessions should not require reading README before they can begin'), 'README/docs must remain optional deep reference for startup');
$assert(!str_contains($agents, 'Read `README.md`, `docs/BRVTAL-SPEC.md`, `docs/DISCADMIN-UX-AUDIT.md`, and `docs/TESTING.md` before changing the product'), 'AGENTS must not require reconstructing startup context from multiple files');

// CI/deployment observability, fast-feedback topology and metadata safety contract.
$assert(str_contains($workflow, 'GITHUB_STEP_SUMMARY'), 'BRVTAL CI must publish GitHub Actions job summaries');
$assert(str_contains($workflow, 'Deploy eligibility'), 'CI summary must state whether a run is deploy-eligible');
$assert(str_contains($workflow, 'Changed files'), 'CI summary must expose changed-file context');
$assert(str_contains($workflow, "  plan:\n") && str_contains($workflow, "  fast:\n"), 'CI must expose a planning gate and a fast syntax/contract gate');
$assert(str_contains($workflow, "  database:\n") && str_contains($workflow, "  browser:\n"), 'CI must split database and Chromium validation into independent jobs');
$assert(str_contains($workflow, "  realstack:\n") && str_contains($workflow, "  webkit:\n"), 'CI must keep real-stack and targeted WebKit validation independent');
$assert(str_contains($workflow, "  validate:\n"), 'CI must preserve a final validate check for branch-protection compatibility');
$assert(str_contains($workflow, 'needs: [plan, fast, database, browser, realstack, webkit]'), 'final validate must aggregate every validation layer');
$assert(str_contains($workflow, 'actions/cache@v4'), 'browser/npm setup must use reusable Actions caches');
$assert(str_contains($workflow, '--project=chromium'), 'normal browser gate must target Chromium explicitly');
$assert(str_contains($workflow, '--project=webkit-totp'), 'WebKit must remain a targeted TOTP regression');
$assert(str_contains($workflow, 'run_webkit'), 'CI planner must make WebKit path-aware on pull requests');
$assert(!str_contains($workflow, 'mariadb-client'), 'CI must not replace the runner MySQL client with mariadb-client');
$assert(!preg_match('/git\s+(?:add|commit)[^\n]*config\/version\.php/i', $workflow), 'CI must not commit config/version.php');
$assert(!preg_match('/(?:>|>>|tee\s+)[^\n]*config\/version\.php/i', $workflow), 'CI must not rewrite config/version.php');

// Incremental PHPStan foundation: pinned, scoped and independently enforced without a blanket baseline.
$assert(is_array($composer), 'composer.json must contain valid JSON');
$assert(($composer['require-dev']['phpstan/phpstan'] ?? null) === '2.2.13', 'PHPStan must stay pinned until deliberately upgraded');
$assert(($composer['config']['platform']['php'] ?? null) === '8.3.0', 'Composer tooling must target the production PHP 8.3 contract');
$assert(str_contains($phpstanConfig, 'level: 3'), 'PHPStan foundation must keep the agreed initial level');
$assert(str_contains($phpstanConfig, 'config/migrations.php'), 'PHPStan scope must cover migration-state helpers');
$assert(str_contains($phpstanConfig, 'scripts/migrations.php'), 'PHPStan scope must cover the migration CLI');
$assert(!str_contains($phpstanConfig, 'ignoreErrors'), 'PHPStan foundation must not hide findings behind ignoreErrors');
$assert(!str_contains($phpstanConfig, 'baseline'), 'PHPStan foundation must not introduce a blanket baseline');
$assert(str_contains($phpstanWorkflow, 'name: BRVTAL PHPStan'), 'PHPStan must have a dedicated CI workflow');
$assert(str_contains($phpstanWorkflow, 'composer analyse'), 'PHPStan workflow must execute the configured analysis command');
$assert(str_contains($phpstanWorkflow, "branches: [main]"), 'PHPStan workflow must validate main and PRs targeting main');

// Web-deny contract for the project-level private storage root.
$assert(str_contains($privateHtaccess, 'Options -Indexes'), '.private must disable directory indexing');
$assert(str_contains($privateHtaccess, 'Require all denied'), '.private must use Apache 2.4/LiteSpeed deny syntax');
$assert(str_contains($privateHtaccess, 'Deny from all'), '.private must retain legacy access-compat denial');

// Migration-state controls are part of operations safety and therefore ride the always-on fast gate.
require __DIR__ . '/migrations-contract.php';

fwrite(STDOUT, "Project operations contract OK\n");
