<?php
declare(strict_types=1);

/** Fail the focused accessibility contract when an invariant is not satisfied. */
function admin_search_a11y_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "ADMIN SEARCH ACCESSIBILITY CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

/**
 * Parse each live DISCADMIN fragment so assertions validate DOM relationships
 * rather than depending on attribute order or serialized source formatting.
 */
function admin_search_a11y_document(string $fragment): DOMDocument
{
    admin_search_a11y_assert(class_exists(DOMDocument::class), 'PHP DOM extension is required for accessibility contract checks.');
    $htmlStart = strpos($fragment, '<section');
    $html = $htmlStart === false ? $fragment : substr($fragment, $htmlStart);
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $loaded = $document->loadHTML('<!doctype html><html><body>' . $html . '</body></html>');
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    admin_search_a11y_assert($loaded, 'DISCADMIN fragment markup must remain parseable.');
    return $document;
}

/** Assert that a visible or screen-reader-only label targets the expected input. */
function admin_search_a11y_label_targets_input(DOMDocument $document, string $controlId, string $labelText): void
{
    $xpath = new DOMXPath($document);
    $label = $xpath->query('//label[@for="' . $controlId . '"]')->item(0);
    admin_search_a11y_assert($label instanceof DOMElement, "{$controlId} must have an associated label.");
    admin_search_a11y_assert(trim((string)$label->textContent) === $labelText, "{$controlId} label text must remain accessible.");
    $control = $document->getElementById($controlId);
    admin_search_a11y_assert($control instanceof DOMElement, "Labeled control {$controlId} must remain present.");
    admin_search_a11y_assert(strtolower($control->tagName) === 'input', "{$controlId} label must target an input element.");
}

$blog = (string)file_get_contents(__DIR__ . '/../discadmin/blog.php');
$media = (string)file_get_contents(__DIR__ . '/../discadmin/media-library.php');
$releases = (string)file_get_contents(__DIR__ . '/../discadmin/releases.php');
$adminCss = (string)file_get_contents(__DIR__ . '/../discadmin/admin-modules.css');

$documents = [
    'blog' => admin_search_a11y_document($blog),
    'media' => admin_search_a11y_document($media),
    'releases' => admin_search_a11y_document($releases),
];

foreach ([
    ['blog', 'blog-search', 'Search blog posts'],
    ['media', 'media-search', 'Search media library'],
    ['media', 'media-file', 'Choose media file to upload'],
    ['releases', 'release-search', 'Search releases'],
] as [$documentKey, $controlId, $label]) {
    admin_search_a11y_label_targets_input($documents[$documentKey], $controlId, $label);
}

admin_search_a11y_assert(
    str_contains($adminCss, '.admin-sr-only{') && str_contains($adminCss, 'clip-path:inset(50%)'),
    'Screen-reader-only labels must remain visually hidden without deprecated clip.'
);
admin_search_a11y_assert(
    preg_match('/\bclip\s*:\s*rect\s*\(/i', $adminCss) !== 1,
    'Screen-reader-only utility must not restore deprecated clip.'
);

$mediaStatus = $documents['media']->getElementById('media-status');
admin_search_a11y_assert($mediaStatus instanceof DOMElement, 'Media status element must remain present.');
admin_search_a11y_assert(strtolower($mediaStatus->tagName) === 'output', 'Media status must use the native output element.');
admin_search_a11y_assert($mediaStatus->getAttribute('aria-live') === 'polite', 'Media status must announce updates politely.');
admin_search_a11y_assert(!$mediaStatus->hasAttribute('role'), 'Media status must not restore a redundant generic role.');

echo "Admin search accessibility contract passed.\n";
