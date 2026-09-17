<?php
declare(strict_types=1);

/** Fail the focused Content Core accessibility contract when an invariant breaks. */
function content_core_a11y_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "CONTENT CORE ACCESSIBILITY CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

/** Parse the Content Core fragment for structural label/control assertions. */
function content_core_a11y_document(string $fragment): DOMDocument
{
    content_core_a11y_assert(class_exists(DOMDocument::class), 'PHP DOM extension is required for accessibility contract checks.');
    $htmlStart = strpos($fragment, '<section');
    $html = $htmlStart === false ? $fragment : substr($fragment, $htmlStart);
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $loaded = $document->loadHTML('<!doctype html><html><body>' . $html . '</body></html>');
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    content_core_a11y_assert($loaded, 'Content Core fragment markup must remain parseable.');
    return $document;
}

/** Assert that one label targets an interactive form control of an allowed type. */
function content_core_a11y_label_targets_control(
    DOMDocument $document,
    string $controlId,
    ?string $labelText = null,
    bool $screenReaderOnly = false
): void {
    $xpath = new DOMXPath($document);
    $label = $xpath->query('//label[@for="' . $controlId . '"]')->item(0);
    content_core_a11y_assert($label instanceof DOMElement, "{$controlId} must have an associated label.");

    if ($labelText !== null) {
        content_core_a11y_assert(
            trim((string)$label->textContent) === $labelText,
            "{$controlId} label text must remain accessible."
        );
    }
    if ($screenReaderOnly) {
        $classes = preg_split('/\s+/', trim($label->getAttribute('class'))) ?: [];
        content_core_a11y_assert(
            in_array('admin-sr-only', $classes, true),
            "{$controlId} search label must remain screen-reader-only."
        );
    }

    $control = $document->getElementById($controlId);
    content_core_a11y_assert($control instanceof DOMElement, "Labeled control {$controlId} must remain present.");
    content_core_a11y_assert(
        in_array(strtolower($control->tagName), ['input', 'textarea', 'select'], true),
        "{$controlId} label must target an input, textarea or select element."
    );
}

$fragment = (string)file_get_contents(__DIR__ . '/../discadmin/content-core.php');
$adminCss = (string)file_get_contents(__DIR__ . '/../discadmin/admin-modules.css');
$document = content_core_a11y_document($fragment);

foreach ([
    'eventSearch' => 'Search events',
    'artistSearch' => 'Search artists',
] as $controlId => $labelText) {
    content_core_a11y_label_targets_control($document, $controlId, $labelText, true);
}

content_core_a11y_assert(
    str_contains($adminCss, '.admin-sr-only{'),
    'Content Core search labels must reuse the shared screen-reader-only utility.'
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
    content_core_a11y_label_targets_control($document, $controlId);
}

$xpath = new DOMXPath($document);
$wizardSteps = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " step ")]');
content_core_a11y_assert($wizardSteps !== false && $wizardSteps->length === 5, 'Event wizard must keep five step indicators.');
foreach ($wizardSteps as $wizardStep) {
    content_core_a11y_assert($wizardStep instanceof DOMElement, 'Wizard step indicator must remain an element.');
    content_core_a11y_assert(!$wizardStep->hasAttribute('role'), 'Wizard step indicators must not claim button semantics without interaction.');
    content_core_a11y_assert(!$wizardStep->hasAttribute('tabindex'), 'Wizard step indicators must not enter the tab order when they are not interactive.');
}

echo "Content Core form accessibility contract passed.\n";
