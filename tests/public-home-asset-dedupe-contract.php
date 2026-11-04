<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/public_assets.php';

function assertHomeAssetDedup(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

$html = <<<'HTML'
<!doctype html><html><head>
<link rel="stylesheet" href="css/public-concept05-tokens.css" data-origin="first">
<link rel="stylesheet" href="css/other.css">
<link rel="stylesheet" href="css/public-concept05-tokens.css" data-origin="last">
<style data-brvtal-inline="css/hero-slider.css">.hero{color:red}</style>
<style data-brvtal-inline="css/hero-slider.css">.hero{color:blue}</style>
<link rel="stylesheet" href="css/public-memories.css" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="css/public-memories.css"></noscript>
<link rel="preload" href="css/public-concept05-tokens.css" as="style">
<link rel="stylesheet" href="https://first.example.org/css/theme.css">
<link rel="stylesheet" href="https://second.example.org/css/theme.css">
</head><body>
<script src="js/public-concept05-shell.js" defer data-run="first"></script>
<script src="js/other.js" defer></script>
<script src="js/public-concept05-shell.js" defer data-run="second"></script>
<script src="js/public-concept05-shell.js" type="module"></script>
<script src="https://first.example.org/js/remote.js" defer></script>
<script src="https://second.example.org/js/remote.js" defer></script>
<script>window.inlineBehavior = true;</script>
</body></html>
HTML;

$rendered = brvtalPublicHomeDedupeAssets($html);
assertHomeAssetDedup(
    !str_contains($rendered, 'data-origin="first"')
    && str_contains($rendered, 'data-origin="last"')
    && strpos($rendered, 'css/other.css') < strpos($rendered, 'data-origin="last"'),
    'Last CSS declaration must win without changing cascade order.'
);
assertHomeAssetDedup(
    !str_contains($rendered, '.hero{color:red}')
    && str_contains($rendered, '.hero{color:blue}'),
    'Inline CSS duplicates must keep their final cascade declaration.'
);
assertHomeAssetDedup(
    str_contains($rendered, 'data-run="first"')
    && !str_contains($rendered, 'data-run="second"')
    && substr_count($rendered, 'js/public-concept05-shell.js') === 2,
    'Duplicate classic JS must execute once; separate module JS remains untouched.'
);
assertHomeAssetDedup(
    str_contains($rendered, '<noscript><link rel="stylesheet" href="css/public-memories.css"></noscript>')
    && str_contains($rendered, 'media="print" onload=')
    && str_contains($rendered, 'rel="preload" href="css/public-concept05-tokens.css"')
    && str_contains($rendered, 'https://first.example.org/css/theme.css')
    && str_contains($rendered, 'https://second.example.org/css/theme.css'),
    'Noscript, preload, media-specific styles, and third-party assets must survive.'
);
assertHomeAssetDedup(
    str_contains($rendered, 'window.inlineBehavior = true;')
    && str_contains($rendered, 'js/other.js')
    && str_contains($rendered, 'https://first.example.org/js/remote.js')
    && str_contains($rendered, 'https://second.example.org/js/remote.js')
    && brvtalPublicHomeDedupeAssets($rendered) === $rendered,
    'Unrelated scripts and idempotence must be preserved.'
);
assertHomeAssetDedup(
    brvtal_public_optimize_home_images($html) === $rendered,
    'The Home image pipeline must apply final asset deduplication.'
);
assertHomeAssetDedup(
    substr_count($rendered, 'css/public-concept05-tokens.css') === 2
    && substr_count($rendered, 'data-brvtal-inline="css/hero-slider.css"') === 1,
    'Active duplicate CSS requests must drop while the preload stays intact.'
);

echo "Public Home asset deduplication contracts passed.\n";
