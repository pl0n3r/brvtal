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

$bulkActions = (string) file_get_contents(__DIR__ . '/../discadmin/bulk-actions.js');
$globalSearch = (string) file_get_contents(__DIR__ . '/../discadmin/global-search.js');

sonar_dom_before_expect(
    str_contains($bulkActions, 'status.before(trigger);'),
    'bulk actions must insert its trigger before status through Element.before().'
);
sonar_dom_before_expect(
    preg_match('/top\s*\.\s*insertBefore\s*\(\s*trigger\s*,\s*status\s*\)/', $bulkActions) !== 1,
    'bulk actions must not restore the S7768 insertBefore pattern.'
);

sonar_dom_before_expect(
    str_contains($globalSearch, "top.appendChild(searchTrigger('top', 'SEARCH'));"),
    'global search must mount its top trigger without depending on the removed ONLINE status node.'
);
sonar_dom_before_expect(
    str_contains($globalSearch, 'logout.before(trigger);'),
    'global search must keep its sidebar trigger immediately before Logout.'
);
sonar_dom_before_expect(
    preg_match('/top\s*\.\s*insertBefore\s*\(\s*trigger\s*,\s*status\s*\)/', $globalSearch) !== 1,
    'global search must not restore the removed status-node insertion pattern.'
);

echo "Sonar DOM before quick wins contract passed.\n";
