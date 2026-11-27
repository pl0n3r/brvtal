<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/public_contact.php';
require_once __DIR__ . '/../config/public_abuse_guard.php';
require_once __DIR__ . '/../config/form_entries.php';

// The required bootstrap loads and validates this configuration.
/** @var array<string, mixed> $config */

// Versioned owner-approved policy (#2321); an explicit server setting always wins.
if (!isset($config['contact']['capture']) && is_file(__DIR__ . '/../config/contact_capture.php')) {
    $config['contact']['capture'] = require __DIR__ . '/../config/contact_capture.php';
}

if (!headers_sent()) {
    header('Cache-Control: no-store');
    header('Pragma: no-cache');
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    try {
        json_response(['ok' => true, 'data' => brvtal_contact_issue_challenge($config)]);
    } catch (Throwable $e) {
        brvtal_log('CONTACT_CHALLENGE_ERROR', 'Unable to issue contact challenge', ['class' => $e::class]);
        json_response(['ok' => false, 'error' => 'CONTACT_UNAVAILABLE'], 503);
    }
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    json_response(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED'], 405);
}

$rate = brvtal_contact_consume_rate_limit(brvtal_contact_client_key($config));
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
    $gate = brvtal_public_abuse_check('contact', $input, $config);
    if (!$gate['allowed']) {
        $headers = $gate['retry_after'] > 0
            ? ['Retry-After' => (string)$gate['retry_after']] : [];
        json_response(['ok'=>false,'error'=>$gate['error']],$gate['status'],$headers);
    }
    $validation = brvtal_contact_validate_payload($input, $config);
    if (!$validation['ok']) {
        json_response(['ok' => false, 'error' => 'VALIDATION_FAILED', 'fields' => $validation['errors']], 422);
    }

    // Build-ahead only: disabled unless an approved capture policy is configured.
    if (brvtal_contact_capture_policy($config) !== null) {
        $key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;
        $fresh = false;
        try {
            brvtal_contact_capture_key($key);
            $fresh = brvtal_contact_capture(db(), $validation['data'], $key, $config);
        } catch (InvalidArgumentException) {
            json_response(['ok' => false, 'error' => 'CONTACT_INTENT_INVALID'], 422);
        } catch (DomainException) {
            json_response(['ok' => false, 'error' => 'CONTACT_INTENT_CONFLICT'], 409);
        }
        // The entry is already committed: keep the owner's usual mail as best effort,
        // once per new entry, until the durable notification bridge (#2066) exists.
        if ($fresh) {
            try {
                $sent = brvtal_contact_send($validation['data'], $config);
            } catch (Throwable) {
                $sent = false;
            }
            if (!$sent) {
                brvtal_log('CONTACT_CAPTURED_MAIL_ERROR', 'Contact captured; legacy mail failed', []);
            }
        }
        json_response(['ok' => true, 'message' => 'MESSAGE_RECEIVED']);
    }

    if (!brvtal_contact_send($validation['data'], $config)) {
        brvtal_log('CONTACT_DELIVERY_ERROR', 'Contact mail transport failed', [
            'recipient_domain' => substr(strrchr(brvtal_contact_recipient($config), '@') ?: '', 1),
        ]);
        json_response(['ok' => false, 'error' => 'CONTACT_DELIVERY_UNAVAILABLE'], 503);
    }

    json_response(['ok' => true, 'message' => 'MESSAGE_SENT']);
} catch (Throwable $e) {
    brvtal_log('CONTACT_ERROR', 'Unexpected public contact error', ['class' => $e::class]);
    json_response(['ok' => false, 'error' => 'CONTACT_UNAVAILABLE'], 503);
}
