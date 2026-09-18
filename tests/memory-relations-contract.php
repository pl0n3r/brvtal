<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/memory_relations.php';

function memory_rel_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "MEMORY RELATIONS CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$schema = (string)file_get_contents(__DIR__ . '/../database/schema.sql');
$migration = (string)file_get_contents(__DIR__ . '/../database/migration_memory_relations_01.sql');
$adminApi = (string)file_get_contents(__DIR__ . '/../api/memories.php');
$adminJs = (string)file_get_contents(__DIR__ . '/../discadmin/memories.js');
$publicApi = (string)file_get_contents(__DIR__ . '/../api/public.php');
$publicPage = (string)file_get_contents(__DIR__ . '/../config/public_page.php');
$publicMedia = (string)file_get_contents(__DIR__ . '/../js/public-media.js');
$connected = (string)file_get_contents(__DIR__ . '/../js/related-content.js');

memory_rel_expect(str_contains($schema, 'CREATE TABLE memory_relations'), 'base schema must include memory_relations');
memory_rel_expect(str_contains($migration, 'CREATE TABLE IF NOT EXISTS memory_relations'), 'deploy migration must be additive/idempotent');
memory_rel_expect(str_contains($migration, 'memory_id INT UNSIGNED NOT NULL'), 'relation owner must be the curated Memory');
memory_rel_expect(str_contains($migration, "ENUM('event','artist','set','release')"), 'relation types must be bounded');
memory_rel_expect(str_contains($migration, 'PRIMARY KEY (memory_id, related_type, related_id)'), 'duplicate semantic edges must be impossible');
memory_rel_expect(str_contains($migration, 'REFERENCES memories(id) ON DELETE CASCADE'), 'deleting curation must cascade relation rows');
memory_rel_expect(!str_contains($migration, 'FOREIGN KEY (related_id)'), 'polymorphic target integrity stays application-side');

$normalized = brvtal_memory_normalize_relations([
    ['related_type'=>'event','related_id'=>'2','sort_order'=>99],
    ['related_type'=>'artist','related_id'=>3],
    ['related_type'=>'event','related_id'=>2],
]);
memory_rel_expect(count($normalized) === 2, 'normalizer must deduplicate type/id pairs');
memory_rel_expect($normalized[0]['sort_order'] === 0 && $normalized[1]['sort_order'] === 1, 'server owns deterministic relation order');
foreach ([0,-1,1.5,true,'1foo'] as $invalid) {
    $rejected = false;
    try { brvtal_memory_relation_id($invalid); } catch (InvalidArgumentException) { $rejected = true; }
    memory_rel_expect($rejected, 'malformed relation IDs must be rejected');
}

memory_rel_expect(str_contains($adminApi, 'brvtal_admin_require();'), 'Memories API remains authenticated');
memory_rel_expect(str_contains($adminApi, 'brvtal_admin_require_csrf();'), 'Memory mutations require CSRF');
memory_rel_expect(str_contains($adminApi, "action === 'catalog'"), 'Memories API exposes the bounded admin relation catalog');
memory_rel_expect(str_contains($adminApi, 'beginTransaction()') && str_contains($adminApi, 'brvtal_memory_replace_relations'), 'Memory and relation writes must share a transaction');
memory_rel_expect(str_contains($adminApi, "array_key_exists('relations', \$input)"), 'missing relation payload must preserve existing links during migration rollout');
memory_rel_expect(str_contains($adminJs, 'data-memory-relation-type') && str_contains($adminJs, 'collectRelations'), 'Memories workspace owns relation editing');
memory_rel_expect(str_contains($publicApi, 'brvtal_public_attach_memory_relations'), 'public payload must sanitize Memory relations against public pools');
memory_rel_expect(str_contains($publicApi, 'brvtal_public_add_memory_edges'), 'CONNECTED graph must receive Memory edges');
memory_rel_expect(str_contains($publicPage, 'brvtal_public_memories_for_entity'), 'canonical entity pages must surface explicit published Memories');
memory_rel_expect(str_contains($publicMedia, 'relationContextNode') && str_contains($publicMedia, 'route_type'), 'Home Memories must expose canonical context links');
memory_rel_expect(str_contains($connected, "group('MEMORIES'") && str_contains($connected, 'memoryItem'), 'CONNECTED detail must expose Memory edges without a fifth tab');

echo "BRVTAL Memory relations contract passed.\n";
