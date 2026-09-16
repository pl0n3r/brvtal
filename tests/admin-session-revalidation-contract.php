<?php
declare(strict_types=1);

function admin_session_contract_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("ADMIN SESSION REVALIDATION CONTRACT FAILED: {$message}");
    }
}

$auth = (string)file_get_contents(__DIR__ . '/../config/admin_auth.php');
$revalidation = (string)file_get_contents(__DIR__ . '/../config/admin_session_revalidation.php');

admin_session_contract_assert(
    str_contains($auth, "require_once __DIR__ . '/admin_session_revalidation.php';"),
    'admin auth must load the account-state revalidation policy'
);
admin_session_contract_assert(
    str_contains($revalidation, 'SELECT is_active FROM admins WHERE id=? LIMIT 1'),
    'account-state helper must query the canonical admins row by primary key'
);
admin_session_contract_assert(
    str_contains($revalidation, '$active !== false && (int)$active === 1'),
    'missing and inactive admins must both fail revalidation'
);
admin_session_contract_assert(
    str_contains($auth, '$adminId = (int)($_SESSION[\'admin_id\'] ?? 0);'),
    'session authorization must normalize the current admin id before revalidation'
);
admin_session_contract_assert(
    str_contains($auth, 'brvtal_admin_account_is_active(db(), $adminId)'),
    'every authenticated session check must consult current admin state'
);
admin_session_contract_assert(
    str_contains($auth, "brvtal_log('AUTH_REVALIDATION_ERROR'"),
    'database revalidation failures must be observable'
);
admin_session_contract_assert(
    preg_match('/catch\s*\(Throwable \$e\).*?return false;/s', $auth) === 1,
    'database revalidation failures must fail closed instead of authorizing the session'
);
admin_session_contract_assert(
    str_contains($auth, "brvtal_log('SECURITY', 'Admin session revoked because account is inactive or missing'"),
    'inactive or deleted account revocation must be security-auditable'
);
$inactiveBlock = strpos($auth, 'if (!$active)');
admin_session_contract_assert($inactiveBlock !== false, 'inactive-account branch must remain explicit');
$inactiveSource = substr($auth, $inactiveBlock, 500);
admin_session_contract_assert(
    str_contains($inactiveSource, 'brvtal_admin_logout();') && str_contains($inactiveSource, 'return false;'),
    'inactive or deleted admins must have their PHP session destroyed and authorization denied'
);

echo "BRVTAL admin session revalidation contract tests passed.\n";
