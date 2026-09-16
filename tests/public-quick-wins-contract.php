<?php
declare(strict_types=1);

function public_quick_win_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "PUBLIC QUICK WINS CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

require_once __DIR__ . '/../config/public_assets.php';

$versioned = brvtal_public_version_assets(
    '<link rel="stylesheet" href="/css/public-entity.css"><script src="js/public-quick-wins.js"></script>',
    'abc1234'
);
public_quick_win_assert(
    str_contains($versioned, '/css/public-entity.css?v=abc1234'),
    'canonical entity stylesheet must support deploy versioning'
);
public_quick_win_assert(
    str_contains($versioned, 'js/public-quick-wins.js?v=abc1234'),
    'public quick-win runtime must be deploy-versioned'
);

$index = (string)file_get_contents(__DIR__ . '/../index.php');
public_quick_win_assert(
    str_contains($index, '$entityHtml = brvtal_public_version_assets($entityHtml, brvtal_deployment_short_sha());'),
    'canonical entity HTML must pass through the deploy asset versioner'
);
public_quick_win_assert(
    str_contains($index, "class=\"artist\" aria-disabled=\"true\""),
    'static Artist fallback placeholders must lose fake href actions'
);
public_quick_win_assert(
    str_contains($index, "class=\"set-action magnetic\" aria-disabled=\"true\" aria-hidden=\"true\" tabindex=\"-1\""),
    'generic SoundCloud fallback actions must be non-interactive'
);
public_quick_win_assert(
    str_contains($index, 'js/public-quick-wins.js'),
    'Home must load the public interaction hardening runtime'
);

$runtime = (string)file_get_contents(__DIR__ . '/../js/public-quick-wins.js');
public_quick_win_assert(
    str_contains($runtime, "slide.setAttribute('inert', '')")
        && str_contains($runtime, "slide.removeAttribute('inert')"),
    'Hero slides must synchronize keyboard interactivity with aria-hidden'
);
public_quick_win_assert(
    str_contains($runtime, "link.setAttribute('aria-label', `Listen to ${title} on ${platform}`)"),
    'dynamic Set external actions must receive a descriptive accessible name'
);
public_quick_win_assert(
    str_contains($runtime, ".artist[href=\"#\"]")
        && str_contains($runtime, 'link.removeAttribute(\'href\')'),
    'runtime fallback hardening must also neutralize placeholder Artist links'
);

echo "BRVTAL public quick-win contract tests passed.\n";
