<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/media.php';
require_once __DIR__ . '/../api/public-media-delivery.php';

function media_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "MEDIA CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$root = brvtal_media_upload_root();
media_assert(is_dir($root), 'uploads root must exist');

$dir = $root . '/contract-test';
if (!is_dir($dir)) {
    mkdir($dir, 0750, true);
}
$file = $dir . '/asset.txt';
file_put_contents($file, 'contract');

$absolute = brvtal_media_local_absolute('/uploads/contract-test/asset.txt');
media_assert($absolute !== null && is_file($absolute), 'local upload path should resolve inside uploads');
media_assert(brvtal_media_local_absolute('/uploads/../config/config.php') === null, 'path traversal must be rejected');
media_assert(brvtal_media_local_absolute('https://example.com/a.jpg') === null, 'external URLs must never resolve as local files');
media_assert(brvtal_media_public_upload_path($file) === '/uploads/contract-test/asset.txt', 'absolute upload path must map back to public upload path');

@unlink($file);
@rmdir($dir);

$delivery = brvtal_public_media_delivery_sanitize([
    'original' => ['path'=>'/uploads/media/example.png','width'=>1920,'height'=>1080,'mime_type'=>'image/png'],
    'variants' => [
        'square' => ['path'=>'/uploads/media/example--square-800.webp','width'=>800,'height'=>800,'mime_type'=>'image/webp'],
        'card' => ['path'=>'/uploads/media/example--card-1200x900.webp','width'=>1200,'height'=>900,'mime_type'=>'image/webp'],
        'display' => ['path'=>'/uploads/media/example--display-1920x1080.webp','width'=>1920,'height'=>1080,'mime_type'=>'image/webp'],
        'w1280' => ['path'=>'/uploads/media/example--w1280.webp','width'=>1280,'height'=>720,'mime_type'=>'image/webp'],
        'w1920' => ['path'=>'/uploads/media/example--w1920.webp','width'=>1920,'height'=>1080,'mime_type'=>'image/webp'],
        'unsafe' => ['path'=>'https://example.com/image.webp','width'=>300,'height'=>300,'mime_type'=>'image/webp'],
    ],
]);
media_assert(is_array($delivery), 'public image delivery should accept a valid Media Engine sidecar');
media_assert(($delivery['variants']['square']['src'] ?? '') === '/uploads/media/example--square-800.webp', 'square WebP variant should be exposed');
media_assert(($delivery['variants']['card']['src'] ?? '') === '/uploads/media/example--card-1200x900.webp', 'card WebP variant should be exposed');
media_assert(($delivery['variants']['display']['src'] ?? '') === '/uploads/media/example--display-1920x1080.webp', 'generic preserve-aspect display WebP should be exposed');
media_assert(($delivery['variants']['w1280']['src'] ?? '') === '/uploads/media/example--w1280.webp', 'preserve-aspect WebP variant should be exposed for generic public delivery');
media_assert(($delivery['variants']['w1920']['src'] ?? '') === '/uploads/media/example--w1920.webp', 'large preserve-aspect WebP variant should be exposed for viewer delivery');
media_assert(!isset($delivery['variants']['unsafe']), 'non-allowlisted variant names must stay private');
media_assert(brvtal_public_media_delivery_sanitize([
    'original'=>['path'=>'https://example.com/private.png','width'=>100,'height'=>100],
    'variants'=>['square'=>['path'=>'/uploads/media/x.webp','width'=>100,'height'=>100,'mime_type'=>'image/webp']],
]) === null, 'public delivery must reject external originals');

