<?php
declare(strict_types=1);

function gs_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "GLOBAL SEARCH CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$api = file_get_contents(__DIR__ . '/../api/admin-search.php');
$js = file_get_contents(__DIR__ . '/../discadmin/global-search.js');
$index = file_get_contents(__DIR__ . '/../discadmin/index.php');

gs_assert(is_string($api) && is_string($js) && is_string($index), 'required search files must exist');
gs_assert(str_contains($api, 'brvtal_admin_require();'), 'global search API must require admin authentication');
gs_assert(str_contains($api, "REQUEST_METHOD") && str_contains($api, "'GET'"), 'global search API must be read-only GET');
gs_assert(str_contains($api, 'prepare('), 'global search API must use prepared statements');
gs_assert(str_contains($api, 'mb_strlen($query) < 2'), 'global search must reject expensive one-character searches');

foreach (['events','artists','sets_media','media','pages','releases','blog_posts'] as $table) {
    gs_assert(str_contains($api, "'table' => '{$table}'"), "global search must cover {$table}");
}
foreach (['events','artists','sets','media','pages','releases','blog'] as $module) {
    gs_assert(str_contains($api, "'module' => '{$module}'"), "global search results must route to {$module}");
}

gs_assert(!preg_match('/\bFROM\s+admins\b/i', $api), 'global search must never search admins');
gs_assert(!preg_match('/\bFROM\s+settings\b/i', $api), 'global search must never search settings');
gs_assert(!preg_match('/\bFROM\s+analytics_events\b/i', $api), 'global search must never search analytics');
gs_assert(str_contains($js, '⌘K / CTRL K'), 'search UI must advertise keyboard shortcut');
gs_assert(str_contains($js, "event.ctrlKey") && str_contains($js, "event.metaKey"), 'search UI must support Ctrl/Cmd+K');
gs_assert(str_contains($js, "window.go(target.module)"), 'search results must use the canonical shell router');
gs_assert(str_contains($js, "role=\"dialog\"") || str_contains($js, "role=\\\"dialog\\\""), 'search overlay must expose dialog semantics');
gs_assert(str_contains($index, '/discadmin/global-search.js'), 'canonical /discadmin shell must load global search');

echo "BRVTAL Global Search contract tests passed.\n";
