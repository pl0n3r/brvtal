<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$workflowPath = $root . '/.github/workflows/production-authenticated-smoke.yml';
$probePath = $root . '/tests/e2e/production-authenticated-smoke.mjs';
$releaseObserverPath = $root . '/tests/e2e/production-release-observer.mjs';
$releaseObserverContractPath = $root . '/tests/e2e/production-release-observer-contract.mjs';
$writeWorkflowPath = $root . '/.github/workflows/production-page-write-smoke.yml';
$writeProbePath = $root . '/tests/e2e/production-page-write-smoke.mjs';
$testingPath = $root . '/docs/TESTING.md';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "Production smoke contract failed: {$message}\n");
        exit(1);
    }
};

$assert(is_file($workflowPath), 'authenticated production smoke workflow must exist');
$assert(is_file($probePath), 'authenticated production smoke probe must exist');
$assert(is_file($releaseObserverPath), 'production release observer helper must exist');
$assert(is_file($releaseObserverContractPath), 'production release observer behavior contract must exist');
$assert(is_file($writeWorkflowPath), 'controlled Page write smoke workflow must exist');
$assert(is_file($writeProbePath), 'controlled Page write smoke probe must exist');

$workflow = (string) file_get_contents($workflowPath);
$probe = (string) file_get_contents($probePath);
$releaseObserver = (string) file_get_contents($releaseObserverPath);
$writeWorkflow = (string) file_get_contents($writeWorkflowPath);
$writeProbe = (string) file_get_contents($writeProbePath);
$testing = (string) file_get_contents($testingPath);

// The authenticated production smoke may be started manually or by the repository
// owner's exact command on #534. It must never become a push/PR/workflow_run/schedule
// side effect or a normal deploy gate. Both paths must use source checked out from main.
$assert(str_contains($workflow, 'name: Authenticated Production Smoke'), 'workflow name must remain explicit');
$assert(str_contains($workflow, "  workflow_dispatch:\n"), 'workflow must remain manually dispatchable');
$assert(!preg_match('/^\s{2}(?:push|pull_request|workflow_run|schedule):/m', $workflow), 'workflow must not run from automatic deploy/code events');
$assert(str_contains($workflow, "  issue_comment:\n"), 'workflow must expose the owner-only issue command trigger');
$assert(str_contains($workflow, 'types: [created]'), 'issue command must only evaluate newly created comments');
$assert(str_contains($workflow, "github.event_name == 'workflow_dispatch' && github.ref == 'refs/heads/main'"), 'manual dispatch must refuse non-main refs');
$assert(str_contains($workflow, 'github.event.issue.number == 534'), 'issue command must be restricted to #534');
$assert(str_contains($workflow, 'github.event.comment.user.login == github.repository_owner'), 'issue command must be restricted to the repository owner');
$assert(str_contains($workflow, "github.event.comment.author_association == 'OWNER'"), 'issue command must require OWNER association');
$assert(str_contains($workflow, "github.event.comment.body == '/production-smoke'"), 'issue command must require exact production-smoke text');
$assert(str_contains($workflow, 'environment: production-smoke'), 'workflow must isolate production smoke credentials');
$assert(str_contains($workflow, 'https://www.brvtal.com.co'), 'workflow must use the canonical www production origin');
$assert(str_contains($workflow, 'ref: main'), 'both smoke triggers must checkout canonical main');
$assert(str_contains($workflow, 'BRVTAL_EXPECTED_SHA=$(git rev-parse HEAD)'), 'smoke evidence must resolve exact checked-out main SHA');
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
$assert(str_contains($probe, 'smoke_stub: true'), 'media-permission repair must be fulfilled locally');
$assert(str_contains($probe, "route.abort('blockedbyclient')"), 'unexpected browser mutations must be blocked');
$assert(str_contains($probe, 'blockedMutations'), 'blocked mutations must be captured in evidence');
$assert(str_contains($probe, 'releaseObserved'), 'smoke must capture whether the expected production release was observed');
$assert(str_contains($probe, 'observedDeployment'), 'smoke must capture runtime deployment identity evidence');
$assert(str_contains($releaseObserver, '/api/deployment.php?__deploy_check='), 'smoke must wait on the canonical deployment endpoint before loading DISCADMIN');
$assert(str_contains($probe, 'await observeBeforeAuthenticate('), 'smoke must use the tested observe-before-auth orchestration boundary');
$assert(str_contains($probe, 'adminVersion'), 'visible Admin product version must be captured in evidence');
$assert(str_contains($probe, 'Admin product version mismatch'), 'production smoke must fail on visible release mismatch');
$assert(str_contains($probe, 'Production has no dated Event available'), '#123 must use existing production data rather than creating an Event');
$assert(str_contains($probe, 'published Artist and one published Event'), '#124 must use existing published relations rather than creating data');
$assert(str_contains($probe, "window.go('hero-slider')"), '#125 must exercise the real Hero Slider manager');
$assert(str_contains($probe, 'attempt <= 3'), '#125 must repeat Hero Slider loading');
$assert(substr_count($probe, 'context.request.post') === 2, 'the only direct POST calls must be login and optional TOTP verification');
$assert(!preg_match('/context\.request\.(?:put|patch|delete)\s*\(/i', $probe), 'probe must not directly mutate production content through APIRequestContext');
$assert(!preg_match('/page\.request\.(?:post|put|patch|delete)\s*\(/i', $probe), 'probe must not mutate production content through page.request');

