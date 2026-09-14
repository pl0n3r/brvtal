<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/media.php';

/**
 * Build a public, allowlisted image-delivery map from Media Engine sidecars.
 *
 * Originals stay authoritative and remain the fallback. Only local /uploads/
 * WebP variants with positive dimensions are exposed publicly.
 */

function brvtal_public_media_delivery_safe_path(?string $path): ?string
{
    $path = trim((string)$path);
    if ($path === '' || !str_starts_with($path, '/uploads/')) {
        return null;
    }
    if (str_contains($path, '..') || str_contains($path, "\0")) {
        return null;
    }
    return $path;
}

function brvtal_public_media_delivery_sanitize(array $sidecar): ?array
{
    $original = is_array($sidecar['original'] ?? null) ? $sidecar['original'] : [];
    $originalPath = brvtal_public_media_delivery_safe_path((string)($original['path'] ?? ''));
    $width = (int)($original['width'] ?? 0);
    $height = (int)($original['height'] ?? 0);

    if ($originalPath === null || $width < 1 || $height < 1) {
        return null;
    }

    $allowedVariantNames = ['square', 'card', 'hero', 'w1280', 'w1920'];
    $variants = [];
    foreach ($allowedVariantNames as $name) {
        $variant = is_array($sidecar['variants'][$name] ?? null) ? $sidecar['variants'][$name] : null;
        if ($variant === null) continue;

        $path = brvtal_public_media_delivery_safe_path((string)($variant['path'] ?? ''));
        $variantWidth = (int)($variant['width'] ?? 0);
        $variantHeight = (int)($variant['height'] ?? 0);
        $mime = strtolower(trim((string)($variant['mime_type'] ?? '')));

        if ($path === null || $variantWidth < 1 || $variantHeight < 1 || $mime !== 'image/webp') {
            continue;
        }

        $variants[$name] = [
            'src' => $path,
            'width' => $variantWidth,
            'height' => $variantHeight,
            'type' => 'image/webp',
        ];
    }

    if ($variants === []) {
        return null;
    }

    return [
        'src' => $originalPath,
        'width' => $width,
        'height' => $height,
        'variants' => $variants,
    ];
}

function brvtal_public_media_delivery_for_path(?string $path): ?array
{
    $safePath = brvtal_public_media_delivery_safe_path($path);
    if ($safePath === null) return null;

    $sidecar = brvtal_media_read_sidecar($safePath);
    if (!is_array($sidecar)) return null;

    $delivery = brvtal_public_media_delivery_sanitize($sidecar);
    if ($delivery === null || $delivery['src'] !== $safePath) {
        return null;
    }
    return $delivery;
}

function brvtal_public_media_delivery_map(array $paths): array
{
    $map = [];
    foreach ($paths as $path) {
        $safePath = brvtal_public_media_delivery_safe_path(is_string($path) ? $path : null);
        if ($safePath === null || isset($map[$safePath])) continue;

        $delivery = brvtal_public_media_delivery_for_path($safePath);
        if ($delivery !== null) {
            $map[$safePath] = $delivery;
        }
    }
    return $map;
}
