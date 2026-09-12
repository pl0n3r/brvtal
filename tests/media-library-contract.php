<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/media.php';

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

$api = (string)file_get_contents(__DIR__ . '/../api/media-library.php');
media_assert(str_contains($api, "brvtal_admin_require_csrf"), 'writes must require CSRF');
media_assert(str_contains($api, "MEDIA_IN_USE"), 'delete must block referenced media');
media_assert(str_contains($api, "brvtal_media_usage"), 'delete/detail must inspect media usage');
media_assert(str_contains($api, "25 * 1024 * 1024"), 'upload size ceiling must be explicit');
media_assert(str_contains($api, "image/webp"), 'WebP uploads must be supported');
media_assert(str_contains($api, "\$action === 'transform'"), 'Media Engine v2 must expose a focal-point transform action');
media_assert(str_contains($api, 'brvtal_media_remove_generated_variants'), 'regeneration must clean previously tracked variants');
media_assert(!str_contains($api, "image/svg+xml"), 'SVG uploads stay disabled until a sanitizer exists');

$permissionApi = (string)file_get_contents(__DIR__ . '/../api/media-permissions.php');
media_assert(str_contains($permissionApi, 'brvtal_admin_require_csrf'), 'media permission repair must require CSRF');
media_assert(str_contains($permissionApi, '0644'), 'public media files must be readable by the web server');
media_assert(str_contains($permissionApi, '0755'), 'public media directories must be traversable by the web server');
media_assert(str_contains($permissionApi, 'brvtal_media_local_absolute'), 'permission repair must stay inside uploads');

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
$publicIndex = (string)file_get_contents(__DIR__ . '/../index.html');
$publicPage = (string)file_get_contents(__DIR__ . '/../config/public_page.php');
media_assert(str_contains($controller, '#f_cover_image,#f_photo,#e_cover_image,#e_ticket_qr'), 'media picker must attach to core image fields');
media_assert(str_contains($controller, 'Delete blocked: this media is currently in use.'), 'UI must surface reference protection');
media_assert(str_contains($controller, 'FOCAL POINT / CROP'), 'inspector must expose focal-point controls');
media_assert(str_contains($controller, 'SAVE FOCUS + REGENERATE'), 'inspector must expose explicit variant regeneration');
media_assert(str_contains($controller, "['square','card','hero']"), 'inspector must preview supported delivery contexts');
media_assert(str_contains($controller, "?action=transform&id="), 'focal-point changes must use the protected transform endpoint');
media_assert(str_contains($publicPage, "brvtal_public_media_variant((string)\$seo['image'], 'hero')"), 'public heroes must select the hero context variant');
media_assert(str_contains($publicPage, "brvtal_public_media_variant((string)\$item['image'], 'card')"), 'public related cards must select the card context variant');
media_assert(str_contains($publicIndex, 'data-public-media-search') && str_contains($publicIndex, 'data-public-media-type="image"'), 'public Media must expose search and type filters');
media_assert(str_contains($publicIndex, 'css/public-media.css') && str_contains($publicIndex, 'js/public-media.js'), 'public Media discovery assets must load in the existing page');
media_assert(str_contains($publicMedia, 'role="dialog"') && str_contains($publicMedia, "event.key === 'Escape'"), 'public image viewer must be accessible and keyboard-dismissible');
media_assert(str_contains($publicMedia, "['image','video','audio']"), 'public Media discovery must support image, video and audio types');

$quality = brvtal_media_quality_guidance(1920, 1080);
media_assert(($quality['grade'] ?? '') === 'excellent', '1920x1080 sources should receive excellent guidance');
media_assert(($quality['contexts']['hero']['ready'] ?? false) === true, '1920x1080 sources should be hero-ready');
$limited = brvtal_media_quality_guidance(700, 700);
media_assert(($limited['grade'] ?? '') === 'limited', 'small sources should receive limited guidance');

echo "BRVTAL Media Library contract tests passed.\n";
