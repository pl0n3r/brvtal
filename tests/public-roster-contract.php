<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/public_visibility.php';
require_once __DIR__ . '/../config/public_page.php';
require_once __DIR__ . '/../config/public_artist.php';

function roster_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "PUBLIC ROSTER CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

roster_expect(brvtal_public_artist_roster_status(['collective_status'=>'active']) === 'active', 'active membership must remain active');
roster_expect(brvtal_public_artist_roster_status(['collective_status'=>'alumni']) === 'alumni', 'alumni membership must remain alumni');
roster_expect(brvtal_public_artist_roster_status(['collective_status'=>'none']) === 'network', 'non-members must be framed as network artists, not invented collective members');

$activeFacts = brvtal_public_artist_membership_facts(['collective_status'=>'active','collective_joined_at'=>'2025-04-10']);
roster_expect(($activeFacts['BRVTAL'] ?? '') === 'ACTIVE COLLECTIVE', 'active Artist page must expose real collective state');
roster_expect(($activeFacts['MEMBER SINCE'] ?? '') === '10.04.2025', 'active Artist page must expose structured joined date');
$alumniFacts = brvtal_public_artist_membership_facts(['collective_status'=>'alumni','collective_joined_at'=>'2024-02-01','collective_left_at'=>'2025-03-09']);
roster_expect(($alumniFacts['MEMBERSHIP'] ?? '') === '01.02.2024 — 09.03.2025', 'alumni Artist page must expose the real membership period');
roster_expect(brvtal_public_artist_membership_facts(['collective_status'=>'none']) === [], 'non-members must not receive a fake collective fact');

$events = brvtal_public_artist_partition_events([
    ['title'=>'Later','status'=>'published','event_date'=>'2099-12-20 22:00:00'],
    ['title'=>'Sooner','status'=>'published','event_date'=>'2099-10-20 22:00:00'],
    ['title'=>'Old','status'=>'finished','event_date'=>'2020-01-01 22:00:00','published_at'=>'2019-12-01 12:00:00'],
]);
roster_expect(array_column($events['upcoming'], 'title') === ['Sooner','Later'], 'upcoming Artist Events must be nearest-first');
roster_expect(array_column($events['historical'], 'title') === ['Old'], 'historical Artist Events must use canonical lifecycle classification');

$api = (string)file_get_contents(__DIR__ . '/../api/public.php');
$artist = (string)file_get_contents(__DIR__ . '/../config/public_artist.php');
$runtime = (string)file_get_contents(__DIR__ . '/../js/public-roster.js');
$loader = (string)file_get_contents(__DIR__ . '/../js/public-runtime-loader.js');
$entry = (string)file_get_contents(__DIR__ . '/../index.php');
$css = (string)file_get_contents(__DIR__ . '/../css/public-roster.css');

roster_expect(str_contains($api, "FROM artists\n         WHERE status='published'"), 'Home Roster source must remain limited to published Artists');
foreach (['collective_status','collective_order','collective_joined_at','collective_left_at'] as $field) {
    roster_expect(str_contains($api, $field), "public Artist payload must expose {$field}");
}
roster_expect(str_contains($runtime, '`/artists/${encodeURIComponent(slug)}`'), 'Home Roster must navigate to canonical Artist pages');
roster_expect(!str_contains($runtime, 'website_url') && !str_contains($runtime, 'instagram_url'), 'Roster navigation must not use external profile URLs as its primary destination');
roster_expect(str_contains($runtime, "['active', 'CORE / ACTIVE']") && str_contains($runtime, "['alumni', 'ALUMNI / ARCHIVE']"), 'Home Roster must visibly separate current and alumni membership');
roster_expect(str_contains($runtime, "return 'ARTIST / COLLABORATOR';"), 'non-member framing must stay contextual and must not create a backend membership tier');
roster_expect(str_contains($runtime, 'window.BRVTALPublicDataPromise'), 'Roster runtime must reuse the canonical public request');
roster_expect(!str_contains($runtime, 'fetch('), 'Roster runtime must not add a duplicate public API request');
roster_expect(str_contains($loader, "'js/app.js', 'js/public-roster.js'"), 'Roster must load after the canonical dynamic frontend');

roster_expect(str_contains($artist, "rel.related_type='artist'"), 'Artist Transmissions must come from explicit Blog relations');
roster_expect(str_contains($artist, "bp.status='published'"), 'Artist Transmissions must suppress unpublished posts');
roster_expect(str_contains($artist, 'brvtal_public_event_is_historical($event)'), 'Artist Event grouping must reuse canonical Event lifecycle');
roster_expect(!str_contains($artist, 'media') && !str_contains($artist, 'memories'), 'Artist pages must not infer Memories without a structured relation');
roster_expect(str_contains($entry, "require_once __DIR__ . '/config/public_artist.php';"), 'canonical delivery must load the Artist roster enhancer');
roster_expect(str_contains($entry, 'brvtal_public_artist_enhance_page'), 'canonical Artist pages must use roster enrichment');
roster_expect(str_contains($entry, 'css/public-roster.css'), 'Home must receive the dedicated Roster visual layer');
roster_expect(str_contains($css, '@media(max-width:760px)'), 'Roster must have an explicit mobile layout');
roster_expect(str_contains($css, 'min-height:44px'), 'Roster/mobile controls must preserve touch-size affordances');
roster_expect(str_contains($css, 'prefers-reduced-motion:reduce'), 'Roster visual layer must respect reduced motion');

$decorated = brvtal_public_artist_decorate_html(
    '<link rel="stylesheet" href="/css/public-entity.css"><body class="entity-page"><a>← BACK TO ARCHIVE</a><div>ABOUT / INFORMATION</div>',
    ['entity'=>['route_type'=>'artists','collective_status'=>'active']]
);
roster_expect(str_contains($decorated, '/css/public-roster.css'), 'Artist page must load Roster styling');
roster_expect(str_contains($decorated, 'entity-page--artist-active'), 'Artist page body must expose membership framing to CSS');
roster_expect(str_contains($decorated, '← BACK TO ROSTER'), 'Artist page must return to the Roster, not generic archive wording');
roster_expect(str_contains($decorated, 'ARTIST / IDENTITY'), 'Artist statement must use identity framing');

echo "BRVTAL public Roster contract tests passed.\n";
