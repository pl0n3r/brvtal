<?php
declare(strict_types=1);

/** Fail when the focused Element.before() DOM invariants regress. */
function sonar_dom_before_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "SONAR DOM BEFORE CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$bulkActions = (string) file_get_contents(__DIR__ . '/../discadmin/bulk-actions.js');
$globalSearch = (string) file_get_contents(__DIR__ . '/../discadmin/global-search.js');

foreach (['bulk-actions.js' => $bulkActions, 'global-search.js' => $globalSearch] as $file => $source) {
    sonar_dom_before_expect(
        str_contains($source, 'status.before(trigger);'),
        "{$file} must insert its trigger with Element.before()."
    );
    sonar_dom_before_expect(
        !str_contains($source, 'top.insertBefore(trigger,status)'),
        "{$file} must not restore the legacy insertBefore trigger pattern."
    );
}

echo "Sonar DOM before contract passed.\n";
