<?php
declare(strict_types=1);

/**
 * Return whether an administrator still exists and is active.
 *
 * This helper is deliberately PDO-only so the account-state policy can be
 * exercised independently from PHP session globals. Callers decide how to
 * handle database unavailability; the authentication boundary must fail closed.
 */
function brvtal_admin_account_is_active(PDO $pdo, int $adminId): bool
{
    if ($adminId < 1) {
        return false;
    }

    $st = $pdo->prepare('SELECT is_active FROM admins WHERE id=? LIMIT 1');
    $st->execute([$adminId]);
    $active = $st->fetchColumn();

    return $active !== false && (int)$active === 1;
}
