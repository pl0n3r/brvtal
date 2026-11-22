<?php
declare(strict_types=1);

/** Safe, intentionally small Markdown subset for public entity descriptions. */
/** Normalize padded Markdown emphasis while preserving HTML escaping. */
function brvtalPublicTextNormalizeEmphasis(string $text): string
{
    // Never match the closing delimiter of a previous span as a new opener.
    // Excluding interior asterisks keeps adjacent spans and their spaces intact.
    $text = preg_replace('/(?<![\pL\pN_*])\*\*[ \t]+(?=\S)([^*\r\n]*?\S)[ \t]*\*\*(?!\*)/u', '**$1**', $text) ?? $text;
    return preg_replace('/(?<![\pL\pN_*])\*[ \t]+(?=\S)([^*\r\n]*?\S)[ \t]*\*(?!\*)/u', '*$1*', $text) ?? $text;
}

function brvtalPublicTextEmphasis(string $text): string
{
    $html = htmlspecialchars(brvtalPublicTextNormalizeEmphasis($text), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/u', '<strong>$1</strong>', $html) ?? $html;
    $html = preg_replace('/(?<!\*)\*(?=\S)(.+?)(?<=\S)\*(?!\*)/u', '<em>$1</em>', $html) ?? $html;
    return preg_replace('/(?<![\pL\pN])_(?=\S)(.+?)(?<=\S)_(?![\pL\pN])/u', '<em>$1</em>', $html) ?? $html;
}

function brvtalPublicTextInline(string $line): string
{
    preg_match_all('/\[([^\[\]\r\n]+)\]\(([^)\r\n]+)\)/u', $line, $matches, PREG_OFFSET_CAPTURE);
    $html = '';
    $cursor = 0;
    foreach ($matches[0] as $index => $match) {
        $offset = $match[1];
        $html .= brvtalPublicTextEmphasis(substr($line, $cursor, $offset - $cursor));
        $url = trim($matches[2][$index][0]);
        $isHttps = preg_match('#^https://[^\s]+$#i', $url) === 1
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && parse_url($url, PHP_URL_USER) === null
            && parse_url($url, PHP_URL_PASS) === null;
        if ($isHttps) {
            $html .= '<a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '" rel="noopener noreferrer">' . brvtalPublicTextEmphasis($matches[1][$index][0]) . '</a>';
        } else {
            $html .= brvtalPublicTextEmphasis($match[0]);
        }
        $cursor = $offset + strlen($match[0]);
    }
    return $html . brvtalPublicTextEmphasis(substr($line, $cursor));
}

function brvtalPublicTextFormat(string $text): string
{
    $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
    if ($text === '') return '';
    $out = [];
    $paragraph = [];
    $list = [];
    $flushParagraph = static function () use (&$paragraph, &$out): void {
        if ($paragraph !== []) {
            $out[] = '<p>' . implode('<br>', $paragraph) . '</p>';
            $paragraph = [];
        }
    };
    $flushList = static function () use (&$list, &$out): void {
        if ($list !== []) {
            $out[] = '<ul>' . implode('', array_map(static fn(string $item): string => '<li>' . $item . '</li>', $list)) . '</ul>';
            $list = [];
        }
    };
    foreach (explode("\n", $text) as $line) {
        $line = trim($line);
        if ($line === '') {
            $flushParagraph();
            $flushList();
            continue;
        }
        if (preg_match('/^(#{1,3})[ \t]+(.+)$/u', $line, $heading)) {
            $flushParagraph();
            $flushList();
            $tag = strlen($heading[1]) === 3 ? 'h3' : 'h2';
            $out[] = '<' . $tag . '>' . brvtalPublicTextInline($heading[2]) . '</' . $tag . '>';
        } elseif (preg_match('/^-[ \t]+(.+)$/u', $line, $item)) {
            $flushParagraph();
            $list[] = brvtalPublicTextInline($item[1]);
        } else {
            $flushList();
            $paragraph[] = brvtalPublicTextInline($line);
        }
    }
    $flushParagraph();
    $flushList();
    return implode("\n", $out);
}

/** SEO and JSON-LD use readable text, never literal Markdown markup. */
function brvtalPublicTextPlain(string $text): string
{
    $text = brvtalPublicTextNormalizeEmphasis($text);
    $plain = preg_replace('/\[([^\]\r\n]+)\]\([^)]+\)/u', '$1', $text) ?? $text;
    $plain = preg_replace('/^\s{0,3}#{1,3}[ \t]+/mu', '', $plain) ?? $plain;
    $plain = preg_replace('/^\s*-[ \t]+/mu', '', $plain) ?? $plain;
    $plain = preg_replace('/\*\*(.*?)\*\*/u', '$1', $plain) ?? $plain;
    $plain = preg_replace('/(?<!\*)\*(.*?)\*(?!\*)/u', '$1', $plain) ?? $plain;
    $plain = preg_replace('/(?<![\pL\pN])_(.*?)_(?![\pL\pN])/u', '$1', $plain) ?? $plain;
    return trim(preg_replace('/\s+/u', ' ', strip_tags($plain)) ?? '');
}

/** Event accent is scoped to Events, defaulting to Cromo red. Text ink meets WCAG AA. */
function brvtalPublicEventPalette(string $value): array
{
    $value = strtolower(trim($value));
    if (!preg_match('/^#(?:[a-f0-9]{3}|[a-f0-9]{4}|[a-f0-9]{6}|[a-f0-9]{8})$/', $value)) {
        $value = '#e12e34';
    }
    $digits = substr($value, 1);
    if (strlen($digits) <= 4) {
        $digits = implode('', array_map(static fn(string $digit): string => $digit . $digit, str_split($digits)));
    }
    $rgb = [hexdec(substr($digits, 0, 2)), hexdec(substr($digits, 2, 2)), hexdec(substr($digits, 4, 2))];
    if (strlen($digits) === 8) {
        // Compute effective color over the nearly black event hero for contrast estimation.
        $alpha = hexdec(substr($digits, 6, 2)) / 255;
        $rgb = array_map(static fn(int $channel): int => (int)round($channel * $alpha + 3 * (1 - $alpha)), $rgb);
    }
    // Preserve the original valid CSS hex spelling; only use expanded/flattened RGB for contrast.
    $signal = $value;
    $luminance = static function (array $channels): float {
        $linear = array_map(static function (int $c): float {
            $s = $c / 255;
            return $s <= .04045 ? $s / 12.92 : (($s + .055) / 1.055) ** 2.4;
        }, $channels);
        return .2126 * $linear[0] + .7152 * $linear[1] + .0722 * $linear[2];
    };
    $l = $luminance($rgb);
    $black = ($l + .05) / ($luminance([3, 3, 3]) + .05);
    $bone = ($luminance([244, 241, 232]) + .05) / ($l + .05);
    $ink = $black >= 4.5 ? '#030303' : ($bone >= 4.5 ? '#f4f1e8' : '#ffffff');
    return ['signal' => $signal, 'ink' => $ink];
}