$observerOutput = [];
$observerStatus = 0;
exec('node ' . escapeshellarg($releaseObserverContractPath) . ' 2>&1', $observerOutput, $observerStatus);
$assert(
    $observerStatus === 0,
    'production release observer behavior contract must pass: ' . implode(' | ', $observerOutput)
);

// #122 needs a real production write to validate the original failure mode, but
// that capability must remain isolated, explicitly confirmed, narrowly scoped,
// uniquely named, and self-cleaning.
$assert(str_contains($writeWorkflow, 'name: Controlled Production Page Write Smoke'), 'controlled write workflow name must remain explicit');
$assert(str_contains($writeWorkflow, "  workflow_dispatch:\n"), 'controlled write workflow must remain manually dispatchable');
$assert(!preg_match('/^\s{2}(?:push|pull_request|workflow_run|schedule):/m', $writeWorkflow), 'controlled write workflow must not run from automatic deploy/code events');
$assert(str_contains($writeWorkflow, 'confirm:'), 'controlled write workflow must require a confirmation input');
$assert(str_contains($writeWorkflow, "inputs.confirm == 'WRITE_AND_DELETE_TEMP_PAGE'"), 'controlled write job must require the exact confirmation token');
$assert(str_contains($writeWorkflow, "github.ref == 'refs/heads/main'"), 'controlled write workflow must refuse non-main refs');
$assert(str_contains($writeWorkflow, 'environment: production-smoke'), 'controlled write workflow must isolate production credentials');
$assert(str_contains($writeWorkflow, 'https://www.brvtal.com.co'), 'controlled write workflow must use the canonical www production origin');
$assert(str_contains($writeWorkflow, 'BRVTAL_EXPECTED_SHA: ${{ github.sha }}'), 'controlled write evidence must be tied to the dispatched main SHA');
$assert(str_contains($writeWorkflow, 'BRVTAL_PROD_PAGE_WRITE_CONFIRM: ${{ inputs.confirm }}'), 'confirmation token must be passed explicitly to the probe');
$assert(str_contains($writeWorkflow, 'secrets.BRVTAL_PROD_ADMIN_EMAIL'), 'controlled write email must come from a secret');
$assert(str_contains($writeWorkflow, 'secrets.BRVTAL_PROD_ADMIN_PASSWORD'), 'controlled write password must come from a secret');
$assert(str_contains($writeWorkflow, 'secrets.BRVTAL_PROD_TOTP_SECRET'), 'controlled write TOTP must come from a secret when enabled');
$assert(str_contains($writeWorkflow, 'actions/upload-artifact@v4'), 'controlled write workflow must retain evidence');
$assert(!preg_match('/BRVTAL_PROD_ADMIN_PASSWORD:\s*["\']?[A-Za-z0-9]/', $writeWorkflow), 'controlled write workflow must not hardcode an admin password');
$assert(!preg_match('/BRVTAL_PROD_TOTP_SECRET:\s*["\']?[A-Z2-7]{8,}/', $writeWorkflow), 'controlled write workflow must not hardcode a TOTP secret');

$assert(str_contains($writeProbe, "const requiredConfirmation = 'WRITE_AND_DELETE_TEMP_PAGE';"), 'write probe must independently require the confirmation token');
$assert(str_contains($writeProbe, 'BRVTAL_EXPECTED_SHA is required for controlled production writes'), 'write probe must require exact deployment identity');
$assert(str_contains($writeProbe, '`production-smoke-122-${shortSha}-${uniqueSuffix}`'), 'write probe must use a unique #122 namespace');
$assert(str_contains($writeProbe, "const pageContent = { text: 'Manifiesto' };"), 'write probe must reproduce the valid JSON payload');
$assert(str_contains($writeProbe, "status: 'published'"), 'write probe must reproduce the published Page status');
$assert(str_contains($writeProbe, 'content_json: JSON.stringify(pageContent)'), 'write probe must submit content_json through the production Pages API');
$assert(substr_count($writeProbe, 'context.request.post') === 3, 'write probe POST calls must be login, optional TOTP, and one Page create');
$assert(substr_count($writeProbe, 'context.request.delete') === 1, 'write probe must delete only the temporary Page it created');
$assert(!preg_match('/context\.request\.(?:put|patch)\s*\(/i', $writeProbe), 'write probe must not PUT/PATCH production content');
$assert(str_contains($writeProbe, 'await deleteCreatedPage(context, csrf, createdPageId);'), 'write probe must always attempt cleanup of its temporary Page');
$assert(str_contains($writeProbe, "verifyResponse.status() !== 404"), 'write probe must verify the temporary Page is absent after cleanup');
$assert(str_contains($writeProbe, 'findPageBySlug(context, pageSlug)'), 'write probe must recover and remove a uniquely named Page even after an ambiguous create response');

// Documentation must preserve the distinction between code-ready and actually run.
$assert(str_contains($testing, 'Authenticated production smoke'), 'testing docs must document the authenticated smoke procedure');
$assert(str_contains($testing, 'Controlled production Page write smoke'), 'testing docs must document the isolated #122 production write procedure');
$assert(str_contains($testing, 'VALIDATED IN PRODUCTION'), 'testing docs must preserve production-validation terminology');

echo "Production smoke safety contract passed.\n";
