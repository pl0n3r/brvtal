<?php
declare(strict_types=1);

/**
 * Canonical DISCADMIN Dashboard preference contract.
 *
 * Preferences reuse the private settings table and are namespaced per
 * administrator. They never pass through the generic Settings editor.
 */

/** @return array<string,array{id:string,width:int,height:int,visible:bool}> */
function brvtalAdminDashboardCatalog(): array
{
    return [
        'next_event' => ['id'=>'next_event','width'=>2,'height'=>1,'visible'=>true],
        'attention' => ['id'=>'attention','width'=>2,'height'=>1,'visible'=>true],
        'drafts' => ['id'=>'drafts','width'=>2,'height'=>1,'visible'=>true],
        'operations' => ['id'=>'operations','width'=>2,'height'=>1,'visible'=>true],
        'activity' => ['id'=>'activity','width'=>2,'height'=>1,'visible'=>true],
        'quick_create' => ['id'=>'quick_create','width'=>2,'height'=>1,'visible'=>true],
        'analytics' => ['id'=>'analytics','width'=>2,'height'=>1,'visible'=>false],
    ];
}

function brvtalAdminDashboardSettingKey(int $adminId): string
{
    if ($adminId < 1) {
        throw new InvalidArgumentException('INVALID_DASHBOARD_CONTEXT');
    }
    return 'admin.dashboard.' . $adminId . '.layout';
}

/** @return array{modules:array<int,array{id:string,width:int,height:int,visible:bool}>} */
function brvtalAdminDashboardDefaultPreferences(): array
{
    return ['modules'=>array_values(brvtalAdminDashboardCatalog())];
}

/**
 * @param array<string,mixed> $input
 * @return array{modules:array<int,array{id:string,width:int,height:int,visible:bool}>}
 */
function brvtalAdminDashboardNormalizePreferences(array $input): array
{
    $catalog = brvtalAdminDashboardCatalog();
    $requested = $input['modules'] ?? null;
    if ($requested === null) {
        return brvtalAdminDashboardDefaultPreferences();
    }
    if (!is_array($requested)) {
        throw new InvalidArgumentException('INVALID_DASHBOARD_MODULES');
    }

    $modules = [];
    $seen = [];
    foreach ($requested as $item) {
        if (!is_array($item)) continue;
        $id = strtolower(trim((string)($item['id'] ?? '')));
        if (!isset($catalog[$id]) || isset($seen[$id])) continue;
        $seen[$id] = true;
        $modules[] = [
            'id'=>$id,
            'width'=>max(1, min(4, (int)($item['width'] ?? $catalog[$id]['width']))),
            'height'=>max(1, min(2, (int)($item['height'] ?? $catalog[$id]['height']))),
            'visible'=>array_key_exists('visible', $item) ? (bool)$item['visible'] : true,
        ];
    }

    foreach ($catalog as $id => $default) {
        if (!isset($seen[$id])) $modules[] = $default;
    }

    if (!array_filter($modules, static fn(array $item): bool => $item['visible'])) {
        $modules[0]['visible'] = true;
    }

    return ['modules'=>$modules];
}
