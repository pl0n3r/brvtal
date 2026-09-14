<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$readme = (string) file_get_contents($root . '/README.md');
$workflow = (string) file_get_contents($root . '/.github/workflows/update-release-metadata.yml');
$performanceWorkflow = (string) file_get_contents($root . '/.github/workflows/production-performance.yml');
$performanceProbe = (string) file_get_contents($root . '/tests/e2e/production-performance-probe.mjs');
$agents = (string) file_get_contents($root . '/AGENTS.md');
$publicHtaccess = (string) file_get_contents($root . '/.htaccess');
$privateHtaccess = (string) file_get_contents($root . '/.private/.htaccess');
$indexPhp = (string) file_get_contents($root . '/index.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "Project operations contract failed: {$message}\n");
        exit(1);
    }
};

// README is intentionally a compact, replace-on-deploy handoff rather than a cumulative manual.
$assert(str_contains($readme, '# BRVTAL — Último deploy'), 'README must identify itself as the latest deploy snapshot');
$assert(str_contains($readme, '## Qué se hizo'), 'README must summarize what changed in the current deploy');
$assert(str_contains($readme, '## Archivos modificados en este deploy'), 'README must list files changed in the current deploy');
$assert(str_contains($readme, '## Validación'), 'README must state current validation status');
$assert(str_contains($readme, '## Qué sigue'), 'README must state the next actionable work');
$assert(str_contains($readme, 'solo el deploy actual'), 'README must explicitly remain deploy-scoped');
$assert(strlen($readme) < 8000, 'README must stay compact instead of becoming a cumulative technical manual');
$assert(!str_contains($readme, '## 3. Arquitectura general'), 'README must not regress to the old cumulative architecture manual');
$assert(!str_contains($readme, 'Checklist de características solicitadas vs estado actual'), 'README must not accumulate the historical feature checklist');

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
$assert(str_contains($agents, 'AI sessions should not require reading README before they can begin'), 'README must remain optional for AI startup');
$assert(str_contains($agents, 'Every deploy-bound PR must replace `README.md`'), 'AGENTS must require a fresh README snapshot for every deploy-bound PR');
$assert(str_contains($agents, 'if a CI fix or late edit changes the PR file set or scope, refresh README before merge'), 'AGENTS must require README refresh when deploy scope changes');
$assert(!str_contains($agents, 'README.md` is the human technical manual + requested-vs-completed checklist'), 'AGENTS must not describe README as a cumulative manual');
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

// Production performance must be measured after a green main CI run and tied to the exact deploy SHA.
$assert(str_contains($performanceWorkflow, 'name: Production Performance'), 'production performance workflow must remain explicit');
$assert(str_contains($performanceWorkflow, 'workflow_dispatch:'), 'production performance measurement must be manually repeatable');
$assert(str_contains($performanceWorkflow, 'workflows: ["BRVTAL CI"]'), 'production performance measurement must follow BRVTAL CI');
$assert(str_contains($performanceWorkflow, 'https://www.brvtal.com.co/'), 'production performance workflow must target the canonical www origin');
$assert(str_contains($performanceWorkflow, 'github.event.workflow_run.head_sha'), 'production performance workflow must preserve exact-main SHA traceability');
$assert(str_contains($performanceWorkflow, '?v=$short_sha'), 'production performance workflow must wait for the exact Hostinger deploy marker');
$assert(str_contains($performanceWorkflow, 'production-performance-mobile.json') && str_contains($performanceWorkflow, 'production-performance-desktop.json'), 'production performance workflow must retain mobile and desktop evidence');
$assert(str_contains($performanceWorkflow, 'actions/upload-artifact@v4'), 'production performance evidence must be downloadable from the run');
$assert(str_contains($performanceProbe, 'largest-contentful-paint'), 'production performance probe must observe LCP directly in Chromium');
$assert(str_contains($performanceProbe, 'layout-shift'), 'production performance probe must observe CLS directly in Chromium');
$assert(str_contains($performanceProbe, 'resourceLoadDelay'), 'production performance probe must expose LCP resource load delay');
$assert(str_contains($performanceProbe, 'resourceLoadDuration'), 'production performance probe must expose LCP resource load duration');
$assert(str_contains($performanceProbe, 'elementRenderDelay'), 'production performance probe must expose LCP element render delay');

