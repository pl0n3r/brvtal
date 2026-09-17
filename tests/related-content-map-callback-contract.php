<?php
declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../js/related-content.js');
if ($source === false) {
    fwrite(STDERR, "Could not read js/related-content.js\n");
    exit(1);
}

$failures = [];

if (str_contains($source, 'list.map(renderItem)')) {
    $failures[] = 'Related Content group rendering must not pass renderItem directly to Array.map().';
}

if (!str_contains($source, 'list.map(item => renderItem(item))')) {
    $failures[] = 'Related Content group rendering must wrap renderItem in a single-argument callback.';
}

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "Related Content map callback contract passed.\n";
