<?php
declare(strict_types=1);

require_once __DIR__ . '/public_contact.php';
require_once __DIR__ . '/password_rate_limit.php';

/**
 * Public POST guard. Never trust forwarded client IPs unless the remote peer is
 * allowlisted by the existing contact proxy contract. Rate state is locked,
 * file-backed and fail-closed, with no raw email or bearer token in filenames.
 */
function brvtal_public_abuse_scopes(): array
{
    return ['contact', 'login', 'register', 'request-reset', 'resend-verification', 'verify',
        'confirm-reset', 'waitlist-subscribe', 'waitlist-unsubscribe'];
}

function brvtal_public_abuse_challenged(string $scope): bool
{
    return in_array($scope, ['register', 'request-reset', 'resend-verification',
        'waitlist-subscribe'], true);
}

/** Signed challenge bound to a single action, built from Contact's HMAC CAPTCHA. */
function brvtal_public_abuse_challenge(array $config, string $scope, ?int $now = null): array
{
    if (!brvtal_public_abuse_challenged($scope)) {
        throw new InvalidArgumentException('PUBLIC_ABUSE_SCOPE_INVALID');
    }
    $challenge = brvtal_contact_issue_challenge($config, $now);
    $signature = hash_hmac('sha256', $scope . '|' . $challenge['token'],
        brvtal_contact_secret($config), true);
    return [
        'question' => $challenge['question'],
        'captcha_token' => $challenge['token'] . '.'
            . brvtal_contact_base64url_encode($signature),
        'expires_in' => $challenge['expires_in'],
    ];
}

function brvtal_public_abuse_verify_challenge(
    array $config, string $scope, array $input, ?int $now = null
): bool {
    if (!brvtal_public_abuse_challenged($scope)) return false;
    $now ??= time();
    $token = $input['captcha_token'] ?? null;
    $answer = $input['captcha_answer'] ?? null;
    if (!is_string($token) || strlen($token) > 512
        || !is_string($answer) && !is_int($answer)) return false;
    $parts = explode('.', $token);
    if (count($parts) !== 3) return false;
    $signature = brvtal_contact_base64url_decode($parts[2]);
    if (!is_string($signature)) return false;
    $contactToken = $parts[0] . '.' . $parts[1];
    $expected = hash_hmac('sha256', $scope . '|' . $contactToken,
        brvtal_contact_secret($config), true);
    if (!hash_equals($expected, $signature)) return false;
    $decoded = brvtal_contact_decode_challenge($contactToken, $config, $now);
    return is_array($decoded) && $now - (int)$decoded['iat'] >= 2
        && brvtal_contact_verify_challenge($contactToken, $answer, $config, $now);
}

/** @return array{allowed:bool,status:int,error:?string,retry_after:int} */
function brvtal_public_abuse_check(
    string $scope, array $input, array $config,
    ?int $now = null, ?string $directory = null
): array {
    $deny = static fn(int $status, string $error, int $retry = 0): array =>
        ['allowed' => false, 'status' => $status, 'error' => $error,
            'retry_after' => $retry];
    if (!in_array($scope, brvtal_public_abuse_scopes(), true)) {
        return $deny(503, 'PUBLIC_ABUSE_SCOPE_UNKNOWN', 60);
    }
    $now ??= time();
    $directory ??= __DIR__ . '/../storage/rate_limits/public_abuse';
    $ip = brvtal_contact_resolve_client_ip($config);
    $subject = $input['email'] ?? $input['buyer_id'] ?? $input['token'] ?? '';
    $subject = is_scalar($subject) ? strtolower(trim((string)$subject)) : '';
    if ($subject === '') $subject = '__unknown__';
    // Consumed on *every* attempt, not only failed credentials or successful
    // messages. No way to evade the IP bucket by rotating target emails.
    $ipLimit = brvtal_password_rate_limit_failure(
        'public|' . $scope . '|ip', $directory, $now, $ip);
    $subjectLimit = brvtal_password_rate_limit_failure(
        'public|' . $scope . '|subject|' . hash('sha256', $subject),
        $directory, $now, '__subject__');
    if ($ipLimit['limited'] || $subjectLimit['limited']) {
        return $deny(429, 'RATE_LIMITED', max(1, (int)$ipLimit['retry_after'],
            (int)$subjectLimit['retry_after']));
    }
    if (!is_string($input['website'] ?? '') || (string)($input['website'] ?? '') !== '') {
        return $deny(422, 'BOT_DETECTED');
    }
    if (brvtal_public_abuse_challenged($scope)) {
        try {
            if (!brvtal_public_abuse_verify_challenge($config, $scope, $input, $now)) {
                return $deny(422, 'CAPTCHA_INVALID');
            }
        } catch (Throwable) {
            return $deny(503, 'CAPTCHA_UNAVAILABLE', 60);
        }
    }
    return ['allowed' => true, 'status' => 200, 'error' => null, 'retry_after' => 0];
}

