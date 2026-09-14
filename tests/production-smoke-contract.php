<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$workflowPath = $root . '/.github/workflows/production-authenticated-smoke.yml';
$probePath = $root . '/tests/e2e/production-authenticated-smoke.mjs';
$testingPath = $root . '/docs/TESTING.md';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "Production smoke contract failed: {$message}\n");
        exit(1);
    }
};

$assert(is_file($workflowPath), 'manual production smoke workflow must exist');
$assert(is_file($probePath), 'authenticated production smoke probe must exist');

$workflow = (string) file_get_contents($workflowPath);
$probe = (string) file_get_contents($probePath);
$testing = (string) file_get_contents($testingPath);

// The authenticated production smoke is deliberately manual-only. It must never
// become a push/PR/workflow_run side effect or a normal deploy gate.
$assert(str_contains($workflow, 'name: Authenticated Production Smoke'), 'workflow name must remain explicit');
$assert(str_contains($workflow, "  workflow_dispatch:\n"), 'workflow must remain manually dispatchable');
$assert(!preg_match('/^\s{2}(?:push|pull_request|workflow_run|schedule):/m', $workflow), 'workflow must remain manual-only');
$assert(str_contains($workflow, "if: github.ref == 'refs/heads/main'"), 'workflow must refuse non-main refs');
$assert(str_contains($workflow, 'environment: production-smoke'), 'workflow must isolate production smoke credentials');
$assert(str_contains($workflow, 'https://www.brvtal.com.co'), 'workflow must use the canonical www production origin');
$assert(str_contains($workflow, 'BRVTAL_EXPECTED_SHA: ${{ github.sha }}'), 'workflow must tie evidence to the dispatched main SHA');
$assert(str_contains($workflow, 'actions/upload-artifact@v4'), 'workflow must retain downloadable evidence');

// Credentials must come from GitHub secrets and must never be embedded in source.
$assert(str_contains($workflow, 'secrets.BRVTAL_PROD_ADMIN_EMAIL'), 'admin email must come from a secret');
$assert(str_contains($workflow, 'secrets.BRVTAL_PROD_ADMIN_PASSWORD'), 'admin password must come from a secret');
$assert(str_contains($workflow, 'secrets.BRVTAL_PROD_TOTP_SECRET'), 'TOTP secret must come from a secret when 2FA is enabled');
$assert(!preg_match('/BRVTAL_PROD_ADMIN_PASSWORD:\s*["\']?[A-Za-z0-9]/', $workflow), 'workflow must not hardcode an admin password');
$assert(!preg_match('/BRVTAL_PROD_TOTP_SECRET:\s*["\']?[A-Z2-7]{8,}/', $workflow), 'workflow must not hardcode a TOTP secret');

// Once login/TOTP completes, the browser must be content-read-only. The known
// DISCADMIN permission-repair POST is fulfilled locally instead of reaching Hostinger.
$assert(str_contains($probe, "['GET', 'HEAD', 'OPTIONS'].includes(method)"), 'probe must allow only read-only browser methods after auth');
$assert(str_contains($probe, "url.pathname === '/api/media-permissions.php'"), 'probe must identify the automatic media-permission repair');
$assert(str_contains($probe, "smoke_stub: true"), 'media-permission repair must be fulfilled locally');
$assert(str_contains($probe, "route.abort('blockedbyclient')"), 'unexpected browser mutations must be blocked');
$assert(str_contains($probe, 'blockedMutations'), 'blocked mutations must be captured in evidence');
$assert(str_contains($probe, 'Production has no dated Event available'), '#123 must use existing production data rather than creating an Event');
$assert(str_contains($probe, 'published Artist and one published Event'), '#124 must use existing published relations rather than creating data');
$assert(str_contains($probe, "window.go('hero-slider')"), '#125 must exercise the real Hero Slider manager');
$assert(substr_count($probe, "window.go('hero-slider')") >= 1 && str_contains($probe, 'attempt <= 3'), '#125 must repeat Hero Slider loading');
$assert(!preg_match('/context\.request->?(?:post|put|patch|delete)/i', $probe), 'probe source must not use PHP-style request mutations');

// Documentation must preserve the distinction between code-ready and actually run.
$assert(str_contains($testing, 'Authenticated production smoke'), 'testing docs must document the authenticated smoke procedure');
$assert(str_contains($testing, 'VALIDATED IN PRODUCTION'), 'testing docs must preserve production-validation terminology');

echo "Production smoke safety contract passed.\n";
