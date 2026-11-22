<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/ticketing_resend.php';

function mail_test_assert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException('TEST MAIL MODE: ' . $message);
    }
}

$job = ['recipient_email'=>'allowed@example.test','message_key'=>'admin:test:synthetic:1',
    'subject'=>'Confirmación', 'body_text'=>'Mensaje sintético'];
putenv('BRVTAL_TICKET_MAIL_MODE=test');
putenv('BRVTAL_TICKET_MAIL_TEST_RECIPIENTS=allowed@example.test, second@example.test');
putenv('BRVTAL_TICKET_MAIL_API_KEY=re_SYNTHETIC_TEST_ONLY_12345');
putenv('BRVTAL_TICKET_MAIL_DOMAIN=example.test');
putenv('BRVTAL_TICKET_MAIL_FROM=BRVTAL QA <sender@example.test>');
putenv('BRVTAL_TICKET_MAIL_ALLOW_LIVE=0');
putenv('BRVTAL_TICKET_MAIL_PRIVACY_APPROVED=0');
putenv('BRVTAL_TICKET_MAIL_DNS_VERIFIED=0');

$calls = [];
$request = static function (array $payload, array $headers) use (&$calls): array {
    $calls[] = [$payload, $headers];
    return ['status'=>200,'body'=>'{"id":"synthetic-resend-test"}'];
};
$allowed = brvtal_ticket_resend_send($job, $request);
mail_test_assert($allowed['state'] === 'accepted', 'allowed recipient accepted without live gates');
mail_test_assert(count($calls) === 1, 'provider only sees allowed recipient');
mail_test_assert($calls[0][0]['subject'] === '[PRUEBA] Confirmación', 'subject prefixed');
mail_test_assert($calls[0][0]['to'] === ['allowed@example.test'], 'provider target');
mail_test_assert($calls[0][1][0] === 'Authorization: Bearer re_SYNTHETIC_TEST_ONLY_12345',
    'test uses its configured provider key');
mail_test_assert($calls[0][1][2] === 'Idempotency-Key: admin:test:synthetic:1',
    'idempotency remains stable');

$blocked = [...$job, 'recipient_email'=>'unlisted@example.test'];
$suppressed = brvtal_ticket_resend_send($blocked, $request);
mail_test_assert($suppressed['state'] === 'suppressed_test', 'unlisted recipient suppressed');
mail_test_assert(count($calls) === 1, 'no provider call for unlisted recipient');

putenv('BRVTAL_TICKET_MAIL_TEST_RECIPIENTS=invalid-address, allowed@example.test');
mail_test_assert(brvtal_ticket_resend_send($job, $request)['state'] === 'suppressed_test',
    'invalid list fails closed');
mail_test_assert(count($calls) === 1, 'invalid list never contacts provider');
putenv('BRVTAL_TICKET_MAIL_TEST_RECIPIENTS=');
mail_test_assert(brvtal_ticket_resend_send($job, $request)['state'] === 'suppressed_test',
    'empty list fails closed');

putenv('BRVTAL_TICKET_MAIL_TEST_RECIPIENTS=allowed@example.test');
putenv('BRVTAL_TICKET_MAIL_API_KEY');
mail_test_assert(brvtal_ticket_resend_send($job, $request)['code'] === 'invalid_configuration',
    'test mode needs real configured transport credentials');
mail_test_assert(count($calls) === 1, 'missing key never invokes provider');

putenv('BRVTAL_TICKET_MAIL_MODE=live');
putenv('BRVTAL_TICKET_MAIL_API_KEY=re_SYNTHETIC_TEST_ONLY_12345');
mail_test_assert(brvtal_ticket_resend_send($job, $request)['code'] === 'transport_disabled',
    'live gates are unchanged');
putenv('BRVTAL_TICKET_MAIL_MODE=disabled');
mail_test_assert(brvtal_ticket_resend_send($job, $request)['code'] === 'transport_disabled',
    'disabled remains disabled');
mail_test_assert(count($calls) === 1, 'no accidental external calls');
echo "BRVTAL test mail allowlist, prefix, strict suppression and live isolation passed.\n";
