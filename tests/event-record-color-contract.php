<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/public_seo.php';
require_once __DIR__ . '/../config/public_page.php';

/** Fail the Event Record color contract with one precise diagnostic. */
function event_record_color_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "EVENT RECORD COLOR CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$seo = [
    'title' => 'Signal Fixture — BRVTAL',
    'description' => 'Event signal color fixture',
    'canonical' => 'https://www.brvtal.com.co/events/signal-fixture',
    'image' => '',
    'schema' => ['@type' => 'MusicEvent'],
];

$basePage = [
    'entity' => [
        'id' => 1,
        'route_type' => 'events',
        'title' => 'SIGNAL FIXTURE',
        'description' => 'Signal validation',
        'status' => 'published',
    ],
    'facts' => [],
    'links' => [],
    'record' => ['state'=>'active','year'=>'2026','status'=>'PUBLISHED'],
    'related' => [],
    'degraded' => false,
];

foreach (['#abc','#abcd','#abcdef','#abcdef12'] as $valid) {
    $page = $basePage;
    $page['entity']['accent'] = $valid;
    $html = brvtal_public_entity_page($page, $seo);
    event_record_color_expect(str_contains($html, '--event-signal:' . $valid), 'valid CSS hex color must be preserved: ' . $valid);
}

foreach (['#12','#12345','#1234567','#123456789','not-a-color'] as $invalid) {
    $page = $basePage;
    $page['entity']['accent'] = $invalid;
    $html = brvtal_public_entity_page($page, $seo);
    event_record_color_expect(str_contains($html, '--event-signal:#b6ff00'), 'invalid Event accent must use signal fallback: ' . $invalid);
    event_record_color_expect(!str_contains($html, '--event-signal:' . $invalid), 'invalid Event accent must never reach inline CSS: ' . $invalid);
}

echo "BRVTAL Event Record color contract tests passed.\n";
