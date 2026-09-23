<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/content-validation.php';

function brvtal_media_reference_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "media-reference-contract: {$message}\n");
        exit(1);
    }
}

$testDir = dirname(__DIR__) . '/uploads/media/contract-reference';
if (!is_dir($testDir) && !mkdir($testDir, 0750, true) && !is_dir($testDir)) {
    fwrite(STDERR, "media-reference-contract: unable to create fixture directory\n");
    exit(1);
}

$imagePath = $testDir . '/pixel.png';
$textPath = $testDir . '/not-image.txt';
file_put_contents(
    $imagePath,
    base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC', true)
);
file_put_contents($textPath, 'not an image');

try {
    $empty = brvtalMediaImageReferenceState('');
    brvtal_media_reference_assert($empty['valid'] && !$empty['usable'], 'empty visual should be valid but missing');

    $external = brvtalMediaImageReferenceState('https://cdn.example.test/poster.jpg');
    brvtal_media_reference_assert($external['valid'] && $external['usable'] && $external['kind'] === 'external', 'HTTPS visual should be structurally usable');

    $invalidScheme = brvtalMediaImageReferenceState('javascript:alert(1)');
    brvtal_media_reference_assert(!$invalidScheme['valid'] && !$invalidScheme['usable'], 'unsafe scheme must be invalid');

    $invalidLocal = brvtalMediaImageReferenceState('/assets/poster.jpg');
    brvtal_media_reference_assert(!$invalidLocal['valid'], 'non-upload local path must be invalid');

    $traversal = brvtalMediaImageReferenceState('/uploads/media/../secret.jpg');
    brvtal_media_reference_assert(!$traversal['valid'], 'upload traversal must be invalid');

    $missing = brvtalMediaImageReferenceState('/uploads/media/contract-reference/missing.jpg');
    brvtal_media_reference_assert($missing['valid'] && !$missing['usable'] && $missing['kind'] === 'local_missing', 'missing local upload must stay draft-compatible but unusable');

    $notImage = brvtalMediaImageReferenceState('/uploads/media/contract-reference/not-image.txt');
    brvtal_media_reference_assert($notImage['valid'] && !$notImage['usable'] && $notImage['kind'] === 'local_not_image', 'non-image local file must not count as usable');

    $image = brvtalMediaImageReferenceState('/uploads/media/contract-reference/pixel.png');
    brvtal_media_reference_assert($image['valid'] && $image['usable'] && $image['kind'] === 'local_image', 'existing decodable image must be usable');

    brvtal_media_reference_assert(
        brvtalContentVisualShapeError('events', ['cover_image'=>'ftp://example.test/a.jpg']) === ['error'=>'INVALID_MEDIA_REFERENCE','field'=>'cover_image'],
        'event visual shape must reject non HTTP(S) remote refs'
    );
    brvtal_media_reference_assert(
        brvtalContentVisualShapeError('artists', ['photo'=>'/uploads/media/contract-reference/missing.jpg']) === null,
        'draft-compatible local-missing shape must not be rejected before final-state evaluation'
    );

    brvtal_media_reference_assert(
        brvtalContentVisualPublicationError('events', ['status'=>'draft','cover_image'=>'/uploads/media/contract-reference/missing.jpg']) === null,
        'draft event may retain a repairable missing local reference'
    );
    brvtal_media_reference_assert(
        brvtalContentVisualPublicationError('events', ['status'=>'published','cover_image'=>'/uploads/media/contract-reference/missing.jpg']) === ['error'=>'MEDIA_REFERENCE_UNRESOLVABLE','field'=>'cover_image'],
        'published event must reject missing local visual'
    );
    brvtal_media_reference_assert(
        brvtalContentVisualPublicationError('artists', ['status'=>'published','photo'=>'/uploads/media/contract-reference/not-image.txt']) === ['error'=>'MEDIA_REFERENCE_UNRESOLVABLE','field'=>'photo'],
        'published artist must reject non-image local visual'
    );
    brvtal_media_reference_assert(
        brvtalContentVisualPublicationError('sets', ['status'=>'published','cover_image'=>'https://cdn.example.test/set.jpg']) === null,
        'published set may use structurally valid external HTTP(S) visual without SSRF probing'
    );
    brvtal_media_reference_assert(
        brvtalContentVisualPublicationError('events', ['status'=>'published','cover_image'=>'']) === null,
        'empty visual remains allowed and is health debt rather than a mutation error'
    );

    $indexSource = (string)file_get_contents(__DIR__ . '/../api/index.php');
    $healthSource = (string)file_get_contents(__DIR__ . '/../api/content-health.php');
    brvtal_media_reference_assert(str_contains($indexSource, 'brvtalContentVisualShapeError'), 'generic CRUD must enforce visual shape');
    brvtal_media_reference_assert(substr_count($indexSource, 'brvtalContentVisualPublicationError') >= 2, 'create and update must enforce final visual state');
    brvtal_media_reference_assert(str_contains($healthSource, 'brvtalMediaImageReferenceState'), 'Content Health must classify visual references');
    brvtal_media_reference_assert(str_contains($healthSource, 'Broken primary visual'), 'Content Health must distinguish broken references from missing visuals');
    brvtal_media_reference_assert(str_contains($healthSource, "'image_reference_kind'"), 'Content Health must expose the diagnostic reference kind');
    brvtal_media_reference_assert(!str_contains($healthSource, "'has_image' => \$image !== ''"), 'Content Health must not treat non-empty as healthy');
} finally {
    @unlink($imagePath);
    @unlink($textPath);
    @rmdir($testDir);
}

echo "media-reference-contract: OK\n";
