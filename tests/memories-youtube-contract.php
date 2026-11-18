<?php
declare(strict_types=1);

function youtube_contract_assert(bool $condition, string $why): void
{
    if (!$condition) throw new RuntimeException('YOUTUBE MEMORY CONTRACT: ' . $why);
}
$root = dirname(__DIR__);
$api = (string)file_get_contents($root . '/api/memories.php');
$public = (string)file_get_contents($root . '/api/public.php');
$schema = (string)file_get_contents($root . '/database/schema.sql');
$migration = (string)file_get_contents($root . '/database/migration_memories_youtube_01.sql');

// Evaluate the exact production parser function without booting the protected admin endpoint.
$start = strpos($api, 'function brvtal_memories_youtube_id(');
$end = strpos($api, "\nfunction brvtal_memories_body(", $start === false ? 0 : $start);
youtube_contract_assert($start !== false && $end !== false, 'pure parser must be available');
eval(substr($api, $start, $end - $start));
$id = 'Ab3deF_90-x';
foreach ([
    "https://www.youtube.com/watch?v={$id}",
    "https://youtube.com/watch?v={$id}&t=30",
    "https://youtu.be/{$id}",
    "https://www.youtube.com/shorts/{$id}",
    "https://youtube.com/embed/{$id}",
] as $url) {
    youtube_contract_assert(brvtal_memories_youtube_id($url) === $id, 'approved YouTube form rejected: ' . $url);
}
foreach ([
    'https://youtube.com/watch?v=short',
    'https://youtu.be/Ab3deF_90-x00',
    'https://evil.example/watch?v=Ab3deF_90-x',
    'https://youtube.com.evil.example/watch?v=Ab3deF_90-x',
    'https://youtube.com/playlist?list=Ab3deF_90-x',
    'https://youtube.com/shorts/Ab3deF_90-x/extra',
    'https://youtube.com@evil.example/watch?v=Ab3deF_90-x',
    'javascript:alert(1)',
    'http://youtube.com/watch?v=Ab3deF_90-x',
    'https://youtube.com/watch?v=<script>',
] as $url) {
    try {
        brvtal_memories_youtube_id($url);
        throw new RuntimeException('invalid URL accepted: ' . $url);
    } catch (InvalidArgumentException $expected) {
        youtube_contract_assert($expected->getMessage() === 'INVALID_YOUTUBE_URL', 'wrong invalid URL rejection');
    }
}
youtube_contract_assert(brvtal_memories_youtube_id('') === null, 'empty edit must clear the video');
youtube_contract_assert(brvtal_memories_youtube_id(null) === null, 'null input must stay nullable');
youtube_contract_assert(str_contains($schema, 'youtube_id CHAR(11) CHARACTER SET ascii COLLATE ascii_bin NULL'), 'schema has nullable ID');
youtube_contract_assert(str_contains($migration, 'ADD COLUMN IF NOT EXISTS youtube_id'), 'migration is replay-safe and additive');
youtube_contract_assert(!str_contains($migration, 'UPDATE memories') && !str_contains($migration, 'DELETE FROM'), 'migration leaves existing rows untouched');
youtube_contract_assert(str_contains($api, 'YOUTUBE_COVER_MUST_BE_IMAGE'), 'admin requires image cover for YouTube');
youtube_contract_assert(str_contains($api, 'brvtal_admin_require_csrf();'), 'admin mutation remains CSRF-protected');
youtube_contract_assert(str_contains($api, 'm.youtube_id'), 'admin retrieves associated YouTube ID');
youtube_contract_assert(str_contains($api, '$youtubeReady && $youtubeProvided'), 'omitted YouTube field must not be rewritten on update');
youtube_contract_assert(str_contains($public, 'm.youtube_id IS NULL OR media.type=\'image\''), 'public query hides invalid YouTube covers');
youtube_contract_assert(str_contains($public, "m.status='published'") && str_contains($public, "media.status='published'"), 'public query protects draft memories and covers');
youtube_contract_assert(str_contains($public, "'m.youtube_id'"), 'public query delivers only DB-controlled identifiers');
fwrite(STDOUT, "YouTube memory validation, migration and publication contracts passed.\n");
