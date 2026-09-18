<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/public_visibility.php';
require_once __DIR__ . '/../config/public_seo.php';
require_once __DIR__ . '/../config/public_page.php';

function contextualTransmissionsExpect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "CONTEXTUAL TRANSMISSIONS CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

contextualTransmissionsExpect(
    brvtalPublicTransmissionRelationType('events') === 'event',
    'Events must map to the singular Blog relation type'
);
contextualTransmissionsExpect(
    brvtalPublicTransmissionRelationType('artists') === 'artist',
    'Artists must map to the singular Blog relation type'
);
contextualTransmissionsExpect(
    brvtalPublicTransmissionRelationType('sets') === 'set',
    'Sets must map to the singular Blog relation type'
);
contextualTransmissionsExpect(
    brvtalPublicTransmissionRelationType('releases') === 'release',
    'Releases must map to the singular Blog relation type'
);
contextualTransmissionsExpect(
    brvtalPublicTransmissionRelationType('pages') === null,
    'Unsupported entity types must not gain inferred Transmissions'
);

$seo = [
    'title' => 'PL0N3R — BRVTAL',
    'description' => 'Artist fixture',
    'canonical' => 'https://www.brvtal.com.co/artists/pl0n3r',
    'image' => 'https://example.com/pl0n3r.jpg',
    'schema' => ['@type' => 'Person'],
];
$page = [
    'entity' => [
        'id' => 1,
        'route_type' => 'artists',
        'title' => 'PL0N3R',
        'description' => 'BRVTAL ARTIST',
    ],
    'facts' => [],
    'links' => [],
    'record' => [],
    'related' => [
        'TRANSMISSIONS' => [[
            'title' => 'GENESIS RECAP',
            'slug' => 'genesis-recap',
            'route_type' => 'blog',
            'image' => '',
            'meta' => '18.09.2026',
        ]],
    ],
    'degraded' => false,
];

$html = brvtal_public_entity_page($page, $seo);
contextualTransmissionsExpect(
    str_contains($html, 'TRANSMISSIONS / 01'),
    'Canonical entity renderer must surface the Transmissions section'
);
contextualTransmissionsExpect(
    str_contains($html, 'href="/blog/genesis-recap"'),
    'Transmission cards must preserve canonical Blog navigation'
);
contextualTransmissionsExpect(
    str_contains($html, 'GENESIS RECAP'),
    'Transmission title must render as editorial content'
);

echo "Contextual Transmissions contract passed.\n";
