<?php
declare(strict_types=1);

function brvtal_page_content_json_error(mixed $value): ?string
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        return 'INVALID_PAGE_CONTENT_JSON';
    }

    return null;
}

function brvtal_page_publication_error(array $state): ?string
{
    if (($state['status'] ?? 'draft') === 'published' && ($state['locale'] ?? 'en') !== 'en') {
        return 'PAGE_PUBLIC_LOCALE_MUST_BE_EN';
    }

    return null;
}
