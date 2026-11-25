<?php
declare(strict_types=1);

/** BRVTAL #2127: the production smoke accepts the Spanish Events heading and nothing else new. */
function smoke_heading_assert(bool $ok, string $what): void
{
    if (!$ok) {
        fwrite(STDERR, "SMOKE EVENTS HEADING FAILED: {$what}\n");
        exit(1);
    }
}

$smoke = (string)file_get_contents(__DIR__ . '/e2e/production-authenticated-smoke.mjs');
smoke_heading_assert(str_contains($smoke, "new Set(['EVENTOS', 'EVENTS'])"), 'accepted headings are exactly EVENTOS and EVENTS');
smoke_heading_assert(str_contains($smoke, 'if (!EVENTS_HEADINGS.has(eventsHeading))'), 'any other heading still fails');
smoke_heading_assert(!str_contains($smoke, "eventsHeading !== 'EVENTS'"), 'the English-only check is gone');
$core = (string)file_get_contents(__DIR__ . '/../discadmin/index-core.php');
smoke_heading_assert(str_contains($core, "events:'EVENTOS'"), 'DISCADMIN titles the Events workspace EVENTOS');
echo "BRVTAL #2127 smoke Events heading contract passed.\n";
