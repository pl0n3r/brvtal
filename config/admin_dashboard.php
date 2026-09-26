<?php
declare(strict_types=1);

/**
 * Canonical DISCADMIN configurable-workspace preference contract.
 *
 * Dashboard and System Status share one validated layout model and the private
 * settings table. They remain namespaced per administrator and never pass
 * through the generic Settings editor.
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

/** @return array<string,array{id:string,width:int,height:int,visible:bool}> */
function brvtalAdminSystemStatusCatalog(): array
{
    return [
        'services' => ['id'=>'services','width'=>4,'height'=>1,'visible'=>true],
        'storage' => ['id'=>'storage','width'=>2,'height'=>1,'visible'=>true],
        'database' => ['id'=>'database','width'=>2,'height'=>1,'visible'=>true],
        'repository' => ['id'=>'repository','width'=>2,'height'=>1,'visible'=>true],
        'editorial' => ['id'=>'editorial','width'=>2,'height'=>1,'visible'=>true],
        'runtime' => ['id'=>'runtime','width'=>2,'height'=>1,'visible'=>true],
        'attention' => ['id'=>'attention','width'=>2,'height'=>1,'visible'=>true],
        'activity' => ['id'=>'activity','width'=>4,'height'=>1,'visible'=>true],
    ];
}

/** @return array<string,array{id:string,width:int,height:int,visible:bool}> */
function brvtalAdminWorkspaceCatalog(string $workspace): array
{
    return match ($workspace) {
        'dashboard' => brvtalAdminDashboardCatalog(),
        'system_status' => brvtalAdminSystemStatusCatalog(),
        default => throw new InvalidArgumentException('INVALID_DASHBOARD_WORKSPACE'),
    };
}

function brvtalAdminWorkspaceSettingKey(int $adminId, string $workspace): string
{
    if ($adminId < 1) {
        throw new InvalidArgumentException('INVALID_DASHBOARD_CONTEXT');
    }
    return match ($workspace) {
        'dashboard' => 'admin.dashboard.' . $adminId . '.layout',
        'system_status' => 'admin.dashboard.' . $adminId . '.system_status_layout',
        default => throw new InvalidArgumentException('INVALID_DASHBOARD_WORKSPACE'),
    };
}

function brvtalAdminDashboardSettingKey(int $adminId): string
{
    return brvtalAdminWorkspaceSettingKey($adminId, 'dashboard');
}

function brvtalAdminSystemStatusSettingKey(int $adminId): string
{
    return brvtalAdminWorkspaceSettingKey($adminId, 'system_status');
}

/** @return array{modules:array<int,array{id:string,width:int,height:int,visible:bool}>} */
function brvtalAdminWorkspaceDefaultPreferences(string $workspace): array
{
    return ['modules'=>array_values(brvtalAdminWorkspaceCatalog($workspace))];
}

/** @return array{modules:array<int,array{id:string,width:int,height:int,visible:bool}>} */
function brvtalAdminDashboardDefaultPreferences(): array
{
    return brvtalAdminWorkspaceDefaultPreferences('dashboard');
}

/** @return array{modules:array<int,array{id:string,width:int,height:int,visible:bool}>} */
function brvtalAdminSystemStatusDefaultPreferences(): array
{
    return brvtalAdminWorkspaceDefaultPreferences('system_status');
}

function brvtalAdminDashboardNormalizeSpan(mixed $value, int $default, array $allowed, string $error): int
{
    if ($value === null) return $default;
    if (is_int($value)) {
        $normalized = $value;
    } elseif (is_string($value) && ctype_digit($value)) {
        $normalized = (int)$value;
    } else {
        throw new InvalidArgumentException($error);
    }
    if (!in_array($normalized, $allowed, true)) {
        throw new InvalidArgumentException($error);
    }
    return $normalized;
}

function brvtalAdminDashboardNormalizeVisible(
    mixed $value,
    bool $default,
    string $error = 'INVALID_DASHBOARD_VISIBILITY'
): bool
{
    if ($value === null) return $default;
    if (!is_bool($value)) {
        throw new InvalidArgumentException($error);
    }
    return $value;
}

/**
 * @param array<string,mixed> $input
 * @return array{modules:array<int,array{id:string,width:int,height:int,visible:bool}>}
 */
function brvtalAdminWorkspaceNormalizePreferences(array $input, string $workspace): array
{
    $catalog = brvtalAdminWorkspaceCatalog($workspace);
    $prefix = $workspace === 'dashboard' ? 'DASHBOARD' : 'SYSTEM_STATUS';
    $requested = $input['modules'] ?? null;
    if ($requested === null) {
        return brvtalAdminWorkspaceDefaultPreferences($workspace);
    }
    if (!is_array($requested)) {
        throw new InvalidArgumentException('INVALID_' . $prefix . '_MODULES');
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
            'width'=>brvtalAdminDashboardNormalizeSpan(
                $item['width'] ?? null,
                $catalog[$id]['width'],
                [1,2,3,4],
                'INVALID_' . $prefix . '_WIDTH'
            ),
            'height'=>brvtalAdminDashboardNormalizeSpan(
                $item['height'] ?? null,
                $catalog[$id]['height'],
                [1,2],
                'INVALID_' . $prefix . '_HEIGHT'
            ),
            'visible'=>brvtalAdminDashboardNormalizeVisible(
                $item['visible'] ?? null,
                $catalog[$id]['visible'],
                'INVALID_' . $prefix . '_VISIBILITY'
            ),
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

/** @param array<string,mixed> $input */
function brvtalAdminDashboardNormalizePreferences(array $input): array
{
    return brvtalAdminWorkspaceNormalizePreferences($input, 'dashboard');
}

/** @param array<string,mixed> $input */
function brvtalAdminSystemStatusNormalizePreferences(array $input): array
{
    return brvtalAdminWorkspaceNormalizePreferences($input, 'system_status');
}
