<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/public-related.php';

function related_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$artists = [
    ['id'=>1,'name'=>'PL0N3R'],
    ['id'=>2,'name'=>'DNL5'],
];
$active = [
    ['id'=>10,'title'=>'NEXT NIGHT','lineup'=>[['artist_id'=>1],['artist_id'=>2]]],
];
$archive = [
    ['id'=>11,'title'=>'PAST NIGHT','lineup'=>[['artist_id'=>2],['artist_id'=>999]]],
];
$rawSets = [
    ['id'=>20,'title'=>'PL0N3R SET','artist_id'=>1,'artist_name'=>'PL0N3R','event_id'=>10,'event_title'=>'NEXT NIGHT'],
    ['id'=>21,'title'=>'DNL5 ARCHIVE SET','artist_id'=>2,'artist_name'=>'DNL5','event_id'=>11,'event_title'=>'PAST NIGHT'],
    ['id'=>22,'title'=>'PRIVATE LINKS','artist_id'=>999,'artist_name'=>'SECRET ARTIST','event_id'=>999,'event_title'=>'SECRET EVENT'],
];
$releases = [
    ['id'=>30,'title'=>'SIGNAL 001','artists'=>[['artist_id'=>1,'name'=>'PL0N3R']]],
    ['id'=>31,'title'=>'SIGNAL 002','artists'=>[['artist_id'=>2,'name'=>'DNL5'],['artist_id'=>999,'name'=>'SECRET']]],
];

$sets = brvtal_public_sanitize_set_relations($rawSets, $artists, $active, $archive);
related_expect($sets[0]['artist_id'] === 1 && $sets[0]['event_id'] === 10, 'Public Set relations must be preserved');
related_expect($sets[2]['artist_id'] === null && $sets[2]['artist_name'] === null, 'Private artist relation must be removed from a public Set');
related_expect($sets[2]['event_id'] === null && $sets[2]['event_title'] === null, 'Private event relation must be removed from a public Set');

$graph = brvtal_public_related_graph($active, $archive, $artists, $sets, $releases);
related_expect($graph['events']['10']['artists'] === [1,2], 'Active event must expose its public artist roster');
related_expect($graph['events']['10']['sets'] === [20], 'Active event must expose directly-related public Sets');
related_expect($graph['events']['11']['artists'] === [2], 'Archive event must ignore non-public lineup artists');
related_expect($graph['artists']['1']['events'] === [10], 'Artist must link back to public Events');
related_expect($graph['artists']['1']['sets'] === [20], 'Artist must link to public Sets');
related_expect($graph['artists']['1']['releases'] === [30], 'Artist must link to public Releases');
related_expect($graph['artists']['2']['events'] === [10,11], 'Artist event history must include active and archived public events');
related_expect($graph['artists']['2']['releases'] === [31], 'Release relation must ignore private artists without dropping public participants');
related_expect($graph['sets']['22']['artist'] === null && $graph['sets']['22']['event'] === null, 'Sanitized Set must not restore private relation IDs');
related_expect($graph['counts']['event_artist'] === 3, 'Event↔Artist count must represent unique public edges');
related_expect($graph['counts']['event_set'] === 2, 'Event↔Set count must represent unique public edges');
related_expect($graph['counts']['artist_set'] === 2, 'Artist↔Set count must represent unique public edges');
related_expect($graph['counts']['artist_release'] === 2, 'Artist↔Release count must represent unique public edges');

$public = file_get_contents(__DIR__ . '/../api/public.php');
$archiveJs = file_get_contents(__DIR__ . '/../js/archive.js');
$relatedJs = file_get_contents(__DIR__ . '/../js/related-content.js');
$relatedCss = file_get_contents(__DIR__ . '/../css/related-content.css');
related_expect(is_string($public) && is_string($archiveJs) && is_string($relatedJs) && is_string($relatedCss), 'Related Content sources must be readable');
related_expect(str_contains($public, "require_once __DIR__ . '/public-related.php';"), 'Public API must use the canonical related-content helper');
related_expect(str_contains($public, "LEFT JOIN artists a ON a.id=s.artist_id AND a.status='published'"), 'Set artist join must be restricted to public artists');
related_expect(str_contains($public, 'NULL AS event_title'), 'Set event title must be resolved only after public lifecycle partitioning');
related_expect(str_contains($public, 'brvtal_public_sanitize_set_relations'), 'Public API must sanitize Set relation IDs before delivery');
related_expect(str_contains($public, "'relations' => \$relations"), 'Public API payload must expose the compact relation graph');
related_expect(str_contains($archiveJs, "new CustomEvent('brvtal:public-data'"), 'Archive loader must publish canonical public data to cross-cutting public modules');
related_expect(str_contains($archiveJs, "import('/js/related-content.js')"), 'Public related-content module must be loaded by the existing public data bootstrap');
related_expect(str_contains($relatedJs, 'RELATED CONTENT / PUBLIC GRAPH'), 'Related Content UI must render as a BRVTAL public graph explorer');
related_expect(str_contains($relatedJs, 'data-related-mode="sets"'), 'Connected must expose Sets as a first-class graph layer');
related_expect(str_contains($relatedJs, 'data-related-mode="releases"'), 'Connected must expose Releases as a first-class graph layer');
related_expect(str_contains($relatedJs, 'function renderSetDetail'), 'Connected must render contextual Set detail');
related_expect(str_contains($relatedJs, 'function renderReleaseDetail'), 'Connected must render contextual Release detail');
related_expect(str_contains($relatedJs, "['artists','events','sets','releases']"), 'All four canonical entity types must be navigable inside the graph');
related_expect(str_contains($relatedJs, "entityUrl('sets', set.slug)"), 'Set graph detail must preserve the canonical public Set route');
related_expect(str_contains($relatedJs, "entityUrl('releases', release.slug)"), 'Release graph detail must preserve the canonical public Release route');
related_expect(str_contains($relatedJs, "group('RELEASES'"), 'Artist discovery must include Releases');
related_expect(str_contains($relatedCss, 'grid-template-columns:repeat(4,minmax(0,1fr))'), 'Connected desktop tabs must accommodate all four graph layers');
related_expect(str_contains($relatedCss, 'grid-template-columns:repeat(2,minmax(0,1fr))'), 'Connected mobile tabs must remain usable with four graph layers');

echo "BRVTAL Related Content contract tests passed.\n";
