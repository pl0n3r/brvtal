<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/media-relations.php';
require_once __DIR__ . '/../api/public-memory-relations.php';

function media_rel_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "MEDIA RELATIONS CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$schema = (string)file_get_contents(__DIR__ . '/../database/schema.sql');
$migration = (string)file_get_contents(__DIR__ . '/../database/migration_media_relations_01.sql');
$adminEndpoint = (string)file_get_contents(__DIR__ . '/../api/media-context.php');
$adminScript = (string)file_get_contents(__DIR__ . '/../discadmin/media-relations.js');
$adminShell = (string)file_get_contents(__DIR__ . '/../discadmin/index.php');
$publicApi = (string)file_get_contents(__DIR__ . '/../api/public.php');
$publicScript = (string)file_get_contents(__DIR__ . '/../js/public-memory-relations.js');
$runtime = (string)file_get_contents(__DIR__ . '/../js/public-runtime-loader.js');
$publicIndex = (string)file_get_contents(__DIR__ . '/../index.php');

media_rel_expect(str_contains($schema, "CREATE TABLE media (\n  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY"), 'canonical Media id must remain unsigned');
media_rel_expect(str_contains($migration, 'CREATE TABLE IF NOT EXISTS media_relations'), 'migration must be additive/idempotent');
media_rel_expect(str_contains($migration, 'media_id INT UNSIGNED NOT NULL'), 'media relation FK must match the canonical Media id type');
media_rel_expect(str_contains($migration, "ENUM('event','artist','set','release')"), 'relation types must be bounded');
media_rel_expect(str_contains($migration, 'PRIMARY KEY (media_id,related_type,related_id)'), 'duplicate semantic edges must be impossible');
media_rel_expect(str_contains($migration, 'ON DELETE CASCADE'), 'deleting Media must cascade semantic relations');
media_rel_expect(!str_contains($migration, 'FOREIGN KEY (related_id)'), 'polymorphic target validation must remain app-side');

$normalized = brvtal_media_normalize_relations([
    ['related_type'=>'event','related_id'=>'2','sort_order'=>999],
    ['related_type'=>'artist','related_id'=>3],
    ['related_type'=>'event','related_id'=>2],
]);
media_rel_expect(count($normalized) === 2, 'normalizer must de-duplicate type/id pairs');
media_rel_expect($normalized[0]['sort_order'] === 0 && $normalized[1]['sort_order'] === 1, 'server must own deterministic sort order');
foreach ([0, -1, 1.5, true, '1foo'] as $invalid) {
    $rejected = false;
    try { brvtal_media_relation_id($invalid); } catch (InvalidArgumentException) { $rejected = true; }
    media_rel_expect($rejected, 'malformed relation IDs must be rejected');
}

media_rel_expect(str_contains($adminEndpoint, 'brvtal_admin_require();'), 'admin context endpoint must require authentication');
media_rel_expect(str_contains($adminEndpoint, 'brvtal_admin_require_csrf();'), 'admin mutation must require CSRF');
media_rel_expect(str_contains($adminEndpoint, 'beginTransaction()') && str_contains($adminEndpoint, 'brvtal_media_replace_relations'), 'metadata and relations must share one transaction');
media_rel_expect(str_contains($adminEndpoint, 'MEDIA_RELATIONS_NOT_READY'), 'source deploy before migration must fail honestly for relation writes');
media_rel_expect(str_contains($adminScript, "event.stopImmediatePropagation()"), 'context extension must replace legacy SAVE only when relation editor is active');
media_rel_expect(str_contains($adminScript, 'title: document.getElementById') && str_contains($adminScript, 'relations: collectRelations()'), 'combined save must submit metadata and relations together');
media_rel_expect(str_contains($adminShell, 'media-relations.js') && str_contains($adminShell, 'media-relations.css'), 'canonical DISCADMIN shell must load the Media context extension');

media_rel_expect(str_contains($publicApi, 'brvtal_public_attach_memory_relations'), 'public API must sanitize Memory relations against final public pools');
media_rel_expect(str_contains($publicApi, 'brvtal_public_add_memory_edges'), 'public graph must receive Memory edges');
media_rel_expect(str_contains($runtime, "'js/public-memory-relations.js'"), 'Memory context must load in the canonical public runtime');
media_rel_expect(!str_contains($publicScript, "fetch('/api/public.php") && !str_contains($publicScript, 'fetch("/api/public.php'), 'Memory runtime must not duplicate the CMS request');
media_rel_expect(str_contains($publicScript, 'data?.relations') && str_contains($publicScript, 'data?.media'), 'public runtime must consume structured Memory edges and Media from the shared payload');
media_rel_expect(str_contains($publicIndex, 'brvtal_public_memories_for_entity'), 'canonical entity pages must surface explicit Memories');
media_rel_expect(str_contains($publicIndex, 'public-memory-relations.css'), 'Home must load Memory relation presentation');

$graph = [
    'events' => ['10'=>['artists'=>[],'sets'=>[]]],
    'artists' => ['20'=>['events'=>[],'sets'=>[],'releases'=>[]]],
    'sets' => [],
    'releases' => [],
    'counts' => [],
];
$media = [[
    'id'=>5,
    'relations'=>[
        ['related_type'=>'event','related_id'=>10],
        ['related_type'=>'artist','related_id'=>20],
    ],
]];
$graph = brvtal_public_add_memory_edges($graph, $media);
media_rel_expect($graph['events']['10']['memories'] === [5], 'Event graph bucket must receive explicit Memory edge');
media_rel_expect($graph['artists']['20']['memories'] === [5], 'Artist graph bucket must receive explicit Memory edge');
media_rel_expect($graph['media']['5']['events'] === [10] && $graph['media']['5']['artists'] === [20], 'Memory graph bucket must point back to public entities');

$missingGraph = brvtal_public_add_memory_edges([
    'events'=>[], 'artists'=>[], 'sets'=>[], 'releases'=>[], 'counts'=>[]
], [[ 'id'=>6, 'relations'=>[['related_type'=>'event','related_id'=>999]] ]]);
media_rel_expect($missingGraph['media'] === [], 'Memory graph must not recreate edges to targets absent from public pools');

echo "BRVTAL Media relation contract tests passed.\n";
