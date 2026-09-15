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

function brvtal_page_content_block_text(mixed $block): ?string
{
    if (!is_array($block)) {
        return null;
    }

    $type = strtolower(trim((string)($block['type'] ?? '')));
    if (!in_array($type, ['text', 'paragraph', 'heading'], true)) {
        return null;
    }

    foreach (['content', 'text', 'body'] as $field) {
        if (array_key_exists($field, $block) && is_string($block[$field])) {
            return trim($block[$field]);
        }
    }

    return null;
}

function brvtal_page_content_structure_error(mixed $value): ?string
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }

    $jsonError = brvtal_page_content_json_error($raw);
    if ($jsonError !== null) {
        return $jsonError;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || $decoded === []) {
        return null;
    }

    if (!array_is_list($decoded)) {
        foreach (['body', 'content', 'text'] as $field) {
            if (array_key_exists($field, $decoded) && is_string($decoded[$field])) {
                return null;
            }
        }

        if (!array_key_exists('blocks', $decoded) || !is_array($decoded['blocks'])) {
            return 'PAGE_CONTENT_STRUCTURE_UNSUPPORTED';
        }
        $blocks = $decoded['blocks'];
    } else {
        $blocks = $decoded;
    }

    foreach ($blocks as $block) {
        if (brvtal_page_content_block_text($block) === null) {
            return 'PAGE_CONTENT_STRUCTURE_UNSUPPORTED';
        }
    }

    return null;
}

function brvtal_page_content_plain_text(mixed $value): string
{
    $raw = trim((string)$value);
    if ($raw === '' || brvtal_page_content_json_error($raw) !== null) {
        return '';
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || $decoded === []) {
        return '';
    }

    if (!array_is_list($decoded)) {
        foreach (['body', 'content', 'text'] as $field) {
            if (array_key_exists($field, $decoded) && is_string($decoded[$field])) {
                return trim($decoded[$field]);
            }
        }
        $blocks = isset($decoded['blocks']) && is_array($decoded['blocks']) ? $decoded['blocks'] : [];
    } else {
        $blocks = $decoded;
    }

    $parts = [];
    foreach ($blocks as $block) {
        $text = brvtal_page_content_block_text($block);
        if ($text !== null && $text !== '') {
            $parts[] = $text;
        }
    }

    return implode("\n\n", $parts);
}
