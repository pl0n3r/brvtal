<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/event-workflow-lib.php';

function event_workflow_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "EVENT WORKFLOW CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$draft = brvtal_event_workflow_request([
    'event'=>['title'=>'Draft Event','slug'=>'Draft Event','status'=>'draft'],
    'ticket_types'=>[],
    'lineup'=>[],
]);
event_workflow_assert(($draft['event']['slug'] ?? '') === 'draft-event', 'Event slug must normalize deterministically');
event_workflow_assert(brvtal_event_state_error(array_replace(['status'=>'draft'], $draft['event'])) === null, 'incomplete drafts must remain allowed');

$missingDate = brvtal_event_state_error(['title'=>'Public Event','status'=>'published','city'=>'Pereira']);
event_workflow_assert(($missingDate['error'] ?? '') === 'EVENT_DATE_REQUIRED', 'non-draft Event must require event_date');
$missingCity = brvtal_event_state_error(['title'=>'Public Event','status'=>'published','event_date'=>'2026-09-16 21:00:00']);
event_workflow_assert(($missingCity['error'] ?? '') === 'EVENT_CITY_REQUIRED', 'non-draft Event must require city');
event_workflow_assert(brvtal_event_state_error(['title'=>'Public Event','status'=>'published','event_date'=>'2026-09-16 21:00:00','city'=>'Pereira']) === null, 'complete public Event must pass');

$ticket = brvtal_event_workflow_ticket([
    'id'=>'7','name'=>'Preventa','price'=>'20000','currency'=>'cop','external_url'=>'https://example.com/tickets',
    'available_from'=>'2026-09-01T10:00','available_until'=>'2026-09-15T23:00','status'=>'active',
]);
event_workflow_assert(($ticket['id'] ?? 0) === 7, 'Ticket ID must normalize to int');
event_workflow_assert(($ticket['currency'] ?? '') === 'COP', 'Ticket currency must normalize uppercase');
event_workflow_assert(($ticket['available_from'] ?? '') === '2026-09-01 10:00:00', 'Ticket availability must normalize to SQL datetime');

event_workflow_assert(brvtal_ticket_window_error($ticket) === null, 'valid Ticket window must pass');
$invalidWindow = false;
try {
    $bad = brvtal_event_workflow_ticket(['name'=>'Bad','available_from'=>'2026-09-15 10:00','available_until'=>'2026-09-14 10:00']);
    $invalidWindow = brvtal_ticket_window_error($bad) !== null;
} catch (Throwable) {
    $invalidWindow = true;
}
event_workflow_assert($invalidWindow, 'invalid Ticket window must be detectable before persistence');

$duplicateTicket = false;
try {
    brvtal_event_workflow_request([
        'event'=>['title'=>'Draft','status'=>'draft'],
        'ticket_types'=>[['id'=>5,'name'=>'A'],['id'=>5,'name'=>'B']],
        'lineup'=>[],
    ]);
} catch (InvalidArgumentException $e) {
    $duplicateTicket = $e->getMessage() === 'DUPLICATE_TICKET_ID';
}
event_workflow_assert($duplicateTicket, 'same Ticket ID cannot appear twice in one workflow save');

$endpoint = (string)file_get_contents(__DIR__ . '/../api/event-workflow.php');
$bridge = (string)file_get_contents(__DIR__ . '/../discadmin/event-workflow.js');
$admin = (string)file_get_contents(__DIR__ . '/../discadmin/index.php');
event_workflow_assert(str_contains($endpoint, 'brvtal_admin_require_csrf'), 'workflow mutation must require CSRF');
event_workflow_assert(str_contains($endpoint, 'brvtal_activity_record'), 'workflow must retain Admin Activity');
event_workflow_assert(str_contains($bridge, '/api/event-workflow.php'), 'Event editor must call the atomic workflow endpoint');
event_workflow_assert(str_contains($bridge, 'TICKETS_NOT_READY') && str_contains($bridge, 'LINEUP_NOT_READY'), 'workflow must refuse unknown ticket/lineup state');
event_workflow_assert(str_contains($admin, '/discadmin/event-workflow.js'), 'atomic workflow bridge must load in DISCADMIN');

echo "BRVTAL Event workflow contract tests passed.\n";
