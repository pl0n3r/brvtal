<?php
declare(strict_types=1);

/**
 * Validate generic resource CREATE data before sanitization and INSERT.
 * Database NOT NULL and integer-bound violations are user input (422), not 5xx.
 * Pure to permit deterministic synthetic tests without real data or DB access.
 *
 * @return array{error:string,field:string}|null
 */
function brvtal_api_create_input_error(string $resource, array $input): ?array
{
    $required = match ($resource) {
        'events' => ['title', 'slug'],
        'artists' => ['name', 'slug'],
        'sets' => ['title', 'external_url'],
        'ticket_types' => ['event_id', 'name'],
        default => null,
    };
    if ($required === null) {
        return null;
    }

    $textFields = [
        'title', 'name', 'slug', 'venue', 'venue_address', 'city', 'description',
        'skin', 'accent', 'cover_image', 'ticket_url', 'ticket_instructions',
        'ticket_qr', 'bio', 'photo', 'instagram_url', 'soundcloud_url',
        'website_url', 'platform', 'external_url', 'embed_url', 'currency',
        'payment_instructions', 'qr_image', 'status', 'event_date', 'publish_at',
        'available_from', 'available_until',
    ];
    foreach ($textFields as $field) {
        if (!array_key_exists($field, $input) || $input[$field] === null) {
            continue;
        }
        if (!is_string($input[$field])) {
            return ['error' => 'INVALID_FIELD_TYPE', 'field' => $field];
        }
        // Unicode and RTL remain legitimate; embedded control bytes do not.
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $input[$field]) === 1) {
            return ['error' => 'INVALID_FIELD_VALUE', 'field' => $field];
        }
    }

    // MariaDB signed INT/unsigned INT bounds. Never cast enormous JSON floats.
    $integerFields = [
        'sort_order' => [-2147483648, 2147483647],
        'event_id' => [1, 4294967295],
        'artist_id' => [1, 4294967295],
        'archive_year' => [0, 9999],
    ];
    foreach ($integerFields as $field => [$min, $max]) {
        if (!array_key_exists($field, $input) || $input[$field] === null) {
            continue;
        }
        $value = $input[$field];
        if (!is_int($value) && (!is_string($value)
            || preg_match('/^-?(0|[1-9][0-9]*)$/D', $value) !== 1)) {
            return ['error' => 'INVALID_INTEGER', 'field' => $field];
        }
        if (is_string($value) && strlen($value) > 11) {
            return ['error' => 'INVALID_INTEGER', 'field' => $field];
        }
        $number = (int)$value;
        if ($number < $min || $number > $max) {
            return ['error' => 'INVALID_INTEGER', 'field' => $field];
        }
    }

    if (array_key_exists('price', $input) && $input['price'] !== null
        && $input['price'] !== '') {
        $price = $input['price'];
        if ((!is_int($price) && !is_float($price) && !is_string($price))
            || !is_numeric($price) || !is_finite((float)$price)
            || (float)$price < 0 || (float)$price >= 10000000000) {
            return ['error' => 'INVALID_PRICE', 'field' => 'price'];
        }
    }

    foreach ($required as $field) {
        if (!array_key_exists($field, $input) || $input[$field] === null) {
            return ['error' => 'REQUIRED_FIELD', 'field' => $field];
        }
        if ($field === 'event_id') {
            continue;
        }
        if (!is_string($input[$field])) {
            return ['error' => 'INVALID_FIELD_TYPE', 'field' => $field];
        }
        // Empty slug already triggers a server-side slugify fallback.
        // Empty external_url is a legitimate draft Set, unlike an omitted key.
        if (trim($input[$field]) === ''
            && !in_array($field, ['slug', 'external_url'], true)) {
            return ['error' => 'REQUIRED_FIELD', 'field' => $field];
        }
    }

    if ($resource === 'ticket_types' && mb_strlen((string)$input['name']) > 120) {
        return ['error' => 'INVALID_FIELD_LENGTH', 'field' => 'name'];
    }
    return null;
}
