<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/public-archive.php';
require_once __DIR__ . '/../config/public_assets.php';

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
    ['id'=>4,'title'=>'Cancelled future','event_date'=>'2026-12-01 21:00:00','published_at'=>'2026-08-01 12:00:00','status'=>'cancelled','ticket_url'=>'https://example.com/4','ticket_types'=>[['id'=>4]]],
    ['id'=>5,'title'=>'Old archive','event_date'=>'2025-06-01 21:00:00','archive_year'=>2025,'status'=>'archived','ticket_url'=>'https://example.com/5','ticket_types'=>[['id'=>5]]],
    ['id'=>6,'title'=>'Private draft','event_date'=>'2026-12-10 21:00:00','status'=>'draft'],
    ['id'=>7,'title'=>'Never published archive','event_date'=>'2026-12-18 21:00:00','status'=>'archived','published_at'=>null],
], $now);

archive_expect(array_column($partition['active'],'id') === [2,1], 'Active events must be date-ordered and contain all public active lifecycle states');
archive_expect(array_column($partition['archive'],'id') === [4,3,5], 'Archive must contain explicit historical states and past public events newest-first');
archive_expect($partition['years'] === [2026,2025], 'Archive years must be unique and descending');
archive_expect(!in_array(6, array_column($partition['active'],'id'), true) && !in_array(6, array_column($partition['archive'],'id'), true), 'Draft event leaked into public partition');
archive_expect(!in_array(7, array_column($partition['archive'],'id'), true), 'Future historical state without publication evidence leaked into public archive');

$past = null;
foreach ($partition['archive'] as $event) if ((int)$event['id'] === 3) $past = $event;
archive_expect(is_array($past), 'Past published event must become historical automatically');
archive_expect((int)$past['archive_year'] === 2026, 'Missing archive_year must derive from event_date');
archive_expect($past['ticket_url'] === null && $past['ticket_instructions'] === null && $past['ticket_qr'] === null, 'Historical event must remove stale ticket CTA fields');
archive_expect($past['ticket_types'] === [], 'Historical event must not expose ticket types');

$public = file_get_contents(__DIR__ . '/../api/public.php');
$index = file_get_contents(__DIR__ . '/../index.html');
$archiveJs = file_get_contents(__DIR__ . '/../js/archive.js');
$runtimeLoader = file_get_contents(__DIR__ . '/../js/public-runtime-loader.js');
archive_expect(is_string($public) && is_string($index) && is_string($archiveJs) && is_string($runtimeLoader), 'Archive sources must be readable');
archive_expect(str_contains($public, "require_once __DIR__ . '/public-archive.php';"), 'Public API must use canonical archive lifecycle helper');
archive_expect(str_contains($public, "WHERE status IN ('published','upcoming','tickets_available','last_tickets','sold_out','cancelled','finished','archived')"), 'Public API must expose only explicit non-draft event lifecycle states');
archive_expect(str_contains($public, "'archive' => \$archive"), 'Public API payload must expose archive data');
archive_expect(str_contains($public, "'related_sets'"), 'Historical events must expose related published sets');
archive_expect(str_contains($index, 'id="eventArchive"'), 'Public frontend must contain a dedicated archive workspace');
archive_expect(str_contains($index, 'css/archive.css') && str_contains($index, 'js/public-runtime-loader.js'), 'Public frontend must load archive styles and adaptive runtime');
archive_expect(str_contains($runtimeLoader, "'js/archive.js'"), 'Adaptive public runtime must load the Archive controller');
archive_expect(!str_contains($archiveJs, 'ticket_instructions'), 'Archive UI must not render payment instructions');
archive_expect(str_contains($index, 'data-archive-search'), 'Archive must expose public text search');
archive_expect(str_contains($index, 'data-archive-relation="sets"'), 'Archive must expose relationship filters');
archive_expect(str_contains($archiveJs, 'applyArchiveFilters'), 'Archive search, year and relationship filters must share one filtering path');
archive_expect(str_contains($archiveJs, '/events/${encodeURIComponent(slug)}'), 'Archived records must link to canonical public event pages');
archive_expect(str_contains($archiveJs, "['/api/public.php', '/api/public'"), 'Archive loader must prefer the production public PHP endpoint');

$optimizedIndex = brvtal_public_optimize_home_images($index);
archive_expect(str_contains($optimizedIndex, 'loading="eager" fetchpriority="high" decoding="async" src="assets/brvtal-logo.jpeg"'), 'Hero logo must remain eager and high priority');
preg_match_all('~<img\b[^>]*>~i', $optimizedIndex, $optimizedImages);
archive_expect(!empty($optimizedImages[0]), 'Optimized public homepage must contain images');
foreach ($optimizedImages[0] as $tag) {
    archive_expect(str_contains($tag, 'decoding="async"'), 'Public homepage images must decode asynchronously');
    if (str_contains($tag, 'hero-logo')) continue;
    archive_expect(str_contains($tag, 'loading="lazy"'), 'Below-fold fallback images must lazy-load');
    archive_expect(!str_contains($tag, 'fetchpriority="high"'), 'Only the hero logo may receive high fetch priority');
}
$manualImage = brvtal_public_optimize_home_images('<img src="/manual.jpg" loading="eager" decoding="sync" alt="Manual">');
archive_expect(substr_count($manualImage, 'loading=') === 1 && str_contains($manualImage, 'loading="eager"'), 'Existing image loading policy must not be duplicated or overwritten');
archive_expect(substr_count($manualImage, 'decoding=') === 1 && str_contains($manualImage, 'decoding="sync"'), 'Existing image decoding policy must not be duplicated or overwritten');

$versioned = brvtal_public_version_assets('<link href="css/style.css"><script src="js/app.js"></script>', 'abc123');
archive_expect(str_contains($versioned, 'css/style.css?v=abc123') && str_contains($versioned, 'js/app.js?v=abc123'), 'Asset versioning must remain intact after image optimization changes');

echo "BRVTAL Public Archive contract tests passed.\n";
