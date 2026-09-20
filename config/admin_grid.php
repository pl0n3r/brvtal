<?php
declare(strict_types=1);

/**
 * Canonical DISCADMIN data-grid preference contract.
 *
 * Preferences intentionally reuse the private settings table so v0.1.23 does
 * not require a production migration. Keys are namespaced by administrator
 * and module and are never exposed through the generic Settings editor.
 */

/** @return array<string,array<int,string>> */
function brvtal_admin_grid_columns(): array
{
    return [
        'events' => ['primary','date','location','status'],
        'artists' => ['primary','links','status','position'],
        'releases' => ['primary','artists','type','date','status','position'],
        'sets' => ['primary','artist','event','status','position'],
        'media' => ['primary','type','mime','size','status'],
        'pages' => ['primary','locale','status'],
        'blog' => ['primary','excerpt','published','status','position'],
    ];
}

function brvtal_admin_grid_setting_key(int $adminId, string $module): string
{
    $columns = brvtal_admin_grid_columns();
    if ($adminId < 1 || !array_key_exists($module, $columns)) {
        throw new InvalidArgumentException('INVALID_GRID_CONTEXT');
    }

    return 'admin.grid.' . $adminId . '.' . $module;
}

/** @return array<int,string> */
function brvtal_admin_grid_default_columns(string $module): array
{
    $columns = brvtal_admin_grid_columns();
    if (!array_key_exists($module, $columns)) {
        throw new InvalidArgumentException('INVALID_GRID_MODULE');
    }
    return $columns[$module];
}

/**
 * @param array<string,mixed> $input
 * @return array{columns:array<int,string>}
 */
function brvtal_admin_grid_normalize_preferences(string $module, array $input): array
{
    $allowed = brvtal_admin_grid_default_columns($module);
    $requested = $input['columns'] ?? $allowed;
    if (!is_array($requested)) {
        throw new InvalidArgumentException('INVALID_GRID_COLUMNS');
    }

    $selected = [];
    foreach ($requested as $column) {
        if (!is_string($column) || !in_array($column, $allowed, true) || in_array($column, $selected, true)) {
            continue;
        }
        $selected[] = $column;
    }

    if ($selected === []) {
        $selected[] = $allowed[0];
    }

    return ['columns' => $selected];
}
