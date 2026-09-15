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
archive_expect(brvtal_public_event_is_visible(['status'=>'published','event_date'=>'2026-10-01 21:00:00'], $now), 'Active public Event must remain visible');
archive_expect(!brvtal_public_event_is_visible(['status'=>'archived','event_date'=>'2026-12-18 21:00:00','published_at'=>null], $now), 'Future historical Event without publication proof must stay private');
archive_expect(!brvtal_public_event_is_visible(['status'=>'cancelled','event_date'=>null,'published_at'=>null], $now), 'Undated historical Event without publication proof must stay private');
archive_expect(brvtal_public_event_is_visible(['status'=>'cancelled','event_date'=>'2026-12-18 21:00:00','published_at'=>'2026-08-01 12:00:00'], $now), 'Published historical Event may remain discoverable before its original date');
archive_expect(brvtal_public_event_is_visible(['status'=>'archived','event_date'=>'2025-06-01 21:00:00','published_at'=>null], $now), 'Past historical records must remain discoverable when legacy published_at is missing');
archive_expect(!brvtal_public_event_is_visible(['status'=>'draft','event_date'=>'2025-06-01 21:00:00','published_at'=>'2025-01-01'], $now), 'Draft must never pass canonical Event visibility');

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
$indexPhp = file_get_contents(__DIR__ . '/../index.php');
$archiveJs = file_get_contents(__DIR__ . '/../js/archive.js');
$runtimeLoader = file_get_contents(__DIR__ . '/../js/public-runtime-loader.js');
$htaccess = file_get_contents(__DIR__ . '/../.htaccess');
archive_expect(is_string($public) && is_string($index) && is_string($indexPhp) && is_string($archiveJs) && is_string($runtimeLoader) && is_string($htaccess), 'Archive/public delivery sources must be readable');
archive_expect(str_contains($public, "require_once __DIR__ . '/public-archive.php';"), 'Public API must use canonical archive lifecycle helper');
archive_expect(str_contains($public, 'brvtal_public_visible_event_statuses()'), 'Public API must derive Event visibility from the canonical lifecycle policy');
archive_expect(str_contains($public, 'brvtal_public_sql_placeholders($eventStatuses)'), 'Public API must bind canonical Event statuses through prepared placeholders');
archive_expect(!str_contains($public, "status IN ('published','upcoming','tickets_available','last_tickets','sold_out','cancelled','finished','archived')"), 'Public API must not duplicate the Event visibility allowlist');
archive_expect(str_contains($public, "'archive' => \$archive"), 'Public API payload must expose archive data');
archive_expect(str_contains($public, "'related_sets'"), 'Historical events must expose related published sets');
archive_expect(str_contains($index, 'id="eventArchive"'), 'Public frontend must contain a dedicated archive workspace');
archive_expect(str_contains($index, 'css/archive.css') && str_contains($index, 'js/public-runtime-loader.js'), 'Public frontend must load archive styles and adaptive runtime');
archive_expect(str_contains($runtimeLoader, "'js/archive.js'"), 'Adaptive public runtime must load the Archive controller');
archive_expect(str_contains($runtimeLoader, 'window.BRVTAL_PUBLIC_VERSION = version'), 'Adaptive public runtime must expose the deployment version for lazy-module cache busting');
archive_expect(!str_contains($archiveJs, 'ticket_instructions'), 'Archive UI must not render payment instructions');
archive_expect(str_contains($index, 'data-archive-search'), 'Archive must expose public text search');
archive_expect(str_contains($index, 'data-archive-relation="sets"'), 'Archive must expose relationship filters');
archive_expect(str_contains($archiveJs, 'applyArchiveFilters'), 'Archive search, year and relationship filters must share one filtering path');
archive_expect(str_contains($archiveJs, '/events/${encodeURIComponent(slug)}'), 'Archived records must link to canonical public event pages');
archive_expect(str_contains($archiveJs, "['/api/public.php', '/api/public'"), 'Archive loader must prefer the production public PHP endpoint');

$logoDimensions = brvtal_public_local_image_dimensions('assets/brvtal-logo.jpeg');
archive_expect(is_array($logoDimensions) && $logoDimensions['width'] > 0 && $logoDimensions['height'] > 0, 'Local public image dimensions must resolve from the actual asset');
archive_expect(brvtal_public_local_image_dimensions('https://example.com/logo.jpg') === null, 'External images must not trigger local filesystem inspection');
archive_expect(brvtal_public_local_image_dimensions('../config/config.php') === null, 'Traversal paths must never resolve as public image assets');

