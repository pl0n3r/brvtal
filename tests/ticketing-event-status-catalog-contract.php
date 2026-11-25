<?php
declare(strict_types=1);

/** BRVTAL #2022: Boletería tools list events in every selling state, not only 'published'. */
function status_catalog_assert(bool $ok, string $what): void
{
    if (!$ok) {
        fwrite(STDERR, "EVENT STATUS CATALOG FAILED: {$what}\n");
        exit(1);
    }
}

require_once __DIR__ . '/../config/public_visibility.php';
$active = brvtal_public_event_statuses()['active'];
foreach (['published', 'upcoming', 'tickets_available', 'last_tickets', 'sold_out'] as $state) {
    status_catalog_assert(in_array($state, $active, true), "canonical active state {$state}");
}
foreach (['discadmin/ticketing-finance.php', 'api/ticketing/admin-promos.php',
          'discadmin/ticketing-door.php', 'config/ticketing_pos.php'] as $file) {
    $php = (string)file_get_contents(__DIR__ . '/../' . $file);
    status_catalog_assert(str_contains($php, "brvtal_public_event_statuses()['active']"), "{$file} uses canonical states");
    status_catalog_assert(preg_match("/FROM events[^;]*status\\s*=\\s*'published'/is", $php) === 0,
        "{$file} must not filter events by exact 'published'");
    status_catalog_assert(!str_contains($php, "\\'active\\',\\'published\\'"), "{$file} must not use the non-state 'active'");
}
echo "BRVTAL #2022 event status catalog contract passed.\n";
