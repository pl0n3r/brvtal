<?php
declare(strict_types=1);

/** Pure contract for a trusted, immutable published form version; no I/O. */
function brvtalFormSchemaFail(): never
{
    throw new InvalidArgumentException('FORM_SCHEMA_INVALID');
}

function brvtalFormSchemaKeys(array $value, array $allowed): void
{
    if (array_diff(array_keys($value), $allowed) !== []) {
        brvtalFormSchemaFail();
    }
}

function brvtalFormSchemaId(mixed $value): bool
{
    return is_string($value) && preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $value) === 1;
}

function brvtalFormSchemaText(mixed $value, int $max, bool $required = true): bool
{
    return is_string($value) && strlen($value) <= $max * 4
        && preg_match('/\A.{0,' . $max . '}\z/usD', $value) === 1
        && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 0
        && (!$required || preg_match('/[^\p{Z}\s]/u', $value) === 1);
}

function brvtalFormSchemaCopy(mixed $value, int $max, bool $required = true): bool
{
    return brvtalFormSchemaText($value, $max, $required)
        && preg_match('~[<>]|(?:https?|javascript|data|vbscript|file):|//~iu', $value) === 0;
}

/** Validate and return the field index without changing the historical definition. */
function brvtalFormSchemaValidate(mixed $definition): array
{
    if (!is_array($definition)) {
        brvtalFormSchemaFail();
    }
    brvtalFormSchemaKeys($definition, ['form_key', 'version_number', 'fields']);
    $fields = $definition['fields'] ?? null;
    if (!brvtalFormSchemaId($definition['form_key'] ?? null)
        || !is_int($definition['version_number'] ?? null) || $definition['version_number'] < 1
        || $definition['version_number'] > 4294967295
        || !is_array($fields) || !array_is_list($fields) || count($fields) < 1 || count($fields) > 32) {
        brvtalFormSchemaFail();
    }
    $index = [];
    $orders = [];
    $consents = [];
    foreach ($fields as $field) {
        if (!is_array($field)) {
            brvtalFormSchemaFail();
        }
        brvtalFormSchemaKeys($field, [
            'id', 'order', 'label', 'help', 'type', 'required', 'max_length',
            'options', 'notice_version', 'purpose',
        ]);
        $id = $field['id'] ?? null;
        $order = $field['order'] ?? null;
        $type = $field['type'] ?? null;
        $required = $field['required'] ?? null;
        if (!brvtalFormSchemaId($id) || isset($index[$id])
            || preg_match(
                '/(?:^|_)(?:password|passwd|pwd|passphrase|secret|token|csrf|otp|pin|cvv|cvc|card|'
                . 'payment|qr|file|upload|credential|authorization|apikey|privatekey|creditcard|bank)(?:_|$)/',
                $id
            ) === 1
            || preg_match(
                '/password|passwd|passphrase|secret|token|credential|creditcard|apikey|privatekey|payment/',
                $id
            ) === 1
            || in_array($id, [
                'id', 'form_key', 'version_number', 'definition_sha256', 'entry_id', 'payload',
                'payload_json', 'notice_version', 'purpose_key', 'retention_policy_key',
                'idempotency_key', 'idempotency_digest', 'state', 'created_at_utc', 'submit',
                'honeypot', 'captcha', 'constructor', 'prototype',
            ], true)
            || !is_int($order) || $order < 0 || $order > 31 || isset($orders[$order])
            || !in_array($type, ['text', 'email', 'textarea', 'select', 'radio', 'checkbox', 'date', 'consent'], true)
            || !is_bool($required) || !brvtalFormSchemaCopy($field['label'] ?? null, 120)
            || (array_key_exists('help', $field) && !brvtalFormSchemaCopy($field['help'], 300, false))) {
            brvtalFormSchemaFail();
        }
        if (in_array($type, ['text', 'email', 'textarea'], true)) {
            $length = $field['max_length'] ?? null;
            if (!is_int($length) || $length < 1 || $length > ($type === 'email' ? 254 : 4096)) {
                brvtalFormSchemaFail();
            }
        } elseif (array_key_exists('max_length', $field)) {
            brvtalFormSchemaFail();
        }
        if (in_array($type, ['select', 'radio'], true)) {
            $options = $field['options'] ?? null;
            if (!is_array($options) || !array_is_list($options) || count($options) < 1 || count($options) > 32) {
                brvtalFormSchemaFail();
            }
            $seen = [];
            foreach ($options as $option) {
                if (!is_array($option)) {
                    brvtalFormSchemaFail();
                }
                brvtalFormSchemaKeys($option, ['value', 'label']);
                $value = $option['value'] ?? null;
                if (!brvtalFormSchemaId($value) || isset($seen[$value])
                    || !brvtalFormSchemaCopy($option['label'] ?? null, 120)) {
                    brvtalFormSchemaFail();
                }
                $seen[$value] = true;
            }
        } elseif (array_key_exists('options', $field)) {
            brvtalFormSchemaFail();
        }
        if ($type === 'consent') {
            $purpose = $field['purpose'] ?? null;
            if (!in_array($purpose, ['privacy', 'marketing'], true) || isset($consents[$purpose])
                || ($purpose === 'privacy' && !$required) || ($purpose === 'marketing' && $required)
                || !is_string($field['notice_version'] ?? null)
                || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,63}\z/D', $field['notice_version']) !== 1) {
                brvtalFormSchemaFail();
            }
            $consents[$purpose] = true;
        } elseif (array_key_exists('notice_version', $field) || array_key_exists('purpose', $field)) {
            brvtalFormSchemaFail();
        }
        $index[$id] = $field;
        $orders[$order] = true;
    }
    uasort($index, static fn(array $a, array $b): int => $a['order'] <=> $b['order']);
    return $index;
}

