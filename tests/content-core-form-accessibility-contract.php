<?php
declare(strict_types=1);

function content_core_a11y_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "CONTENT CORE ACCESSIBILITY CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$fragment = (string)file_get_contents(__DIR__ . '/../discadmin/content-core.php');

content_core_a11y_assert(
    str_contains($fragment, 'id="eventSearch" class="search" aria-label="Search events"'),
    'Event search must expose an accessible name.'
);
content_core_a11y_assert(
    str_contains($fragment, 'id="artistSearch" class="search" aria-label="Search artists"'),
    'Artist search must expose an accessible name.'
);

foreach ([
    'e_title',
    'e_slug',
    'e_description',
    'e_cover_image',
    'e_accent',
    'e_featured',
    'e_event_date',
    'e_city',
    'e_venue',
    'e_archive_year',
    'e_status',
    'e_ticket_instructions',
    'e_ticket_qr',
    'e_ticket_url',
] as $controlId) {
    content_core_a11y_assert(
        str_contains($fragment, 'for="' . $controlId . '"'),
        "Visible label must be associated with {$controlId}."
    );
    content_core_a11y_assert(
        str_contains($fragment, 'id="' . $controlId . '"'),
        "Labeled control {$controlId} must remain present."
    );
}

echo "Content Core form accessibility contract passed.\n";
