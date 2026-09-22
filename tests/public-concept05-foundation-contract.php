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

echo "OK\n";
