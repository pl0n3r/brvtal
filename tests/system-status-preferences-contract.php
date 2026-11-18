<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_dashboard.php';

function system_pref_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "SYSTEM STATUS PREFERENCES CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

system_pref_assert(
    brvtalAdminSystemStatusSettingKey(9) === 'admin.dashboard.9.system_status_layout',
    'System Status layout must be isolated by administrator inside the protected dashboard namespace'
);
$defaults = brvtalAdminSystemStatusDefaultPreferences();
system_pref_assert(count($defaults['modules']) === 8, 'System Status must expose eight canonical modules');
system_pref_assert($defaults['modules'][0]['id'] === 'services' && $defaults['modules'][0]['width'] === 4, 'service checks must default to full width');
$normalized = brvtalAdminSystemStatusNormalizePreferences([
    'modules'=>[
        ['id'=>'storage','width'=>4,'height'=>2,'visible'=>true],
        ['id'=>'storage','width'=>1,'height'=>1,'visible'=>false],
        ['id'=>'not-real','width'=>1,'height'=>1,'visible'=>true],
        ['id'=>'runtime','width'=>1,'height'=>1,'visible'=>false],
    ],
]);
system_pref_assert($normalized['modules'][0] === ['id'=>'storage','width'=>4,'height'=>2,'visible'=>true], 'first canonical module occurrence must win');
system_pref_assert($normalized['modules'][1] === ['id'=>'runtime','width'=>1,'height'=>1,'visible'=>false], 'validated spans and visibility must persist');
system_pref_assert(count($normalized['modules']) === 8, 'missing canonical modules must be restored');

foreach ([
    [['id'=>'storage','width'=>9,'height'=>1,'visible'=>true], 'INVALID_SYSTEM_STATUS_WIDTH'],
    [['id'=>'storage','width'=>2,'height'=>9,'visible'=>true], 'INVALID_SYSTEM_STATUS_HEIGHT'],
    [['id'=>'storage','width'=>2,'height'=>1,'visible'=>'false'], 'INVALID_SYSTEM_STATUS_VISIBILITY'],
] as [$invalidModule, $expectedError]) {
    try {
        brvtalAdminSystemStatusNormalizePreferences(['modules'=>[$invalidModule]]);
        system_pref_assert(false, $expectedError . ' must fail closed');
    } catch (InvalidArgumentException $error) {
        system_pref_assert($error->getMessage() === $expectedError, $expectedError . ' must be explicit');
    }
}

$hidden = brvtalAdminSystemStatusNormalizePreferences([
    'modules'=>array_map(static fn(array $item): array => ['id'=>$item['id'],'visible'=>false], $defaults['modules']),
]);
system_pref_assert($hidden['modules'][0]['visible'] === true, 'at least one System Status module must remain visible');

echo "BRVTAL System Status preferences contract passed.\n";
