<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/content-validation.php';

function event_invariant_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "EVENT PUBLICATION CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

event_invariant_assert(
    brvtal_event_publication_error(['title'=>'Draft shell','status'=>'draft']) === null,
    'draft Events may remain incomplete'
);

event_invariant_assert(
    brvtal_event_publication_error(['title'=>'Public shell','status'=>'published']) === ['error'=>'EVENT_DATE_REQUIRED','field'=>'event_date'],
    'non-draft Events must require event_date'
);

event_invariant_assert(
    brvtal_event_publication_error(['title'=>'Public shell','status'=>'published','event_date'=>'2026-10-31 21:00:00']) === ['error'=>'EVENT_CITY_REQUIRED','field'=>'city'],
    'non-draft Events must require city'
);

event_invariant_assert(
    brvtal_event_publication_error([
        'title'=>'Complete Event',
        'status'=>'tickets_available',
        'event_date'=>'2026-10-31 21:00:00',
        'city'=>'Pereira',
    ]) === null,
    'complete non-draft Events must pass'
);

$indexSource = (string)file_get_contents(__DIR__ . '/../api/index.php');
$bulkSource = (string)file_get_contents(__DIR__ . '/../api/bulk-actions-lib.php');

event_invariant_assert(
    substr_count($indexSource, 'brvtal_event_publication_error(') >= 2,
    'generic Event POST and PUT must enforce the shared invariant'
);
event_invariant_assert(
    str_contains($indexSource, "array_replace(['status'=>'draft'],\$p)")
        && str_contains($indexSource, 'array_replace($before,$p)'),
    'generic Event validation must use final state for create and update'
);
event_invariant_assert(
    str_contains($bulkSource, "require_once __DIR__ . '/content-validation.php';")
        && str_contains($bulkSource, "'events' ? ',title,event_date,city,published_at,cancelled_at,finished_at'")
        && str_contains($bulkSource, "brvtal_event_publication_error(array_replace(\$row, ['status'=>\$status]))"),
    'Bulk Actions must load and enforce the same Event invariant before mutation'
);

echo "BRVTAL Event publication invariant contract passed.\n";
