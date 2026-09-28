<?php
declare(strict_types=1);

/** Fail closed when BRVTAL's Factory adoption status drifts from executable reality. */
function factory_adoption_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FACTORY ADOPTION CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

/** @return array<string, mixed> */
function factory_adoption_json(string $path): array
{
    $decoded = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    factory_adoption_expect(is_array($decoded), "{$path} must decode to an object");
    return $decoded;
}

$root = dirname(__DIR__);
$status = factory_adoption_json($root . '/docs/factory-adoption.json');

factory_adoption_expect(
    array_keys($status) === [
        'version',
        'epic_issue',
        'production_green',
        'epic_close_allowed',
        'consumed',
        'external_blockers',
        'pending_adoptions',
    ],
    'top-level adoption schema drifted'
);
factory_adoption_expect(($status['version'] ?? null) === 1, 'adoption schema version must remain 1');
factory_adoption_expect(($status['epic_issue'] ?? null) === 630, 'adoption status must belong to epic #630');
factory_adoption_expect(($status['production_green'] ?? null) === false, 'production cannot be claimed GREEN while declared blockers remain');
factory_adoption_expect(($status['epic_close_allowed'] ?? null) === false, 'epic cannot close while blockers remain');

$expectedConsumers = [
    'ci' => [
        'workflow' => '.github/workflows/factory-ci.yml',
        'reusable' => 'pl0n3r/factory/.github/workflows/ci.yml@v1',
    ],
    'policy' => [
        'workflow' => '.github/workflows/factory-policy.yml',
        'reusable' => 'pl0n3r/factory/.github/workflows/politica.yml@v1',
    ],
    'release' => [
        'workflow' => '.github/workflows/factory-release.yml',
        'reusable' => 'pl0n3r/factory/.github/workflows/release.yml@v1',
    ],
    'labels' => [
        'workflow' => '.github/workflows/factory-labels.yml',
        'reusable' => 'pl0n3r/factory/.github/workflows/etiquetas.yml@v1',
    ],
    'coordination' => [
        'workflow' => '.github/workflows/work-coordination.yml',
        'reusable' => 'pl0n3r/factory/.github/workflows/coordinacion.yml@v1',
    ],
    'observer' => [
        'workflow' => '.github/workflows/production-deploy-observer.yml',
        'reusable' => 'pl0n3r/factory/.github/workflows/observar.yml@v1',
    ],
];
factory_adoption_expect(($status['consumed'] ?? null) === $expectedConsumers, 'consumed Factory v1 capabilities drifted');
foreach ($expectedConsumers as $consumer) {
    $workflow = (string) file_get_contents($root . '/' . $consumer['workflow']);
    factory_adoption_expect(
        str_contains($workflow, 'uses: ' . $consumer['reusable']),
        $consumer['workflow'] . ' must keep consuming ' . $consumer['reusable']
    );
}

$blockers = $status['external_blockers'] ?? null;
factory_adoption_expect(is_array($blockers) && array_is_list($blockers), 'external_blockers must be a list');
factory_adoption_expect(
    $blockers === [],
    '#689 must clear the obsolete external observer blocker once Factory push parity is consumed'
);

$observer = (string) file_get_contents($root . '/.github/workflows/production-deploy-observer.yml');
factory_adoption_expect(str_contains($observer, "  push:\n    branches: [main]"), '#689 requires preserving the immediate push-to-main observer');
factory_adoption_expect(
    str_contains($observer, 'pl0n3r/factory/.github/workflows/observar.yml@v1'),
    '#689 must consume Factory v1 observer after central push parity is available'
);

$pending = $status['pending_adoptions'] ?? null;
factory_adoption_expect(
    $pending === [[
        'issue' => 689,
        'code' => 'observer-exact-main-validation',
        'required_for_epic_close' => true,
    ]],
    '#689 must remain pending only for exact-main production observation before epic closure'
);

$coordination = (string) file_get_contents($root . '/.github/workflows/work-coordination.yml');
factory_adoption_expect(
    str_contains($coordination, 'pl0n3r/factory/.github/workflows/coordinacion.yml@v1'),
    '#692 must consume Factory v1 coordination'
);
factory_adoption_expect(
    !is_file($root . '/scripts/work_coordinator.py'),
    '#692 must remove the repository-local coordination authority'
);
factory_adoption_expect(
    !is_file($root . '/tests/test_work_coordinator.py'),
    '#692 must remove the legacy local-coordinator behavior suite'
);

$hasMandatoryPending = array_filter(
    $pending,
    static fn(array $item): bool => ($item['required_for_epic_close'] ?? false) === true
) !== [];
if ($blockers !== [] || $hasMandatoryPending) {
    factory_adoption_expect($status['production_green'] === false, 'GREEN must fail closed while blockers/pending adoption exist');
    factory_adoption_expect($status['epic_close_allowed'] === false, 'epic close must fail closed while blockers/pending adoption exist');
}

echo "Factory adoption status contract passed.\n";
