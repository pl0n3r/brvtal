<?php
declare(strict_types=1);
// Rector probe #686; removed before merge.

/**
 * Return a stable validation error when a Set is not safe to publish.
 * Draft Sets may remain incomplete; public Sets need a real http(s) listening URL.
 */
function brvtal_set_publication_error(array $set): ?string
{
    if (strtolower(trim((string)($set['status'] ?? 'draft'))) !== 'published') {
        return null;
    }

    $url = trim((string)($set['external_url'] ?? ''));
    if ($url === '') {
        return 'SET_LISTENING_URL_REQUIRED';
    }

    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        return 'INVALID_SET_LISTENING_URL';
    }

    $scheme = strtolower((string)(parse_url($url, PHP_URL_SCHEME) ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return 'INVALID_SET_LISTENING_URL';
    }

    return null;
}
