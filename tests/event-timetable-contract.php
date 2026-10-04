<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/event-workflow-lib.php';
require_once __DIR__ . '/../config/migrations.php';

function timetable_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "EVENT TIMETABLE CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

function timetable_error(callable $callback): string
{
    try {
        $callback();
    } catch (InvalidArgumentException $exception) {
        return $exception->getMessage();
    }
    return '';
}

$items = brvtal_event_timetable([
    [
        'artist_id'=>12,
        'starts_at'=>'2026-08-15T01:00',
        'ends_at'=>'2026-08-15T02:20',
        'timezone'=>'America/Bogota',
        'status'=>'approved',
        'sort_order'=>9,
    ],
    [
        'label'=>'OPENING',
        'starts_at'=>'2026-08-14T21:00',
        'ends_at'=>'2026-08-14T22:20',
        'timezone'=>'America/Bogota',
        'status'=>'draft',
        'sort_order'=>4,
    ],
    [
        'artist_id'=>7,
        'starts_at'=>'2026-08-14T22:20',
        'ends_at'=>'2026-08-14T23:40',
        'timezone'=>'America/Bogota',
        'status'=>'approved',
        'sort_order'=>2,
    ],
]);

timetable_assert(count($items) === 3, 'three canonical timetable rows expected');
timetable_assert($items[0]['label'] === 'OPENING', 'rows must sort by actual time');
timetable_assert($items[0]['starts_at_utc'] === '2026-08-15 02:00:00', 'Bogota start must normalize to UTC');
timetable_assert($items[1]['artist_id'] === 7, 'linked Artist identity must survive normalization');
timetable_assert($items[2]['ends_at_utc'] === '2026-08-15 07:20:00', 'cross-midnight row must normalize correctly');
timetable_assert(array_column($items, 'sort_order') === [0,1,2], 'canonical order must be dense and deterministic');

$overlap = timetable_error(static fn(): array => brvtal_event_timetable([
    ['label'=>'A','starts_at'=>'2026-08-14 21:00','ends_at'=>'2026-08-14 22:30','timezone'=>'America/Bogota'],
    ['label'=>'B','starts_at'=>'2026-08-14 22:00','ends_at'=>'2026-08-14 23:00','timezone'=>'America/Bogota'],
]));
timetable_assert($overlap === 'TIMETABLE_OVERLAP', 'overlap must fail closed');

$invalidWindow = timetable_error(static fn(): array => brvtal_event_timetable([
    ['label'=>'A','starts_at'=>'2026-08-14 22:00','ends_at'=>'2026-08-14 22:00','timezone'=>'America/Bogota'],
]));
timetable_assert($invalidWindow === 'INVALID_TIMETABLE_WINDOW', 'end <= start must fail closed');

$invalidTimezone = timetable_error(static fn(): array => brvtal_event_timetable([
    ['label'=>'A','starts_at'=>'2026-08-14 21:00','ends_at'=>'2026-08-14 22:00','timezone'=>'Bogota/Invented'],
]));
timetable_assert($invalidTimezone === 'INVALID_TIMETABLE_TIMEZONE', 'unknown timezone must fail closed');

$freeFormIdentity = timetable_error(static fn(): array => brvtal_event_timetable([
    [
        'artist_id'=>7,
        'artist_name'=>'SHOULD NOT BECOME AUTHORITY',
        'starts_at'=>'2026-08-14 21:00',
        'ends_at'=>'2026-08-14 22:00',
        'timezone'=>'America/Bogota',
    ],
]));
timetable_assert($freeFormIdentity === 'INVALID_TIMETABLE_FIELD', 'artist_name must never be an authoritative timetable field');

$composite = timetable_error(static fn(): array => brvtal_event_timetable([
    [
        'label'=>['nested'=>'not allowed'],
        'starts_at'=>'2026-08-14 21:00',
        'ends_at'=>'2026-08-14 22:00',
        'timezone'=>'America/Bogota',
    ],
]));
timetable_assert($composite === 'INVALID_TIMETABLE_FIELD_TYPE', 'composite timetable fields must fail closed');

$linked = brvtal_event_timetable([
    ['artist_id'=>7,'label'=>'CLOSING SLOT','starts_at'=>'2026-08-14 23:00','ends_at'=>'2026-08-15 00:20','timezone'=>'America/Bogota'],
]);
timetable_assert($linked[0]['artist_id'] === 7, 'artist_id is the linked identity');
timetable_assert(!array_key_exists('artist_name', $linked[0]), 'normalizer must not emit duplicated artist_name authority');

$migration = (string)file_get_contents(__DIR__ . '/../database/migration_event_timetable_01.sql');
$schema = (string)file_get_contents(__DIR__ . '/../database/schema.sql');
brvtalMigrationAssertAdditiveSql($migration);
foreach ([$migration, $schema] as $sql) {
    timetable_assert(str_contains($sql, 'event_timetable_items'), 'schema must define timetable table');
    timetable_assert(str_contains($sql, 'artist_id INT UNSIGNED NULL'), 'schema must use relational artist_id');
    timetable_assert(!str_contains($sql, 'artist_name'), 'schema must not duplicate Artist names');
    timetable_assert(str_contains($sql, "ENUM('draft','approved')"), 'schema must preserve explicit approval state');
}

echo "BRVTAL Event timetable contract passed.\n";
