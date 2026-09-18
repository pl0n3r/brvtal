<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/public_visibility.php';
require_once __DIR__ . '/../config/public_seo.php';
require_once __DIR__ . '/../config/public_page.php';

function contextual_transmissions_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "CONTEXTUAL TRANSMISSIONS CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

contextual_transmissions_expect(
    brvtal_public_transmission_relation_type('events') === 'event',
    'Events must map to the singular Blog relation type'
);
contextual_transmissions_expect(
    brvtal_public_transmission_relation_type('artists') === 'artist',
    'Artists must map to the singular Blog relation type'
);
contextual_transmissions_expect(
    brvtal_public_transmission_relation_type('sets') === 'set',
    'Sets must map to the singular Blog relation type'
);
contextual_transmissions_expect(
    brvtal_public_transmission_relation_type('releases') === 'release',
    'Releases must map to the singular Blog relation type'
);
contextual_transmissions_expect(
    brvtal_public_transmission_relation_type('pages') === null,
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
contextual_transmissions_expect(
    str_contains($html, 'TRANSMISSIONS / 01'),
    'Canonical entity renderer must surface the Transmissions section'
);
contextual_transmissions_expect(
    str_contains($html, 'href="/blog/genesis-recap"'),
    'Transmission cards must preserve canonical Blog navigation'
);
contextual_transmissions_expect(
    str_contains($html, 'GENESIS RECAP'),
    'Transmission title must render as editorial content'
);

echo "Contextual Transmissions contract passed.\n";
