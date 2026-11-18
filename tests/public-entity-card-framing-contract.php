<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/public_seo.php';
require_once __DIR__ . '/../config/public_page.php';

function entityCardExpect(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "ENTITY CARD FRAMING CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, is_json INTEGER)');
$valid = [
    'desktop' => ['zoom' => 2.1, 'x' => 28.5, 'y' => 15.0],
    'mobile' => ['zoom' => 1.6, 'x' => 65.0, 'y' => 7.0],
];
$insert = $pdo->prepare('INSERT INTO settings(setting_key,setting_value,is_json) VALUES(?,?,1)');
$insert->execute(['artist.hero.41', json_encode($valid, JSON_THROW_ON_ERROR)]);
$invalid = [
    'desktop' => ['zoom' => 500, 'x' => 0, 'y' => 0],
    'mobile' => ['zoom' => 1.0, 'x' => 0, 'y' => 0],
];
$insert->execute(['artist.hero.43', json_encode($invalid, JSON_THROW_ON_ERROR)]);

$related = [
    'LINEUP' => [
        ['artist_card_id' => 41, 'route_type' => 'artists', 'title' => 'PLON3R', 'slug' => 'plon3r', 'image' => '/uploads/plon3r.svg'],
        ['artist_card_id' => 42, 'route_type' => 'artists', 'title' => "CRIXXES'T", 'slug' => 'crixxes', 'image' => ''],
        ['artist_card_id' => 43, 'route_type' => 'artists', 'title' => 'SIN ENCUADRE', 'slug' => 'sin-encuadre', 'image' => '/uploads/artist.svg'],
    ],
    'SETS' => [
        ['route_type' => 'sets', 'title' => 'SET', 'slug' => 'set', 'image' => '/uploads/set.svg']
    ],
];
$enriched = brvtalPublicEntityArtistCardFraming($pdo, $related);
entityCardExpect(!isset($enriched['LINEUP'][0]['artist_card_id']), 'IDs never cross public boundary');
entityCardExpect($enriched['LINEUP'][0]['card_framing'] === brvtalArtistHeroValidate($valid), 'saved desktop/mobile framing preserved');
entityCardExpect(!isset($enriched['LINEUP'][1]['card_framing']), 'missing framing uses CSS default');
entityCardExpect(!isset($enriched['LINEUP'][2]['card_framing']), 'invalid framing rejected');
entityCardExpect(!isset($enriched['SETS'][0]['card_framing']), 'set card unaffected');

$page = [
    'entity' => ['id' => 1, 'route_type' => 'events', 'title' => 'GENESIS', 'description' => 'Fixture'],
    'facts' => [], 'links' => [], 'record' => ['state' => 'active', 'year' => '2026', 'status' => 'UPCOMING'],
    'related' => $enriched, 'degraded' => false,
];
$seo = [
    'title' => 'GENESIS', 'description' => 'Synthetic fixture',
    'canonical' => 'https://www.brvtal.com.co/events/genesis',
    'image' => '/uploads/cover.svg', 'schema' => ['@type' => 'MusicEvent'],
];
$html = brvtal_public_entity_page($page, $seo);
entityCardExpect(substr_count($html, 'class="entity-card entity-card--artist"') === 3, 'three artist cards have portrait class');
entityCardExpect(str_contains($html, '--artist-card-desktop-x:28.5%'), 'desktop X encoded as validated CSS');
entityCardExpect(str_contains($html, '--artist-card-desktop-y:15%'), 'desktop Y encoded as validated CSS');
entityCardExpect(str_contains($html, '--artist-card-mobile-x:65%'), 'mobile X encoded as validated CSS');
entityCardExpect(str_contains($html, '--artist-card-mobile-y:7%'), 'mobile Y encoded as validated CSS');
entityCardExpect(!str_contains($html, '--artist-card-desktop-zoom'), 'zoom not applied to cards');
entityCardExpect(!str_contains($html, 'artist_card_id'), 'internal artist ID not rendered');
entityCardExpect(str_contains($html, 'class="entity-card-mark"'), 'placeholder preserved');
entityCardExpect(str_contains($html, 'class="entity-card" href="/sets/set"'), 'nonartist cards preserve class');
entityCardExpect(str_contains($html, 'style="--artist-card-desktop-x:28.5%'), 'only saved artist framing has inline style');
entityCardExpect(!str_contains($html, '500%'), 'unbounded framing not rendered');

echo "Artist entity card framing contract passed.\n";
