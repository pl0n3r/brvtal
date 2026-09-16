<?php
declare(strict_types=1);

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

function brvtal_content_temporal_normalize(string $resource, array $payload): array
{
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
        $raw = trim((string)$payload[$field]);
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
