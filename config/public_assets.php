<?php
declare(strict_types=1);

function brvtal_public_version_assets(string $html, string $version): string
{
    if ($version === '') return $html;

    return preg_replace_callback(
        '~(?<prefix>(?:href|src)="(?:css|js)/[^"?]+)(?:\?[^"#]*)?(?<suffix>")~',
        static fn(array $match): string => $match['prefix'] . '?v=' . rawurlencode($version) . $match['suffix'],
        $html
    ) ?? $html;
}
