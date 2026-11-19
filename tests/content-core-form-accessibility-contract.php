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
    'eventInsightsWindow',
] as $controlId) {
    content_core_a11y_label_targets_control($document, $controlId);
}

$xpath = new DOMXPath($document);
$wizardSteps = $xpath->query('//button[contains(concat(" ", normalize-space(@class), " "), " step ")]');
content_core_a11y_assert($wizardSteps !== false && $wizardSteps->length === 5, 'Event editor must have exactly five commercial steps.');
foreach ($wizardSteps as $wizardStep) {
    content_core_a11y_assert($wizardStep instanceof DOMElement, 'Wizard step indicator must remain an element.');
    content_core_a11y_assert($wizardStep->getAttribute('type') === 'button', 'Each step is an interactive native button with no form submit side-effect.');
    content_core_a11y_assert($wizardStep->hasAttribute('data-step'), 'Every step must retain keyboard-accessible navigation.');
}

$insightsPanel = $xpath->query('//*[@data-event-insights-panel]')->item(0);
content_core_a11y_assert($insightsPanel instanceof DOMElement, 'Event Insights panel must remain present.');
$contentParent = $insightsPanel->parentNode;
$foundPromotionAncestor = false;
while ($contentParent instanceof DOMElement) {
    $classes = preg_split('/\s+/', trim($contentParent->getAttribute('class'))) ?: [];
    if (in_array('step-content', $classes, true)) {
        content_core_a11y_assert(
            $contentParent->getAttribute('data-content') === '4',
            'Event Insights must be folded inside Promotion, not shown in other steps.'
        );
        $foundPromotionAncestor = true;
    }
    $contentParent = $contentParent->parentNode;
}
content_core_a11y_assert($foundPromotionAncestor, 'Event Insights must have a Promotion step ancestor.');

$insightsStatus = $document->getElementById('eventInsightsStatus');
content_core_a11y_assert($insightsStatus instanceof DOMElement, 'Event Insights status must remain present.');
content_core_a11y_assert(strtolower($insightsStatus->tagName) === 'output', 'Event Insights status must remain a read-only output.');
content_core_a11y_assert($insightsStatus->getAttribute('aria-live') === 'polite', 'Event Insights status must announce state changes politely.');
content_core_a11y_assert(trim($insightsStatus->getAttribute('aria-label')) !== '', 'Event Insights status must keep an accessible name.');

foreach (['eventInsightsUsers','eventInsightsSessions','eventInsightsViews'] as $metricId) {
    $metric = $document->getElementById($metricId);
    content_core_a11y_assert($metric instanceof DOMElement, "{$metricId} must remain present.");
    content_core_a11y_assert(strtolower($metric->tagName) === 'output', "{$metricId} must remain a read-only output.");
    content_core_a11y_assert(trim($metric->getAttribute('aria-label')) !== '', "{$metricId} must keep an accessible name.");
}

echo "Content Core form accessibility contract passed.\n";
