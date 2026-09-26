<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_dashboard.php';

function dashboard_pref_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "DASHBOARD PREFERENCES CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

dashboard_pref_assert(
    brvtalAdminDashboardSettingKey(7) === 'admin.dashboard.7.layout',
    'preference key must be isolated by administrator'
);
$defaults = brvtalAdminDashboardDefaultPreferences();
dashboard_pref_assert(count($defaults['modules']) === 7, 'catalog must include six core modules plus Analytics');
dashboard_pref_assert(
    end($defaults['modules'])['id'] === 'analytics' && end($defaults['modules'])['visible'] === false,
    'Analytics must be available but hidden by default until configured'
);

$normalized = brvtalAdminDashboardNormalizePreferences([
    'modules'=>[
        ['id'=>'activity','width'=>99,'height'=>99,'visible'=>true],
        ['id'=>'activity','width'=>1,'height'=>1,'visible'=>false],
        ['id'=>'not-real','width'=>2,'height'=>1,'visible'=>true],
        ['id'=>'operations','width'=>1,'height'=>2,'visible'=>false],
    ],
]);
dashboard_pref_assert($normalized['modules'][0] === [
    'id'=>'activity','width'=>4,'height'=>2,'visible'=>true,
], 'normalization must keep first valid module and clamp spans');
dashboard_pref_assert($normalized['modules'][1] === [
    'id'=>'operations','width'=>1,'height'=>2,'visible'=>false,
], 'normalization must preserve validated visibility and spans');
dashboard_pref_assert(count($normalized['modules']) === 7, 'missing canonical modules must be restored');

$allHidden = brvtalAdminDashboardNormalizePreferences([
    'modules'=>array_map(
        static fn(array $item): array => ['id'=>$item['id'],'visible'=>false],
        $defaults['modules']
    ),
]);
dashboard_pref_assert($allHidden['modules'][0]['visible'] === true, 'at least one module must remain visible');

echo "BRVTAL Dashboard preferences contract passed.\n";
