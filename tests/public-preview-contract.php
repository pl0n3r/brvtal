<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$htaccess = file_get_contents($root . '/.htaccess');
$api = file_get_contents($root . '/api/public-preview.php');
$config = file_get_contents($root . '/config/public_preview.php');
$delivery = file_get_contents($root . '/config/public_entity_delivery.php');
$preview = file_get_contents($root . '/preview.php');
$publicIndex = file_get_contents($root . '/index.php');
$admin = file_get_contents($root . '/discadmin/public-preview.js');
$adminCore = file_get_contents($root . '/discadmin/index-core.php');
$contentCore = file_get_contents($root . '/discadmin/content-core.php')
    . file_get_contents($root . '/discadmin/content-core.js');
$blog = file_get_contents($root . '/discadmin/blog.js');
$releases = file_get_contents($root . '/discadmin/releases.js');

$fail = static function (string $message): never {
    fwrite(STDERR, "Public preview contract failed: {$message}\n");
    exit(1);
};
$mustContain = static function (string $haystack, string $needle, string $message) use ($fail): void {
    if (!str_contains($haystack, $needle)) $fail($message . " (missing {$needle})");
};
$mustNotContain = static function (string $haystack, string $needle, string $message) use ($fail): void {
    if (str_contains($haystack, $needle)) $fail($message . " (found {$needle})");
};

$mustContain($htaccess, 'RewriteRule ^preview/([a-f0-9]{48})/?$', 'preview route must be tokenized');
$mustContain($api, 'brvtal_admin_require();', 'preview creation must require an authenticated admin');
$mustContain($api, 'brvtal_admin_require_csrf();', 'preview creation must require CSRF');
$mustContain($api, "header('Cache-Control: no-store');", 'preview creation response must never be cacheable');
$mustContain($config, 'BRVTAL_PUBLIC_PREVIEW_TTL = 600', 'preview tokens must be short-lived');
$mustContain($config, "\$_SESSION['public_previews']", 'draft snapshots must remain session-bound');
$mustContain($config, 'bin2hex(random_bytes(24))', 'preview URLs must use cryptographically random tokens');
$mustNotContain($config, 'INSERT INTO', 'preview snapshots must not persist draft content to public tables');
$mustContain($config, 'brvtalMediaImageReferenceState', 'preview must preserve media-reference safety before rendering raw drafts');
$mustContain($config, 'INVALID_PREVIEW_URL', 'preview must reject unsafe raw editor URLs');
$mustContain($config, 'INVALID_PREVIEW_DATE', 'preview must reject malformed event dates instead of inventing output');

$mustContain($delivery, 'function brvtalPublicEntityDocument', 'canonical entity delivery must be a shared function');
$mustContain($publicIndex, 'brvtalPublicEntityDocument(', 'production entity routes must use shared delivery');
$mustContain($preview, 'brvtalPublicEntityDocument(', 'preview must use the production entity delivery function');
$mustContain($preview, "header('X-Robots-Tag: noindex, nofollow');", 'preview must be non-indexable at HTTP level');
$mustContain($preview, '<meta name="robots" content="noindex,nofollow">', 'preview shell must be non-indexable in markup');
$mustContain($preview, 'Cache-Control: no-store', 'preview documents must never be cached');
$mustContain($preview, 'data-width="1440"', 'preview shell must expose the canonical desktop width');
$mustContain($preview, 'data-width="390"', 'preview shell must expose the canonical mobile width');
$mustNotContain($preview, 'srcdoc=', 'public preview must not introduce an approximate second renderer');

$mustContain($adminCore, 'id="previewBtn"', 'shared legacy editors must expose public preview');
$mustContain($adminCore, 'BRVTALPublicPreview?.bindLegacy', 'legacy editor preview must consume unsaved form values');
$mustContain($contentCore, 'PUBLIC PREVIEW', 'Content Core Events must expose public preview');
$mustContain($contentCore, 'ticket_types:', 'event preview must include unsaved ticket types');
$mustContain($contentCore, 'lineup:eventPreviewLineup()', 'event preview must include current lineup selection');
$mustContain($blog, "'blog'", 'Blog editor must bind full public preview');
$mustContain($releases, "'releases'", 'Releases editor must bind full public preview');
$mustContain($admin, "credentials:'same-origin'", 'preview requests must carry the authenticated session');
$mustContain($admin, "'X-CSRF-Token':await csrfToken()", 'preview requests must carry CSRF');

echo "Public preview contract passed.\n";