function brvtalFormPayloadFail(): never
{
    throw new InvalidArgumentException('FORM_PAYLOAD_INVALID');
}

/** Raw JSON is required: decoding to a PHP array first would hide duplicate keys. */
function brvtalFormPayloadDecode(mixed $json): array
{
    if (!is_string($json) || strlen($json) > 65536) {
        brvtalFormPayloadFail();
    }
    try {
        $decoded = json_decode($json, false, 4, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        brvtalFormPayloadFail();
    }
    if (!$decoded instanceof stdClass) {
        brvtalFormPayloadFail();
    }
    $values = get_object_vars($decoded);
    if (count($values) > 32) {
        brvtalFormPayloadFail();
    }
    foreach ($values as $value) {
        if (!is_string($value) && !is_bool($value)) {
            brvtalFormPayloadFail();
        }
    }
    // Validated flat object: tokenize complete JSON strings before scanning colons.
    if (preg_match_all('~"(?:[^"\\\\]++|\\\\.)*+"|:~s', $json, $tokens) === false) {
        brvtalFormPayloadFail();
    }
    $seen = [];
    foreach ($tokens[0] as $i => $token) {
        if ($token !== ':' || $i === 0) {
            continue;
        }
        $key = json_decode($tokens[0][$i - 1], true, 2, JSON_THROW_ON_ERROR);
        if (!is_string($key) || isset($seen[$key])) {
            brvtalFormPayloadFail();
        }
        $seen[$key] = true;
    }
    if (count($seen) !== count($values)) {
        brvtalFormPayloadFail();
    }
    return $values;
}

function brvtalFormPayloadDate(mixed $value): bool
{
    return is_string($value) && preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/D', $value, $m) === 1
        && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

/** Caller resolves the published version by form key/version, never from client definition. */
function brvtalFormPayloadValidate(mixed $published, mixed $formKey, mixed $version, mixed $json): array
{
    $fields = brvtalFormSchemaValidate($published);
    if ($formKey !== $published['form_key'] || $version !== $published['version_number']) {
        brvtalFormPayloadFail();
    }
    $values = brvtalFormPayloadDecode($json);
    if (array_diff_key($values, $fields) !== []) {
        brvtalFormPayloadFail();
    }
    $result = [];
    foreach ($fields as $id => $field) {
        if (!array_key_exists($id, $values)) {
            if ($field['required']) {
                brvtalFormPayloadFail();
            }
            continue;
        }
        $value = $values[$id];
        $valid = match ($field['type']) {
            'text', 'textarea' => brvtalFormSchemaText($value, $field['max_length'], $field['required']),
            'email' => brvtalFormSchemaText($value, $field['max_length'])
                && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'date' => brvtalFormPayloadDate($value),
            'select', 'radio' => is_string($value) && in_array($value, array_column($field['options'], 'value'), true),
            'checkbox', 'consent' => is_bool($value) && (!$field['required'] || $value),
            default => false,
        };
        if (!$valid) {
            brvtalFormPayloadFail();
        }
        $result[$id] = $value;
    }
    return $result;
}
