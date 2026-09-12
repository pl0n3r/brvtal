<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/public-archive.php';

function archive_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$groups = brvtal_public_event_statuses();
archive_expect($groups['active'] === ['published','upcoming','tickets_available','last_tickets','sold_out'], 'Active public lifecycle allowlist changed unexpectedly');
archive_expect($groups['historical'] === ['finished','archived','cancelled'], 'Historical lifecycle allowlist changed unexpectedly');
archive_expect(!in_array('draft', brvtal_public_visible_event_statuses(), true), 'Draft must never be a public event state');

$now = new DateTimeImmutable('2026-09-11 12:00:00');
$partition = brvtal_public_partition_events([
    ['id'=>1,'title'=>'Future published','event_date'=>'2026-10-01 21:00:00','status'=>'published','sort_order'=>0,'ticket_url'=>'https://example.com/1','ticket_types'=>[['id'=>1]]],
    ['id'=>2,'title'=>'Tickets live','event_date'=>'2026-09-20 21:00:00','status'=>'tickets_available','sort_order'=>0,'ticket_url'=>'https://example.com/2','ticket_types'=>[['id'=>2]]],
    ['id'=>3,'title'=>'Past published','event_date'=>'2026-08-07 21:00:00','archive_year'=>null,'status'=>'published','ticket_url'=>'https://example.com/3','ticket_instructions'=>'pay','ticket_qr'=>'/qr.png','ticket_types'=>[['id'=>3]]],
    ['id'=>4,'title'=>'Cancelled future','event_date'=>'2026-12-01 21:00:00','status'=>'cancelled','ticket_url'=>'https://example.com/4','ticket_types'=>[['id'=>4]]],
    ['id'=>5,'title'=>'Old archive','event_date'=>'2025-06-01 21:00:00','archive_year'=>2025,'status'=>'archived','ticket_url'=>'https://example.com/5','ticket_types'=>[['id'=>5]]],
    ['id'=>6,'title'=>'Private draft','event_date'=>'2026-12-10 21:00:00','status'=>'draft'],
], $now);

archive_expect(array_column($partition['active'],'id') === [2,1], 'Active events must be date-ordered and contain all public active lifecycle states');
archive_expect(array_column($partition['archive'],'id') === [4,3,5], 'Archive must contain explicit historical states and past public events newest-first');
archive_expect($partition['years'] === [2026,2025], 'Archive years must be unique and descending');
archive_expect(!in_array(6, array_column($partition['active'],'id'), true) && !in_array(6, array_column($partition['archive'],'id'), true), 'Draft event leaked into public partition');

$past = null;
foreach ($partition['archive'] as $event) if ((int)$event['id'] === 3) $past = $event;
archive_expect(is_array($past), 'Past published event must become historical automatically');
archive_expect((int)$past['archive_year'] === 2026, 'Missing archive_year must derive from event_date');
archive_expect($past['ticket_url'] === null && $past['ticket_instructions'] === null && $past['ticket_qr'] === null, 'Historical event must remove stale ticket CTA fields');
archive_expect($past['ticket_types'] === [], 'Historical event must not expose ticket types');

$public = file_get_contents(__DIR__ . '/../api/public.php');
$index = file_get_contents(__DIR__ . '/../index.html');
$archiveJs = file_get_contents(__DIR__ . '/../js/archive.js');
archive_expect(is_string($public) && is_string($index) && is_string($archiveJs), 'Archive sources must be readable');
archive_expect(str_contains($public, "require_once __DIR__ . '/public-archive.php';"), 'Public API must use canonical archive lifecycle helper');
archive_expect(str_contains($public, "WHERE status IN ('published','upcoming','tickets_available','last_tickets','sold_out','cancelled','finished','archived')"), 'Public API must expose only explicit non-draft event lifecycle states');
archive_expect(str_contains($public, "'archive' => \$archive"), 'Public API payload must expose archive data');
archive_expect(str_contains($public, "'related_sets'"), 'Historical events must expose related published sets');
archive_expect(str_contains($index, 'id="eventArchive"'), 'Public frontend must contain a dedicated archive workspace');
archive_expect(str_contains($index, 'css/archive.css') && str_contains($index, 'js/archive.js'), 'Public frontend must load archive assets');
archive_expect(!str_contains($archiveJs, 'ticket_instructions'), 'Archive UI must not render payment instructions');

echo "BRVTAL Public Archive contract tests passed.\n";
