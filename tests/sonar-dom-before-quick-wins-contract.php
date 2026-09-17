<?php
declare(strict_types=1);

/** Fail the focused DOM insertion contract when an equivalent legacy pattern regresses. */
function sonar_dom_before_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "SONAR DOM BEFORE CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$files = [
    'bulk actions' => (string) file_get_contents(__DIR__ . '/../discadmin/bulk-actions.js'),
    'global search' => (string) file_get_contents(__DIR__ . '/../discadmin/global-search.js'),
];

foreach ($files as $label => $source) {
    sonar_dom_before_expect(
        str_contains($source, 'status.before(trigger);'),
        "{$label} must insert its trigger before status through Element.before()."
    );
    sonar_dom_before_expect(
        preg_match('/top\s*\.\s*insertBefore\s*\(\s*trigger\s*,\s*status\s*\)/', $source) !== 1,
        "{$label} must not restore the S7768 insertBefore pattern."
    );
}

echo "Sonar DOM before quick wins contract passed.\n";
