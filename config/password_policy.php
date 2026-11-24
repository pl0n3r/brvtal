<?php
declare(strict_types=1);

/**
 * Shared pure password policy for buyer and administrator accounts.
 * No DB, sessions, payment, mail, TOTP or bootstrap dependencies.
 */
function brvtal_admin_password_error(string $password, string $currentHash = ''): ?string
{
    if (mb_strlen($password) < 12) {
        return 'PASSWORD_TOO_SHORT';
    }
    if (mb_strlen($password) > 4096) {
        return 'PASSWORD_TOO_LONG';
    }
    if ($currentHash !== '' && password_verify($password, $currentHash)) {
        return 'PASSWORD_REUSED';
    }

    $normalized = strtolower(trim($password));
    $common = [
        'password1234', 'password123!', 'admin123456', 'qwerty123456',
        '123456789012', 'letmein123456', 'brvtal123456',
    ];
    if (in_array($normalized, $common, true)) {
        return 'PASSWORD_COMMON';
    }
    return null;
}
