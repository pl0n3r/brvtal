<?php
declare(strict_types=1);

function load_contract_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "GITHUB LOAD CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$telemetry = (string) file_get_contents($root . '/.github/workflows/ci-throughput-telemetry.yml');
$coordination = (string) file_get_contents($root . '/.github/workflows/work-coordination.yml');
$agents = (string) file_get_contents($root . '/AGENTS.md');

load_contract_expect(!str_contains($telemetry, 'workflow_run:'), 'throughput telemetry must not run after every CI');
load_contract_expect(str_contains($telemetry, "cron: '17 * * * *'"), 'throughput telemetry must sample hourly');
load_contract_expect(str_contains($telemetry, 'workflow_dispatch:'), 'throughput telemetry must keep manual sampling');
load_contract_expect(str_contains($telemetry, 'age <= 3600'), 'scheduled telemetry must reject stale CI runs');
load_contract_expect(
    str_contains($telemetry, 'group: ci-throughput-${{ github.repository }}'),
    'throughput telemetry must serialize per repository'
);
load_contract_expect(str_contains($telemetry, 'timeout-minutes: 8'), 'throughput job must be bounded');
load_contract_expect(
    str_contains($telemetry, 'actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1'),
    'checkout must stay SHA-pinned'
);
load_contract_expect(
    str_contains($telemetry, 'persist-credentials: false'),
    'telemetry checkout must not persist credentials'
);
load_contract_expect(
    str_contains($telemetry, 'ref: ${{ github.event.repository.default_branch }}'),
    'telemetry reporter must execute from the trusted default branch'
);
load_contract_expect(
    !str_contains($telemetry, 'ref: ${{ steps.source.outputs.head_sha }}'),
    'telemetry must never execute reporter code from the observed CI head'
);

foreach ([
    "github.event_name == 'issue_comment'",
    "github.event.sender.login != 'github-actions[bot]'",
    "github.event.comment.body == '/take'",
    "startsWith(github.event.comment.body, '/release ')",
    "startsWith(github.event.comment.body, '/transfer ')",
    "startsWith(github.event.comment.body, '/recover ')",
] as $required) {
    load_contract_expect(
        str_contains($coordination, $required),
        "coordination must retain command-level filter: {$required}"
    );
}

load_contract_expect(
    str_contains($agents, 'one active implementation work line per repository'),
    'AGENTS must enforce one implementation work line per repository'
);
load_contract_expect(
    str_contains($agents, 'Never poll CI/checks/reviews/comments in a loop'),
    'AGENTS must explicitly prohibit polling loops'
);
load_contract_expect(
    str_contains($agents, 'Batch related multi-file writes into one logical Git tree/commit/push when possible'),
    'AGENTS must preserve grouped-push guidance'
);
load_contract_expect(
    !str_contains($agents, 'Use up to **4 concurrent work lines**'),
    'stale four-work-line rule must be removed'
);

echo "GitHub load contract passed.\n";
