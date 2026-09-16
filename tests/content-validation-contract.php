<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/content-validation.php';

function content_validation_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "CONTENT VALIDATION CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$event = brvtal_content_temporal_normalize('events', ['event_date' => '2026-10-31T21:05']);
content_validation_expect($event['error'] === null, 'datetime-local Event values must remain accepted');
content_validation_expect(($event['payload']['event_date'] ?? null) === '2026-10-31 21:05:00', 'Event datetime-local values must normalize for MariaDB');

$invalidEvent = brvtal_content_temporal_normalize('events', ['event_date' => '2026-02-31T21:05']);
content_validation_expect(($invalidEvent['error']['error'] ?? null) === 'INVALID_DATE', 'impossible Event dates must be rejected');
content_validation_expect(($invalidEvent['error']['field'] ?? null) === 'event_date', 'Event date errors must identify event_date');
$nonScalarEvent = brvtal_content_temporal_normalize('events', ['event_date' => ['2026-10-31T21:05']]);
content_validation_expect(($nonScalarEvent['error']['error'] ?? null) === 'INVALID_DATE', 'array Event dates must fail cleanly before string conversion');
content_validation_expect(($nonScalarEvent['error']['field'] ?? null) === 'event_date', 'non-scalar Event date errors must identify event_date');

$artist = brvtal_content_temporal_normalize('artists', ['collective_joined_at' => '2026-09-16']);
content_validation_expect($artist['error'] === null, 'valid Artist lifecycle DATE values must be accepted');
$invalidArtist = brvtal_content_temporal_normalize('artists', ['collective_left_at' => '16/09/2026']);
content_validation_expect(($invalidArtist['error']['field'] ?? null) === 'collective_left_at', 'malformed Artist lifecycle dates must be rejected');

$ticket = brvtal_content_temporal_normalize('ticket_types', [
    'available_from' => '2026-10-10T20:00',
    'available_until' => '2026-10-10 23:00:00',
]);
content_validation_expect($ticket['error'] === null, 'valid Ticket availability datetimes must be accepted');
content_validation_expect(brvtal_ticket_window_error($ticket['payload']) === null, 'chronological Ticket availability windows must be accepted');
$invalidWindow = brvtal_ticket_window_error([
    'available_from' => '2026-10-10 20:00:00',
    'available_until' => '2026-10-09 20:00:00',
]);
content_validation_expect(($invalidWindow['error'] ?? null) === 'INVALID_AVAILABILITY_WINDOW', 'inverted Ticket availability windows must be rejected');
content_validation_expect(($invalidWindow['field'] ?? null) === 'available_until', 'inverted Ticket windows must identify available_until');
content_validation_expect(brvtal_ticket_window_error(['available_from' => '2026-10-10 20:00:00']) === null, 'open-ended Ticket availability must remain valid');

content_validation_expect((brvtal_page_identity_error(['title'=>'','slug'=>'manifesto'])['field'] ?? null) === 'title', 'Pages must require a title');
content_validation_expect((brvtal_page_identity_error(['title'=>'Manifesto','slug'=>''])['field'] ?? null) === 'slug', 'Pages must require a slug');
content_validation_expect(brvtal_page_identity_error(['title'=>'Manifesto','slug'=>'manifesto']) === null, 'valid Page identity must pass');

$index = (string)file_get_contents(__DIR__ . '/../api/index.php');
$pageUi = (string)file_get_contents(__DIR__ . '/../discadmin/pages-publication-contract.js');
content_validation_expect(str_contains($index, "require_once __DIR__ . '/content-validation.php';"), 'Core API must load the shared content validation contract');
content_validation_expect(str_contains($index, 'brvtal_content_temporal_normalize($resource,$d)'), 'Core API must normalize temporal fields before database writes');
content_validation_expect(str_contains($index, 'brvtal_ticket_window_error(array_replace($before,$p))'), 'Ticket PUT validation must compare the final merged window');
content_validation_expect(str_contains($index, 'brvtal_page_identity_error($pageState)'), 'Page PUT validation must use the final merged identity');
content_validation_expect(str_contains($pageUi, 'title.required = true') && str_contains($pageUi, 'slug.required = true'), 'Page editor must expose required identity fields');

echo "BRVTAL content validation contract tests passed.\n";