$api = (string)file_get_contents(__DIR__ . '/../api/media-library.php');
$publicApi = (string)file_get_contents(__DIR__ . '/../api/public.php');
$mediaConfig = (string)file_get_contents(__DIR__ . '/../config/media.php');
$mediaIntegrity = (string)file_get_contents(__DIR__ . '/../config/media_integrity.php');
media_assert(str_contains($api, "brvtal_admin_require_csrf"), 'writes must require CSRF');
media_assert(str_contains($api, "MEDIA_IN_USE"), 'delete must block referenced media');
media_assert(str_contains($api, 'brvtal_media_integrity_usage'), 'delete/detail must use the canonical integrity-aware usage boundary');
media_assert(str_contains($mediaIntegrity, 'brvtal_media_usage($pdo, $media)') && str_contains($mediaIntegrity, 'brvtal_media_duplicate_usage($pdo, $media)'), 'integrity-aware usage must preserve editorial references and add duplicate local Media ownership');
media_assert(str_contains($api, "25 * 1024 * 1024"), 'upload size ceiling must be explicit');
media_assert(str_contains($api, "image/webp"), 'WebP uploads must be supported');
media_assert(str_contains($api, "MEDIA_DEDUP_MIGRATION_REQUIRED"), 'physical upload must expose explicit dedup migration state');
media_assert(str_contains($api, "brvtalMediaFindDuplicate"), 'physical upload must use exact-content deduplication before storage');
media_assert(str_contains($api, "\$action === 'transform'"), 'Media Engine v2 must expose a focal-point transform action');
media_assert(str_contains($api, 'brvtal_media_remove_generated_variants'), 'regeneration must clean previously tracked variants');
media_assert(!str_contains($api, "image/svg+xml"), 'SVG uploads stay disabled until a sanitizer exists');
media_assert(str_contains($api, 'brvtal_media_local_absolute($path)'), 'registered local media must resolve through the contained uploads helper');
media_assert(str_contains($api, 'LOCAL_MEDIA_NOT_FOUND'), 'register must reject missing local upload paths');
media_assert(str_contains($api, 'finfo(FILEINFO_MIME_TYPE)'), 'register must derive MIME from local file bytes');
media_assert(str_contains($api, 'MEDIA_TYPE_MISMATCH'), 'register must reject a declared type that disagrees with the local file');
media_assert(str_contains($api, '$size = max(0, (int)(@filesize($absolute) ?: 0));'), 'register must persist the actual local file size');
$uploadStart = strpos($api, "if (\$method === 'POST' && \$action === 'upload')");
$registerStart = strpos($api, "if (\$method === 'POST' && \$action === 'register')");
media_assert($uploadStart !== false && $registerStart !== false && $registerStart > $uploadStart, 'upload source block must remain discoverable');
$uploadSource = substr($api, $uploadStart, $registerStart - $uploadStart);
media_assert(
    str_contains($uploadSource, "\$st->execute([\$type, \$title, \$publicPath, \$mime, \$size, \$contentHash, \$alt, 'draft']);"),
    'new physical uploads must be persisted as draft'
);
media_assert(!str_contains($uploadSource, "'published'"), 'upload path must not silently publish newly uploaded assets');
media_assert(preg_match("/FROM media\\s+WHERE status='published'/", $publicApi) === 1, 'public Media must remain limited to explicitly published records');
media_assert(str_contains($mediaConfig, "in_array(\$mime, ['image/jpeg', 'image/png'], true)"), 'JPEG and PNG uploads must generate a generic preserve-aspect WebP');
media_assert(str_contains($mediaConfig, "\$result['variants']['display']"), 'Media Engine must track the generic display WebP in its sidecar');
media_assert(str_contains($mediaConfig, "--display-"), 'generic upload WebP filenames must be deterministic and context-identifiable');
media_assert(str_contains($mediaConfig, "'original' => [") && str_contains($mediaConfig, "'path' => brvtal_media_public_upload_path(\$absoluteOriginal)"), 'Media Engine must keep the uploaded original authoritative');

$permissionApi = (string)file_get_contents(__DIR__ . '/../api/media-permissions.php');
media_assert(str_contains($permissionApi, 'brvtal_admin_require_csrf'), 'media permission repair must require CSRF');
media_assert(str_contains($permissionApi, '0644'), 'public media files must be readable by the web server');
media_assert(str_contains($permissionApi, '0755'), 'public media directories must be traversable by the web server');
media_assert(str_contains($permissionApi, 'brvtal_media_local_absolute'), 'media permission repair must stay inside uploads');

$module = (string)file_get_contents(__DIR__ . '/../discadmin/media-library.php');
media_assert(str_contains($module, 'data-admin-module="media"'), 'Media Library must be a shell module fragment');
media_assert(str_contains($module, 'X_BRVTAL_ADMIN_FRAGMENT'), 'direct Media Library requests must redirect to DISCADMIN shell');

$adminModules = (string)file_get_contents(__DIR__ . '/../discadmin/admin-modules.js');
media_assert(str_contains($adminModules, "media: {url:'/discadmin/media-library.php'"), 'canonical shell loader must register media module');
media_assert(str_contains($adminModules, "section==='media'") && str_contains($adminModules, 'prepareModuleWorkspace(section)'), 'MEDIA navigation must be intercepted into module workspace');
media_assert(str_contains($adminModules, 'window.BRVTALFeedback'), 'DISCADMIN must expose global mutation feedback');
media_assert(str_contains($adminModules, 'window.save = async function'), 'canonical CRUD forms must have a working save handler');
media_assert(str_contains($adminModules, "type === 'events'"), 'save handler must serialize event fields');
media_assert(str_contains($adminModules, "type === 'artists'"), 'save handler must serialize artist fields');
media_assert(str_contains($adminModules, 'mediaPermissions.repair'), 'Media module must repair existing public thumbnail permissions');
media_assert(str_contains($adminModules, "document.addEventListener('error'"), 'image failures must have a global fallback handler');
media_assert(str_contains($adminModules, 'syncMediaFieldPreview'), 'picker changes must update modal previews immediately');

$sessionBootstrap = (string)file_get_contents(__DIR__ . '/../discadmin/totp-login.js');
media_assert(str_contains($sessionBootstrap, 'const initialSection = window.BRVTALAdminModules.initialSection();'), 'initial DISCADMIN section must be resolved by the dynamic module router');
media_assert(str_contains($sessionBootstrap, 'await window.go(initialSection);'), 'restored sessions must route the first module through window.go');
media_assert(str_contains($sessionBootstrap, "window.restoreSession = async function()"), 'session bootstrap must replace the legacy initial restore path');

