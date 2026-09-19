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

gs_assert(str_contains($api, "\$_GET['type']"), 'global search API must accept a content-type scope for progressive loading');
gs_assert(str_contains($api, "\$_GET['offset']"), 'global search API must accept an offset for progressive loading');
gs_assert(str_contains($api, 'brvtal_admin_search_count('), 'global search must compute exact match totals when a page can be truncated');
gs_assert(str_contains($api, 'OFFSET {$offset}'), 'global search rows must support deterministic pagination offsets');
gs_assert(str_contains($api, "'has_more' => \$visibleThrough < \$matchTotal"), 'global search groups must expose whether more matching records remain');
gs_assert(str_contains($api, '$total += $matchTotal;'), 'top-level total must sum exact group totals rather than the truncated page size');
gs_assert(str_contains($api, "'OFFSET_REQUIRES_TYPE'"), 'global pagination offsets must be scoped to one content type');

gs_assert(str_contains($js, '⌘K / CTRL K'), 'search UI must advertise keyboard shortcut');
gs_assert(str_contains($js, "dataset.globalSearchLocation = locationName"), 'global search must expose stable top/sidebar trigger locations');
gs_assert(str_contains($js, "document.querySelector('.side .sidefoot')"), 'global search must mount a persistent sidebar affordance');
gs_assert(str_contains($js, "logout.before(trigger)"), 'sidebar global search must sit immediately above Logout');
gs_assert(str_contains($js, "event.ctrlKey") && str_contains($js, "event.metaKey"), 'search UI must support Ctrl/Cmd+K');
gs_assert(str_contains($js, "window.go(target.module)"), 'search results must use the canonical shell router');
gs_assert(str_contains($js, "role=\"dialog\"") || str_contains($js, "role=\\\"dialog\\\""), 'search overlay must expose dialog semantics');
gs_assert(str_contains($js, 'data-search-more'), 'global search UI must render an explicit load-more control for truncated groups');
gs_assert(str_contains($js, 'async function loadMore('), 'global search UI must progressively fetch additional matches');
gs_assert(str_contains($js, "'&type=' + encodeURIComponent(type) + '&offset=' + offset"), 'load-more requests must keep pagination scoped to the selected content type');
gs_assert(str_contains($js, 'items.length} / ${total}'), 'group counters must distinguish loaded results from the exact match total');
gs_assert(str_contains($index, '/discadmin/global-search.js'), 'canonical /discadmin shell must load global search');

echo "BRVTAL Global Search contract tests passed.\n";
