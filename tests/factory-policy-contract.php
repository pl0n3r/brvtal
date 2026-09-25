<?php
declare(strict_types=1);

/** Fail closed when the published Factory policy contract drifts locally. */
function factory_policy_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FACTORY POLICY CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$raw = (string)file_get_contents($root . '/decisiones.yml');
$policy = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
factory_policy_expect(is_array($policy), 'decisiones.yml must decode to an object');
factory_policy_expect(array_keys($policy) === ['version', 'review_round_limit', 'decisions'], 'policy top-level schema must match Factory v1');
factory_policy_expect(($policy['version'] ?? null) === 1, 'policy version must remain 1');
factory_policy_expect(($policy['review_round_limit'] ?? null) === 3, 'automated review round limit must remain 3');

$decisions = $policy['decisions'] ?? null;
factory_policy_expect(is_array($decisions) && count($decisions) === 5, 'shared owner decision set must contain exactly five adopted decisions');
$expectedIds = ['D-054', 'D-055', 'D-056', 'D-057', 'D-058'];
$ids = [];
foreach ($decisions as $decision) {
    factory_policy_expect(is_array($decision), 'every decision must be an object');
    factory_policy_expect(array_keys($decision) === ['id', 'status', 'text'], 'decision schema must match Factory v1');
    factory_policy_expect(($decision['status'] ?? null) === 'active', 'adopted shared decisions must remain active');
    factory_policy_expect(is_string($decision['text'] ?? null) && trim($decision['text']) !== '', 'decision text must be non-empty');
    $ids[] = $decision['id'] ?? null;
}
factory_policy_expect($ids === $expectedIds, 'shared owner decision IDs/order drifted');

$byId = [];
foreach ($decisions as $decision) {
    $byId[(string)$decision['id']] = (string)$decision['text'];
}
factory_policy_expect(str_contains($byId['D-054'], 'backup previo'), 'D-054 must preserve backup-before-migration safety');
factory_policy_expect(str_contains($byId['D-055'], 'destructivo') && str_contains($byId['D-055'], 'autorización explícita'), 'D-055 must preserve destructive-write human gate');
factory_policy_expect(str_contains($byId['D-056'], 'versión/SHA exactos'), 'D-056 must preserve exact release identity');
factory_policy_expect(str_contains($byId['D-057'], 'exactamente una etiqueta'), 'D-057 must preserve one-label-per-dimension governance');
factory_policy_expect(str_contains($byId['D-058'], 'roles profesionales'), 'D-058 must preserve professional-role requirement');

$workflow = (string)file_get_contents($root . '/.github/workflows/factory-policy.yml');
factory_policy_expect(str_contains($workflow, 'pull_request:'), 'policy caller must run on pull requests');
factory_policy_expect(str_contains($workflow, 'branches: [main]'), 'policy caller must target main PRs');
factory_policy_expect(str_contains($workflow, 'uses: pl0n3r/factory/.github/workflows/politica.yml@v1'), 'policy caller must use the protected Factory v1 major channel');
factory_policy_expect(str_contains($workflow, 'pr_number: $' . '{{ github.event.pull_request.number }}'), 'caller must pass the real PR number');
factory_policy_expect(substr_count($workflow, 'contents: read') >= 2, 'caller and reusable job must keep contents read-only');
factory_policy_expect(substr_count($workflow, 'pull-requests: read') >= 2, 'caller and reusable job must keep pull requests read-only');
factory_policy_expect(!str_contains($workflow, 'kit_ref:'), 'policy caller must not expose a dynamic Factory ref');
factory_policy_expect(!str_contains($workflow, 'secrets: inherit'), 'policy caller must not inherit secrets');
factory_policy_expect(!str_contains($workflow, 'pull_request_target'), 'policy caller must not execute in privileged pull_request_target context');
factory_policy_expect(!str_contains($workflow, 'workflow_dispatch'), 'policy caller must not provide a manual bypass event');

echo "Factory policy contract passed.\n";
