<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/media.php';
require_once __DIR__ . '/../config/media_integrity.php';
require_once __DIR__ . '/../api/route.php';

function media_integrity_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("MEDIA INTEGRITY CONTRACT FAILED: {$message}");
    }
}

$legacyUpload = brvtal_api_parse_route('/api/index.php/upload', '/api/index.php', 'POST');
media_integrity_assert($legacyUpload['resource'] === '', 'legacy upload mutation route must fail closed');
$legacyMediaDelete = brvtal_api_parse_route('/api/index.php/media/42', '/api/index.php', 'DELETE');
media_integrity_assert($legacyMediaDelete['resource'] === '', 'generic Media DELETE route must fail closed');
$legacyMediaPost = brvtal_api_parse_route('/api/index.php/media', '/api/index.php', 'POST');
media_integrity_assert($legacyMediaPost['resource'] === '', 'generic Media POST route must fail closed');
$legacyMediaPut = brvtal_api_parse_route('/api/index.php/media/42', '/api/index.php', 'PUT');
media_integrity_assert($legacyMediaPut['resource'] === '', 'generic Media PUT route must fail closed');
$mediaRead = brvtal_api_parse_route('/api/index.php/media/42', '/api/index.php', 'GET');
media_integrity_assert($mediaRead['resource'] === 'media' && $mediaRead['id'] === 42, 'generic Media GET compatibility must remain available');

$root = brvtal_media_upload_root();
$dir = $root . '/integrity-contract';
if (!is_dir($dir)) {
    mkdir($dir, 0750, true);
}
$file = $dir . '/stage.txt';
file_put_contents($file, 'media-integrity');
$publicPath = '/uploads/integrity-contract/stage.txt';

$failedStage = brvtal_media_stage_delete(
    ['id'=>1, 'file_path'=>$publicPath],
    static fn(string $source, string $target): bool => false
);
media_integrity_assert(($failedStage['ok'] ?? true) === false, 'failed staging must be reported');
media_integrity_assert(is_file($file), 'failed staging must leave the public source in place');

$stage = brvtal_media_stage_delete(['id'=>1, 'file_path'=>$publicPath]);
media_integrity_assert(($stage['ok'] ?? false) === true, 'valid local media should stage into private storage');
media_integrity_assert(!is_file($file), 'staged media must stop being publicly reachable before DB deletion');
media_integrity_assert(count((array)($stage['staged'] ?? [])) === 1, 'the original file must be staged');
$private = (string)($stage['staged'][0]['private'] ?? '');
media_integrity_assert($private !== '' && is_file($private), 'staged file must exist under private storage');
brvtal_media_restore_staged_delete($stage);
media_integrity_assert(is_file($file), 'rollback must restore the public file');

$stage = brvtal_media_stage_delete(['id'=>1, 'file_path'=>$publicPath]);
media_integrity_assert(($stage['ok'] ?? false) === true, 'media should stage again for cleanup behavior');
$cleanup = brvtal_media_finalize_staged_delete(
    $stage,
    static fn(string $path): bool => false
);
media_integrity_assert(($cleanup['deleted'] ?? []) === [], 'failed private cleanup must not be reported as deleted');
media_integrity_assert(($cleanup['cleanup_failed'] ?? []) === [$publicPath], 'failed private cleanup must retain its logical media path');
media_integrity_assert(!is_file($file), 'private cleanup failure must never restore the old public URL');
foreach ((array)($stage['staged'] ?? []) as $entry) {
    $privatePath = (string)($entry['private'] ?? '');
    if ($privatePath !== '') @unlink($privatePath);
}
$trashDir = (string)($stage['trash_dir'] ?? '');
if ($trashDir !== '') @rmdir($trashDir);
@rmdir(dirname($trashDir));
@rmdir($dir);

$endpoint = (string)file_get_contents(__DIR__ . '/../api/media-library.php');
media_integrity_assert(str_contains($endpoint, "require_once __DIR__ . '/../config/media_integrity.php';"), 'canonical Media endpoint must load integrity helpers');
media_integrity_assert(str_contains($endpoint, 'brvtal_media_reference_mutex_lock($pdo)'), 'delete must hold the reference mutex while staging and deleting');
media_integrity_assert(str_contains($endpoint, 'brvtal_media_integrity_usage($pdo, $row)'), 'delete/detail must include duplicate Media ownership');
media_integrity_assert(str_contains($endpoint, 'brvtal_media_stage_delete($row)'), 'delete must stage public files before deleting the DB row');
media_integrity_assert(str_contains($endpoint, 'brvtal_media_restore_staged_delete($stage)'), 'failed DB deletion must restore staged public files');
media_integrity_assert(str_contains($endpoint, 'brvtal_media_finalize_staged_delete'), 'committed deletion must finalize private cleanup');
media_integrity_assert(str_contains($endpoint, 'LOCAL_MEDIA_ALREADY_REGISTERED'), 'canonical register must reject duplicate local ownership');

$privateHtaccess = (string)file_get_contents(__DIR__ . '/../.private/.htaccess');
media_integrity_assert(str_contains($privateHtaccess, 'Require all denied') && str_contains($privateHtaccess, 'Deny from all'), 'staged delete storage must be denied from public web access');

echo "BRVTAL Media integrity contract tests passed.\n";
