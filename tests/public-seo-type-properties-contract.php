<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/public_seo.php';

function seo_type_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$eventSchema = brvtal_public_seo_type_properties([
    'schema_type' => 'MusicEvent',
    'event_date' => '2026-08-14 22:00:00',
    'venue' => 'Discoteca La Perla',
    'city' => 'Pereira',
    'status' => 'sold_out',
]);
seo_type_assert(isset($eventSchema['startDate']) && str_starts_with($eventSchema['startDate'], '2026-08-14'), 'MusicEvent must expose a real startDate');
seo_type_assert(($eventSchema['location']['name'] ?? '') === 'Discoteca La Perla', 'MusicEvent location must carry the real venue');
seo_type_assert(($eventSchema['location']['address']['addressLocality'] ?? '') === 'Pereira', 'MusicEvent location must carry the real city');
seo_type_assert($eventSchema['eventStatus'] === 'https://schema.org/SoldOut', 'MusicEvent status must map sold_out to SoldOut');

$emptyEvent = brvtal_public_seo_type_properties(['schema_type' => 'MusicEvent']);
seo_type_assert(!isset($emptyEvent['startDate']), 'missing event_date must never produce a fabricated startDate');
seo_type_assert(!isset($emptyEvent['location']), 'missing venue/city must never produce a fabricated location');

$blogSchema = brvtal_public_seo_type_properties([
    'schema_type' => 'BlogPosting',
    'published_at' => '2026-08-12 09:00:00',
    'updated_at' => '2026-08-18 10:30:00',
]);
seo_type_assert(isset($blogSchema['datePublished']), 'BlogPosting must expose datePublished when the entity has one');
seo_type_assert(isset($blogSchema['dateModified']), 'BlogPosting must expose dateModified when the entity has one');

$genericSchema = brvtal_public_seo_type_properties(['schema_type' => 'MusicGroup']);
seo_type_assert($genericSchema === [], 'types without real enrichable fields must not gain fabricated properties');

echo "OK\n";
