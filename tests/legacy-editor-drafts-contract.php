<?php
declare(strict_types=1);

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "Legacy editor drafts contract failed: {$message}\n");
        exit(1);
    }
};

$index = (string)file_get_contents(__DIR__ . '/../discadmin/index.php');
$core = (string)file_get_contents(__DIR__ . '/../discadmin/index-core.php');
$modules = (string)file_get_contents(__DIR__ . '/../discadmin/admin-modules.js');
$drafts = (string)file_get_contents(__DIR__ . '/../discadmin/legacy-editor-drafts.js');
$css = (string)file_get_contents(__DIR__ . '/../discadmin/legacy-editor-drafts.css');
$eventWorkflow = (string)file_get_contents(__DIR__ . '/../discadmin/event-workflow.js');
$releases = (string)file_get_contents(__DIR__ . '/../discadmin/releases.js');
$contentCore = (string)file_get_contents(__DIR__ . '/../discadmin/content-core.js');

$expect(str_contains($index, '/discadmin/legacy-editor-drafts.js'), 'shell must load the legacy draft adapter');
$expect(str_contains($index, '/discadmin/legacy-editor-drafts.css'), 'shell must load the legacy draft UI');
$expect(
    str_contains($core, 'BRVTALLegacyDrafts?.bind?.(type,id,r||{})'),
    'legacy openModal must bind the draft adapter after rendering the editor'
);
$expect(
    str_contains($modules, 'BRVTALLegacyDrafts?.serverSaved?.'),
    'manual Save must hand successful mutations back to the draft adapter'
);
$expect(
    str_contains($modules, 'BRVTALLegacyDrafts?.saveFailed?.'),
    'failed manual Save must preserve recoverable local state'
);
$expect(
    str_contains($core, 'BRVTALDrafts?.clearAll?.()'),
    'explicit logout must purge local editorial drafts'
);
$expect(
    str_contains($drafts, "['pages', {"),
    'legacy draft adapter must keep the validated Pages contract'
);
$expect(
    str_contains($drafts, "['artists', {"),
    'Artists rollout must reuse the legacy draft adapter'
);
$expect(
    str_contains($drafts, "is_collective_member")
        && str_contains($drafts, "instagram_url")
        && str_contains($drafts, "soundcloud_url"),
    'Artists draft contract must cover membership and social fields'
);
$expect(
    str_contains($drafts, "['sets', {"),
    'Sets rollout must reuse the legacy draft adapter'
);
$expect(
    str_contains($drafts, "artist_id")
        && str_contains($drafts, "event_id")
        && str_contains($drafts, "external_url"),
    'Sets draft contract must cover relations and public source fields'
);
$expect(
    str_contains($drafts, 'Object.entries(left).every'),
    'save comparison must ignore non-editable payload metadata such as visual sort_order'
);
$expect(
    !str_contains($drafts, 'fetch(') && !str_contains($drafts, 'XMLHttpRequest'),
    'draft autosave must not perform network mutations'
);
$expect(
    str_contains($drafts, 'sameData(current, payload)')
        && str_contains($drafts, 'newer edits remain unsaved'),
    'server Save must preserve edits made after the submitted payload'
);
$expect(
    str_contains($drafts, "['events', {"),
    'Events rollout must reuse the draft adapter without a second engine'
);
$expect(
    str_contains($drafts, "tickets")
        && str_contains($drafts, "lineup")
        && str_contains($drafts, "editorRoot(type)"),
    'Events drafts must cover the atomic editor and use its own modal root'
);
$expect(
    str_contains($eventWorkflow, "BRVTALLegacyDrafts?.bind?.")
        && str_contains($eventWorkflow, "BRVTALLegacyDrafts?.snapshot?.('events')")
        && str_contains($eventWorkflow, "BRVTALLegacyDrafts?.serverSaved?.")
        && str_contains($eventWorkflow, "BRVTALLegacyDrafts?.saveFailed?."),
    'Event workflow must hand open/save/failure lifecycle to the draft adapter'
);
$expect(
    str_contains($drafts, "['releases', {")
        && str_contains($drafts, "data-release-artist")
        && str_contains($drafts, "release_type"),
    'Releases rollout must reuse the draft adapter for editorial and artist fields'
);
$expect(
    str_contains($releases, "BRVTALLegacyDrafts?.bind?.('releases'")
        && str_contains($releases, "BRVTALLegacyDrafts?.serverSaved?.")
        && str_contains($releases, "BRVTALLegacyDrafts?.saveFailed?."),
    'Releases Save lifecycle must integrate with the draft adapter'
);
$expect(
    str_contains($drafts, 'result?.data?.id')
        && str_contains($drafts, "featured:document.getElementById('release_featured')?.checked ? 1 : 0")
        && str_contains($drafts, 'BRVTALReleases?.normalizeMediaPath'),
    'Releases must compare canonical payload values and adopt the server identity after create'
);
$expect(
    str_contains($contentCore, "whenEventReady:()=>eventEditorReady")
        && str_contains($contentCore, "getCurrentEvent:()=>currentEvent"),
    'Content Core must expose the stable loaded Event before draft recovery binds'
);
$expect(
    str_contains($css, '[data-legacy-draft-recovery][hidden]{display:none}'),
    'recovery UI must respect the hidden attribute'
);

fwrite(STDOUT, "Legacy editor drafts contract passed.\n");
