<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/seo_defaults.php';
require_once __DIR__ . '/../config/public_routes.php';

function seo_defaults_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "SEO DEFAULTS CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

seo_defaults_assert(brvtal_seo_default_title('Genesis') === 'Genesis', 'default SEO title must use the editorial title');

$html = '<p>Underground <strong>techno</strong> event   in Pereira.</p>';
seo_defaults_assert(
    brvtal_seo_default_description($html, 160) === 'Underground techno event in Pereira.',
    'default SEO description must strip markup and collapse whitespace'
);

$long = str_repeat('BRVTAL underground electronic music experience ', 10);
seo_defaults_assert(mb_strlen(brvtal_seo_default_description($long, 160)) <= 160, 'automatic SEO description must stay within 160 characters');

$pageJson = json_encode([
    'type'=>'hero',
    'title'=>'BRVTAL manifesto',
    'blocks'=>[
        ['type'=>'text','body'=>'Dark electronic music culture from Colombia.'],
        ['type'=>'image','src'=>'/uploads/cover.jpg','alt'=>'Poster'],
    ],
], JSON_UNESCAPED_SLASHES);
$pageText = brvtal_seo_default_description($pageJson, 160);
seo_defaults_assert(str_contains($pageText, 'BRVTAL manifesto'), 'page JSON fallback must extract editorial text');
seo_defaults_assert(str_contains($pageText, 'Dark electronic music culture from Colombia.'), 'page JSON fallback must include textual block content');
seo_defaults_assert(!str_contains($pageText, '/uploads/cover.jpg'), 'page JSON fallback must ignore media paths');

$api = (string)file_get_contents(__DIR__ . '/../api/seo-metadata.php');
seo_defaults_assert(str_contains($api, "'artists' => ['table'=>'artists','title'=>'name','description'=>'bio']"), 'Artists must default SEO from name and bio');
seo_defaults_assert(str_contains($api, "'events' => ['table'=>'events','title'=>'title','description'=>'description']"), 'Events must default SEO from title and description');
seo_defaults_assert(str_contains($api, "'sets' => ['table'=>'sets_media','title'=>'title','description'=>'description']"), 'Sets must default SEO from title and description');
seo_defaults_assert(str_contains($api, "'releases' => ['table'=>'releases','title'=>'title','description'=>'description']"), 'Releases must default SEO from title and description');
seo_defaults_assert(brvtalSeoOverrideValue('   ', 190) === null, 'blank SEO title override must remain absent');
seo_defaults_assert(brvtalSeoOverrideValue('<b>Manual title</b>', 190) === 'Manual title', 'manual SEO override must be normalized');
seo_defaults_assert(mb_strlen((string)brvtalSeoOverrideValue(str_repeat('description ', 50), 320)) <= 320, 'manual SEO override must respect its storage cap');
seo_defaults_assert(str_contains($api, 'brvtalSeoPersistOverrides($pdo, $resource, $id, $definition, $body)'), 'SEO PUT must use the tested persistence boundary');

$adminDefaults = (string)file_get_contents(__DIR__ . '/../discadmin/seo-editorial-defaults.js');
seo_defaults_assert(str_contains($adminDefaults, 'persistableValue'), 'DISCADMIN must expose mode-aware SEO persistence');
seo_defaults_assert(str_contains($adminDefaults, 'seoFallback'), 'DISCADMIN must keep automatic SEO as preview fallback state');
seo_defaults_assert(!str_contains($adminDefaults, 'window.fetch ='), 'automatic defaults must not rewrite Blog/Page mutation payloads');
seo_defaults_assert(str_contains($adminDefaults, 'AUTO_DESCRIPTION_LIMIT = 160'), 'admin automatic descriptions must use the 160-character limit');

$entry = (string)file_get_contents(__DIR__ . '/../discadmin/index.php');
$defaultsPos = strpos($entry, '/discadmin/seo-editorial-defaults.js');
$metadataPos = strpos($entry, '/discadmin/seo-metadata.js');
seo_defaults_assert($defaultsPos !== false, 'DISCADMIN must load the SEO defaults enhancement');
seo_defaults_assert($metadataPos !== false && $defaultsPos < $metadataPos, 'SEO defaults must wrap fetch before the metadata persistence enhancement');

$definitions = brvtal_public_content_definitions();
seo_defaults_assert(
    ($definitions['pages']['description_field'] ?? null) === 'content_json',
    'Pages must derive public fallback descriptions from content JSON'
);
$publicSeo = (string)file_get_contents(__DIR__ . '/../config/public_seo.php');
seo_defaults_assert(str_contains($publicSeo, "brvtal_seo_default_description(\$entity['description'] ?? '', 160)"), 'public automatic descriptions must use the SEO cap');

echo "BRVTAL SEO editorial defaults contract tests passed.\n";
