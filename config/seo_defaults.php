<?php
declare(strict_types=1);

function brvtal_seo_plain_text(mixed $value): string
{
    if ($value === null) return '';
    if (is_bool($value)) return '';
    if (is_int($value) || is_float($value)) return (string)$value;

    if (is_array($value)) {
        $parts = [];
        foreach ($value as $key => $item) {
            $keyName = strtolower((string)$key);
            if (in_array($keyName, ['id','type','slug','url','href','src','image','cover_image','photo','file_path','platform','status','locale','class','style'], true)) {
                continue;
            }
            $text = brvtal_seo_plain_text($item);
            if ($text !== '') $parts[] = $text;
        }
        return trim(implode(' ', $parts));
    }

    if (is_object($value)) return brvtal_seo_plain_text((array)$value);

    $raw = trim((string)$value);
    if ($raw === '') return '';

    $decoded = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        return brvtal_seo_plain_text($decoded);
    }

    $text = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return trim($text);
}

function brvtal_seo_truncate(string $value, int $limit): string
{
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    if ($limit < 1 || $value === '') return '';
    if (mb_strlen($value) <= $limit) return $value;

    $cut = rtrim(mb_substr($value, 0, $limit));
    $wordSafe = preg_replace('/\s+\S*$/u', '', $cut) ?? '';
    if ($wordSafe !== '' && mb_strlen($wordSafe) >= (int)floor($limit * 0.65)) {
        $cut = $wordSafe;
    }
    return rtrim($cut, " \t\n\r\0\x0B,.;:-");
}

function brvtal_seo_default_title(mixed $value): string
{
    return brvtal_seo_truncate(brvtal_seo_plain_text($value), 190);
}

function brvtal_seo_default_description(mixed $value, int $limit = 160): string
{
    return brvtal_seo_truncate(brvtal_seo_plain_text($value), $limit);
}
