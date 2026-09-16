<?php
declare(strict_types=1);

/** Parse an exact temporal value without accepting rollover or partial matches. */
function brvtal_exact_temporal_value(string $value, array $formats): ?string
{
    foreach ($formats as [$inputFormat, $outputFormat]) {
        $date = DateTimeImmutable::createFromFormat('!' . $inputFormat, $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date) continue;
        if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) continue;
        if ($date->format($inputFormat) !== $value) continue;
        return $date->format($outputFormat);
    }
    return null;
}

/** Return the required editorial identity field for resources that cannot be emptied. */
function brvtal_required_identity_field(string $resource): ?array
{
    return match ($resource) {
        'events' => ['field' => 'title', 'error' => 'TITLE_REQUIRED'],
        'artists' => ['field' => 'name', 'error' => 'NAME_REQUIRED'],
        'sets' => ['field' => 'title', 'error' => 'TITLE_REQUIRED'],
        default => null,
    };
}

/**
 * Reject explicit attempts to clear a required identity while still allowing
 * partial updates that do not touch that field.
 */
function brvtal_required_identity_error(string $resource, array $payload): ?array
{
    $rule = brvtal_required_identity_field($resource);
    if ($rule === null || !array_key_exists($rule['field'], $payload)) return null;
    if (trim((string)$payload[$rule['field']]) !== '') return null;
    return ['error' => $rule['error'], 'field' => $rule['field']];
}

/** Normalize supported temporal fields and surface deterministic validation errors. */
function brvtal_content_temporal_normalize(string $resource, array $payload): array
{
    $identityError = brvtal_required_identity_error($resource, $payload);
    if ($identityError !== null) {
        return ['payload' => $payload, 'error' => $identityError];
    }

    $fields = match ($resource) {
        'events' => [
            'event_date' => [
                ['Y-m-d H:i:s', 'Y-m-d H:i:s'],
                ['Y-m-d H:i', 'Y-m-d H:i:s'],
                ['Y-m-d\\TH:i:s', 'Y-m-d H:i:s'],
                ['Y-m-d\\TH:i', 'Y-m-d H:i:s'],
            ],
        ],
        'ticket_types' => [
            'available_from' => [
                ['Y-m-d H:i:s', 'Y-m-d H:i:s'],
                ['Y-m-d H:i', 'Y-m-d H:i:s'],
                ['Y-m-d\\TH:i:s', 'Y-m-d H:i:s'],
                ['Y-m-d\\TH:i', 'Y-m-d H:i:s'],
            ],
            'available_until' => [
                ['Y-m-d H:i:s', 'Y-m-d H:i:s'],
                ['Y-m-d H:i', 'Y-m-d H:i:s'],
                ['Y-m-d\\TH:i:s', 'Y-m-d H:i:s'],
                ['Y-m-d\\TH:i', 'Y-m-d H:i:s'],
            ],
        ],
        'artists' => [
            'collective_joined_at' => [['Y-m-d', 'Y-m-d']],
            'collective_left_at' => [['Y-m-d', 'Y-m-d']],
        ],
        default => [],
    };

    foreach ($fields as $field => $formats) {
        if (!array_key_exists($field, $payload)) continue;
        $value = $payload[$field];
        if (is_array($value) || is_object($value)) {
            return [
                'payload' => $payload,
                'error' => ['error' => 'INVALID_DATE', 'field' => $field],
            ];
        }
        $raw = trim((string)$value);
        if ($raw === '') {
            $payload[$field] = null;
            continue;
        }
        $normalized = brvtal_exact_temporal_value($raw, $formats);
        if ($normalized === null) {
            return [
                'payload' => $payload,
                'error' => ['error' => 'INVALID_DATE', 'field' => $field],
            ];
        }
        $payload[$field] = $normalized;
    }

    return ['payload' => $payload, 'error' => null];
}

/** Validate the editorial invariants required for every non-draft Event state. */
function brvtal_event_publication_error(array $state): ?array
{
    if (trim((string)($state['title'] ?? '')) === '') {
        return ['error' => 'TITLE_REQUIRED', 'field' => 'title'];
    }
    $status = strtolower(trim((string)($state['status'] ?? 'draft')));
    if ($status === 'draft') return null;
    if (trim((string)($state['event_date'] ?? '')) === '') {
        return ['error' => 'EVENT_DATE_REQUIRED', 'field' => 'event_date'];
    }
    if (trim((string)($state['city'] ?? '')) === '') {
        return ['error' => 'EVENT_CITY_REQUIRED', 'field' => 'city'];
    }
    return null;
}

/** Validate a Ticket Type availability interval when both bounds are present. */
function brvtal_ticket_window_error(array $state): ?array
{
    $from = trim((string)($state['available_from'] ?? ''));
    $until = trim((string)($state['available_until'] ?? ''));
    if ($from === '' || $until === '') return null;

    if ($until < $from) {
        return ['error' => 'INVALID_AVAILABILITY_WINDOW', 'field' => 'available_until'];
    }
    return null;
}

/** Validate the identity fields required for every CMS Page. */
function brvtal_page_identity_error(array $state): ?array
{
    if (trim((string)($state['title'] ?? '')) === '') {
        return ['error' => 'TITLE_REQUIRED', 'field' => 'title'];
    }
    if (trim((string)($state['slug'] ?? '')) === '') {
        return ['error' => 'SLUG_REQUIRED', 'field' => 'slug'];
    }
    return null;
}
