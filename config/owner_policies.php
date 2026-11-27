<?php
declare(strict_types=1);

/**
 * Owner-approved activations, versioned so they ship through GitHub -> Hostinger
 * and stay traceable. Each value only fills a key that config/config.php leaves
 * unset: an explicit server value (for example false) always wins, so the server
 * can switch any of these off without a deploy.
 */
function brvtal_owner_policies(): array
{
    return [
        // #2332: DISCADMIN Formularios inbox, owner/superadmin only. The owner
        // activated Contact capture on 2026-11-27 (#2321, decision on #1671).
        'forms' => [
            'admin_read_enabled' => true,
            'privacy_approved' => true,
            'admin_manage_enabled' => true,
        ],
    ];
}

function brvtal_apply_owner_policies(array $config): array
{
    foreach (brvtal_owner_policies() as $section => $values) {
        $current = $config[$section] ?? [];
        if (!is_array($current)) {
            continue;
        }
        foreach ($values as $key => $value) {
            if (!array_key_exists($key, $current)) {
                $current[$key] = $value;
            }
        }
        $config[$section] = $current;
    }
    return $config;
}
