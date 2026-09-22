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
$identity = brvtal_public_home_identity($html);

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
dressing_assert(
    str_contains($rendered, 'id="connected"'),
    'dressing must inject the real 07 / CONNECTED section'
);
dressing_assert(
    str_contains($rendered, 'data-index="07"'),
    'Connected must use the canonical Concept 05 section index 07'
);
dressing_assert(
    str_contains($rendered, 'js/public-concept05-connected.js'),
    'dressing must load the Connected data-fill script'
);

dressing_assert(
    str_contains($identity, 'css/public-concept05-hero.css'),
    'Home identity must load the authored Concept 05 Hero stylesheet'
);
dressing_assert(
    str_contains($identity, 'js/public-concept05-hero.js'),
    'Home identity must load the Concept 05 Hero data projection runtime'
);
dressing_assert(
    str_contains($identity, 'data-c5-hero-documentary'),
    'Concept 05 Hero must expose a documentary media surface'
);
dressing_assert(
    str_contains($identity, 'data-c5-hero-description'),
    'Concept 05 Hero statement must expose managed site-description projection'
);
dressing_assert(
    str_contains($identity, 'class="c5-hero-explore magnetic"')
        && str_contains($identity, 'href="#genesis"'),
    'Concept 05 Hero must keep a canonical Explore CTA into the public journey'
);
dressing_assert(
    !str_contains($rendered, 'data-connected-count>0<')
    && (bool)preg_match('~data-connected-count>&mdash;<~', $rendered),
    'Connected counts must start as static placeholders, never a fabricated number'
);
dressing_assert(
    strpos($rendered, 'id="connected"') < strpos($rendered, '<footer class="footer scene"'),
    'Connected section must sit before the footer'
);

$idempotent = brvtal_public_home_concept05_dressing($rendered);
$identityIdempotent = brvtal_public_home_identity($identity);
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
    substr_count($identityIdempotent, 'css/public-concept05-hero.css') === 1,
    'Concept 05 Hero stylesheet must not duplicate on repeated calls'
);
dressing_assert(
    substr_count($identityIdempotent, 'js/public-concept05-hero.js') === 1,
    'Concept 05 Hero runtime must not duplicate on repeated calls'
);
dressing_assert(
    substr_count($identityIdempotent, '<figure class="c5-hero-documentary" data-c5-hero-documentary') === 1,
    'Concept 05 documentary surface must not duplicate on repeated calls'
);
dressing_assert(
    substr_count($idempotent, 'events scene c5-numbered') === 1,
    'numbered label class must not duplicate on repeated calls'
);
dressing_assert(
    substr_count($idempotent, 'id="connected"') === 1,
    'Connected section must not duplicate on repeated calls'
);

echo "OK\n";
