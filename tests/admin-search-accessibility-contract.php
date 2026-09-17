<?php
declare(strict_types=1);

function admin_search_a11y_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "ADMIN SEARCH ACCESSIBILITY CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$blog = (string)file_get_contents(__DIR__ . '/../discadmin/blog.php');
$media = (string)file_get_contents(__DIR__ . '/../discadmin/media-library.php');
$releases = (string)file_get_contents(__DIR__ . '/../discadmin/releases.php');

admin_search_a11y_assert(
    str_contains($blog, 'id="blog-search" aria-label="Search blog posts"'),
    'Blog search must expose an accessible name.'
);
admin_search_a11y_assert(
    str_contains($media, 'id="media-search" class="search" type="search" aria-label="Search media library"'),
    'Media search must expose an accessible name.'
);
admin_search_a11y_assert(
    str_contains($media, 'id="media-file" type="file" aria-label="Choose media file to upload" hidden'),
    'Hidden Media file input must retain an explicit accessible name for static analysis.'
);
admin_search_a11y_assert(
    str_contains($media, '<output id="media-status" class="media-status" aria-live="polite"></output>'),
    'Media status must use the native output element.'
);
admin_search_a11y_assert(
    !str_contains($media, 'id="media-status" class="media-status" role="status"'),
    'Media status must not restore the generic status role container.'
);
admin_search_a11y_assert(
    str_contains($releases, 'id="release-search" aria-label="Search releases"'),
    'Releases search must expose an accessible name.'
);

echo "Admin search accessibility contract passed.\n";
