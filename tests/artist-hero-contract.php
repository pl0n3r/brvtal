<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/public_visibility.php';
require_once __DIR__ . '/../config/public_seo.php';
require_once __DIR__ . '/../config/public_page.php';
require_once __DIR__ . '/../config/public_artist.php';

function artistHeroExpect(bool $condition, string $description): void
{
    if (!$condition) {
        fwrite(STDERR, "ARTIST HERO CONTRACT FAILED: {$description}\n");
        exit(1);
    }
}

$defaults = brvtalArtistHeroDefault();
artistHeroExpect($defaults['desktop']['zoom'] === 1.0 && $defaults['mobile']['x'] === 50.0, 'safe default');
$framing = [
    'mobile'=>['x'=>36,'y'=>18,'zoom'=>1.1],
    'desktop'=>['zoom'=>1.65,'x'=>74,'y'=>22],
];
$valid = brvtalArtistHeroValidate($framing);
artistHeroExpect(is_array($valid), 'allow arbitrary JSON key order with proper geometry');
artistHeroExpect($valid['desktop']['x'] === 74.0, 'normalize numeric values');
artistHeroExpect(brvtalArtistHeroValidate([
    'desktop'=>['zoom'=>4,'x'=>74,'y'=>50],
    'mobile'=>$defaults['mobile'],
]) === null, 'reject unbounded zoom');
artistHeroExpect(brvtalArtistHeroValidate([
    'desktop'=>['zoom'=>'1; background:red','x'=>74,'y'=>50],
    'mobile'=>$defaults['mobile'],
]) === null, 'reject CSS injection');
artistHeroExpect(brvtalArtistHeroValidate([
    'desktop'=>$defaults['desktop'],
    'mobile'=>['zoom'=>1,'x'=>101,'y'=>50],
]) === null, 'reject out-of-range position');
artistHeroExpect(brvtalArtistHeroValidate(['desktop'=>$defaults['desktop']]) === null, 'require both breakpoints');
$css = brvtalArtistHeroCssVariables($valid);
artistHeroExpect(str_contains($css, '--artist-hero-desktop-x:74%'), 'CSS desktop variable');
artistHeroExpect(str_contains($css, '--artist-hero-mobile-zoom:1.1'), 'CSS mobile variable');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE settings(setting_key TEXT PRIMARY KEY,setting_value TEXT,is_json INTEGER)');
artistHeroExpect(brvtalArtistHeroRead($pdo, 7) === $defaults, 'missing setting does not change default');
brvtalArtistHeroWrite($pdo, 7, $valid);
artistHeroExpect(brvtalArtistHeroRead($pdo, 7) === $valid, 'read/write round trip');
$rows = brvtalArtistHeroEnrich($pdo, [
    ['id'=>7,'name'=>'PL0N3R','photo'=>'/uploads/media/pl0n3r.jpg'],
    ['id'=>8,'name'=>'OTHER'],
]);
artistHeroExpect($rows[0]['hero_framing'] === $valid, 'enrich the intended artist');
artistHeroExpect($rows[1]['hero_framing'] === $defaults, 'other artists keep default');
artistHeroExpect(brvtalArtistHeroEnrich($pdo, ['id'=>7])['hero_framing'] === $valid, 'enrich single artist');
brvtalArtistHeroWrite($pdo, 7, $defaults);
artistHeroExpect(brvtalArtistHeroRead($pdo, 7) === $defaults, 'reset persisted geometry');
artistHeroExpect((int)$pdo->query("SELECT COUNT(*) FROM settings")->fetchColumn() === 0, 'reset removes redundant setting');

$head = '<link rel="stylesheet" href="/css/public-entity.css">';
$source = 'https://www.brvtal.com.co/uploads/media/pl0n3r.jpg';
$html = $head . '<body class="entity-page cromo-page"><div class="entity-image"><img src="https://www.brvtal.com.co/uploads/media/thumbnail--hero.webp" alt="PL0N3R"></div>';
$decorated = brvtal_public_artist_decorate_html($html, [
    'entity'=>[
        'id'=>7, 'route_type'=>'artists', 'is_collective_member'=>1,
        'image'=>$source,'hero_framing'=>$valid
    ]
]);
artistHeroExpect(str_contains($decorated, 'entity-page--artist-member'), 'profile gets artist-specific CSS class');
artistHeroExpect(str_contains($decorated, '--artist-hero-desktop-zoom:1.65'), 'profile gets persisted zoom');
artistHeroExpect(str_contains($decorated, 'src="' . $source . '"'), 'profile uses preserve-aspect source, not cropped hero');
artistHeroExpect(!str_contains($decorated, '--hero.webp'), 'cropped hero variant must not persist in profile');
artistHeroExpect(str_contains($decorated, '/css/public-roster.css'), 'profile loads roster-only CSS');

$api = (string)file_get_contents(__DIR__ . '/../api/index.php');
$admin = (string)file_get_contents(__DIR__ . '/../discadmin/index-core.php');
$payload = (string)file_get_contents(__DIR__ . '/../discadmin/admin-modules.js');
$runtime = (string)file_get_contents(__DIR__ . '/../discadmin/artist-hero-preview.js');
$cssSource = (string)file_get_contents(__DIR__ . '/../css/public-roster.css');
artistHeroExpect(str_contains($api, 'brvtalArtistHeroWrite($pdo, $id, $hero)')
    && str_contains($api, 'brvtalArtistHeroEnrich($pdo, $rows)'), 'artist API persists and returns hero fields');
artistHeroExpect(str_contains($api, "'INVALID_ARTIST_HERO_FRAMING'"), 'artist API rejects unsafe cropping');
artistHeroExpect(str_contains($admin, 'ENCUADRE DE PORTADA')
    && str_contains($admin, 'data-hero-switch="mobile"'), 'artist editor offers preview and mobile view');
artistHeroExpect(str_contains($payload, 'BRVTALArtistHeroPreview?.values()'), 'artist Save includes crop');
artistHeroExpect(str_contains($runtime, 'function safeImageUrl(')
    && str_contains($runtime, 'new URL(raw, window.location.origin)')
    && str_contains($runtime, 'img.src = safeUrl')
    && !str_contains($runtime, "img.setAttribute('src',safePath)"), 'preview must parse and validate URLs before assigning image property');
artistHeroExpect(str_contains($runtime, 'data-hero-reset')
    && str_contains($runtime, 'function sync(root)'), 'preview responds to changes');
artistHeroExpect(str_contains($cssSource, 'body.cromo-page.entity-page--artist .entity-hero .entity-image img'), 'only artist profile hero is styled');

echo "Artist hero framing contracts passed.\n";
