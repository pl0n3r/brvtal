<?php
declare(strict_types=1);

/**
 * Return the current administrator authentication state.
 *
 * The credential epoch is incremented whenever a password credential changes.
 * Sessions pin the epoch observed at login and fail closed when it changes.
 *
 * @return array{active:bool,credential_epoch:int}|null
 */
function brvtal_admin_account_state(PDO $pdo, int $adminId): ?array
{
    if ($adminId < 1) {
        return null;
    }

    $st = $pdo->prepare(
        'SELECT is_active, credential_epoch FROM admins WHERE id=? LIMIT 1'
    );
    $st->execute([$adminId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }

    return [
        'active' => (int)($row['is_active'] ?? 0) === 1,
        'credential_epoch' => max(1, (int)($row['credential_epoch'] ?? 1)),
    ];
}

function brvtal_admin_account_is_active(PDO $pdo, int $adminId): bool
{
    $state = brvtal_admin_account_state($pdo, $adminId);
    return $state !== null && $state['active'];
}
