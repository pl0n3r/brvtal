<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/public-related.php';

function public_sets_library_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "PUBLIC SETS LIBRARY CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$sets = [
    ['id'=>1,'title'=>'Public linked set','slug'=>'public-set','artist_id'=>10,'event_id'=>20,'artist_name'=>'legacy','event_title'=>'legacy'],
    ['id'=>2,'title'=>'Private relations','slug'=>'private-relations','artist_id'=>99,'event_id'=>98,'artist_name'=>'secret','event_title'=>'secret'],
];
$artists = [
    ['id'=>10,'name'=>'DNL5','slug'=>'dnl5'],
];
$activeEvents = [
    ['id'=>20,'title'=>'GENESIS','slug'=>'genesis'],
];

$sanitized = brvtal_public_sanitize_set_relations($sets, $artists, $activeEvents, []);
public_sets_library_expect(($sanitized[0]['artist_id'] ?? null) === 10, 'public Artist relation must survive');
public_sets_library_expect(($sanitized[0]['artist_name'] ?? null) === 'DNL5', 'public Artist name must be canonical');
public_sets_library_expect(($sanitized[0]['artist_slug'] ?? null) === 'dnl5', 'public Artist slug must be exposed for canonical navigation');
public_sets_library_expect(($sanitized[0]['event_id'] ?? null) === 20, 'public Event relation must survive');
public_sets_library_expect(($sanitized[0]['event_title'] ?? null) === 'GENESIS', 'public Event title must be canonical');
public_sets_library_expect(($sanitized[0]['event_slug'] ?? null) === 'genesis', 'public Event slug must be exposed for canonical navigation');
public_sets_library_expect(($sanitized[1]['artist_id'] ?? 'not-null') === null, 'non-public Artist id must be removed');
public_sets_library_expect(($sanitized[1]['artist_name'] ?? 'not-null') === null, 'non-public Artist name must be removed');
public_sets_library_expect(($sanitized[1]['artist_slug'] ?? 'not-null') === null, 'non-public Artist slug must be removed');
public_sets_library_expect(($sanitized[1]['event_id'] ?? 'not-null') === null, 'non-public Event id must be removed');
public_sets_library_expect(($sanitized[1]['event_title'] ?? 'not-null') === null, 'non-public Event title must be removed');
public_sets_library_expect(($sanitized[1]['event_slug'] ?? 'not-null') === null, 'non-public Event slug must be removed');

$script = (string)file_get_contents(__DIR__ . '/../js/public-sets-library.js');
$runtime = (string)file_get_contents(__DIR__ . '/../js/public-runtime-loader.js');
$entry = (string)file_get_contents(__DIR__ . '/../index.php');
$page = (string)file_get_contents(__DIR__ . '/../config/public_page.php');

public_sets_library_expect(str_contains($script, 'window.BRVTALPublicDataPromise'), 'Sets library must reuse the shared public request');
public_sets_library_expect(!str_contains($script, "fetch('/api/public.php"), 'Sets library must not issue a second public API request');
public_sets_library_expect(!preg_match('/\bgenre\b/i', $script), 'Sets library must not infer or display genre metadata');
public_sets_library_expect(str_contains($script, "data-sets-mode=\"artist\""), 'Artist discovery mode must exist');
public_sets_library_expect(str_contains($script, "data-sets-mode=\"event\""), 'Event discovery mode must exist');
public_sets_library_expect(str_contains($script, "routeUrl('sets', slug)"), 'Set title must navigate to canonical Set route');
public_sets_library_expect(str_contains($runtime, "'js/public-sets-library.js'"), 'Sets library must be part of the versioned core runtime');
public_sets_library_expect(str_contains($entry, 'css/public-sets-library.css'), 'Home must deliver Sets library styles');
public_sets_library_expect(str_contains($entry, 'SET / ARCHIVE'), 'static Set fallback must not present hard-coded genre copy');
public_sets_library_expect(str_contains($page, "\$type === 'sets'"), 'canonical Set page branch must exist');
public_sets_library_expect(str_contains($page, "'LISTEN' => \$detail['external_url']"), 'canonical Set page must keep external LISTEN action');
public_sets_library_expect(str_contains($page, "\$data['related']['ARTIST']"), 'canonical Set page must connect published Artist');
public_sets_library_expect(str_contains($page, "\$data['related']['EVENT']"), 'canonical Set page must connect public Event');

echo "BRVTAL public Sets library contract tests passed.\n";
