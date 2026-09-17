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
$adminCss = (string)file_get_contents(__DIR__ . '/../discadmin/admin-modules.css');

foreach ([
    [$blog, 'blog-search', 'Search blog posts'],
    [$media, 'media-search', 'Search media library'],
    [$media, 'media-file', 'Choose media file to upload'],
    [$releases, 'release-search', 'Search releases'],
] as [$fragment, $controlId, $label]) {
    admin_search_a11y_assert(
        str_contains($fragment, 'for="' . $controlId . '">' . $label . '</label>'),
        "{$controlId} must have an associated label."
    );
    admin_search_a11y_assert(
        str_contains($fragment, 'id="' . $controlId . '"'),
        "Labeled control {$controlId} must remain present."
    );
}

admin_search_a11y_assert(
    str_contains($adminCss, '.admin-sr-only{'),
    'Screen-reader-only labels must remain visually hidden without leaving the accessibility tree.'
);
admin_search_a11y_assert(
    str_contains($media, '<output id="media-status" class="media-status" aria-live="polite"></output>'),
    'Media status must use the native output element.'
);
admin_search_a11y_assert(
    !str_contains($media, 'id="media-status" class="media-status" role="status"'),
    'Media status must not restore the generic status role container.'
);

echo "Admin search accessibility contract passed.\n";
