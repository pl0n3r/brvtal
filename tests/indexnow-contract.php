<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/indexnow.php';

$config = [
    'app' => ['base_url' => 'https://www.brvtal.com.co'],
];

function indexnow_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "IndexNow contract failed: {$message}\n");
        exit(1);
    }
}

indexnow_assert(brvtal_indexnow_key_valid('Abcd1234'), '8-character key should be valid');
indexnow_assert(brvtal_indexnow_key_valid('BRVTAL-indexnow-key-2026'), 'letters, numbers and hyphens should be valid');
indexnow_assert(!brvtal_indexnow_key_valid('short'), 'short key should be rejected');
indexnow_assert(!brvtal_indexnow_key_valid('invalid_key'), 'underscore should be rejected');

indexnow_assert(
    brvtal_indexnow_setting_error('{"enabled":true,"key":"Abcd1234"}', 1) === null,
    'enabled valid JSON setting should pass'
);
indexnow_assert(
    (brvtal_indexnow_setting_error('{"enabled":true,"key":""}', 1)['error'] ?? '') === 'INDEXNOW_KEY_REQUIRED',
    'enabled setting without key should fail'
);
indexnow_assert(
    (brvtal_indexnow_setting_error('{"enabled":false,"key":"bad_key"}', 1)['error'] ?? '') === 'INDEXNOW_KEY_INVALID',
    'malformed stored key should fail even while disabled'
);
indexnow_assert(
    (brvtal_indexnow_setting_error('{"enabled":false,"key":""}', 0)['error'] ?? '') === 'INDEXNOW_JSON_REQUIRED',
    'IndexNow must remain a typed JSON setting'
);

$artistBefore = ['slug'=>'old-artist','status'=>'published'];
$artistAfter = ['slug'=>'new-artist','status'=>'published'];
$artistUrls = brvtal_indexnow_change_urls('artists', $artistBefore, $artistAfter);
sort($artistUrls);
indexnow_assert($artistUrls === [
    'https://www.brvtal.com.co/',
    'https://www.brvtal.com.co/artists/new-artist',
    'https://www.brvtal.com.co/artists/old-artist',
], 'published slug change should notify old URL, new URL and Home');

$unpublishUrls = brvtal_indexnow_change_urls(
    'blog',
    ['slug'=>'dispatch','status'=>'published'],
    ['slug'=>'dispatch','status'=>'draft']
);
sort($unpublishUrls);
indexnow_assert($unpublishUrls === [
    'https://www.brvtal.com.co/',
    'https://www.brvtal.com.co/blog/dispatch',
], 'unpublishing should notify the former public URL and Home');

indexnow_assert(
    brvtal_indexnow_change_urls('pages', null, ['slug'=>'private-es','status'=>'published','locale'=>'es']) === [],
    'non-English CMS Pages must not be submitted'
);
indexnow_assert(
    brvtal_indexnow_change_urls('artists', null, ['slug'=>'draft-artist','status'=>'draft']) === [],
    'draft entities must not be submitted'
);
indexnow_assert(
    brvtal_indexnow_change_urls('admin', null, ['slug'=>'discadmin','status'=>'published']) === [],
    'admin routes must never be eligible'
);

$eventUrls = brvtal_indexnow_change_urls('events', null, [
    'slug'=>'future-night',
    'status'=>'tickets_available',
    'event_date'=>'2026-12-31 21:00:00',
    'published_at'=>'2026-09-18 12:00:00',
]);
indexnow_assert(
    in_array('https://www.brvtal.com.co/events/future-night', $eventUrls, true),
    'public Event lifecycle should resolve to canonical Event URL'
);

$sourceContracts = [
    __DIR__ . '/../discadmin/settings-v2.js' => [
        "jsonValue('indexnow')",
        'sv2_indexnow_enabled',
        "text('indexnow_key'",
        "persistJson('indexnow'",
    ],
    __DIR__ . '/../api/index.php' => [
        "brvtal_indexnow_setting_error",
        "brvtal_indexnow_notify_change",
        "brvtal_indexnow_notify_setting",
    ],
    __DIR__ . '/../api/blog.php' => ["brvtal_indexnow_notify_change"],
    __DIR__ . '/../api/releases.php' => ["brvtal_indexnow_notify_change"],
    __DIR__ . '/../api/event-workflow.php' => ["brvtal_indexnow_notify_change"],
    __DIR__ . '/../api/seo-metadata.php' => ["brvtal_indexnow_notify_entity_id"],
    __DIR__ . '/../indexnow-key.php' => ["brvtal_indexnow_setting", "text/plain"],
    __DIR__ . '/../.htaccess' => ["indexnow-key\\.txt"],
];

foreach ($sourceContracts as $path => $needles) {
    $source = (string)file_get_contents($path);
    foreach ($needles as $needle) {
        indexnow_assert(str_contains($source, $needle), basename($path) . " missing contract: {$needle}");
    }
}

echo "IndexNow contract passed.\n";
