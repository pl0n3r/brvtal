<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/public_visibility.php';
require_once __DIR__ . '/../config/public_page.php';

function event_record_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "EVENT RECORD CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$now = new DateTimeImmutable('2026-09-16 12:00:00');
event_record_expect(!brvtal_public_event_is_historical([
    'status' => 'tickets_available',
    'event_date' => '2026-09-20 21:00:00',
], $now), 'future active Event must stay in active presentation mode');
event_record_expect(brvtal_public_event_is_historical([
    'status' => 'published',
    'event_date' => '2026-08-14 21:00:00',
], $now), 'past public Event must become a historical record even before manual status cleanup');
event_record_expect(brvtal_public_event_is_historical([
    'status' => 'cancelled',
    'event_date' => '2026-10-01 21:00:00',
    'published_at' => '2026-08-01 12:00:00',
], $now), 'published cancelled Event must remain a historical public record');
event_record_expect(!brvtal_public_event_is_historical([
    'status' => 'draft',
    'event_date' => '2026-08-14 21:00:00',
], $now), 'private draft must never gain historical public presentation');

$source = (string)file_get_contents(__DIR__ . '/../config/public_page.php');
$archive = (string)file_get_contents(__DIR__ . '/../api/public-archive.php');
$css = (string)file_get_contents(__DIR__ . '/../css/public-event-record.css');
event_record_expect(str_contains($archive, 'brvtal_public_event_is_historical($event, $now)'), 'Archive and Event Record must share lifecycle classification');
event_record_expect(str_contains($source, 'brvtal_public_event_is_historical($detail)'), 'canonical Event page must use lifecycle classification');
event_record_expect(str_contains($source, "sets_media WHERE event_id=? AND status='published'"), 'Event Record must reuse explicit published Set relation');
event_record_expect(str_contains($source, "rel.related_type='event' AND rel.related_id=?"), 'Event Record must reuse explicit editorial relation');
event_record_expect(str_contains($source, "bp.status='published'"), 'Event Record transmissions must remain publication-gated');
event_record_expect(!str_contains($source, "['MEMORIES']"), 'Event Record must not invent a Memories relation that the backend does not own yet');
event_record_expect(str_contains($source, 'public-event-record.css'), 'Event pages must load the dedicated Event Record visual layer');
event_record_expect(str_contains($css, 'body.entity-page--event:before'), 'Event Record keeps BRVTAL grain/signal texture');
event_record_expect(str_contains($css, '--event-signal:#b6ff00'), 'Event Record keeps acid green as signal fallback rather than page background');
event_record_expect(str_contains($css, '@media(max-width:620px)'), 'Event Record must have a dedicated mobile layout');
event_record_expect(str_contains($css, 'prefers-reduced-motion:reduce'), 'Event Record must honor reduced motion');

$seo = [
    'title' => 'GENESIS — BRVTAL',
    'description' => 'Event record fixture',
    'canonical' => 'https://www.brvtal.com.co/events/genesis',
    'image' => 'https://example.com/genesis.jpg',
    'schema' => ['@type' => 'MusicEvent'],
];
$historicalPage = [
    'entity' => [
        'id' => 14,
        'route_type' => 'events',
        'title' => 'GENESIS',
        'description' => 'BRVTAL x RANDOM KORE',
        'status' => 'finished',
        'accent' => '#b6ff00',
    ],
    'facts' => ['DATE'=>'14.08.2026 / 21:00','LOCATION'=>'PEREIRA / COLOMBIA','STATUS'=>'FINISHED','RECORD'=>'ARCHIVE / 2026'],
    'links' => [],
    'record' => ['state'=>'historical','year'=>'2026','status'=>'FINISHED'],
    'related' => [
        'LINEUP' => [['title'=>'PL0N3R','slug'=>'pl0n3r','route_type'=>'artists','image'=>'','meta'=>'DJ']],
        'SETS' => [['title'=>'GENESIS LIVE SET','slug'=>'genesis-live','route_type'=>'sets','image'=>'','meta'=>'SOUNDCLOUD']],
        'TRANSMISSIONS' => [['title'=>'GENESIS RECAP','slug'=>'genesis-recap','route_type'=>'blog','image'=>'','meta'=>'16.08.2026']],
    ],
    'degraded' => false,
];
$historicalHtml = brvtal_public_entity_page($historicalPage, $seo);
event_record_expect(str_contains($historicalHtml, 'data-event-record-state="historical"'), 'historical Event must identify its record state in markup');
event_record_expect(str_contains($historicalHtml, 'EVENT RECORD / BRVTAL'), 'historical Event hero must frame the page as Event Record');
event_record_expect(str_contains($historicalHtml, 'EVENT RECORD / FINISHED'), 'historical record band must retain lifecycle status');
event_record_expect(str_contains($historicalHtml, 'LINEUP / RECORD / 01'), 'historical lineup must be framed as archived record');
event_record_expect(str_contains($historicalHtml, 'RECORDED SETS / 01'), 'historical Sets must be framed as recorded material');
event_record_expect(str_contains($historicalHtml, 'TRANSMISSIONS / 01'), 'explicit editorial relation must surface in Event Record');
event_record_expect(!str_contains($historicalHtml, '>TICKETS ↗<'), 'historical Event must not regain stale commercial CTA');

$activePage = $historicalPage;
$activePage['entity']['status'] = 'tickets_available';
$activePage['record'] = ['state'=>'active','year'=>'2026','status'=>'TICKETS AVAILABLE'];
$activePage['facts']['STATUS'] = 'TICKETS AVAILABLE';
unset($activePage['facts']['RECORD']);
$activePage['links'] = ['TICKETS'=>'https://tickets.example.com/genesis'];
$activePage['related'] = ['LINEUP'=>$historicalPage['related']['LINEUP']];
$activeHtml = brvtal_public_entity_page($activePage, $seo);
event_record_expect(str_contains($activeHtml, 'data-event-record-state="active"'), 'active Event must identify active presentation state');
event_record_expect(str_contains($activeHtml, 'EVENT SIGNAL / TICKETS AVAILABLE'), 'active Event band must expose real lifecycle status');
event_record_expect(str_contains($activeHtml, '>TICKETS ↗<'), 'active Event keeps valid commercial CTA supplied by lifecycle policy');
event_record_expect(str_contains($activeHtml, '← BACK TO EVENTS'), 'active Event returns to Events while historical Event returns to Archive framing');

echo "BRVTAL Event Record contract tests passed.\n";
