<?php
declare(strict_types=1);

/** Fail the focused Sonar DOM API contract when a quick-win invariant regresses. */
function sonar_dom_api_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "SONAR DOM API CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$recordLists = (string) file_get_contents(__DIR__ . '/../discadmin/admin-record-lists.js');
$setsLibrary = (string) file_get_contents(__DIR__ . '/../js/public-sets-library.js');

sonar_dom_api_expect(
    str_contains($recordLists, 'delete cell.dataset.label;'),
    'Record lists must remove data-label through dataset.'
);
sonar_dom_api_expect(
    !str_contains($recordLists, "cell.removeAttribute('data-label')"),
    'Record lists must not restore the S7761 removeAttribute pattern.'
);
sonar_dom_api_expect(
    str_contains($setsLibrary, 'intro.after(controls);'),
    'Sets controls must use Element.after().'
);
sonar_dom_api_expect(
    !str_contains($setsLibrary, "intro.insertAdjacentElement('afterend', controls)"),
    'Sets library must not restore the S7768 insertAdjacentElement pattern.'
);

echo "Sonar DOM API quick wins contract passed.\n";
