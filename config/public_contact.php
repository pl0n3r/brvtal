<?php
declare(strict_types=1);

/**
 * Stateless CAPTCHA + rate limiting helpers for the public Contact form.
 * The CAPTCHA token is signed with the existing application security secret,
 * so no extra third-party service or browser-visible secret is required.
 */

function brvtal_contact_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function brvtal_contact_base64url_decode(string $value): string|false
{
    if ($value === '' || preg_match('/[^A-Za-z0-9_-]/', $value)) return false;
    $padding = (4 - (strlen($value) % 4)) % 4;
    return base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);
}

function brvtal_contact_secret(array $config): string
{
    $secret = trim((string)($config['security']['csrf_key'] ?? ''));
    if (strlen($secret) < 24) {
        throw new RuntimeException('CONTACT_SECURITY_UNAVAILABLE');
    }
    return $secret;
}

function brvtal_contact_issue_challenge(array $config, ?int $now = null): array
{
    $now ??= time();
    $a = random_int(2, 9);
    $b = random_int(2, 9);
    $payload = json_encode([
        'a' => $a,
        'b' => $b,
        'iat' => $now,
        'nonce' => bin2hex(random_bytes(8)),
    ], JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) throw new RuntimeException('CONTACT_CHALLENGE_FAILED');

    $encoded = brvtal_contact_base64url_encode($payload);
    $signature = hash_hmac('sha256', $encoded, brvtal_contact_secret($config), true);

    return [
        'question' => $a . ' + ' . $b . ' = ?',
        'token' => $encoded . '.' . brvtal_contact_base64url_encode($signature),
        'expires_in' => 600,
    ];
}

function brvtal_contact_decode_challenge(string $token, array $config, ?int $now = null): ?array
{
    $now ??= time();
    $parts = explode('.', trim($token));
    if (count($parts) !== 2) return null;
    [$encoded, $providedSignature] = $parts;

    $signature = brvtal_contact_base64url_decode($providedSignature);
    if ($signature === false) return null;
    $expected = hash_hmac('sha256', $encoded, brvtal_contact_secret($config), true);
    if (!hash_equals($expected, $signature)) return null;

    $decoded = brvtal_contact_base64url_decode($encoded);
    if ($decoded === false) return null;
    $payload = json_decode($decoded, true);
    if (!is_array($payload)) return null;

    $a = filter_var($payload['a'] ?? null, FILTER_VALIDATE_INT);
    $b = filter_var($payload['b'] ?? null, FILTER_VALIDATE_INT);
    $issuedAt = filter_var($payload['iat'] ?? null, FILTER_VALIDATE_INT);
    $nonce = (string)($payload['nonce'] ?? '');
    if ($a === false || $b === false || $issuedAt === false || !preg_match('/^[a-f0-9]{16}$/', $nonce)) return null;
    if ($a < 2 || $a > 9 || $b < 2 || $b > 9) return null;
    if ($issuedAt > $now + 60 || $issuedAt < $now - 600) return null;

    return ['a' => $a, 'b' => $b, 'iat' => $issuedAt, 'nonce' => $nonce];
}

function brvtal_contact_verify_challenge(string $token, mixed $answer, array $config, ?int $now = null): bool
{
    $payload = brvtal_contact_decode_challenge($token, $config, $now);
    if (!$payload) return false;
    $value = filter_var($answer, FILTER_VALIDATE_INT);
    return $value !== false && $value === ($payload['a'] + $payload['b']);
}

function brvtal_contact_ip_matches_rule(string $ip, string $rule): bool
{
    $ip = trim($ip);
    $rule = trim($rule);
    if (!filter_var($ip, FILTER_VALIDATE_IP) || $rule === '') return false;

    if (!str_contains($rule, '/')) {
        if (!filter_var($rule, FILTER_VALIDATE_IP)) return false;
        $ipPacked = @inet_pton($ip);
        $rulePacked = @inet_pton($rule);
        return is_string($ipPacked) && is_string($rulePacked) && hash_equals($rulePacked, $ipPacked);
    }

    [$network, $prefixRaw] = array_pad(explode('/', $rule, 2), 2, '');
    if (!filter_var($network, FILTER_VALIDATE_IP) || !preg_match('/^\d{1,3}$/', $prefixRaw)) return false;
    $ipPacked = @inet_pton($ip);
    $networkPacked = @inet_pton($network);
    if (!is_string($ipPacked) || !is_string($networkPacked) || strlen($ipPacked) !== strlen($networkPacked)) return false;

    $prefix = (int)$prefixRaw;
    $maxBits = strlen($ipPacked) * 8;
    if ($prefix < 0 || $prefix > $maxBits) return false;
    $wholeBytes = intdiv($prefix, 8);
    $remainingBits = $prefix % 8;
    if ($wholeBytes > 0 && substr($ipPacked, 0, $wholeBytes) !== substr($networkPacked, 0, $wholeBytes)) return false;
    if ($remainingBits === 0) return true;

    $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
    return (ord($ipPacked[$wholeBytes]) & $mask) === (ord($networkPacked[$wholeBytes]) & $mask);
}

function brvtal_contact_resolve_client_ip(array $config = []): string
{
    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if (!filter_var($remote, FILTER_VALIDATE_IP)) return 'unknown';

    $trusted = $config['contact']['trusted_proxies'] ?? [];
    if (is_array($trusted)) {
        foreach ($trusted as $rule) {
            if (!is_string($rule) || !brvtal_contact_ip_matches_rule($remote, $rule)) continue;
            $forwarded = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
            if (filter_var($forwarded, FILTER_VALIDATE_IP)) return $forwarded;
            break;
        }
    }

    return $remote;
}

