<?php
declare(strict_types=1);
require_once __DIR__ . '/secrets.php';
require_once __DIR__ . '/buyer_auth_mail_templates.php';

/**
 * Isolated Resend adapter. Live requires the protected production gates; test
 * sends only to an explicit recipient allowlist. Never logs recipient or API key.
 * The caller supplies the stable outbox message_key as Idempotency-Key.
 *
 * Resend retains idempotency keys for 24h:
 * https://resend.com/changelog/idempotency-keys
 */
/**
 * Read a bounded allowlist from private ticketing.mail.test_recipients or the
 * encrypted Settings integration key. Invalid lists fail closed (no sending).
 * Values are never returned to a browser or written to logs.
 *
 * @return list<string>
 */
function brvtal_ticket_test_recipients(): array
{
    try {
        $raw = brvtal_secret_value('BRVTAL_TICKET_MAIL_TEST_RECIPIENTS');
    } catch (Throwable) {
        return [];
    }
    if ($raw === '' || strlen($raw) > 4096) {
        return [];
    }
    $parts = preg_split('/[\r\n,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($parts) || !$parts || count($parts) > 25) {
        return [];
    }
    $recipients = [];
    foreach ($parts as $item) {
        $email = strtolower(trim($item));
        if ($email === '' || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return [];
        }
        $recipients[$email] = true;
    }
    return array_keys($recipients);
}

/** An unauthorized address is never handed to Resend, even with a callback. */
function brvtal_ticket_test_recipient_allowed(string $recipient): bool
{
    $recipient = strtolower(trim($recipient));
    return filter_var($recipient, FILTER_VALIDATE_EMAIL) !== false
        && in_array($recipient, brvtal_ticket_test_recipients(), true);
}

function brvtal_ticket_resend_send(array $job, ?callable $request = null): array
{
    $mode = (string)(brvtal_secret_value('BRVTAL_TICKET_MAIL_MODE') ?: 'disabled');
    $email = (string)($job['recipient_email'] ?? '');
    $mock = $mode === 'mock' && brvtal_secret_value('BRVTAL_TICKET_MAIL_TESTING') === '1';
    $testMode = $mode === 'test';
    // Correo de prueba pedido por un administrador desde DISCADMIN: sale con la
    // configuración guardada sin depender del modo live/test (decisión del dueño,
    // #1904). Solo el endpoint autenticado y con CSRF fija este indicador.
    $adminTest = ($job['admin_test'] ?? false) === true;
    $live = $mode === 'live'
        && brvtal_secret_value('BRVTAL_TICKET_MAIL_ALLOW_LIVE') === '1'
        && brvtal_secret_value('BRVTAL_TICKET_MAIL_PRIVACY_APPROVED') === '1'
        && brvtal_secret_value('BRVTAL_TICKET_MAIL_DNS_VERIFIED') === '1';
    if ($adminTest) {
        $mock = false;
        $testMode = false;
        $live = true;
    } elseif ($testMode && !brvtal_ticket_test_recipient_allowed($email)) {
        return ['state' => 'suppressed_test', 'code' => 'recipient_not_allowlisted'];
    }
    if (!$mock && !$testMode && !$live) {
        return ['state' => 'failed', 'code' => 'transport_disabled'];
    }

    $key = (string)($job['message_key'] ?? '');
    $subject = (($testMode || $adminTest) ? '[PRUEBA] ' : '') . (string)($job['subject'] ?? '');
    $body = (string)($job['body_text'] ?? '');
    if (str_starts_with($body, BRVTAL_BUYER_MAIL_SEAL_PREFIX)) {
        $revealed = brvtalBuyerMailUnseal($body);
        if (!is_string($revealed)) {
            return ['state' => 'failed', 'code' => 'invalid_configuration'];
        }
        // The bearer exists only in memory for this provider request; never log.
        $body = $revealed;
    }
    $html = $job['body_html'] ?? null;
    if ($html !== null) {
        if (!is_string($html)) return ['state'=>'failed','code'=>'invalid_configuration'];
        if (str_starts_with($html, BRVTAL_BUYER_MAIL_SEAL_PREFIX)) {
            $html = brvtalBuyerMailUnseal($html);
            if ($html === null) return ['state'=>'failed','code'=>'invalid_configuration'];
        }
        if (!str_starts_with($html, '<!doctype html>') || strlen($html) > 150000) {
            return ['state'=>'failed','code'=>'invalid_configuration'];
        }
    }
    $domain = strtolower(trim((string)brvtal_secret_value('BRVTAL_TICKET_MAIL_DOMAIN')));
    $from = trim((string)brvtal_secret_value('BRVTAL_TICKET_MAIL_FROM'));
    $apiKey = trim((string)brvtal_secret_value('BRVTAL_TICKET_MAIL_API_KEY'));
    if ($mock) {
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
        || (($live || $testMode) && $apiKey === '')) {
        return ['state' => 'failed', 'code' => 'invalid_configuration'];
    }
    $payload = ['from' => $from, 'to' => [$email], 'subject' => $subject, 'text' => $body];
    if ($html !== null) $payload['html'] = $html;
    $headers = ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json',
        'Idempotency-Key: ' . $key];
    if ($mock && $request === null) {
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
