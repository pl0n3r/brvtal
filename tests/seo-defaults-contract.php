<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/seo_defaults.php';

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
seo_defaults_assert(str_contains($api, 'brvtal_seo_default_description($locked[\'source_description\'] ?? \'\', 160)'), 'SEO persistence must cap automatic descriptions at 160');

$adminDefaults = (string)file_get_contents(__DIR__ . '/../discadmin/seo-editorial-defaults.js');
seo_defaults_assert(str_contains($adminDefaults, "url.pathname.endsWith('/api/blog.php')"), 'Blog saves must receive editorial SEO defaults');
seo_defaults_assert(str_contains($adminDefaults, '/api\\/index\\.php\\/pages'), 'Page saves must receive editorial SEO defaults');
seo_defaults_assert(str_contains($adminDefaults, 'AUTO_DESCRIPTION_LIMIT = 160'), 'admin automatic descriptions must use the 160-character limit');

$entry = (string)file_get_contents(__DIR__ . '/../discadmin/index.php');
$defaultsPos = strpos($entry, '/discadmin/seo-editorial-defaults.js');
$metadataPos = strpos($entry, '/discadmin/seo-metadata.js');
seo_defaults_assert($defaultsPos !== false, 'DISCADMIN must load the SEO defaults enhancement');
seo_defaults_assert($metadataPos !== false && $defaultsPos < $metadataPos, 'SEO defaults must wrap fetch before the metadata persistence enhancement');

$publicSeo = (string)file_get_contents(__DIR__ . '/../config/public_seo.php');
seo_defaults_assert(str_contains($publicSeo, "'pages' => ['pages', 'title', 'content_json'"), 'Pages must derive public fallback descriptions from content JSON');
seo_defaults_assert(str_contains($publicSeo, "brvtal_seo_default_description(\$entity['description'] ?? '', 160)"), 'public automatic descriptions must use the SEO cap');

echo "BRVTAL SEO editorial defaults contract tests passed.\n";
