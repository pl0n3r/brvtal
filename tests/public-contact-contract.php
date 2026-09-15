<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/public_contact.php';

function contact_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$config = [
    'security' => ['csrf_key' => str_repeat('contact-contract-secret-', 3)],
    'contact' => ['to' => 'inbox@example.com'],
];
$now = 1_800_000_000;
$challenge = brvtal_contact_issue_challenge($config, $now);
contact_assert(str_contains((string)$challenge['question'], '+'), 'challenge exposes a human-readable arithmetic prompt');
contact_assert((int)$challenge['expires_in'] === 600, 'challenge expiry is explicit');

$decoded = brvtal_contact_decode_challenge((string)$challenge['token'], $config, $now);
contact_assert(is_array($decoded), 'signed challenge decodes');
$answer = (int)$decoded['a'] + (int)$decoded['b'];
contact_assert(brvtal_contact_verify_challenge((string)$challenge['token'], $answer, $config, $now + 3), 'correct CAPTCHA answer verifies');
contact_assert(!brvtal_contact_verify_challenge((string)$challenge['token'] . 'x', $answer, $config, $now + 3), 'tampered CAPTCHA token is rejected');
contact_assert(!brvtal_contact_verify_challenge((string)$challenge['token'], $answer + 1, $config, $now + 3), 'wrong CAPTCHA answer is rejected');
contact_assert(brvtal_contact_decode_challenge((string)$challenge['token'], $config, $now + 601) === null, 'expired CAPTCHA token is rejected');

$payload = [
    'name' => 'Felipe Test',
    'email' => 'felipe@example.com',
    'subject' => 'Booking inquiry',
    'message' => 'This is a sufficiently long contact message.',
    'website' => '',
    'captcha_token' => (string)$challenge['token'],
    'captcha_answer' => (string)$answer,
];
$valid = brvtal_contact_validate_payload($payload, $config, $now + 3);
contact_assert($valid['ok'] === true, 'valid contact payload passes server-side validation');
contact_assert($valid['errors'] === [], 'valid contact payload has no field errors');

$tooFast = brvtal_contact_validate_payload($payload, $config, $now + 1);
contact_assert(($tooFast['errors']['captcha'] ?? '') === 'CAPTCHA_TOO_FAST', 'timing heuristic rejects instant automated submission');

$bad = $payload;
$bad['email'] = 'not-an-email';
$bad['message'] = 'short';
$bad['website'] = 'spam.example';
$invalid = brvtal_contact_validate_payload($bad, $config, $now + 3);
contact_assert(($invalid['errors']['email'] ?? '') === 'INVALID_EMAIL', 'invalid email is rejected');
contact_assert(($invalid['errors']['message'] ?? '') === 'INVALID_MESSAGE', 'short message is rejected');
contact_assert(($invalid['errors']['form'] ?? '') === 'BOT_DETECTED', 'honeypot is enforced server-side');

$temp = sys_get_temp_dir() . '/brvtal-contact-rate-' . bin2hex(random_bytes(5)) . '.json';
$key = hash('sha256', '127.0.0.1');
for ($i = 0; $i < 5; $i++) {
    $rate = brvtal_contact_consume_rate_limit($key, $temp, $now + $i, 5, 900);
    contact_assert($rate['allowed'] === true, 'allowed requests stay inside rate window');
}
$blocked = brvtal_contact_consume_rate_limit($key, $temp, $now + 5, 5, 900);
contact_assert($blocked['allowed'] === false && $blocked['error'] === 'RATE_LIMITED', 'sixth request is rate limited');
contact_assert((int)$blocked['retry_after'] > 0, 'rate limit exposes retry window');
@unlink($temp);

[$recipient, $mailSubject, $body, $headers] = brvtal_contact_build_mail($valid['data'], $config);
contact_assert($recipient === 'inbox@example.com', 'contact recipient comes from server-side configuration');
contact_assert($mailSubject === '[BRVTAL CONTACT] Booking inquiry', 'mail subject is scoped and sanitized');
contact_assert(str_contains($body, 'Felipe Test') && str_contains($body, 'Booking inquiry'), 'mail body contains validated contact fields');
contact_assert(str_contains($headers, 'Reply-To: felipe@example.com'), 'validated sender becomes Reply-To without exposing destination in the frontend');

echo "Public contact contract passed.\n";
