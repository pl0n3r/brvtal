<?php
declare(strict_types=1);

/** #1927: Wompi test and production key sets, chosen by BRVTAL_TICKET_WOMPI_MODE. */
require_once __DIR__ . '/../config/ticketing_wompi_client.php';
require_once __DIR__ . '/../config/ticketing_wompi_webhook.php';
require_once __DIR__ . '/../config/integration_secrets_store.php';

function wompiEnvCheck(bool $ok, string $why): void
{
    if (!$ok) {
        fwrite(STDERR, "WOMPI ENVIRONMENT CONTRACT FAILED: {$why}\n");
        exit(1);
    }
}
function wompiEnvRejects(callable $callback, string $code, string $why): void
{
    try {
        $callback();
    } catch (DomainException $e) {
        wompiEnvCheck($e->getMessage() === $code, "{$why}: expected {$code}, got {$e->getMessage()}");
        return;
    }
    wompiEnvCheck(false, "{$why}: accepted");
}

$common = [
    'BRVTAL_TICKET_WOMPI_ENABLED_METHODS' => 'CARD,NEQUI,PSE',
    'BRVTAL_TICKET_WOMPI_RETURN_URL' => 'https://example.invalid/mi-cuenta',
];
$test = [
    'BRVTAL_TICKET_WOMPI_PUBLIC_KEY' => 'pub_test_fixture_public',
    'BRVTAL_TICKET_WOMPI_PRIVATE_KEY' => 'prv_test_fixture_private',
    'BRVTAL_TICKET_WOMPI_INTEGRITY_SECRET' => 'test_integrity_fixture_abc',
    'BRVTAL_TICKET_WOMPI_EVENTS_SECRET' => 'test_events_fixture_abc',
];
$prod = [
    'BRVTAL_TICKET_WOMPI_PROD_PUBLIC_KEY' => 'pub_prod_fixture_public',
    'BRVTAL_TICKET_WOMPI_PROD_PRIVATE_KEY' => 'prv_prod_fixture_private',
    'BRVTAL_TICKET_WOMPI_PROD_INTEGRITY_SECRET' => 'prod_integrity_fixture_abc',
    'BRVTAL_TICKET_WOMPI_PROD_EVENTS_SECRET' => 'prod_events_fixture_abc',
];
$both = $common + $test + $prod;

// Sandbox: test keys and sandbox API, no extra SANDBOX_ENABLED flag required.
$sandbox = brvtal_ticket_wompi_config($both + ['BRVTAL_TICKET_WOMPI_MODE' => 'sandbox']);
wompiEnvCheck($sandbox['public'] === 'pub_test_fixture_public', 'sandbox uses test public key');
wompiEnvCheck($sandbox['apiBase'] === 'https://sandbox.wompi.co/v1', 'sandbox API base');
wompiEnvCheck($sandbox['eventEnvironment'] === 'test', 'sandbox webhook environment');

// Production: only the PROD set and the production API.
$production = brvtal_ticket_wompi_config($both + ['BRVTAL_TICKET_WOMPI_MODE' => 'production']);
wompiEnvCheck($production['public'] === 'pub_prod_fixture_public', 'production uses prod public key');
wompiEnvCheck($production['private'] === 'prv_prod_fixture_private', 'production uses prod private key');
wompiEnvCheck($production['integrity'] === 'prod_integrity_fixture_abc', 'production uses prod integrity');
wompiEnvCheck($production['apiBase'] === 'https://production.wompi.co/v1', 'production API base');
$payload = brvtal_ticket_wompi_payload($production, 'BW7-7-CARD-' . str_repeat('b', 24), 25000, 'CARD');
wompiEnvCheck($payload['environment'] === 'production' && $payload['public_key'] === 'pub_prod_fixture_public',
    'production checkout payload');
wompiEnvCheck(!str_contains(json_encode($payload, JSON_THROW_ON_ERROR), 'prv_prod_'), 'private key never in payload');

// Fail closed: off, live, unknown, missing or mixed prefixes.
foreach (['', 'off', 'live', 'prod', 'Production'] as $mode) {
    wompiEnvRejects(fn() => brvtal_ticket_wompi_config($both + ['BRVTAL_TICKET_WOMPI_MODE' => $mode]),
        'WOMPI_DISABLED', "mode '{$mode}'");
}
wompiEnvRejects(fn() => brvtal_ticket_wompi_config($common + $test + ['BRVTAL_TICKET_WOMPI_MODE' => 'production']),
    'WOMPI_CREDENTIALS_MISSING', 'production without prod keys');