function brvtal_contact_client_key(array $config = []): string
{
    return hash('sha256', brvtal_contact_resolve_client_ip($config));
}

function brvtal_contact_rate_limit_path(): string
{
    return dirname(__DIR__) . '/storage/rate_limits/contact-rate-limit.json';
}

function brvtal_contact_consume_rate_limit(
    string $key,
    ?string $path = null,
    ?int $now = null,
    int $limit = 5,
    int $windowSeconds = 900
): array {
    $path ??= brvtal_contact_rate_limit_path();
    $now ??= time();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
        return ['allowed' => false, 'retry_after' => 60, 'error' => 'RATE_LIMIT_UNAVAILABLE'];
    }

    $handle = @fopen($path, 'c+');
    if (!$handle) return ['allowed' => false, 'retry_after' => 60, 'error' => 'RATE_LIMIT_UNAVAILABLE'];

    try {
        if (!flock($handle, LOCK_EX)) {
            return ['allowed' => false, 'retry_after' => 60, 'error' => 'RATE_LIMIT_UNAVAILABLE'];
        }
        rewind($handle);
        $raw = stream_get_contents($handle) ?: '';
        $state = json_decode($raw, true);
        if (!is_array($state)) $state = [];

        $cutoff = $now - $windowSeconds;
        foreach ($state as $bucketKey => $timestamps) {
            if (!is_array($timestamps)) {
                unset($state[$bucketKey]);
                continue;
            }
            $timestamps = array_values(array_filter(
                array_map('intval', $timestamps),
                static fn(int $timestamp): bool => $timestamp > $cutoff && $timestamp <= $now + 60
            ));
            if ($timestamps) $state[$bucketKey] = $timestamps;
            else unset($state[$bucketKey]);
        }

        $bucket = $state[$key] ?? [];
        if (count($bucket) >= $limit) {
            $retryAfter = max(1, ($bucket[0] + $windowSeconds) - $now);
            return ['allowed' => false, 'retry_after' => $retryAfter, 'error' => 'RATE_LIMITED'];
        }

        $bucket[] = $now;
        $state[$key] = $bucket;
        $encoded = json_encode($state, JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            return ['allowed' => false, 'retry_after' => 60, 'error' => 'RATE_LIMIT_UNAVAILABLE'];
        }
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, $encoded);
        fflush($handle);
        return ['allowed' => true, 'retry_after' => 0, 'error' => null];
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function brvtal_contact_validate_payload(array $input, array $config, ?int $now = null): array
{
    $now ??= time();
    $name = trim((string)($input['name'] ?? ''));
    $email = trim((string)($input['email'] ?? ''));
    $subject = trim((string)($input['subject'] ?? ''));
    $message = trim((string)($input['message'] ?? ''));
    $honeypot = trim((string)($input['website'] ?? ''));
    $token = trim((string)($input['captcha_token'] ?? ''));
    $answer = $input['captcha_answer'] ?? null;
    $errors = [];

    if ($honeypot !== '') $errors['form'] = 'BOT_DETECTED';
    if (mb_strlen($name) < 2 || mb_strlen($name) > 100) $errors['name'] = 'INVALID_NAME';
    if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'INVALID_EMAIL';
    if (mb_strlen($subject) < 2 || mb_strlen($subject) > 140) $errors['subject'] = 'INVALID_SUBJECT';
    if (mb_strlen($message) < 10 || mb_strlen($message) > 5000) $errors['message'] = 'INVALID_MESSAGE';

    $challenge = $token !== '' ? brvtal_contact_decode_challenge($token, $config, $now) : null;
    if (!$challenge || !brvtal_contact_verify_challenge($token, $answer, $config, $now)) {
        $errors['captcha'] = 'INVALID_CAPTCHA';
    } elseif (($now - (int)$challenge['iat']) < 2) {
        $errors['captcha'] = 'CAPTCHA_TOO_FAST';
    }

    return [
        'ok' => !$errors,
        'errors' => $errors,
        'data' => [
            'name' => preg_replace('/[\r\n]+/', ' ', $name) ?? $name,
            'email' => $email,
            'subject' => preg_replace('/[\r\n]+/', ' ', $subject) ?? $subject,
            'message' => str_replace(["\r\n", "\r"], "\n", $message),
        ],
    ];
}

function brvtal_contact_recipient(array $config): string
{
    $candidate = trim((string)($config['contact']['to'] ?? getenv('BRVTAL_CONTACT_TO') ?: 'contact@brvtal.com.co'));
    return filter_var($candidate, FILTER_VALIDATE_EMAIL) ? $candidate : 'contact@brvtal.com.co';
}

function brvtal_contact_build_mail(array $data, array $config): array
{
    $recipient = brvtal_contact_recipient($config);
    $subject = '[BRVTAL CONTACT] ' . $data['subject'];
    $body = "New message from BRVTAL.com.co\n\n"
        . "Name: {$data['name']}\n"
        . "Email: {$data['email']}\n"
        . "Subject: {$data['subject']}\n\n"
        . "Message:\n{$data['message']}\n";
    $headers = [
        'From: BRVTAL Website <no-reply@brvtal.com.co>',
        'Reply-To: ' . $data['email'],
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: BRVTAL Contact',
    ];
    return [$recipient, $subject, $body, implode("\r\n", $headers)];
}

function brvtal_contact_send(array $data, array $config): bool
{
    [$recipient, $subject, $body, $headers] = brvtal_contact_build_mail($data, $config);
    return @mail($recipient, $subject, $body, $headers);
}
