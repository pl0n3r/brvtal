<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/public_time_format.php';

function brvtalPublicClockExpect(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "PUBLIC CLOCK CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$originalZone = date_default_timezone_get();
date_default_timezone_set('America/Bogota');
$zone = new DateTimeZone('America/Bogota');
foreach ([
    '00:00:00' => '12:00 am',
    '09:05:00' => '9:05 am',
    '12:00:00' => '12:00 pm',
    '12:30:00' => '12:30 pm',
    '21:00:00' => '9:00 pm',
    '23:59:00' => '11:59 pm',
] as $input => $expected) {
    $date = new DateTimeImmutable('2026-12-04 ' . $input, $zone);
    brvtalPublicClockExpect(brvtalPublicTimeFormat($date) === $expected, "{$input} => {$expected}");
    brvtalPublicClockExpect(
        brvtalPublicTimeFormat($date->getTimestamp()) === $expected,
        'Unix timestamp uses existing process timezone'
    );
}
$start = new DateTimeImmutable('2026-12-04 21:00:00', $zone);
$end = new DateTimeImmutable('2026-12-04 23:30:00', $zone);
brvtalPublicClockExpect(
    brvtalPublicTimeRange($start, $end) === '9:00 pm–11:30 pm',
    'same-day timetable slot uses 12-hour clock'
);
brvtalPublicClockExpect(
    brvtalPublicTimeRange($start, new DateTimeImmutable('2026-12-05 00:30:00', $zone))
        === '04.12 9:00 pm–05.12 12:30 am',
    'cross-day timetable retains date prefixes'
);
brvtalPublicClockExpect(
    brvtalPublicDateTimeFormat($start) === '04.12.2026 / 9:00 pm',
    'event detail preserves date layout'
);
brvtalPublicClockExpect(
    $start->format('Y-m-d\TH:i:sP') === '2026-12-04T21:00:00-05:00',
    'ISO datetime is unchanged by display formatting'
);

$root = dirname(__DIR__);
$page = (string)file_get_contents($root . '/config/public_page.php');
$preview = (string)file_get_contents($root . '/config/public_preview.php');
$home = (string)file_get_contents($root . '/config/public_home.php');
$cromo = (string)file_get_contents($root . '/js/public-cromo-home.js');
brvtalPublicClockExpect(str_contains($page, 'brvtalPublicDateTimeFormat('), 'public Event facts use canonical clock');
brvtalPublicClockExpect(str_contains($page, 'brvtalPublicTimeRange($localStart, $localEnd)'), 'timetable uses canonical range');
brvtalPublicClockExpect(str_contains($preview, 'brvtalPublicDateTimeFormat('), 'preview uses canonical clock');
brvtalPublicClockExpect(str_contains($home, 'brvtalPublicTimeFormat($date)'), 'home uses canonical clock');
brvtalPublicClockExpect(substr_count($cromo, 'formatPublicTime(when)') === 2, 'cromo nights and experience use 12-hour clock');
brvtalPublicClockExpect(str_contains($page, "format('Y-m-d\\\\TH:i')"), 'timetable machine timestamps stay ISO');
brvtalPublicClockExpect(!str_contains($page, "date('d.m.Y / H:i'"), 'no remaining 24-hour public Event fact');
brvtalPublicClockExpect(!str_contains($preview, "date('d.m.Y / H:i'"), 'no remaining 24-hour preview fact');

date_default_timezone_set($originalZone);
echo "Public event 12-hour clock contract passed.\n";