// Public web-server performance contract: compress text payloads and cache deploy-versioned assets without wasting CPU on binaries.
$assert(str_contains($publicHtaccess, '<IfModule mod_deflate.c>'), 'public .htaccess must enable Apache/LiteSpeed-compatible compression when available');
$assert(str_contains($publicHtaccess, 'DEFLATE text/html'), 'HTML responses must be compression-eligible');
$assert(str_contains($publicHtaccess, 'application/javascript'), 'JavaScript responses must be compression-eligible');
$assert(str_contains($publicHtaccess, 'application/x-javascript'), 'LiteSpeed application/x-javascript responses must be compression-eligible');
$assert(str_contains($publicHtaccess, 'application/json'), 'JSON responses must be compression-eligible');
$assert(str_contains($publicHtaccess, 'image/svg+xml'), 'SVG responses must be compression-eligible');
$assert(!str_contains($publicHtaccess, 'DEFLATE image/jpeg'), 'JPEG assets must not be redundantly recompressed by mod_deflate');
$assert(!str_contains($publicHtaccess, 'DEFLATE font/woff2'), 'WOFF2 assets must not be redundantly recompressed by mod_deflate');
$assert(str_contains($publicHtaccess, 'ExpiresByType application/x-javascript "access plus 1 year"'), 'LiteSpeed JavaScript MIME must receive a one-year expiry');
$assert(str_contains($publicHtaccess, 'ExpiresByType text/javascript "access plus 1 year"'), 'text/javascript responses must receive a one-year expiry');
$assert(preg_match('/FilesMatch\s+"\\\\\.\(\?:css\|js\|/i', $publicHtaccess) === 1 || str_contains($publicHtaccess, '(?:css|js|'), 'immutable static-asset cache policy must include JavaScript');
$assert(str_contains($publicHtaccess, 'max-age=31536000, immutable'), 'versioned static assets must retain immutable one-year Cache-Control');

// Web-deny contract for the project-level private storage root.
$assert(str_contains($privateHtaccess, 'Options -Indexes'), '.private must disable directory indexing');
$assert(str_contains($privateHtaccess, 'Require all denied'), '.private must use Apache 2.4/LiteSpeed deny syntax');
$assert(str_contains($privateHtaccess, 'Deny from all'), '.private must retain legacy access-compat denial');

// Public asset request dedupe: decorative copies must reuse the same deploy-versioned URLs.
require_once $root . '/config/public_assets.php';
$assetFixture = '<html><head></head><body>'
    . '<img class="hero-logo" src="assets/brvtal-logo.jpeg">'
    . '<div class="hero-logo-glitch"></div>'
    . '<img src="assets/flyers/F60DFB48-3DB5-4E3E-B627-BF1B8129EB1D_1_105_c.jpeg">'
    . '<div class="genesis-bg"></div>'
    . '</body></html>';
$assetFixture = brvtal_public_dedupe_decorative_assets($assetFixture);
$assetFixture = brvtal_public_version_assets($assetFixture, 'abc1234');
$assert(str_contains($indexPhp, 'brvtal_public_dedupe_decorative_assets($html)'), 'Home renderer must normalize decorative asset URLs before versioning');
$assert(substr_count($assetFixture, 'assets/brvtal-logo.jpeg?v=abc1234') === 3, 'favicon, hero and glitch logo must share one versioned cache URL');
$assert(substr_count($assetFixture, 'F60DFB48-3DB5-4E3E-B627-BF1B8129EB1D_1_105_c.jpeg?v=abc1234') === 2, 'Genesis image and decorative background must share one versioned cache URL');
$assert(str_contains($assetFixture, 'rel="icon"'), 'Home must declare an explicit favicon instead of triggering /favicon.ico');
$assert(str_contains($assetFixture, 'background-image:none'), 'decorative CSS backgrounds must be suppressed before browser fetch');

