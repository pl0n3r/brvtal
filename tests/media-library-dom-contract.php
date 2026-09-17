<?php
declare(strict_types=1);

/** Fail when Media Library DOM modernization loses the behavior covered in-browser. */
function media_library_dom_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "MEDIA LIBRARY DOM CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$source = (string) file_get_contents(__DIR__ . '/../discadmin/media-library.js');
$browser = (string) file_get_contents(__DIR__ . '/e2e/discadmin-media.spec.mjs');

media_library_dom_expect(
    preg_match('/delete\s+img\.dataset\.fallback\s*;/', $source) === 1,
    'Image fallback must be consumed through dataset deletion.'
);
media_library_dom_expect(
    preg_match('/img\.removeAttribute\s*\(\s*[\'\"]data-fallback[\'\"]\s*\)/', $source) !== 1,
    'Legacy removeAttribute data-fallback handling must not return.'
);
media_library_dom_expect(
    preg_match('/input\.after\s*\(\s*button\s*\)/', $source) === 1,
    'Picker trigger must use Element.after().' 
);
media_library_dom_expect(
    preg_match('/input\.insertAdjacentElement\s*\(\s*[\'\"]afterend[\'\"]\s*,\s*button\s*\)/', $source) !== 1,
    'Legacy insertAdjacentElement picker insertion must not return.'
);
media_library_dom_expect(
    str_contains($browser, '#f_cover_image + .media-picker-btn')
        && str_contains($browser, "not.toHaveAttribute('data-fallback')"),
    'Browser coverage must verify trigger adjacency and one-shot fallback consumption.'
);

echo "Media Library DOM contract passed.\n";
