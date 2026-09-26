<?php
declare(strict_types=1);

/**
 * Return whether an administrator still exists and is active.
 *
 * This helper is deliberately PDO-only so the account-state policy can be
 * exercised independently from PHP session globals. Callers decide how to
 * handle database unavailability; the authentication boundary must fail closed.
 */
/** @return array{is_active:bool,credential_epoch:int}|null */
function brvtal_admin_account_session_state(PDO $pdo, int $adminId): ?array
{
    if ($adminId < 1) {
        return null;
    }

    $st = $pdo->prepare('SELECT is_active,credential_epoch FROM admins WHERE id=? LIMIT 1');
    $st->execute([$adminId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }

    return [
        'is_active' => (int)($row['is_active'] ?? 0) === 1,
        'credential_epoch' => max(1, (int)($row['credential_epoch'] ?? 0)),
    ];
}

function brvtal_admin_account_is_active(PDO $pdo, int $adminId): bool
{
    $state = brvtal_admin_account_session_state($pdo, $adminId);
    return $state !== null && $state['is_active'];
}
