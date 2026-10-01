<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/public_translation_overlay.php';

function overlay_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('EDITORIAL OVERLAY CONTRACT FAILED: ' . $message);
    }
}

final class OverlayTestAdapter implements BrvtalPublicTranslationAdapter
{
    public int $calls = 0;

    public function version(): string
    {
        return 'overlay-test-v1';
    }

    public function translate(string $source, string $sourceLocale, string $targetLocale): string
    {
        $this->calls++;
        if ($sourceLocale !== 'es' || $targetLocale !== 'en') {
            throw new RuntimeException('unexpected locale pair');
        }
        return 'EN ' . $source;
    }
}

function overlay_cache(): array
{
    $store = [];
    $read = static function (array $identity) use (&$store): ?string {
        return $store[(string)$identity['cache_key']] ?? null;
    };
    $write = static function (array $identity, string $value) use (&$store): void {
        $store[(string)$identity['cache_key']] = $value;
    };
    return [$read, $write];
}

function canonical_blog(array $overrides = []): array
{
    return array_replace([
        'id' => 41,
        'slug' => 'ritual-industrial',
        'route_type' => 'blog',
        'status' => 'published',
        'title' => 'Ritual industrial',
        'description' => 'Una crónica desde la pista.',
        'seo_title' => 'Ritual industrial — BRVTAL',
        'seo_description' => 'Crónica editorial canónica.',
    ], $overrides);
}

$case = $argv[1] ?? '';

if ($case === 'identity') {
    $source = canonical_blog();
    $adapter = new OverlayTestAdapter();
    [$read, $write] = overlay_cache();

    $overlay = brvtalPublicEditorialOverlayResolve(
        $source,
        'en',
        $adapter,
        $read,
        $write,
        true,
        true
    );
    overlay_expect(is_array($overlay), 'published trusted source must resolve an overlay');
    overlay_expect($overlay['source_identity'] === 'blog:41', 'Spanish source identity must remain stable');
    overlay_expect($overlay['source_locale'] === 'es', 'Spanish must remain canonical source locale');
    overlay_expect($overlay['target_locale'] === 'en', 'overlay target must be English');
    overlay_expect($adapter->calls === 4, 'blog overlay must resolve the four approved editorial fields');

    $applied = brvtalPublicEditorialOverlayApply($source, $overlay, true, true);
    overlay_expect(is_array($applied), 'matching source hash must apply');
    overlay_expect($applied['entity']['id'] === 41, 'overlay must preserve canonical entity id');
    overlay_expect($applied['entity']['slug'] === 'ritual-industrial', 'overlay must preserve canonical slug');
    overlay_expect($applied['entity']['route_type'] === 'blog', 'overlay must preserve canonical route type');
    overlay_expect($applied['entity']['title'] === 'EN Ritual industrial', 'overlay must project translated title');
    overlay_expect(!array_key_exists('locale', $applied['entity']), 'overlay must not create a duplicate localized record');

    $changed = canonical_blog(['description' => 'La fuente española cambió.']);
    $changedIdentity = brvtalPublicEditorialOverlaySourceIdentity($changed);
    overlay_expect(
        $changedIdentity['source_identity'] === $overlay['source_identity'],
        'content changes must not change stable source identity'
    );
    overlay_expect(
        $changedIdentity['source_hash'] !== $overlay['source_hash'],
        'content changes must change the source hash'
    );
    overlay_expect(
        brvtalPublicEditorialOverlayApply($changed, $overlay, true, true) === null,
        'stale overlay must fail closed after source change'
    );

    echo "identity-ok\n";
    exit(0);
}

if ($case === 'visibility') {
    $source = canonical_blog();
    $adapter = new OverlayTestAdapter();
    [$read, $write] = overlay_cache();

    overlay_expect(
        brvtalPublicEditorialOverlayResolve($source, 'en', $adapter, $read, $write, false, true) === null,
        'private publication boundary must not resolve overlay'
    );
    overlay_expect(
        brvtalPublicEditorialOverlayResolve($source, 'en', $adapter, $read, $write, true, false) === null,
        'untrusted publication boundary must not resolve overlay'
    );
    overlay_expect($adapter->calls === 0, 'blocked content must never reach translation adapter');

    $valid = brvtalPublicEditorialOverlayResolve($source, 'en', $adapter, $read, $write, true, true);
    overlay_expect(is_array($valid), 'control overlay must resolve');

    $draft = canonical_blog(['status' => 'draft']);
    overlay_expect(
        brvtalPublicEditorialOverlayApply($draft, $valid, true, true) === null,
        'draft source must not publish through overlay'
    );
    $private = canonical_blog(['visibility' => 'private']);
    overlay_expect(
        brvtalPublicEditorialOverlayApply($private, $valid, true, true) === null,
        'private source must not publish through overlay'
    );
    $untrusted = canonical_blog(['trusted' => false]);
    overlay_expect(
        brvtalPublicEditorialOverlayApply($untrusted, $valid, true, true) === null,
        'untrusted source must not publish through overlay'
    );

    $eventSource = [
        'id' => 88,
        'slug' => 'genesis',
        'route_type' => 'events',
        'status' => 'published',
        'title' => 'GENESIS',
        'description' => 'Noche canónica.',
        'seo_title' => 'GENESIS — BRVTAL',
        'seo_description' => 'Evento canónico.',
    ];
    $eventOverlay = brvtalPublicEditorialOverlayResolve(
        $eventSource,
        'en',
        $adapter,
        $read,
        $write,
        true,
        true
    );
    overlay_expect(is_array($eventOverlay), 'public event overlay must resolve');
    overlay_expect(
        !array_key_exists('title', $eventOverlay['fields']),
        'protected event title must not be translated by editorial overlay'
    );

    echo "visibility-ok\n";
    exit(0);
}

throw new RuntimeException('UNKNOWN_SCENARIO');
