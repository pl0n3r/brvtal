<?php
declare(strict_types=1);

function memories_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('MEMORIES CONTRACT FAILED: ' . $message);
    }
}

$root = dirname(__DIR__);
$migration = (string)file_get_contents($root . '/database/migration_memories_01.sql');
$adminApi = (string)file_get_contents($root . '/api/memories.php');
$publicApi = (string)file_get_contents($root . '/api/public.php');
$integrity = (string)file_get_contents($root . '/config/media_integrity.php');
$adminFragment = (string)file_get_contents($root . '/discadmin/memories.php');
$adminJs = (string)file_get_contents($root . '/discadmin/memories.js');
$publicJs = (string)file_get_contents($root . '/js/public-media.js');
$publicCss = (string)file_get_contents($root . '/css/public-memories.css');

memories_assert(str_contains($migration, 'CREATE TABLE IF NOT EXISTS memories'), 'migration must create memories table');
memories_assert(str_contains($migration, 'UNIQUE KEY uniq_memories_media (media_id)'), 'one source asset must map to at most one Memory');
memories_assert(str_contains($migration, 'FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE RESTRICT'), 'source Media deletion must be restricted');
memories_assert(str_contains($migration, "status ENUM('draft','published')"), 'Memories need explicit publication state');
memories_assert(str_contains($migration, 'sort_order INT NOT NULL DEFAULT 0'), 'Memories need explicit ordering');

memories_assert(str_contains($adminApi, 'brvtal_admin_require();'), 'admin API must require authentication');
memories_assert(substr_count($adminApi, 'brvtal_admin_require_csrf();') >= 3, 'every Memories mutation must require CSRF');
memories_assert(str_contains($adminApi, "['image','video','audio']"), 'admin curation must allow only supported public media types');
memories_assert(str_contains($adminApi, "MEDIA_NOT_PUBLIC"), 'published Memory must require published source media');
memories_assert(str_contains($adminApi, 'DELETE FROM memories WHERE id=?'), 'removal must delete only the curated Memory row');
memories_assert(!str_contains($adminApi, 'DELETE FROM media'), 'Memories API must never delete Media Library assets');

memories_assert(str_contains($publicApi, 'function brvtal_public_memories'), 'canonical public API must own Memories delivery');
memories_assert(str_contains($publicApi, "m.status='published'"), 'draft Memories must stay private');
memories_assert(str_contains($publicApi, "media.status='published'"), 'draft/private source media must stay private');
memories_assert(str_contains($publicApi, "media.type IN ('image','video','audio')"), 'public Memories type allowlist must be explicit');
memories_assert(str_contains($publicApi, 'ORDER BY m.sort_order ASC,m.id ASC'), 'public Memories must preserve editorial order');
memories_assert(str_contains($publicApi, "'memories' => \$memories"), 'canonical public envelope must expose curated Memories');

memories_assert(str_contains($integrity, "'resource' => 'MEMORY'"), 'Media integrity must surface Memory references');
memories_assert(str_contains($integrity, 'brvtal_media_memory_usage'), 'Media deletion guard must check Memories');

memories_assert(str_contains($adminFragment, 'data-admin-module="memories"'), 'Memories must render as a DISCADMIN workspace module');
memories_assert(str_contains($adminFragment, 'data-memories-add'), 'Memories workspace needs an ADD MEMORY action');
memories_assert(str_contains($adminJs, "text:'MEMORIES'"), 'Memories must be reachable from the admin navigation');
memories_assert(str_contains($adminJs, "url.searchParams.set('module','media')"), 'Memories must remain inside the canonical MEDIA route family');
memories_assert(str_contains($adminJs, "url.searchParams.set('view','memories')"), 'Memories deep link must preserve the same shell');
memories_assert(str_contains($adminJs, "api('available')"), 'ADD MEMORY must select from existing Media Library assets');
memories_assert(!str_contains($adminJs, 'FormData('), 'Memories curation must not introduce a second upload path');
memories_assert(!str_contains($adminJs, '.innerHTML'), 'admin Memories must not inject API data through innerHTML');
memories_assert(str_contains($adminJs, 'replaceChildren'), 'admin Memories should render through DOM nodes');
memories_assert(str_contains($adminJs, "type === 'audio' ? 'AUDIO SIGNAL' : 'MEDIA UNAVAILABLE'"), 'admin preview must distinguish unavailable non-audio media from audio assets');

memories_assert(str_contains($publicJs, 'event.detail?.memories'), 'Home Memories must consume the curated API collection');
memories_assert(!str_contains($publicJs, 'event.detail?.media || []'), 'Home Memories must not fall back to the full Media Library collection');
memories_assert(str_contains($publicJs, 'Previous memory'), 'viewer must navigate across Memories');
memories_assert(str_contains($publicJs, 'publicMediaContext'), 'viewer must expose optional editorial context');
memories_assert(!str_contains($publicJs, '.innerHTML'), 'public Memories must not inject API data through innerHTML');
memories_assert(str_contains($publicJs, 'replaceChildren'), 'public Memories should render through DOM nodes');
memories_assert(str_contains($publicCss, 'grid-template-columns:repeat(2,minmax(0,1fr))'), 'mobile Memories must keep a two-column editorial rhythm');
memories_assert(str_contains($publicCss, 'grid-column:1/-1!important'), 'mobile layout must support deliberate full-span Memories');

fwrite(STDOUT, "BRVTAL curated Memories contract passed.\n");
