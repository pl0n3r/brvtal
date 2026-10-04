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
$editor = (string)file_get_contents(__DIR__ . '/../discadmin/content-core.js');
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
event_timetable_workflow_assert(str_contains($editor, "fetch('/api/event-workflow.php'+query"), 'editor must call canonical event workflow endpoint');
event_timetable_workflow_assert(str_contains($editor, 'timetable:timetablePayloadFromRows()'), 'atomic request must include timetable');
event_timetable_workflow_assert(str_contains($editor, 'ticket_types:ticketWorkflowPayloads()'), 'atomic request must include tickets');
event_timetable_workflow_assert(str_contains($editor, 'lineup:lineupPayloadFromSelection()'), 'atomic request must include lineup');
event_timetable_workflow_assert(!str_contains($editor, 'await originalSaveEvent()'), 'active editor must not use split Event save before atomic workflow');
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
event_timetable_workflow_assert(($package['version'] ?? null) === '0.1.112', 'package version must be 0.1.112');
event_timetable_workflow_assert(
    ($package['scripts']['test:event-timetable-workflow'] ?? null) === 'python3 -m unittest tests/test_event_timetable_workflow.py',
    'workflow acceptance script must be registered'
);
event_timetable_workflow_assert(str_contains($version, "BRVTAL_APP_VERSION = '0.1.112'"), 'runtime version must be 0.1.112');

echo "BRVTAL Event timetable workflow contract passed.\n";