$optimizedIndex = brvtal_public_optimize_home_images($index);
archive_expect(str_contains($optimizedIndex, 'loading="eager" fetchpriority="high" decoding="async"'), 'Hero logo must remain eager and high priority');
preg_match_all('~<img\b[^>]*>~i', $optimizedIndex, $optimizedImages);
archive_expect(!empty($optimizedImages[0]), 'Optimized public homepage must contain images');
foreach ($optimizedImages[0] as $tag) {
    archive_expect(str_contains($tag, 'decoding="async"'), 'Public homepage images must decode asynchronously');
    archive_expect(preg_match('~\bwidth="[1-9][0-9]*"~', $tag) === 1, 'Initial public images must expose intrinsic width');
    archive_expect(preg_match('~\bheight="[1-9][0-9]*"~', $tag) === 1, 'Initial public images must expose intrinsic height');
    if (str_contains($tag, 'hero-logo')) continue;
    archive_expect(str_contains($tag, 'loading="lazy"'), 'Below-fold fallback images must lazy-load');
    archive_expect(!str_contains($tag, 'fetchpriority="high"'), 'Only the hero logo may receive high fetch priority');
}
$manualImage = brvtal_public_optimize_home_images('<img src="/manual.jpg" loading="eager" decoding="sync" alt="Manual">');
archive_expect(substr_count($manualImage, 'loading=') === 1 && str_contains($manualImage, 'loading="eager"'), 'Existing image loading policy must not be duplicated or overwritten');
archive_expect(substr_count($manualImage, 'decoding=') === 1 && str_contains($manualImage, 'decoding="sync"'), 'Existing image decoding policy must not be duplicated or overwritten');
$presizedImage = brvtal_public_optimize_home_images('<img src="assets/brvtal-logo.jpeg" width="10" height="20" alt="Manual size">');
archive_expect(substr_count($presizedImage, 'width=') === 1 && str_contains($presizedImage, 'width="10"'), 'Existing image width must be preserved');
archive_expect(substr_count($presizedImage, 'height=') === 1 && str_contains($presizedImage, 'height="20"'), 'Existing image height must be preserved');

$deferred = brvtal_public_defer_stylesheets(
    '<link rel="stylesheet" href="css/style.css"><link rel="stylesheet" href="css/archive.css">',
    ['css/archive.css']
);
archive_expect(str_contains($deferred, '<link rel="stylesheet" href="css/style.css">'), 'Critical public stylesheet must remain render-blocking');
archive_expect(str_contains($deferred, 'href="css/archive.css" media="print" onload="this.media=\'all\'"'), 'Below-fold public stylesheet must load without blocking first render');
archive_expect(str_contains($deferred, '<noscript><link rel="stylesheet" href="css/archive.css"></noscript>'), 'Deferred stylesheet must retain a no-JavaScript fallback');
archive_expect(str_contains($indexPhp, "'css/archive.css'") && str_contains($indexPhp, "'css/public-media.css'") && str_contains($indexPhp, "'css/input-accessibility.css'") && str_contains($indexPhp, "'css/mobile-events.css'"), 'Canonical Home delivery must defer the known below-fold/support stylesheets');
archive_expect(!str_contains($indexPhp, "'css/style.css',"), 'Primary Home stylesheet must not be deferred');
$inlineHeroPos = strpos($indexPhp, 'brvtal_public_inline_stylesheets($html');
$deferStylesPos = strpos($indexPhp, 'brvtal_public_defer_stylesheets($html');
archive_expect($inlineHeroPos !== false && $deferStylesPos !== false && $inlineHeroPos < $deferStylesPos, 'Hero styles must be made synchronous before below-fold stylesheet deferral');
$inlineHeroFixture = brvtal_public_inline_stylesheets(
    '<link rel="stylesheet" href="css/hero-slider.css"><link rel="stylesheet" href="css/hero-slider-v2.css">',
    ['css/hero-slider.css','css/hero-slider-v2.css']
);
archive_expect(str_contains($inlineHeroFixture, 'data-brvtal-inline="css/hero-slider.css"') && str_contains($inlineHeroFixture, 'data-brvtal-inline="css/hero-slider-v2.css"'), 'Hero slider styles must remain synchronous through inline critical CSS');
archive_expect(!str_contains($inlineHeroFixture, 'href="css/hero-slider.css"') && !str_contains($inlineHeroFixture, 'href="css/hero-slider-v2.css"'), 'Inlined Hero styles must not leave render-blocking stylesheet requests');

$versioned = brvtal_public_version_assets('<link href="css/style.css"><script src="js/app.js"></script><img src="assets/brvtal-logo.jpeg">', 'abc123');
archive_expect(str_contains($versioned, 'css/style.css?v=abc123') && str_contains($versioned, 'js/app.js?v=abc123'), 'CSS/JS deployment versioning must remain intact');
archive_expect(str_contains($versioned, 'assets/brvtal-logo.jpeg?v=abc123'), 'Initial static image URLs must receive deploy-SHA cache busting before long-lived caching');
archive_expect(str_contains($htaccess, 'ExpiresByType text/css "access plus 1 year"'), 'Versioned CSS must receive a long browser cache lifetime');
archive_expect(str_contains($htaccess, 'ExpiresByType image/jpeg "access plus 1 year"') && str_contains($htaccess, 'max-age=31536000, immutable'), 'Static image/font delivery must receive immutable long-lived caching');
archive_expect(!str_contains($htaccess, 'ExpiresByType application/javascript'), 'JavaScript must not become immutable until every lazy import is deploy-versioned');

echo "BRVTAL Public Archive contract tests passed.\n";