$controller = (string)file_get_contents(__DIR__ . '/../discadmin/media-library.js');
$publicMedia = (string)file_get_contents(__DIR__ . '/../js/public-media.js');
$publicDeliveryApi = (string)file_get_contents(__DIR__ . '/../api/public-image-delivery.php');
$publicDeliveryLib = (string)file_get_contents(__DIR__ . '/../api/public-media-delivery.php');
$publicIndex = (string)file_get_contents(__DIR__ . '/../index.html');
$publicRuntime = (string)file_get_contents(__DIR__ . '/../js/public-runtime-loader.js');
$publicPage = (string)file_get_contents(__DIR__ . '/../config/public_page.php');
media_assert(str_contains($controller, '#f_cover_image,#f_photo,#e_cover_image,#e_ticket_qr'), 'media picker must attach to core image fields');
media_assert(str_contains($controller, 'Delete blocked: this media is currently in use.'), 'UI must surface reference protection');
media_assert(str_contains($controller, 'FOCAL POINT / CROP'), 'inspector must expose focal-point controls');
media_assert(str_contains($controller, 'SAVE FOCUS + REGENERATE'), 'inspector must expose explicit variant regeneration');
media_assert(str_contains($controller, "['square','card','hero']"), 'inspector must preview supported delivery contexts');
media_assert(str_contains($controller, "?action=transform&id="), 'focal-point changes must use the protected transform endpoint');
media_assert(str_contains($controller, 'media-edit-status') && str_contains($controller, "?action=update&id="), 'Media inspector must keep an explicit publication control for draft uploads');
media_assert(str_contains($publicPage, "brvtal_public_media_variant((string)\$seo['image'], 'hero')"), 'public heroes must select the hero context variant');
media_assert(str_contains($publicPage, "brvtal_public_media_variant((string)\$item['image'], 'card')"), 'public related cards must select the card context variant');
media_assert(str_contains($publicIndex, 'data-public-media-search') && str_contains($publicIndex, 'data-public-media-type="image"'), 'public Media must expose search and type filters');
media_assert(str_contains($publicIndex, 'css/public-media.css') && str_contains($publicIndex, 'js/public-runtime-loader.js'), 'public Media discovery styles and adaptive runtime must load in the existing page');
media_assert(str_contains($publicRuntime, "'js/public-media.js'"), 'adaptive public runtime must load the Media discovery controller');
media_assert(str_contains($publicMedia, 'role="dialog"') && str_contains($publicMedia, "event.key === 'Escape'"), 'public image viewer must be accessible and keyboard-dismissible');
media_assert(str_contains($publicMedia, "['image','video','audio']"), 'public Media discovery must support image, video and audio types');
media_assert(str_contains($publicMedia, '/api/public-image-delivery.php'), 'public Media must load the allowlisted image-delivery map');
media_assert(preg_match("/deliveryCandidate\\(url,\\s*'card'\\)/", $publicMedia) === 1, 'public Media grid must prefer card WebP variants');
media_assert(str_contains($publicMedia, "image.closest('.related-item-image')") && str_contains($publicMedia, "return 'square'"), 'related thumbnails must prefer square WebP variants');
media_assert(str_contains($publicMedia, "scope.querySelectorAll('img')"), 'public delivery must inspect every public image node for eligible uploaded media');
media_assert(str_contains($publicMedia, "image.getAttribute('data-src')"), 'deferred Hero Slider images must be eligible for WebP before activation');
media_assert(str_contains($publicMedia, "image.matches('.brvtal-hero-media')") && str_contains($publicMedia, "return 'hero'"), 'Hero Slider uploaded images must use the hero WebP context');
media_assert(str_contains($publicMedia, "context === 'preserve'") && str_contains($publicMedia, 'variants.display'), 'generic uploaded images must fall back to the preserve-aspect display WebP');
media_assert(str_contains($publicMedia, "context === 'viewer'") && str_contains($publicMedia, 'variants.w1920'), 'full-screen viewer must prefer the largest preserve-aspect WebP variant');
media_assert(str_contains($publicMedia, 'brvtalWebpFallbackBound') && str_contains($publicMedia, 'brvtalOriginalSrc'), 'WebP delivery must retain an automatic original-image fallback');
media_assert(str_contains($publicDeliveryLib, "'display'"), 'sanitized public delivery must allow the generic display WebP variant');
media_assert(str_contains($publicDeliveryApi, "status='published' AND type='image'"), 'public image delivery endpoint must be limited to published image records');
media_assert(str_contains($publicDeliveryApi, 'brvtal_public_media_delivery_map'), 'public image delivery endpoint must use sanitized Media Engine sidecars');

$quality = brvtal_media_quality_guidance(1920, 1080);
media_assert(($quality['grade'] ?? '') === 'excellent', '1920x1080 sources should receive excellent guidance');
media_assert(($quality['contexts']['hero']['ready'] ?? false) === true, '1920x1080 sources should be hero-ready');
$limited = brvtal_media_quality_guidance(700, 700);
media_assert(($limited['grade'] ?? '') === 'limited', 'small sources should receive limited guidance');

echo "BRVTAL Media Library contract tests passed.\n";
