<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/media.php';

function media_optimization_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "MEDIA OPTIMIZATION CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$root = brvtal_media_upload_root();
$dir = $root . '/contract-optimization-' . bin2hex(random_bytes(6));
if (!mkdir($dir, 0750, true) && !is_dir($dir)) {
    fwrite(STDERR, "MEDIA OPTIMIZATION CONTRACT FAILED: could not create isolated fixture directory\n");
    exit(1);
}
register_shutdown_function(static function () use ($dir): void {
    foreach (glob($dir . '/*') ?: [] as $file) {
        if (is_file($file) || is_link($file)) {
            @unlink($file);
        }
    }
    @rmdir($dir);
});
$absolute = $dir . '/source.jpg';
file_put_contents($absolute, 'planner-fixture');
$specs = brvtalMediaVariantSpecs($absolute, 'image/jpeg', 2400, 1350);
media_optimization_assert(($specs['display']['path'] ?? '') !== '', 'future variant public paths must resolve before generation');

media_optimization_assert(isset($specs['square'], $specs['card'], $specs['hero']), 'crop-dependent contexts must remain present');
media_optimization_assert(($specs['square']['crop'] ?? false) === true, 'square must remain focal-sensitive');
media_optimization_assert(($specs['card']['crop'] ?? false) === true, 'card must remain focal-sensitive');
media_optimization_assert(($specs['hero']['crop'] ?? false) === true, 'hero must remain focal-sensitive');
media_optimization_assert(($specs['display']['crop'] ?? true) === false, 'display must preserve aspect ratio');
media_optimization_assert(($specs['w1280']['crop'] ?? true) === false, 'w1280 must preserve aspect ratio');
media_optimization_assert(($specs['w1920']['alias_of'] ?? '') === 'display', 'w1920 must alias equivalent display output');
media_optimization_assert(($specs['w1920']['path'] ?? '') === ($specs['display']['path'] ?? ''), 'alias must preserve the public delivery path');

$previous = [
    'path' => $specs['card']['path'],
    'width' => $specs['card']['width'],
    'height' => $specs['card']['height'],
    'mime_type' => 'image/webp',
    'generated_at' => '2026-09-23T06:00:00-05:00',
];
$valid = static fn(array $spec): bool => true;
$missing = static fn(array $spec): bool => false;

media_optimization_assert(
    brvtalMediaVariantDecision($previous, $specs['card'], true, true, $valid) === 'reuse',
    'same source/policy/focal must reuse a valid crop'
);
media_optimization_assert(
    brvtalMediaVariantDecision($previous, $specs['card'], true, false, $valid) === 'generate',
    'focal-only changes must regenerate crop-dependent output'
);

$displayPrevious = [
    'path' => $specs['display']['path'],
    'width' => $specs['display']['width'],
    'height' => $specs['display']['height'],
    'mime_type' => 'image/webp',
    'generated_at' => '2026-09-23T06:00:00-05:00',
];
media_optimization_assert(
    brvtalMediaVariantDecision($displayPrevious, $specs['display'], true, false, $valid) === 'reuse',
    'focal-only changes must reuse preserve-aspect output'
);
media_optimization_assert(
    brvtalMediaVariantDecision($displayPrevious, $specs['display'], true, true, $missing) === 'repair',
    'missing/corrupt derivative must be repaired rather than trusted from sidecar metadata'
);
media_optimization_assert(
    brvtalMediaVariantDecision($displayPrevious, $specs['display'], false, true, $valid) === 'generate',
    'policy/source changes must force regeneration'
);

$entry = brvtalMediaVariantEntry(
    $specs['display'],
    'reused',
    '2026-09-23T07:00:00-05:00',
    $displayPrevious
);
media_optimization_assert(($entry['generated_at'] ?? '') === $displayPrevious['generated_at'], 'reuse must preserve original generated timestamp');
media_optimization_assert(($entry['reused_at'] ?? '') === '2026-09-23T07:00:00-05:00', 'reuse must have distinct reuse timestamp');

$policy = brvtalMediaVariantPolicy();
media_optimization_assert(($policy['version'] ?? 0) === 3, 'policy version must be explicit');
media_optimization_assert(($policy['generator_version'] ?? 0) === 3, 'generator version must be explicit');
media_optimization_assert(($policy['webp_quality'] ?? 0) === 82, 'WebP quality must be explicit');

$target = $dir . '/staged.webp';
$temporary = $target . '.tmp-contract';
file_put_contents($target, 'old-variant');
file_put_contents($temporary, 'new-variant');
$stored = brvtalMediaStoreSidecar($absolute, [
    'version'=>3,
    'status'=>'ready',
    'original'=>['path'=>brvtal_media_public_upload_path($absolute),'width'=>1,'height'=>1,'mime_type'=>'image/jpeg'],
    'variants'=>[],
    '_staged_files'=>[[
        'logical_name'=>'display',
        'temporary'=>$temporary,
        'target'=>$target,
    ]],
]);
media_optimization_assert($stored === true, 'sidecar commit must atomically promote staged variants');
media_optimization_assert((string)file_get_contents($target) === 'new-variant', 'staged derivative must replace the previous physical file only at commit');
$sidecar = brvtal_media_read_sidecar((string)brvtal_media_public_upload_path($absolute));
media_optimization_assert(is_array($sidecar), 'sidecar must remain readable after staged commit');
media_optimization_assert(!array_key_exists('_staged_files', $sidecar), 'private staging paths must never leak into sidecar metadata');

$rollbackTarget = $dir . '/rollback.webp';
$rollbackBackup = $rollbackTarget . '.bak-contract';
$rollbackPending = $dir . '/pending.webp.tmp-contract';
file_put_contents($rollbackTarget, 'new-visible');
file_put_contents($rollbackBackup, 'old-visible');
file_put_contents($rollbackPending, 'uncommitted');
brvtalMediaRollbackStagedVariants(
    [['target'=>$rollbackTarget, 'backup'=>$rollbackBackup]],
    [['temporary'=>$rollbackPending, 'target'=>$dir . '/pending.webp']]
);
media_optimization_assert((string)file_get_contents($rollbackTarget) === 'old-visible', 'rollback must restore the previous public derivative');
media_optimization_assert(!is_file($rollbackBackup), 'rollback must consume the backup after restoration');
media_optimization_assert(!is_file($rollbackPending), 'rollback must discard uncommitted staged derivatives');

echo "BRVTAL Media optimization contract tests passed.\n";
