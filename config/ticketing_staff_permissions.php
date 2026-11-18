<?php
declare(strict_types=1);

/**
 * Ticketing preparation permissions. Every future write endpoint must enforce
 * this policy server-side; hiding a tab is never an authorization decision.
 * Buyer accounts are separate from the DISCADMIN staff session (D-060).
 */
function brvtalTicketingCan(string $role, string $capability): bool
{
    $grants = [
        'superadmin' => [
            'view', 'orders', 'entries', 'buyers', 'door', 'finance', 'promos',
            'promoters', 'mail', 'settings', 'reissue', 'void', 'refund',
            'courtesy', 'export',
        ],
        'admin' => ['view','orders','entries','buyers','door','finance','promos','promoters','mail','export'],
        'door' => ['view','door'],
    ];
    $role = strtolower(trim($role));
    if (in_array($role, ['root', 'owner', 'platform_owner'], true)) {
        $role = 'superadmin';
    }
    return in_array($capability, $grants[$role] ?? [], true);
}

/** @return array<string,string> Public, non-secret status. No provider is enabled here. */
function brvtalTicketingProviderStatus(): array
{
    return ['wompi'=>'disabled', 'resend'=>'disabled', 'health'=>'not_checked'];
}
