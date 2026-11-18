<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/public_home.php';

function concept05_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$baseHtml = "<!doctype html>\n<html>\n<head>\n</head>\n<body>\n<main></main>\n</body>\n</html>\n";

$result = brvtal_public_home_concept05_foundation($baseHtml);

concept05_assert(
    str_contains($result, 'css/public-concept05-tokens.css'),
    'foundation must link the Concept 05 tokens stylesheet'
);
concept05_assert(
    str_contains($result, 'js/public-concept05-motion.js'),
    'foundation must load the Concept 05 motion script'
);
concept05_assert(
    str_contains($result, 'css/public-concept05-shell.css')
        && str_contains($result, 'js/public-concept05-shell.js'),
    'foundation must load the authored Concept 05 public shell'
);
concept05_assert(
    str_contains($result, 'data-concept="05"'),
    'body must carry the Concept 05 root hook'
);

$idempotent = brvtal_public_home_concept05_foundation($result);
concept05_assert(
    substr_count($idempotent, 'css/public-concept05-tokens.css') === 1,
    'stylesheet link must not duplicate on repeated calls'
);
concept05_assert(
    substr_count($idempotent, 'js/public-concept05-motion.js') === 1,
    'motion script must not duplicate on repeated calls'
);
concept05_assert(
    substr_count($idempotent, 'css/public-concept05-shell.css') === 1
        && substr_count($idempotent, 'js/public-concept05-shell.js') === 1,
    'shell assets must not duplicate on repeated calls'
);
concept05_assert(
    substr_count($idempotent, 'data-concept="05"') === 1,
    'root hook must not duplicate on repeated calls'
);

$noHead = "<!doctype html>\n<html>\n<body>\n<main></main>\n</body>\n</html>\n";
$safe = brvtal_public_home_concept05_foundation($noHead);
concept05_assert(
    !str_contains($safe, 'css/public-concept05-tokens.css'),
    'missing </head> must not crash or inject a broken tag'
);

$existingAttrs = "<!doctype html>\n<html>\n<head></head>\n<body data-scene=\"CORE\">\n<main></main>\n</body>\n</html>\n";
$withHook = brvtal_public_home_concept05_foundation($existingAttrs);
concept05_assert(
    str_contains($withHook, '<body data-scene="CORE" data-concept="05">'),
    'root hook must be appended to <body> without dropping existing attributes'
);

$concept05Styles = glob(__DIR__ . '/../css/public-concept05-*.css') ?: [];
concept05_assert(count($concept05Styles) >= 7, 'Concept 05 font-token contract must inspect the authored stylesheet family');
foreach ($concept05Styles as $stylesheet) {
    $css = file_get_contents($stylesheet);
    if ($css === false) {
        concept05_assert(false, 'Concept 05 stylesheet must be readable: ' . basename($stylesheet));
    }
    $withoutMonoFallback = str_replace(
        'var(--theme-mono-font,"Space Mono",monospace)',
        '',
        $css
    );
    concept05_assert(
        !str_contains($withoutMonoFallback, '"Space Mono",monospace'),
        'Concept 05 mono typography must use the active Theme Studio token: ' . basename($stylesheet)
    );
    concept05_assert(
        !str_contains($css, '"Barlow Condensed",sans-serif'),
        'Concept 05 body typography must use the active Theme Studio token: ' . basename($stylesheet)
    );
}

echo "OK\n";
