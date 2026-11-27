<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/owner_policies.php';

function owner_policies_expect(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$applied = brvtal_apply_owner_policies(['app' => ['timezone' => 'America/Bogota']]);
owner_policies_expect(($applied['forms']['admin_read_enabled'] ?? null) === true, 'forms inbox read is enabled by the owner policy');
owner_policies_expect(($applied['forms']['privacy_approved'] ?? null) === true, 'forms privacy approval follows the owner decision');
owner_policies_expect(($applied['forms']['admin_manage_enabled'] ?? null) === true, 'forms management is enabled by the owner policy');
owner_policies_expect($applied['app'] === ['timezone' => 'America/Bogota'], 'unrelated sections are untouched');

$server = brvtal_apply_owner_policies(['forms' => ['admin_read_enabled' => false, 'extra' => 1]]);
owner_policies_expect($server['forms']['admin_read_enabled'] === false, 'an explicit server value always wins');
owner_policies_expect($server['forms']['extra'] === 1 && $server['forms']['privacy_approved'] === true, 'only missing keys are filled');

$scalar = brvtal_apply_owner_policies(['forms' => 'disabled']);
owner_policies_expect($scalar['forms'] === 'disabled', 'a non-array server section is never overwritten');

$bootstrap = (string)file_get_contents(__DIR__ . '/../config/bootstrap.php');
owner_policies_expect(str_contains($bootstrap, 'brvtal_apply_owner_policies($config)'), 'bootstrap applies owner policies after loading config.php');

echo "Owner policies contract passed.\n";