// Home LCP discovery and visibility: fetch early and never let intro motion hide the measured image.
$preloadNeedle = '<link rel="preload" as="image" href="assets/brvtal-logo.jpeg" fetchpriority="high">';
$criticalVisibility = '<style data-brvtal-lcp-visible>.hero-logo-wrap{opacity:1!important}</style>';
$coreCssNeedle = '<link rel="stylesheet" href="css/style.css">';
$lcpFixture = '<html><head>' . $coreCssNeedle . '</head><body><img src="assets/brvtal-logo.jpeg"></body></html>';
$lcpFixture = brvtal_public_preload_home_lcp($lcpFixture);
$lcpFixture = brvtal_public_keep_home_lcp_visible($lcpFixture);
$preloadPosition = strpos($lcpFixture, $preloadNeedle);
$visibilityPosition = strpos($lcpFixture, $criticalVisibility);
$coreCssPosition = strpos($lcpFixture, $coreCssNeedle);
$assert(str_contains($indexPhp, 'brvtal_public_preload_home_lcp($html)'), 'Home renderer must apply the LCP preload helper before later asset transforms');
$assert(str_contains($indexPhp, 'brvtal_public_keep_home_lcp_visible($html)'), 'Home renderer must keep the LCP visible before later asset transforms');
$assert($preloadPosition !== false && $coreCssPosition !== false && $preloadPosition < $coreCssPosition, 'LCP preload must appear before core style.css in transformed Home HTML');
$assert($visibilityPosition !== false && $coreCssPosition !== false && $visibilityPosition < $coreCssPosition, 'critical LCP visibility style must appear before core style.css');
$assert(str_contains($criticalVisibility, 'opacity:1!important'), 'critical LCP visibility must override normal inline animation opacity');
$assert(substr_count($lcpFixture, $preloadNeedle) === 1, 'Home LCP preload helper must add exactly one preload');
$assert(substr_count($lcpFixture, $criticalVisibility) === 1, 'Home LCP visibility helper must add exactly one critical style');
$assert(brvtal_public_preload_home_lcp($lcpFixture) === $lcpFixture, 'Home LCP preload helper must be idempotent');
$assert(brvtal_public_keep_home_lcp_visible($lcpFixture) === $lcpFixture, 'Home LCP visibility helper must be idempotent');
$lcpFixture = brvtal_public_version_assets($lcpFixture, 'abc1234');
$assert(substr_count($lcpFixture, 'assets/brvtal-logo.jpeg?v=abc1234') === 2, 'preload and hero image must resolve to the exact same deploy-versioned logo URL');

// Tiny Home-only Hero CSS is critical but should not incur separate render-blocking requests.
$heroCssFixture = '<html><head>'
    . '<link rel="stylesheet" href="css/style.css">'
    . '<link rel="stylesheet" href="css/hero-slider.css">'
    . '<link rel="stylesheet" href="css/hero-slider-v2.css" data-hero-v2-public="1">'
    . '</head></html>';
$heroCssFixture = brvtal_public_inline_stylesheets($heroCssFixture, [
    'css/hero-slider.css',
    'css/hero-slider-v2.css',
]);
$assert(str_contains($indexPhp, 'brvtal_public_inline_stylesheets($html'), 'Home renderer must inline the tiny critical Hero styles');
$assert(str_contains($heroCssFixture, 'data-brvtal-inline="css/hero-slider.css"'), 'base Hero Slider CSS must be inlined');
$assert(str_contains($heroCssFixture, 'data-brvtal-inline="css/hero-slider-v2.css"'), 'Hero Slider v2 CSS must be inlined');
$assert(!str_contains($heroCssFixture, 'href="css/hero-slider.css"'), 'base Hero CSS must not remain a blocking request');
$assert(!str_contains($heroCssFixture, 'href="css/hero-slider-v2.css"'), 'Hero v2 CSS must not remain a blocking request');
$assert(str_contains($heroCssFixture, 'href="css/style.css"'), 'core style.css must remain an external blocking stylesheet');
$assert(str_contains($heroCssFixture, '.brvtal-hero-slider'), 'inlined Hero CSS must contain the actual slider rules');
$assert(str_contains($heroCssFixture, '.brvtal-hero-layer'), 'inlined Hero v2 CSS must contain the layer rules');

// Migration-state controls are part of operations safety and therefore ride the always-on fast gate.
require __DIR__ . '/migrations-contract.php';

fwrite(STDOUT, "Project operations contract OK\n");
