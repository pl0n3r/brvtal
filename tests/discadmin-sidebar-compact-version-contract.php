<?php
declare(strict_types=1);

/** #1925: the sidebar footer shows the product version and V3 roots stay compact. */
function brvtal_sidebar_compact_assert(bool $ok, string $reason): void
{
    if (!$ok) {
        fwrite(STDERR, "DISCADMIN SIDEBAR CONTRACT FAILED: {$reason}\n");
        exit(1);
    }
}

$core = (string) file_get_contents(__DIR__ . '/../discadmin/index-core.php');
$console = (string) file_get_contents(__DIR__ . '/../discadmin/admin-console.css');
brvtal_sidebar_compact_assert($core !== '' && $console !== '', 'missing source');

brvtal_sidebar_compact_assert(
    (bool) preg_match(
        '/data-testid="admin-product-version".*?\?>">\s*BRVTAL v<\?= htmlspecialchars\(BRVTAL_APP_VERSION/s',
        $core
    ),
    'sidebar footer must render "BRVTAL v<version>"'
);

// Any rule that targets .ia-navgroup must not add the group-heading margin.
$css = (string) preg_replace('#/\*.*?\*/#s', '', $console);
preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
foreach ($rules as [, $selector, $body]) {
    if (str_contains($selector, '.ia-navgroup') && preg_match('/(^|;)\s*margin\s*:/', $body)) {
        brvtal_sidebar_compact_assert(false, 'admin-console.css must not give .ia-navgroup a margin: ' . trim($selector));
    }
}

echo "DISCADMIN sidebar compact/version contract passed\n";
