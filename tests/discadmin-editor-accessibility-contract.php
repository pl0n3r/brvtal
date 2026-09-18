<?php
declare(strict_types=1);

function editor_a11y_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "DISCADMIN EDITOR ACCESSIBILITY CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$blog = (string)file_get_contents(__DIR__ . '/../discadmin/blog.js');
$core = (string)file_get_contents(__DIR__ . '/../discadmin/content-core.js');
$media = (string)file_get_contents(__DIR__ . '/../discadmin/media-library.js');
$releases = (string)file_get_contents(__DIR__ . '/../discadmin/releases.js');

foreach ([
    'blog_title',
    'blog_slug',
    'blog_excerpt',
    'blog_body',
    'blog_cover_image',
    'blog_status_field',
    'blog_sort_order',
    'blog_tags',
    'blog_seo_title',
    'blog_seo_description',
] as $controlId) {
    editor_a11y_assert(
        str_contains($blog, 'label for="' . $controlId . '"'),
        "Blog control {$controlId} must keep an explicit label association."
    );
    editor_a11y_assert(
        str_contains($blog, 'id="' . $controlId . '"'),
        "Blog label target {$controlId} must remain present."
    );
}

foreach ([
    'release_title',
    'release_slug',
    'release_type',
    'release_catalog',
    'release_date',
    'release_status_field',
    'release_artwork',
    'release_description',
    'release_sort_order',
    'release_spotify',
    'release_soundcloud',
    'release_bandcamp',
    'release_youtube',
    'release_beatport',
] as $controlId) {
    editor_a11y_assert(
        str_contains($releases, 'label for="' . $controlId . '"'),
        "Release control {$controlId} must keep an explicit label association."
    );
    editor_a11y_assert(
        str_contains($releases, 'id="' . $controlId . '"'),
        "Release label target {$controlId} must remain present."
    );
}

editor_a11y_assert(
    str_contains($releases, '<div class="release-artist-item">')
        && !str_contains($releases, '<label class="release-artist-item">'),
    'Release artist rows must not use one label element for multiple controls.'
);
editor_a11y_assert(
    str_contains($releases, 'aria-label="Link ${esc(artist.name)} to release"'),
    'Release artist checkbox must keep an accessible name.'
);
editor_a11y_assert(
    str_contains($releases, 'aria-label="Role for ${esc(artist.name)}"'),
    'Release artist role input must keep an accessible name.'
);

foreach ([
    'aria-label="Ticket name"',
    'aria-label="Ticket price"',
    'aria-label="Ticket status"',
    'aria-label="Ticket external URL"',
    'aria-label="Remove ticket type"',
] as $marker) {
    editor_a11y_assert(str_contains($core, $marker), "Content Core must keep {$marker}.");
}
foreach (['a_name','a_status','a_order','a_joined','a_left'] as $controlId) {
    editor_a11y_assert(
        str_contains($core, 'label for="' . $controlId . '"'),
        "Artist lifecycle control {$controlId} must keep an explicit label association."
    );
    editor_a11y_assert(
        str_contains($core, 'id="' . $controlId . '"'),
        "Artist lifecycle label target {$controlId} must remain present."
    );
}
editor_a11y_assert(
    str_contains($core, 'aria-label="Include ${esc(a.name)} in event lineup"'),
    'Event lineup checkbox must keep an artist-specific accessible name.'
);
editor_a11y_assert(
    str_contains($core, 'for="event_artist_${Number(a.id)}"')
        && str_contains($core, 'id="event_artist_${Number(a.id)}"'),
    'Event lineup visible artist name must remain natively associated with its checkbox.'
);
editor_a11y_assert(
    str_contains($releases, 'for="release_artist_${Number(artist.id)}"')
        && str_contains($releases, 'id="release_artist_${Number(artist.id)}"'),
    'Release artist visible name must remain natively associated with its checkbox.'
);

editor_a11y_assert(
    str_contains($media, 'data-search aria-label="Search media library"'),
    'Media picker search field must keep an accessible name.'
);

echo "DISCADMIN editor accessibility contract passed.\n";
