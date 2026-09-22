<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/public_home.php';

function dressing_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$html = (string)file_get_contents(__DIR__ . '/../index.html');
$rendered = brvtal_public_home_concept05_dressing($html);

dressing_assert(
    str_contains($rendered, 'css/public-concept05-home.css'),
    'dressing must link the Concept 05 home stylesheet'
);
dressing_assert(
    str_contains($rendered, 'c5-header-nav'),
    'dressing must inject the desktop header nav'
);
dressing_assert(
    str_contains($rendered, 'c5-bottom-nav'),
    'dressing must inject the persistent mobile bottom nav'
);
dressing_assert(
    (bool)preg_match('~<section class="genesis[^"]*\bc5-numbered\b[^"]*"~', $rendered),
    'Next Experience section must carry the numbered label class'
);
dressing_assert(
    (bool)preg_match('~<section class="events[^"]*\bc5-numbered\b[^"]*"~', $rendered),
    'Events section must carry the numbered label class'
);

$idempotent = brvtal_public_home_concept05_dressing($rendered);
dressing_assert(
    substr_count($idempotent, 'css/public-concept05-home.css') === 1,
    'stylesheet link must not duplicate on repeated calls'
);
dressing_assert(
    substr_count($idempotent, 'c5-header-nav') === 1,
    'header nav must not duplicate on repeated calls'
);
dressing_assert(
    substr_count($idempotent, 'c5-bottom-nav') === 1,
    'bottom nav must not duplicate on repeated calls'
);
dressing_assert(
    substr_count($idempotent, 'events scene c5-numbered') === 1,
    'numbered label class must not duplicate on repeated calls'
);

echo "OK\n";
