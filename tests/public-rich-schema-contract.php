<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/public_seo.php';

function rich_schema_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "PUBLIC RICH SCHEMA CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$base = 'https://www.brvtal.com.co';
$event = [
    'route_type' => 'events',
    'slug' => 'night-one',
    'schema_type' => 'MusicEvent',
    'title' => 'Night One',
    'description' => 'A real night',
    'event_date' => '2026-10-14 22:30:00',
    'venue' => 'Club Example',
    'city' => 'Pereira',
    'status' => 'tickets_available',
    'schema_artists' => [['name' => 'Published Artist', 'slug' => 'published-artist']],
];
$seo = brvtal_public_seo_document($event, $base);
$schema = $seo['schema'];
rich_schema_expect($schema['@type'] === 'MusicEvent', 'dated and located Event must be MusicEvent');
rich_schema_expect($schema['startDate'] === '2026-10-14T22:30:00', 'Event date must remain local, without fabricated timezone');
rich_schema_expect($schema['location']['@type'] === 'Place' && $schema['location']['name'] === 'Club Example / Pereira', 'Event must include real Place');
rich_schema_expect($schema['location']['address']['addressLocality'] === 'Pereira', 'Event city must be exposed');
rich_schema_expect($schema['eventStatus'] === 'https://schema.org/EventScheduled', 'active Event must be scheduled');
rich_schema_expect($schema['performer'][0]['url'] === $base.'/artists/published-artist', 'performers must use canonical routes');
rich_schema_expect($seo['canonical'] === $base.'/events/night-one', 'canonical URL must not change');

$cancelled = brvtal_public_seo_document(array_replace($event, ['status'=>'cancelled']), $base);
rich_schema_expect($cancelled['schema']['eventStatus'] === 'https://schema.org/EventCancelled', 'cancelled Event must not advertise ticket eligibility');
$finished = brvtal_public_seo_document(array_replace($event, ['status'=>'finished']), $base);
rich_schema_expect($finished['schema']['eventStatus'] === 'https://schema.org/EventCompleted', 'finished Event must have completed lifecycle');
$undated = brvtal_public_seo_document(array_replace($event, ['event_date'=>null]), $base);
rich_schema_expect($undated['schema']['@type'] === 'WebPage' && !isset($undated['schema']['startDate']), 'undated Event must degrade rather than invent a date');
$unlocated = brvtal_public_seo_document(array_replace($event, ['venue'=>'', 'city'=>'']), $base);
rich_schema_expect($unlocated['schema']['@type'] === 'WebPage' && !isset($unlocated['schema']['location']), 'unlocated Event must degrade rather than invent a Place');
rich_schema_expect(brvtal_public_schema_date('2026-02-30 25:61:00') === null, 'invalid SQL calendar values must fail closed');
rich_schema_expect(brvtal_public_schema_date(['broken']) === null, 'invalid date field types must fail without PHP diagnostics');
rich_schema_expect(brvtal_public_schema_external_url(['broken']) === null, 'invalid URL field types must fail without PHP diagnostics');
rich_schema_expect(brvtal_public_schema_date('2026-10-14') === '2026-10-14', 'SQL DATE must not acquire a fake time');

$blog = brvtal_public_seo_document([
    'route_type'=>'blog','slug'=>'real-post','schema_type'=>'BlogPosting','title'=>'Real Post',
    'description'=>'True editorial content','published_at'=>'2026-09-20 12:00:00',
    'updated_at'=>'2026-09-22 16:12:02',
], $base);
rich_schema_expect($blog['schema']['headline'] === 'Real Post' && $blog['schema']['mainEntityOfPage'] === $base.'/blog/real-post', 'Blog headline and main entity must stay canonical');
rich_schema_expect($blog['schema']['datePublished'] === '2026-09-20T12:00:00', 'Blog must publish its real timestamp');
rich_schema_expect($blog['schema']['dateModified'] === '2026-09-22T16:12:02', 'Blog must publish its actual modification');
rich_schema_expect($blog['schema']['publisher']['name'] === 'BRVTAL', 'Blog publisher must be the actual site brand');
$blogUndated = brvtal_public_seo_document([
    'route_type'=>'blog','slug'=>'undated','schema_type'=>'BlogPosting','title'=>'Undated',
], $base);
rich_schema_expect($blogUndated['schema']['@type'] === 'WebPage', 'dateless Blog must not assert a fake publication date');

