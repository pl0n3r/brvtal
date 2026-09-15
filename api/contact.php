<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/public_contact.php';

if (!headers_sent()) {
    header('Cache-Control: no-store');
    header('Pragma: no-cache');
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    try {
        json_response(['ok' => true, 'data' => brvtal_contact_issue_challenge($config)]);
    } catch (Throwable $e) {
        brvtal_log('CONTACT_CHALLENGE_ERROR', 'Unable to issue contact challenge', ['class' => get_class($e)]);
        json_response(['ok' => false, 'error' => 'CONTACT_UNAVAILABLE'], 503);
    }
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    json_response(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED'], 405);
}

$rate = brvtal_contact_consume_rate_limit(brvtal_contact_client_key());
if (!$rate['allowed']) {
    $status = ($rate['error'] ?? '') === 'RATE_LIMITED' ? 429 : 503;
    json_response(
        ['ok' => false, 'error' => $rate['error'] ?? 'CONTACT_UNAVAILABLE', 'retry_after' => (int)($rate['retry_after'] ?? 60)],
        $status,
        ['Retry-After' => (string)(int)($rate['retry_after'] ?? 60)]
    );
}

try {
    $input = input_json();
    $validation = brvtal_contact_validate_payload($input, $config);
    if (!$validation['ok']) {
        json_response(['ok' => false, 'error' => 'VALIDATION_FAILED', 'fields' => $validation['errors']], 422);
    }

    if (!brvtal_contact_send($validation['data'], $config)) {
        brvtal_log('CONTACT_DELIVERY_ERROR', 'Contact mail transport failed', [
            'recipient_domain' => substr(strrchr(brvtal_contact_recipient($config), '@') ?: '', 1),
        ]);
        json_response(['ok' => false, 'error' => 'CONTACT_DELIVERY_UNAVAILABLE'], 503);
    }

    json_response(['ok' => true, 'message' => 'MESSAGE_SENT']);
} catch (Throwable $e) {
    brvtal_log('CONTACT_ERROR', 'Unexpected public contact error', ['class' => get_class($e)]);
    json_response(['ok' => false, 'error' => 'CONTACT_UNAVAILABLE'], 503);
}
