<?php
declare(strict_types=1);

$config = [
    'indexnow' => [
        'enabled' => true,
        'key' => 'BRVTAL-IndexNow-2026',
    ],
];

require_once __DIR__ . '/../config/indexnow.php';

function indexnow_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

indexnow_expect(brvtal_indexnow_valid_key('abcDEF12-34'), 'Protocol-valid key should be accepted');
indexnow_expect(!brvtal_indexnow_valid_key('short'), 'Keys shorter than 8 chars must be rejected');
indexnow_expect(!brvtal_indexnow_valid_key('invalid key!'), 'Keys with invalid characters must be rejected');

$publishedEvent = [
    'slug'=>'genesis',
    'status'=>'published',
    'event_date'=>'2026-12-01 21:00:00',
    'published_at'=>'2026-09-18 10:00:00',
];
$draftEvent = $publishedEvent;
$draftEvent['status'] = 'draft';

indexnow_expect(
    brvtal_indexnow_public_url('events', $publishedEvent) === 'https://www.brvtal.com.co/events/genesis',
    'Published Event must resolve to its canonical URL'
);
indexnow_expect(brvtal_indexnow_public_url('events', $draftEvent) === null, 'Draft Event must never be submitted');
indexnow_expect(
    brvtal_indexnow_public_url('pages', ['slug'=>'about','status'=>'published','locale'=>'en'])
        === 'https://www.brvtal.com.co/pages/about',
    'Published English Page must resolve'
);
indexnow_expect(
    brvtal_indexnow_public_url('pages', ['slug'=>'acerca','status'=>'published','locale'=>'es']) === null,
    'Non-canonical locale Page must not be submitted'
);

$renamedEvent = $publishedEvent;
$renamedEvent['slug'] = 'genesis-2026';
indexnow_expect(
    brvtal_indexnow_transition_urls('events', $publishedEvent, $renamedEvent) === [
        'https://www.brvtal.com.co/events/genesis',
        'https://www.brvtal.com.co/events/genesis-2026',
    ],
    'Slug change must submit old and new canonical URLs'
);
indexnow_expect(
    brvtal_indexnow_transition_urls('events', $publishedEvent, $draftEvent) === [
        'https://www.brvtal.com.co/events/genesis',
    ],
    'Unpublish must submit the previously public URL'
);
indexnow_expect(
    brvtal_indexnow_transition_urls('events', $publishedEvent, null) === [
        'https://www.brvtal.com.co/events/genesis',
    ],
    'Delete must submit the previously public URL'
);
indexnow_expect(
    brvtal_indexnow_transition_urls('events', $draftEvent, $draftEvent) === [],
    'Private-to-private edits must not notify IndexNow'
);

$captured = null;
$result = brvtal_indexnow_submit_urls(
    [
        'https://www.brvtal.com.co/events/genesis',
        'https://www.brvtal.com.co/events/genesis',
        'https://example.com/not-brvtal',
    ],
    static function (string $endpoint, array $payload) use (&$captured): array {
        $captured = [$endpoint, $payload];
        return ['status'=>200];
    }
);
indexnow_expect($result['ok'] === true && $result['attempted'] === true, 'Accepted submission should report success');
indexnow_expect(count($result['urls']) === 1, 'Submission must deduplicate and reject foreign-host URLs');
indexnow_expect($captured[0] === BRVTAL_INDEXNOW_ENDPOINT, 'Submission must use the official IndexNow endpoint');
indexnow_expect($captured[1]['host'] === 'www.brvtal.com.co', 'Payload host must stay canonical');
indexnow_expect($captured[1]['keyLocation'] === 'https://www.brvtal.com.co/indexnow-key.txt', 'Payload must expose canonical keyLocation');

$failed = brvtal_indexnow_notify_transition(
    'events',
    null,
    $publishedEvent,
    static function (): array {
        throw new RuntimeException('network down');
    }
);
indexnow_expect($failed['attempted'] === true && $failed['ok'] === false, 'Transport failure must fail open');

$config['indexnow']['key'] = 'bad key';
$called = false;
$disabled = brvtal_indexnow_submit_urls(
    ['https://www.brvtal.com.co/events/genesis'],
    static function () use (&$called): array {
        $called = true;
        return ['status'=>200];
    }
);
indexnow_expect($disabled['attempted'] === false && !$called, 'Invalid/missing key must disable transport');

$htaccess = file_get_contents(__DIR__ . '/../.htaccess');
$keyRoute = file_get_contents(__DIR__ . '/../indexnow-key.php');
$coreApi = file_get_contents(__DIR__ . '/../api/index.php');
$eventApi = file_get_contents(__DIR__ . '/../api/event-workflow.php');
$blogApi = file_get_contents(__DIR__ . '/../api/blog.php');
$releasesApi = file_get_contents(__DIR__ . '/../api/releases.php');
indexnow_expect(is_string($htaccess) && str_contains($htaccess, 'indexnow-key'), 'Root key-file rewrite must exist');
indexnow_expect(is_string($keyRoute) && str_contains($keyRoute, 'brvtal_indexnow_key()'), 'Public key endpoint must use configured key');
foreach ([$coreApi,$eventApi,$blogApi,$releasesApi] as $source) {
    indexnow_expect(is_string($source) && str_contains($source, 'brvtal_indexnow_notify_transition'), 'Editorial mutation surface must notify IndexNow');
}

echo "BRVTAL IndexNow contract tests passed.\n";
