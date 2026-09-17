<?php
declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../discadmin/media-library.js');
if ($source === false) {
    fwrite(STDERR, "Could not read discadmin/media-library.js\n");
    exit(1);
}

$failures = [];

if (str_contains($source, '.sort().reverse()')) {
    $failures[] = 'Media Library month ordering must not rely on default Array.sort().reverse().';
}

if (!str_contains($source, '.sort((a, b) => b.localeCompare(a))')) {
    $failures[] = 'Media Library month ordering must use an explicit locale-aware comparator.';
}

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "Media Library sort contract passed.\n";
