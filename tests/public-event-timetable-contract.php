<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/public_page.php';

function public_event_timetable_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "PUBLIC EVENT TIMETABLE CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$api = (string)file_get_contents(__DIR__ . '/../api/public.php');
$page = (string)file_get_contents(__DIR__ . '/../config/public_page.php');
$version = (string)file_get_contents(__DIR__ . '/../config/version.php');
$package = json_decode((string)file_get_contents(__DIR__ . '/../package.json'), true);

foreach ([
    "t.status='approved'",
    "a.status='published'",
    'brvtalPublicEventTimetableRows',
    'brvtal_public_event_is_visible($event)',
    "\$event['timetable']",
] as $needle) {
    public_event_timetable_expect(str_contains($api, $needle), "public API missing {$needle}");
}
foreach ([
    'function brvtalPublicEventTimetableRows',
    'function brvtalPublicEventTimetableForEvent',
    'brvtal_public_event_is_visible($detail)',
    "\$data['related']['TIMETABLE']",
    "'TIMETABLE' => 'TIMETABLE / RECORD'",
] as $needle) {
    public_event_timetable_expect(str_contains($page, $needle), "Event Record missing {$needle}");
}

$rows = [
    [
        'id'=>9,'event_id'=>2,'artist_id'=>null,'label'=>'LATE EXTERNAL',
        'starts_at_utc'=>'2026-08-15 04:00:00','ends_at_utc'=>'2026-08-15 05:00:00',
        'timezone'=>'America/Bogota','status'=>'approved','sort_order'=>4,
    ],
    [
        'id'=>1,'event_id'=>2,'artist_id'=>7,'label'=>'SHOULD DISAPPEAR',
        'starts_at_utc'=>'2026-08-15 02:00:00','ends_at_utc'=>'2026-08-15 03:00:00',
        'timezone'=>'America/Bogota','status'=>'approved','sort_order'=>1,
        'artist_name'=>'PUBLIC ARTIST','artist_slug'=>'public-artist','artist_image'=>'/artist.webp','artist_status'=>'published',
    ],
    [
        'id'=>2,'event_id'=>2,'artist_id'=>8,'label'=>'PRIVATE FALLBACK',
        'starts_at_utc'=>'2026-08-15 03:00:00','ends_at_utc'=>'2026-08-15 04:00:00',
        'timezone'=>'America/Bogota','status'=>'approved','sort_order'=>2,
        'artist_name'=>null,'artist_slug'=>null,'artist_image'=>null,'artist_status'=>null,
    ],
    [
        'id'=>3,'event_id'=>2,'artist_id'=>null,'label'=>'DRAFT SLOT',
        'starts_at_utc'=>'2026-08-15 01:00:00','ends_at_utc'=>'2026-08-15 02:00:00',
        'timezone'=>'America/Bogota','status'=>'draft','sort_order'=>0,
    ],
    [
        'id'=>4,'event_id'=>2,'artist_id'=>null,'label'=>'BAD ZONE',
        'starts_at_utc'=>'2026-08-15 05:00:00','ends_at_utc'=>'2026-08-15 06:00:00',
        'timezone'=>'Not/AZone','status'=>'approved','sort_order'=>5,
    ],
];

$publicRows = brvtalPublicEventTimetableRows($rows);
public_event_timetable_expect(count($publicRows) === 2, 'only valid approved rows may cross the public boundary');
public_event_timetable_expect(array_column($publicRows, 'title') === ['PUBLIC ARTIST','LATE EXTERNAL'], 'rows must be chronological');
public_event_timetable_expect(($publicRows[0]['route_type'] ?? '') === 'artists', 'published linked Artist must retain public route');
public_event_timetable_expect(!isset($publicRows[1]['route_type']), 'external label must not invent an Artist route');
foreach ($publicRows as $row) {
    public_event_timetable_expect(!array_key_exists('id', $row), 'timetable item id must stay private');
    public_event_timetable_expect(!array_key_exists('event_id', $row), 'event id must stay outside timetable row');
    public_event_timetable_expect(!array_key_exists('artist_id', $row), 'artist id must stay private');
}
$json = json_encode($publicRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
public_event_timetable_expect(
    is_string($json)
        && !str_contains($json, 'PRIVATE FALLBACK')
        && !str_contains($json, 'DRAFT SLOT')
        && !str_contains($json, 'BAD ZONE')
        && !str_contains($json, 'SHOULD DISAPPEAR'),
    'private, draft, invalid and linked fallback labels must fail closed'
);

public_event_timetable_expect(($package['version'] ?? null) === '0.1.113', 'package version must be 0.1.113');
public_event_timetable_expect(
    ($package['scripts']['test:public-event-timetable'] ?? null) === 'python3 -m unittest tests/test_public_event_timetable.py',
    'public timetable acceptance script must be registered'
);
public_event_timetable_expect(str_contains($version, "BRVTAL_APP_VERSION = '0.1.113'"), 'runtime version must be 0.1.113');

echo "BRVTAL public Event timetable contract passed.\n";
