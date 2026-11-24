<?php
declare(strict_types=1);

require_once __DIR__ . '/secrets.php';
require_once __DIR__ . '/integration_secrets_store.php';

/**
 * #1962: DISCADMIN mail switch. Apagado (disabled) / Pruebas (test) / Real (live).
 * Pruebas only ever reaches the test-recipient allowlist; Real needs the owner's
 * privacy and DNS confirmations plus the explicit ENVIO_REAL token.
 */
const BRVTAL_MAIL_LIVE_CONFIRMATION = 'ENVIO_REAL';

/** Mode, readiness and a reason code; never keys or recipients. */
function brvtal_ticket_mail_status(?array $override = null): array
{
    $value = static fn(string $key): string => trim((string)brvtal_secret_value($key, $override));
    $mode = $value('BRVTAL_TICKET_MAIL_MODE');
    $mode = in_array($mode, ['test', 'live'], true) ? $mode : 'disabled';
    $config = is_array($GLOBALS['config'] ?? null) ? $GLOBALS['config'] : [];
    $pinned = $override === null && trim((string)($config['ticketing']['mail']['mode'] ?? '')) !== '';
    $credentials = $value('BRVTAL_TICKET_MAIL_API_KEY') !== '' && $value('BRVTAL_TICKET_MAIL_DOMAIN') !== ''
        && $value('BRVTAL_TICKET_MAIL_FROM') !== '';
    $reason = match (true) {
        $mode === 'disabled' => 'MAIL_DISABLED',
        !$credentials => 'MAIL_CREDENTIALS_MISSING',
        $value('BRVTAL_TICKET_MAIL_PRIVACY_APPROVED') !== '1' => 'MAIL_PRIVACY_REQUIRED',
        $value('BRVTAL_TICKET_MAIL_LOOKUP_SECRET') === '' => 'MAIL_LOOKUP_SECRET_MISSING',
        $mode === 'test' && $value('BRVTAL_TICKET_MAIL_TEST_RECIPIENTS') === '' => 'MAIL_TEST_RECIPIENTS_MISSING',
        $mode === 'live' && ($value('BRVTAL_TICKET_MAIL_DNS_VERIFIED') !== '1'
            || $value('BRVTAL_TICKET_MAIL_ALLOW_LIVE') !== '1') => 'MAIL_LIVE_GATES_MISSING',
        default => null,
    };
    return ['mode' => $mode, 'ready' => $reason === null, 'reason' => $reason, 'pinned' => $pinned];
}

/** Returns an error code, or null after saving the new mode. */
function brvtal_ticket_mail_mode_apply(
    PDO $pdo, string $mode, bool $privacy, bool $dns, mixed $confirm, int $adminId, ?array $override = null
): ?string {
    if (!in_array($mode, ['disabled', 'test', 'live'], true)) return 'MAIL_MODE_INVALID';
    $value = static fn(string $key): string => trim((string)brvtal_secret_value($key, $override));
    $save = static fn(string $key, string $v) => brvtalIntegrationSecretMutate($pdo, 'POST', $key, $v, $adminId);
    if ($mode === 'disabled') {
        // Close the real-send gate first, then the mode.
        $save('BRVTAL_TICKET_MAIL_ALLOW_LIVE', '0');
        $save('BRVTAL_TICKET_MAIL_MODE', 'disabled');
        return null;
    }
    if (!$privacy) return 'MAIL_PRIVACY_REQUIRED';
    if ($value('BRVTAL_TICKET_MAIL_API_KEY') === '' || $value('BRVTAL_TICKET_MAIL_DOMAIN') === ''
        || $value('BRVTAL_TICKET_MAIL_FROM') === '') {
        return 'MAIL_CREDENTIALS_MISSING';
    }
    if ($mode === 'test' && $value('BRVTAL_TICKET_MAIL_TEST_RECIPIENTS') === '') return 'MAIL_TEST_RECIPIENTS_MISSING';
    if ($mode === 'live') {
        if (!$dns) return 'MAIL_DNS_REQUIRED';
        if ($confirm !== BRVTAL_MAIL_LIVE_CONFIRMATION) return 'MAIL_LIVE_CONFIRMATION_REQUIRED';
    }
    // The history lookup key is server-generated once; the owner never types it.
    if ($value('BRVTAL_TICKET_MAIL_LOOKUP_SECRET') === '') {
        $save('BRVTAL_TICKET_MAIL_LOOKUP_SECRET', bin2hex(random_bytes(32)));
    }
    $save('BRVTAL_TICKET_MAIL_PRIVACY_APPROVED', '1');
    $save('BRVTAL_TICKET_MAIL_DNS_VERIFIED', $dns ? '1' : '0');
    $save('BRVTAL_TICKET_MAIL_ALLOW_LIVE', $mode === 'live' ? '1' : '0');
    $save('BRVTAL_TICKET_MAIL_MODE', $mode); // the mode switches last, after every gate
    return null;
}
