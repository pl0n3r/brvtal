<?php
declare(strict_types=1);

/**
 * Isolated Resend adapter. Disabled unless the operator explicitly configures
 * verified sender and the protected live gate. Never logs recipient or API key.
 * The caller supplies the stable outbox message_key as Idempotency-Key.
 *
 * Resend retains idempotency keys for 24h:
 * https://resend.com/changelog/idempotency-keys
 */
function brvtal_ticket_resend_send(array $job, ?callable $request = null): array
{
    $mode = (string)(getenv('BRVTAL_TICKET_MAIL_MODE') ?: 'disabled');
    $test = $mode === 'mock' && getenv('BRVTAL_TICKET_MAIL_TESTING') === '1';
    $live = $mode === 'live'
        && getenv('BRVTAL_TICKET_MAIL_ALLOW_LIVE') === '1'
        && getenv('BRVTAL_TICKET_MAIL_PRIVACY_APPROVED') === '1'
        && getenv('BRVTAL_TICKET_MAIL_DNS_VERIFIED') === '1';
    if (!$test && !$live) {
        return ['state' => 'failed', 'code' => 'transport_disabled'];
    }

    $email = (string)($job['recipient_email'] ?? '');
    $key = (string)($job['message_key'] ?? '');
    $subject = (string)($job['subject'] ?? '');
    $body = (string)($job['body_text'] ?? '');
    $domain = strtolower(trim((string)getenv('BRVTAL_TICKET_MAIL_DOMAIN')));
    $from = trim((string)getenv('BRVTAL_TICKET_MAIL_FROM'));
    $apiKey = (string)getenv('BRVTAL_TICKET_MAIL_API_KEY');
    if ($test) {
        // A mock must never pass a configured production secret to callbacks.
        $apiKey = '';
        $domain = 'brvtal.invalid';
        $from = 'Pruebas BRVTAL <test@brvtal.invalid>';
    }
    $fromEmail = preg_match('/<([^<>]+)>/', $from, $parts) ? $parts[1] : $from;
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)
        || !preg_match('/^[A-Za-z0-9:_-]{1,128}$/D', $key)
        || $subject === '' || strlen($subject) > 255
        || $body === '' || strlen($body) > 50000
        || strpbrk($from, "\r\n") !== false
        || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)
        || !filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
        || strtolower((string)substr(strrchr($fromEmail, '@') ?: '', 1)) !== $domain
        || ($live && $apiKey === '')) {
        return ['state' => 'failed', 'code' => 'invalid_configuration'];
    }
    $payload = ['from' => $from, 'to' => [$email], 'subject' => $subject, 'text' => $body];
    $headers = ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json',
        'Idempotency-Key: ' . $key];
    if ($test && $request === null) {
        return ['state' => 'accepted', 'id' => 'mock-' . hash('sha256', $key)];
    }
    if ($request !== null) {
        $response = $request($payload, $headers);
    } else {
        if (!function_exists('curl_init')) {
            return ['state' => 'failed', 'code' => 'transport_unavailable'];
        }
        $curl = curl_init('https://api.resend.com/emails');
        if ($curl === false) {
            return ['state' => 'failed', 'code' => 'transport_unavailable'];
        }
        $retryAfter = 0;
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$retryAfter): int {
                if (stripos($line, 'Retry-After:') === 0) {
                    $value = trim(substr($line, 12));
                    if (ctype_digit($value)) {
                        $retryAfter = min(300, (int)$value);
                    }
                }
                return strlen($line);
            },
        ]);
        $bodyResponse = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if (!is_string($bodyResponse)) {
            return ['state' => 'uncertain', 'code' => 'network_unknown'];
        }
        $response = ['status' => $status, 'body' => $bodyResponse, 'retry_after' => $retryAfter];
    }
    $status = (int)($response['status'] ?? 0);
    if ($status >= 200 && $status < 300) {
        $json = json_decode((string)($response['body'] ?? ''), true);
        $id = is_array($json) ? (string)($json['id'] ?? '') : '';
        return $id !== '' && strlen($id) <= 128
            ? ['state' => 'accepted', 'id' => $id]
            : ['state' => 'uncertain', 'code' => 'receipt_unknown'];
    }
    if ($status === 429 || ($status >= 500 && $status <= 599)) {
        return ['state' => 'retry', 'code' => $status === 429 ? 'rate_limited' : 'provider_5xx',
            'retry_after' => min(300, max(0, (int)($response['retry_after'] ?? 0)))];
    }
    return ['state' => 'failed', 'code' => 'provider_rejected'];
}
