<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/secrets.php';
require_once __DIR__ . '/../config/ticketing_wompi_client.php';
require_once __DIR__ . '/../config/ticketing_wompi_webhook.php';
require_once __DIR__ . '/../config/ticketing_resend.php';
require_once __DIR__ . '/../config/ticketing_mail_events.php';
require_once __DIR__ . '/../config/admin_mailer.php';
require_once __DIR__ . '/../config/admin_analytics_signals.php';

function provisionCheck(bool $ok, string $why): void {
    if (!$ok) throw new RuntimeException('SECRETS_PROVISIONING: ' . $why);
}
$synthetic = [
    'ticketing' => [
        'wompi' => [
            'mode'=>'sandbox','sandbox_enabled'=>'1',
            'public_key'=>'pub_test_synthetic_public',
            'private_key'=>'prv_test_synthetic_private',
            'integrity_secret'=>'test_integrity_synthetic_fixture',
            'events_secret'=>'test_events_synthetic_fixture',
            'enabled_methods'=>'CARD,NEQUI',
            'return_url'=>'https://example.invalid/return',
        ],
        'mail'=>[
            'mode'=>'disabled','api_key'=>'re_synthetic_private',
            'domain'=>'example.invalid','from'=>'test@example.invalid',
            'webhook_secret'=>'whsec_synthetic',
        ],
    ],
    'mail'=>['smtp'=>[
        'host'=>'smtp.example.invalid','user'=>'synthetic_user','password'=>'secret-only-in-test',
        'port'=>587,'encryption'=>'tls','from'=>'test@example.invalid',
    ]],
    'analytics'=>['ga4'=>[
        'property_id'=>'1234567','client_email'=>'fixture@example.invalid',
        'private_key'=>'-----BEGIN PRIVATE KEY-----\\nFAKE\\n-----END PRIVATE KEY-----',
    ]],
    'observability'=>['sentry_dsn'=>'https://synthetic@example.invalid/1'],
];
$original = $GLOBALS['config'] ?? null;
$GLOBALS['config'] = $synthetic;
putenv('BRVTAL_TICKET_WOMPI_PUBLIC_KEY=pub_test_legacy_env');
try {
    provisionCheck(brvtal_secret_value('BRVTAL_TICKET_WOMPI_PUBLIC_KEY')
        === 'pub_test_synthetic_public','private config overrides env');
    provisionCheck(brvtal_secret_value('BRVTAL_SMTP_PASSWORD') === 'secret-only-in-test',
        'SMTP secret from mail.smtp');
    provisionCheck(brvtal_secret_value('BRVTAL_SENTRY_DSN')
        === 'https://synthetic@example.invalid/1','Sentry from observability');
    provisionCheck(brvtal_secret_value('BRVTAL_GA4_PROPERTY_ID') === '1234567',
        'GA4 from analytics.ga4');
    provisionCheck(brvtal_secret_value('BRVTAL_TICKET_MAIL_API_KEY') === 're_synthetic_private',
        'Resend key from ticketing.mail');
    $fallback = $synthetic;
    $fallback['ticketing']['wompi']['public_key'] = '';
    provisionCheck(brvtal_secret_value('BRVTAL_TICKET_WOMPI_PUBLIC_KEY',null,$fallback)
        === 'pub_test_legacy_env','empty config falls back to getenv');
    provisionCheck(brvtal_secret_value('BRVTAL_TICKET_WOMPI_PUBLIC_KEY',
        ['BRVTAL_TICKET_WOMPI_PUBLIC_KEY'=>'pub_test_explicit'])
        === 'pub_test_explicit','explicit fixture remains isolated');
    try {
        $invalid = $synthetic;
        $invalid['ticketing']['wompi']['public_key'] = ['secret'];
        brvtal_secret_value('BRVTAL_TICKET_WOMPI_PUBLIC_KEY',null,$invalid);
        throw new RuntimeException('invalid type accepted');
    } catch (RuntimeException $e) {
        provisionCheck($e->getMessage() === 'SECRET_CONFIG_INVALID','invalid private type denied');
    }

    $wompiSettings = brvtal_ticket_wompi_config();
    provisionCheck($wompiSettings['mode'] === 'sandbox','Wompi restricted to sandbox');
    $hook = brvtal_wompi_hook_config();
    provisionCheck($hook['eventsSecret'] === 'test_events_synthetic_fixture','events key is separate');
    $reference='BW7-42-CARD-' . str_repeat('a',24);
    $payload=brvtal_ticket_wompi_payload($wompiSettings,$reference,25000,'CARD');
    provisionCheck($payload['state']==='awaiting_provider'
        && $payload['environment']==='sandbox','checkout cannot confirm sale');
    provisionCheck(!str_contains(json_encode($payload,JSON_THROW_ON_ERROR),
        'prv_test_synthetic_private'),'no private key in checkout');
    $bad=$synthetic;
    $bad['ticketing']['wompi']['mode']='live';
    $GLOBALS['config']=$bad;
    try {
        brvtal_ticket_wompi_config();
        throw new RuntimeException('live accepted');
    } catch(DomainException $e) {
        provisionCheck($e->getMessage()==='WOMPI_DISABLED','live must stay unavailable');
    }
    $GLOBALS['config']=$synthetic;
    $snapshot=brvtal_provisioning_snapshot();
    provisionCheck($snapshot['integrations'][0]['status']==='modo sandbox','Wompi sandbox label');
    foreach (array_slice($snapshot['integrations'],1) as $row)
        provisionCheck($row['status']==='configurado','presence-only integration status');
    $json=json_encode($snapshot,JSON_THROW_ON_ERROR);
    foreach (['prv_test_synthetic_private','secret-only-in-test','re_synthetic_private',
        'test_events_synthetic_fixture','FAKE','smtp.example.invalid'] as $secret)
        provisionCheck(!str_contains($json,$secret),'no credentials in responses');
    $missing=brvtal_provisioning_snapshot(['ticketing'=>[],'mail'=>[],'analytics'=>[],'observability'=>[]]);
    provisionCheck($missing['integrations'][0]['status']==='falta','missing integration denied');
    provisionCheck($snapshot['workers'][0]['last_execution']===null,'never invent cron evidence');
} finally {
    putenv('BRVTAL_TICKET_WOMPI_PUBLIC_KEY');
    if ($original===null) unset($GLOBALS['config']);
    else $GLOBALS['config']=$original;
}
// HTTP worker guard contract: deny requests before private config or SMTP is loaded.
$adminWorker = (string)file_get_contents(__DIR__.'/../ops/admin-password-mail-worker.php');
$cliGuard = strpos($adminWorker, "if (PHP_SAPI !== 'cli')");
provisionCheck($cliGuard !== false, 'admin password worker must be CLI only');
provisionCheck(strpos($adminWorker, 'http_response_code(404);') > $cliGuard
    && strpos($adminWorker, 'http_response_code(404);') < strpos($adminWorker, '$configPath ='),
    'web entry must fail closed before loading private config');

echo "BRVTAL secrets precedence, sandbox-only, fallbacks, and redacted status passed.\n";