wompiEnvRejects(fn() => brvtal_ticket_wompi_config($common + $prod + ['BRVTAL_TICKET_WOMPI_MODE' => 'sandbox']),
    'WOMPI_CREDENTIALS_MISSING', 'sandbox without test keys');
wompiEnvRejects(fn() => brvtal_ticket_wompi_config(array_replace($both, [
    'BRVTAL_TICKET_WOMPI_MODE' => 'production',
    'BRVTAL_TICKET_WOMPI_PROD_PUBLIC_KEY' => 'pub_test_fixture_public',
])), 'WOMPI_CREDENTIALS_MISSING', 'test key in production slot');
wompiEnvRejects(fn() => brvtal_ticket_wompi_config(array_replace($both, [
    'BRVTAL_TICKET_WOMPI_MODE' => 'sandbox',
    'BRVTAL_TICKET_WOMPI_INTEGRITY_SECRET' => 'prod_integrity_fixture_abc',
])), 'WOMPI_CREDENTIALS_MISSING', 'prod secret in test slot');

// Webhook: each environment uses its own events secret and event label.
$hookProd = brvtal_wompi_hook_config($both + ['BRVTAL_TICKET_WOMPI_MODE' => 'production']);
wompiEnvCheck($hookProd['eventsSecret'] === 'prod_events_fixture_abc', 'production events secret');
$hookTest = brvtal_wompi_hook_config($both + ['BRVTAL_TICKET_WOMPI_MODE' => 'sandbox']);
wompiEnvCheck($hookTest['eventsSecret'] === 'test_events_fixture_abc', 'sandbox events secret');
wompiEnvRejects(fn() => brvtal_wompi_hook_config(array_replace($both, [
    'BRVTAL_TICKET_WOMPI_MODE' => 'production',
    'BRVTAL_TICKET_WOMPI_PROD_EVENTS_SECRET' => 'test_events_fixture_abc',
])), 'WEBHOOK_DISABLED', 'test events secret in production');

$now = 1_800_000_000;
$signedEvent = static function (array $settings, string $environment) use ($now): string {
    $tx = ['id' => 'tx-1', 'status' => 'APPROVED', 'amount_in_cents' => 2500000,
        'reference' => 'BW7-7-CARD-' . str_repeat('b', 24), 'currency' => 'COP'];
    $props = ['transaction.id', 'transaction.status', 'transaction.amount_in_cents'];
    $checksum = hash('sha256', 'tx-1APPROVED2500000' . $now . $settings['eventsSecret']);
    return json_encode(['event' => 'transaction.updated', 'environment' => $environment, 'timestamp' => $now,
        'data' => ['transaction' => $tx], 'signature' => ['properties' => $props, 'checksum' => $checksum]],
        JSON_THROW_ON_ERROR);
};
$verified = brvtal_wompi_hook_verify($signedEvent($hookProd, 'prod'), '', $hookProd, $now);
wompiEnvCheck($verified['id'] === 'tx-1', 'production event verifies with production secret');
wompiEnvRejects(fn() => brvtal_wompi_hook_verify($signedEvent($hookProd, 'test'), '', $hookProd, $now),
    'WEBHOOK_INVALID', 'test event sent to production');
wompiEnvRejects(fn() => brvtal_wompi_hook_verify($signedEvent($hookTest, 'prod'), '', $hookTest, $now),
    'WEBHOOK_INVALID', 'production event sent to sandbox');
$seen = '';
try {
    brvtal_wompi_hook_lookup($hookProd, 'tx-1', function (string $url) use (&$seen): array {
        $seen = $url;
        return ['status' => 404, 'body' => ''];
    });
} catch (RuntimeException) {
    // A 404 never authorizes a sale; only the URL matters here.
}
wompiEnvCheck($seen === 'https://production.wompi.co/v1/transactions/tx-1', 'production lookup URL');

// Status: mode and reason code only, never key material.
$status = brvtal_ticket_wompi_status($common + $test + ['BRVTAL_TICKET_WOMPI_MODE' => 'production']);
wompiEnvCheck($status === ['mode' => 'production', 'ready' => false, 'reason' => 'WOMPI_CREDENTIALS_MISSING'],
    'status explains why production is off');
wompiEnvCheck(brvtal_ticket_wompi_status($both + ['BRVTAL_TICKET_WOMPI_MODE' => 'sandbox'])['ready'] === true,
    'status ready in sandbox');
wompiEnvCheck(brvtal_ticket_wompi_status([])['mode'] === 'off', 'empty mode reads as off');

