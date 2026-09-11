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
media_assert(str_contains($adminModules, "if(section==='media')"), 'MEDIA navigation must be intercepted into module workspace');
media_assert(str_contains($adminModules, 'window.BRVTALFeedback'), 'DISCADMIN must expose global mutation feedback');
media_assert(str_contains($adminModules, 'window.save = async function'), 'canonical CRUD forms must have a working save handler');
media_assert(str_contains($adminModules, "type === 'events'"), 'save handler must serialize event fields');
media_assert(str_contains($adminModules, "type === 'artists'"), 'save handler must serialize artist fields');
media_assert(str_contains($adminModules, 'mediaPermissions.repair'), 'Media module must repair existing public thumbnail permissions');
media_assert(str_contains($adminModules, "document.addEventListener('error'"), 'image failures must have a global fallback handler');
media_assert(str_contains($adminModules, 'syncMediaFieldPreview'), 'picker changes must update modal previews immediately');

$controller = (string)file_get_contents(__DIR__ . '/../discadmin/media-library.js');
media_assert(str_contains($controller, '#f_cover_image,#f_photo,#e_cover_image,#e_ticket_qr'), 'media picker must attach to core image fields');
media_assert(str_contains($controller, 'Delete blocked: this media is currently in use.'), 'UI must surface reference protection');

echo "BRVTAL Media Library contract tests passed.\n";
