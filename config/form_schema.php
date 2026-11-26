<?php
declare(strict_types=1);

/** Pure contract for a trusted, immutable published form version; no I/O. */
function brvtal_form_schema_fail(): never
{
    throw new InvalidArgumentException('FORM_SCHEMA_INVALID');
}

function brvtal_form_schema_keys(array $value, array $allowed): void
{
    if (array_diff(array_keys($value), $allowed) !== []) {
        brvtal_form_schema_fail();
    }
}

function brvtal_form_schema_id(mixed $value): bool
{
    return is_string($value) && preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $value) === 1;
}

function brvtal_form_schema_text(mixed $value, int $max, bool $required = true): bool
{
    return is_string($value) && strlen($value) <= $max * 4
        && preg_match('/\A.{0,' . $max . '}\z/usD', $value) === 1
        && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 0
        && (!$required || preg_match('/[^\p{Z}\s]/u', $value) === 1);
}

function brvtal_form_schema_copy(mixed $value, int $max, bool $required = true): bool
{
    return brvtal_form_schema_text($value, $max, $required)
        && preg_match('~[<>]|(?:https?|javascript|data|vbscript|file):|//~iu', $value) === 0;
}

/** Validate and return the field index without changing the historical definition. */
function brvtal_form_schema_validate(mixed $definition): array
{
    if (!is_array($definition)) {
        brvtal_form_schema_fail();
    }
    brvtal_form_schema_keys($definition, ['form_key', 'version_number', 'fields']);
    $fields = $definition['fields'] ?? null;
    if (!brvtal_form_schema_id($definition['form_key'] ?? null)
        || !is_int($definition['version_number'] ?? null) || $definition['version_number'] < 1
        || $definition['version_number'] > 4294967295
        || !is_array($fields) || !array_is_list($fields) || count($fields) < 1 || count($fields) > 32) {
        brvtal_form_schema_fail();
    }
    $index = [];
    $orders = [];
    $consents = [];
    foreach ($fields as $field) {
        if (!is_array($field)) {
            brvtal_form_schema_fail();
        }
        brvtal_form_schema_keys($field, ['id', 'order', 'label', 'help', 'type', 'required', 'max_length', 'options', 'notice_version', 'purpose']);
        $id = $field['id'] ?? null;
        $order = $field['order'] ?? null;
        $type = $field['type'] ?? null;
        $required = $field['required'] ?? null;
        if (!brvtal_form_schema_id($id) || isset($index[$id])
            || preg_match('/(?:^|_)(?:password|passwd|pwd|passphrase|secret|token|csrf|otp|pin|cvv|cvc|card|payment|qr|file|upload|credential|authorization|apikey|privatekey|creditcard|bank)(?:_|$)/', $id) === 1
            || preg_match('/password|passwd|passphrase|secret|token|credential|creditcard|apikey|privatekey|payment/', $id) === 1
            || in_array($id, ['id', 'form_key', 'version_number', 'definition_sha256', 'entry_id', 'payload', 'payload_json', 'notice_version', 'purpose_key', 'retention_policy_key', 'idempotency_key', 'idempotency_digest', 'state', 'created_at_utc', 'submit', 'honeypot', 'captcha', 'constructor', 'prototype'], true)
            || !is_int($order) || $order < 0 || $order > 31 || isset($orders[$order])
            || !in_array($type, ['text', 'email', 'textarea', 'select', 'radio', 'checkbox', 'date', 'consent'], true)
            || !is_bool($required) || !brvtal_form_schema_copy($field['label'] ?? null, 120)
            || (array_key_exists('help', $field) && !brvtal_form_schema_copy($field['help'], 300, false))) {
            brvtal_form_schema_fail();
        }
        if (in_array($type, ['text', 'email', 'textarea'], true)) {
            $length = $field['max_length'] ?? null;
            if (!is_int($length) || $length < 1 || $length > ($type === 'email' ? 254 : 4096)) {
                brvtal_form_schema_fail();
            }
        } elseif (array_key_exists('max_length', $field)) {
            brvtal_form_schema_fail();
        }
        if (in_array($type, ['select', 'radio'], true)) {
            $options = $field['options'] ?? null;
            if (!is_array($options) || !array_is_list($options) || count($options) < 1 || count($options) > 32) {
                brvtal_form_schema_fail();
            }
            $seen = [];
            foreach ($options as $option) {
                if (!is_array($option)) {
                    brvtal_form_schema_fail();
                }
                brvtal_form_schema_keys($option, ['value', 'label']);
                $value = $option['value'] ?? null;
                if (!brvtal_form_schema_id($value) || isset($seen[$value]) || !brvtal_form_schema_copy($option['label'] ?? null, 120)) {
                    brvtal_form_schema_fail();
                }
                $seen[$value] = true;
            }
        } elseif (array_key_exists('options', $field)) {
            brvtal_form_schema_fail();
        }
        if ($type === 'consent') {
            $purpose = $field['purpose'] ?? null;
            if (!in_array($purpose, ['privacy', 'marketing'], true) || isset($consents[$purpose])
                || ($purpose === 'privacy' && !$required) || ($purpose === 'marketing' && $required)
                || !is_string($field['notice_version'] ?? null)
                || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,63}\z/D', $field['notice_version']) !== 1) {
                brvtal_form_schema_fail();
            }
            $consents[$purpose] = true;
        } elseif (array_key_exists('notice_version', $field) || array_key_exists('purpose', $field)) {
            brvtal_form_schema_fail();
        }
        $index[$id] = $field;
        $orders[$order] = true;
    }
    uasort($index, static fn(array $a, array $b): int => $a['order'] <=> $b['order']);
    return $index;
}

