<?php
declare(strict_types=1);

function brvtal_public_envelope(mixed $data, int $status = 200): array
{
    if ($status >= 400) {
        $error = is_array($data) ? trim((string)($data['error'] ?? '')) : '';
        return [
            'ok' => false,
            'error' => $error !== '' ? $error : 'REQUEST_FAILED',
        ];
    }

    return [
        'ok' => true,
        'data' => $data,
    ];
}

function brvtal_public_etag(array $payload): string
{
    return '"' . sha1(json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    )) . '"';
}