$artists = [['name'=>'Actual Public Artist','slug'=>'public-artist']];
$release = brvtal_public_seo_document([
    'route_type'=>'releases','slug'=>'actual-record','schema_type'=>'MusicAlbum',
    'title'=>'Actual Record','release_date'=>'2026-09-12','catalog_number'=>'BRVTAL-42',
    'spotify_url'=>'https://open.spotify.com/album/real-record','bandcamp_url'=>'javascript:alert(1)',
    'schema_artists'=>$artists,
], $base);
rich_schema_expect($release['schema']['@type'] === 'MusicAlbum', 'real record must retain MusicAlbum');
rich_schema_expect($release['schema']['datePublished'] === '2026-09-12', 'release date must be real');
rich_schema_expect($release['schema']['identifier'] === 'BRVTAL-42', 'catalog number must be preserved');
rich_schema_expect($release['schema']['sameAs'] === ['https://open.spotify.com/album/real-record'], 'only safe real album destinations may be advertised');
rich_schema_expect($release['schema']['byArtist'][0]['url'] === $base.'/artists/public-artist', 'album artist must resolve to canonical public route');

$set = brvtal_public_seo_document([
    'route_type'=>'sets','slug'=>'actual-set','schema_type'=>'MusicRecording','title'=>'Actual Set',
    'external_url'=>'https://soundcloud.com/example/real-set',
    'schema_artists'=>$artists,
], $base);
rich_schema_expect($set['schema']['@type'] === 'MusicRecording', 'real set must retain MusicRecording');
rich_schema_expect($set['schema']['byArtist'][0]['name'] === 'Actual Public Artist', 'recording must expose its published artist');
rich_schema_expect($set['schema']['sameAs'] === 'https://soundcloud.com/example/real-set', 'recording must reference its real safe destination');
foreach (['javascript:alert(1)', 'https://user:pass@example.com/path', '/internal/path'] as $unsafe) {
    rich_schema_expect(brvtal_public_schema_external_url($unsafe) === null, 'unsafe external URL must be omitted');
}
$missingSet = brvtal_public_seo_document([
    'route_type'=>'sets','slug'=>'missing','schema_type'=>'MusicRecording','title'=>'Missing',
    'external_url'=>'javascript:alert(1)',
], $base);
rich_schema_expect($missingSet['schema']['@type'] === 'WebPage' && !isset($missingSet['schema']['sameAs']), 'relationless set must degrade to generic page');

$artist = brvtal_public_seo_document([
    'route_type'=>'artists','slug'=>'published-artist','schema_type'=>'MusicGroup',
    'title'=>'Published Artist','instagram_url'=>'https://instagram.com/artist',
    'soundcloud_url'=>'javascript:alert(1)','website_url'=>'https://instagram.com/artist',
], $base);
rich_schema_expect($artist['schema']['sameAs'] === ['https://instagram.com/artist'], 'artist identity links must be valid and deduplicated');
$page = brvtal_public_seo_document([
    'route_type'=>'pages','slug'=>'contact','schema_type'=>'WebPage','title'=>'Contact','locale'=>'en',
], $base);
rich_schema_expect($page['schema']['inLanguage'] === 'en', 'published English Page must declare its real locale');

$json = brvtal_public_seo_tags(brvtal_public_seo_document(array_replace($event, [
    'title'=>'</script><script>alert(1)</script>',
]), $base));
rich_schema_expect(!str_contains($json, '</script><script>'), 'structured data must not allow script termination from CMS text');
rich_schema_expect(str_contains($json, '\u003C'), 'JSON-LD must HTML-escape tags');

echo "BRVTAL entity-specific public JSON-LD contracts passed.\n";
