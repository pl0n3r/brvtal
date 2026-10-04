<?php
declare(strict_types=1);

function event_timetable_workflow_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "EVENT TIMETABLE WORKFLOW CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$lib = (string)file_get_contents(__DIR__ . '/../api/event-workflow-lib.php');
$endpoint = (string)file_get_contents(__DIR__ . '/../api/event-workflow.php');
$markup = (string)file_get_contents(__DIR__ . '/../discadmin/content-core.php');
$core = (string)file_get_contents(__DIR__ . '/../discadmin/content-core.js');
$workflow = (string)file_get_contents(__DIR__ . '/../discadmin/event-workflow.js');
$integration = (string)file_get_contents(__DIR__ . '/integration/event-workflow.php');
$package = json_decode((string)file_get_contents(__DIR__ . '/../package.json'), true);
$version = (string)file_get_contents(__DIR__ . '/../config/version.php');

foreach ([
    "'timetable'=>\$timetableProvided ? brvtalEventTimetable(\$timetable) : null",
    'brvtalEventWorkflowFetchTimetable',
    'brvtalEventTimetableForEditor',
    "'timetable_create'",
    "'timetable_update'",
    "'timetable_delete'",
    "'event_timetable_items'",
    'TIMETABLE_ARTIST_NOT_FOUND',
    'TIMETABLE_ITEM_NOT_FOUND',
] as $needle) {
    event_timetable_workflow_assert(str_contains($lib, $needle), "workflow lib missing {$needle}");
}

event_timetable_workflow_assert(str_contains($endpoint, "'GET','POST'"), 'event workflow endpoint must support GET and POST');
event_timetable_workflow_assert(str_contains($endpoint, "'timetable'=>brvtalEventTimetableForEditor"), 'GET must return editor timetable');
event_timetable_workflow_assert(str_contains($endpoint, "'timetable_count'=>count"), 'workflow log must include timetable count');

event_timetable_workflow_assert(str_contains($markup, '06 · TIMETABLE'), 'existing Event wizard must gain TIMETABLE step');
event_timetable_workflow_assert(str_contains($markup, 'id="eventTimetable"'), 'wizard must own one timetable editor container');
event_timetable_workflow_assert(str_contains($core, 'refreshTimetable'), 'Content Core must hydrate timetable for existing Events');
event_timetable_workflow_assert(str_contains($core, 'Atomic Event workflow unavailable. Reload DISCADMIN.'), 'Content Core must fail closed if the canonical atomic wrapper is unavailable with TIMETABLE present');

foreach ([
    'function timetablePayloads(root)',
    "root.querySelector('#eventTimetable')",
    'TIMETABLE_NOT_READY',
    'submitEventWorkflow(eventData,tickets,lineup,timetable)',
    'Array.isArray(timetable) ? {timetable} : {}',
    'Event, tickets, roster and timetable saved together.',
] as $needle) {
    event_timetable_workflow_assert(str_contains($workflow, $needle), "canonical editor workflow missing {$needle}");
}

foreach ([
    'timetable creates must be audited',
    'timetable updates must be audited',
    'timetable deletes must be audited',
    'timetable failure must roll back Event mutation',
    'timetable failure must roll back Ticket mutation',
    'timetable failure must roll back lineup mutation',
    'timetable failure must roll back timetable mutation',
    'overlapping timetable must fail before workflow mutation',
] as $needle) {
    event_timetable_workflow_assert(str_contains($integration, $needle), "integration proof missing {$needle}");
}

$runtimeVersion = null;
if (preg_match("/BRVTAL_APP_VERSION\\s*=\\s*'([^']+)'/", $version, $versionMatches) === 1) {
    $runtimeVersion = $versionMatches[1];
}
event_timetable_workflow_assert(
    is_string($runtimeVersion) && $runtimeVersion !== '',
    'runtime version must be declared'
);
event_timetable_workflow_assert(
    ($package['version'] ?? null) === $runtimeVersion,
    'package/runtime versions must stay aligned'
);
event_timetable_workflow_assert(
    version_compare($runtimeVersion, '0.1.112', '>='),
    'timetable workflow requires runtime version 0.1.112 or newer'
);
event_timetable_workflow_assert(
    ($package['scripts']['test:event-timetable-workflow'] ?? null) === 'python3 -m unittest tests/test_event_timetable_workflow.py',
    'workflow acceptance script must be registered'
);

echo "BRVTAL Event timetable workflow contract passed.\n";