/** Atomically consume a per-recipient and global hourly mail budget. */
function brvtal_public_abuse_mail_limit(
    string $recipient, ?string $path = null, ?int $now = null,
    int $perRecipient = 5, int $global = 100
): array {
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)
        || $perRecipient < 1 || $global < 1) {
        return ['allowed' => false, 'retry_after' => 3600, 'error' => 'MAIL_RATE_INVALID'];
    }
    $path ??= __DIR__ . '/../storage/rate_limits/public-mail-hourly.json';
    $name = 'recipient:' . hash('sha256', strtolower(trim($recipient)));
    $person = brvtal_contact_consume_rate_limit($name, $path, $now,
        $perRecipient, 3600);
    if (!$person['allowed']) return $person;
    return brvtal_contact_consume_rate_limit('global', $path, $now, $global, 3600);
}

/** Fixed public form scope: signature includes the opaque identity, version and retry intent. */
function brvtalFormsChallenge(array $config, string $context, ?int $now = null): array
{
    $challenge = brvtal_contact_issue_challenge($config, $now);
    $token = $challenge['token'];
    $mac = hash_hmac('sha256', 'forms|'.$context.'|'.$token, brvtal_contact_secret($config), true);
    return ['question'=>$challenge['question'], 'captcha_token'=>$token.'.'.brvtal_contact_base64url_encode($mac),
        'expires_in'=>$challenge['expires_in']];
}

function brvtalFormsVerify(array $config, string $context, mixed $token, mixed $answer, ?int $now = null): bool
{
    if (!is_string($token) || strlen($token) > 512 || !is_string($answer)) return false;
    $parts = explode('.', $token);
    if (count($parts) !== 3) return false;
    $contact = $parts[0].'.'.$parts[1];
    $mac = hash_hmac('sha256', 'forms|'.$context.'|'.$contact, brvtal_contact_secret($config), true);
    $provided = brvtal_contact_base64url_decode($parts[2]);
    if (!is_string($provided) || !hash_equals($mac, $provided)) return false;
    $now ??= time();
    $decoded = brvtal_contact_decode_challenge($contact, $config, $now);
    return is_array($decoded) && $now - $decoded['iat'] >= 2
        && brvtal_contact_verify_challenge($contact, $answer, $config, $now);
}

function brvtalFormsRate(array $config, string $id, ?string $path = null, ?int $now = null): array
{
    $path ??= __DIR__.'/../storage/rate_limits/forms.json';
    $ip = hash_hmac('sha256', brvtal_contact_resolve_client_ip($config), brvtal_contact_secret($config));
    foreach (['forms:global'=>300, 'forms:ip:'.$ip=>30, 'forms:scope:'.$id=>60] as $bucket=>$max) {
        $result = brvtal_contact_consume_rate_limit($bucket, $path, $now, $max, 900);
        if (!$result['allowed']) return $result;
    }
    return $result;
}