function brvtal_form_payload_fail(): never
{
    throw new InvalidArgumentException('FORM_PAYLOAD_INVALID');
}

/** Raw JSON is required: decoding to a PHP array first would hide duplicate keys. */
function brvtal_form_payload_decode(mixed $json): array
{
    if (!is_string($json) || strlen($json) > 65536) {
        brvtal_form_payload_fail();
    }
    try {
        $decoded = json_decode($json, false, 4, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        brvtal_form_payload_fail();
    }
    if (!$decoded instanceof stdClass) {
        brvtal_form_payload_fail();
    }
    $values = get_object_vars($decoded);
    if (count($values) > 32) {
        brvtal_form_payload_fail();
    }
    foreach ($values as $value) {
        if (!is_string($value) && !is_bool($value)) {
            brvtal_form_payload_fail();
        }
    }
    // Validated flat object: tokenize complete JSON strings before scanning colons.
    if (preg_match_all('~"(?:[^"\\\\]++|\\\\.)*+"|:~s', $json, $tokens) === false) {
        brvtal_form_payload_fail();
    }
    $seen = [];
    foreach ($tokens[0] as $i => $token) {
        if ($token !== ':' || $i === 0) {
            continue;
        }
        $key = json_decode($tokens[0][$i - 1], true, 2, JSON_THROW_ON_ERROR);
        if (!is_string($key) || isset($seen[$key])) {
            brvtal_form_payload_fail();
        }
        $seen[$key] = true;
    }
    if (count($seen) !== count($values)) {
        brvtal_form_payload_fail();
    }
    return $values;
}

function brvtal_form_payload_date(mixed $value): bool
{
    return is_string($value) && preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/D', $value, $m) === 1
        && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

/** Caller resolves the published version by form key/version, never from client definition. */
function brvtal_form_payload_validate(mixed $published, mixed $formKey, mixed $version, mixed $json): array
{
    $fields = brvtal_form_schema_validate($published);
    if ($formKey !== $published['form_key'] || $version !== $published['version_number']) {
        brvtal_form_payload_fail();
    }
    $values = brvtal_form_payload_decode($json);
    if (array_diff_key($values, $fields) !== []) {
        brvtal_form_payload_fail();
    }
    $result = [];
    foreach ($fields as $id => $field) {
        if (!array_key_exists($id, $values)) {
            if ($field['required']) {
                brvtal_form_payload_fail();
            }
            continue;
        }
        $value = $values[$id];
        $valid = match ($field['type']) {
            'text', 'textarea' => brvtal_form_schema_text($value, $field['max_length'], $field['required']),
            'email' => brvtal_form_schema_text($value, $field['max_length']) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'date' => brvtal_form_payload_date($value),
            'select', 'radio' => is_string($value) && in_array($value, array_column($field['options'], 'value'), true),
            'checkbox', 'consent' => is_bool($value) && (!$field['required'] || $value),
            default => false,
        };
        if (!$valid) {
            brvtal_form_payload_fail();
        }
        $result[$id] = $value;
    }
    return $result;
}
