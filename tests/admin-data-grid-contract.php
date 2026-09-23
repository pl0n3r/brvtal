<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config/admin_grid.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "Admin data-grid contract failed: {$message}\n");
        exit(1);
    }
};

$modules = brvtalAdminGridColumns();
$expected = ['events','artists','releases','sets','media','pages','blog'];
$assert(array_keys($modules) === $expected, 'canonical modules must cover the seven product-scope lists');
$assert(brvtalAdminGridSettingKey(7, 'events') === 'admin.grid.7.events', 'preference key must include admin and module');
$assert(
    brvtalAdminGridSettingKey(8, 'events') !== brvtalAdminGridSettingKey(7, 'events'),
    'preferences must be isolated per administrator'
);
$assert(
    brvtalAdminGridSettingKey(7, 'blog') !== brvtalAdminGridSettingKey(7, 'events'),
    'preferences must be isolated per module'
);

$normalized = brvtalAdminGridNormalizePreferences('blog', [
    'columns' => ['status','primary','status','not-real'],
]);
$assert($normalized['columns'] === ['status','primary'], 'normalization must preserve valid unique requested columns');
$reset = brvtalAdminGridNormalizePreferences('releases', []);
$assert($reset['columns'] === brvtalAdminGridDefaultColumns('releases'), 'missing preferences must restore module defaults');
$none = brvtalAdminGridNormalizePreferences('pages', ['columns'=>[]]);
$assert($none['columns'] === ['primary'], 'at least one data column must remain visible');

$js = (string)file_get_contents($root . '/discadmin/admin-data-grid.js');
$css = (string)file_get_contents($root . '/discadmin/admin-data-grid.css');
$api = (string)file_get_contents($root . '/api/admin-grid-preferences.php');
$index = (string)file_get_contents($root . '/discadmin/index.php');
$coreApi = (string)file_get_contents($root . '/api/index.php');

$assert(str_contains($js, "state.sortDirection = 'asc'") && str_contains($js, "state.sortDirection = 'desc'"), 'grid must implement tri-state sorting');
$assert(str_contains($js, 'data-grid-columns-reset'), 'grid must expose restore-default columns control');
$assert(str_contains($js, 'data-grid-select-all') && str_contains($js, 'data-grid-clear'), 'grid must expose select-all and clear-selection controls');
$assert(str_contains($js, 'BRVTALBulkActions?.open?.(state.module,[...selected])'), 'grid selection must reuse the safe bulk-actions engine');
$assert(str_contains($js, "releases: {") && str_contains($js, "blog: {") && str_contains($js, "media: {"), 'shared renderer must own Releases, Blog and Media configs');
$assert(in_array('collective', brvtalAdminGridDefaultColumns('artists'), true), 'Artist grid must expose canonical BRVTAL membership');
$assert(str_contains($js, 'data-grid-membership-filter') && str_contains($js, "state.membershipFilter"), 'Artist grid must expose useful membership filtering');
$assert(str_contains($js, 'data-grid-page-prev') && str_contains($js, 'data-grid-page-next'), 'shared grid must expose server-page navigation controls');
$assert(str_contains($js, 'state.options.pagination') && str_contains($js, 'state.options.onPageChange'), 'shared grid must consume canonical server pagination metadata/callbacks');
$assert(str_contains($api, 'brvtalAdminGridSettingKey($adminId, $module)'), 'preference endpoint must namespace persistence by authenticated admin');
$assert(str_contains($api, 'brvtal_admin_require_csrf()'), 'preference mutation must retain CSRF');
$assert(str_contains($coreApi, "setting_key NOT LIKE 'admin.grid.%'"), 'generic settings listing must hide private grid preferences');
$assert(substr_count($coreApi, "str_starts_with(\$key, 'admin.grid.')") >= 2, 'generic settings mutation paths must protect private grid preferences');
$assert(str_contains($index, '/discadmin/admin-data-grid.js') && str_contains($index, '/discadmin/admin-data-grid.css'), 'canonical shell must load shared data-grid assets');
$assert(str_contains($css, '@media(max-width:760px)'), 'data grid must define mobile behavior');
$assert(str_contains($css, '.admin-grid-sort:focus-visible'), 'sortable headers must expose keyboard focus');
$assert(str_contains($css, '.admin-grid-membership-filter'), 'Artist membership filter must have shared responsive styling');
$assert(str_contains($css, '.admin-grid-pagination'), 'server pagination must have shared responsive styling');

fwrite(STDOUT, "Admin data-grid contract OK\n");