// DISCADMIN catalog: every key is storable with the right prefix only.
foreach ($test + $prod + $common + ['BRVTAL_TICKET_WOMPI_MODE' => 'production'] as $key => $value) {
    wompiEnvCheck(isset(brvtalIntegrationSecretCatalog()[$key]), "catalog allows {$key}");
    brvtalIntegrationSecretValidate($key, $value);
}
foreach ([
    ['BRVTAL_TICKET_WOMPI_PROD_PUBLIC_KEY', 'pub_test_fixture_public'],
    ['BRVTAL_TICKET_WOMPI_PUBLIC_KEY', 'pub_prod_fixture_public'],
    ['BRVTAL_TICKET_WOMPI_PROD_EVENTS_SECRET', 'test_events_fixture_abc'],
    ['BRVTAL_TICKET_WOMPI_MODE', 'live'],
    ['BRVTAL_TICKET_WOMPI_ENABLED_METHODS', 'CARD,BOGUS'],
    ['BRVTAL_TICKET_WOMPI_ENABLED_METHODS', 'CARD,,PSE'],
    ['BRVTAL_TICKET_WOMPI_RETURN_URL', 'http://example.invalid/'],
    ['BRVTAL_TICKET_WOMPI_RETURN_URL', 'https://example.invalid/#token'],
] as [$key, $value]) {
    try {
        brvtalIntegrationSecretValidate($key, $value);
        wompiEnvCheck(false, "catalog accepted {$key}={$value}");
    } catch (InvalidArgumentException) {
    }
}

// Mode selector guard: production needs the token and a complete production set.
wompiEnvCheck(brvtal_ticket_wompi_mode_change_error('sandbox', null, $common + $test) === null, 'sandbox switch allowed');
wompiEnvCheck(brvtal_ticket_wompi_mode_change_error('off', null, []) === null, 'turning off always allowed');
wompiEnvCheck(brvtal_ticket_wompi_mode_change_error('production', null, $both)
    === 'WOMPI_PRODUCTION_CONFIRMATION_REQUIRED', 'production needs confirmation');
wompiEnvCheck(brvtal_ticket_wompi_mode_change_error('production', 'cobros_reales', $both)
    === 'WOMPI_PRODUCTION_CONFIRMATION_REQUIRED', 'confirmation is exact');
wompiEnvCheck(brvtal_ticket_wompi_mode_change_error('production', 'COBROS_REALES', $common + $test)
    === 'WOMPI_PRODUCTION_KEYS_INCOMPLETE', 'production needs prod keys');
$noEvents = $both;
unset($noEvents['BRVTAL_TICKET_WOMPI_PROD_EVENTS_SECRET']);
wompiEnvCheck(brvtal_ticket_wompi_mode_change_error('production', 'COBROS_REALES', $noEvents)
    === 'WOMPI_PRODUCTION_KEYS_INCOMPLETE', 'production needs prod events secret');
wompiEnvCheck(brvtal_ticket_wompi_mode_change_error('production', 'COBROS_REALES', $both) === null,
    'complete production set with confirmation is allowed');
$api = (string) file_get_contents(__DIR__ . '/../api/admin-integration-secrets.php');
wompiEnvCheck(str_contains($api, 'brvtal_ticket_wompi_mode_change_error('), 'admin API applies the mode guard');

// #1933: pasted values with outer whitespace are trimmed before validation;
// a value from the wrong environment is still rejected after trimming.
wompiEnvCheck(brvtalIntegrationSecretNormalize('BRVTAL_TICKET_WOMPI_INTEGRITY_SECRET', " test_integrity_fixture_abc \n")
    === 'test_integrity_fixture_abc', 'single-line secret is trimmed');
brvtalIntegrationSecretValidate('BRVTAL_TICKET_WOMPI_EVENTS_SECRET',
    brvtalIntegrationSecretNormalize('BRVTAL_TICKET_WOMPI_EVENTS_SECRET', "test_events_fixture_abc\r\n"));
$pem = "-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----\n";
wompiEnvCheck(brvtalIntegrationSecretNormalize('BRVTAL_GA4_PRIVATE_KEY', $pem) === $pem, 'PEM keeps its own handling');
try {
    brvtalIntegrationSecretValidate('BRVTAL_TICKET_WOMPI_INTEGRITY_SECRET',
        brvtalIntegrationSecretNormalize('BRVTAL_TICKET_WOMPI_INTEGRITY_SECRET', ' prod_integrity_fixture_abc '));
    wompiEnvCheck(false, 'production secret accepted in the test slot after trimming');
} catch (InvalidArgumentException) {
}

echo "BRVTAL Wompi test/production environment contract passed\n";
