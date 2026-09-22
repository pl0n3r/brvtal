<?php
declare(strict_types=1);

/**
 * Canonical rich Blog HTML sanitizer shared by mutation and public rendering.
 * Returns clean semantic HTML plus user-facing warnings whenever input changed.
 */
function brvtal_blog_sanitize_html(string $html): array
{
    $html = trim($html);
    if ($html === '') return ['html' => '', 'warnings' => []];

    $allowedTags = ['p','br','h2','h3','h4','strong','b','em','i','ul','ol','li','a','blockquote','img'];
    $allowedAttrs = [
        'a' => ['href','title','target','rel'],
        'img' => ['src','alt','title','width','height'],
        'p' => ['style'], 'h2' => ['style'], 'h3' => ['style'], 'h4' => ['style'],
    ];
    $safeStyle = static function (string $style): string {
        $kept = [];
        foreach (explode(';', $style) as $declaration) {
            if (!str_contains($declaration, ':')) continue;
            [$property,$value] = array_map('trim', explode(':', $declaration, 2));
            $property = strtolower($property);
            $value = strtolower($value);
            if ($property === 'text-align' && in_array($value, ['left','center','right'], true)) {
                $kept[] = 'text-align:' . $value;
            }
        }
        return implode(';', $kept);
    };
    $safeUrl = static function (string $url, bool $image = false): bool {
        $url = trim($url);
        if ($url === '') return false;
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) return true;
        if (!$image && (str_starts_with($url, '#') || str_starts_with(strtolower($url), 'mailto:'))) return true;
        $parts = parse_url($url);
        return is_array($parts) && in_array(strtolower((string)($parts['scheme'] ?? '')), ['http','https'], true);
    };

    if (!class_exists('DOMDocument')) {
        return [
            'html' => htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'warnings' => ['Rich HTML parsing is unavailable; body markup was rendered as text.'],
        ];
    }

    $previous = libxml_use_internal_errors(true);
    $dom = new DOMDocument('1.0', 'UTF-8');
    $wrapped = '<!doctype html><html><body><div id="brvtal-root">' . $html . '</div></body></html>';
    $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded) return ['html' => '', 'warnings' => ['Body HTML could not be parsed and was removed.']];

    $warnings = [];
    $root = $dom->getElementById('brvtal-root');
    if (!$root) return ['html' => '', 'warnings' => ['Body HTML could not be parsed and was removed.']];

    $walk = function (DOMNode $node) use (&$walk,$allowedTags,$allowedAttrs,$safeStyle,$safeUrl,&$warnings): void {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (!($child instanceof DOMElement)) continue;
            $tag = strtolower($child->tagName);
            if (!in_array($tag, $allowedTags, true)) {
                $warnings['tag:' . $tag] = 'Unsupported <' . $tag . '> markup was removed.';
                if (in_array($tag, ['script','style','iframe','object','embed','svg','math'], true)) {
                    $child->parentNode?->removeChild($child);
                    continue;
                }
                while ($child->firstChild) $child->parentNode?->insertBefore($child->firstChild, $child);
                $child->parentNode?->removeChild($child);
                continue;
            }
            foreach (iterator_to_array($child->attributes ?? []) as $attr) {
                $name = strtolower($attr->name);
                if (str_starts_with($name, 'on') || !in_array($name, $allowedAttrs[$tag] ?? [], true)) {
                    $warnings['attr:' . $name] = 'Unsupported attribute "' . $name . '" was removed.';
                    $child->removeAttribute($attr->name);
                    continue;
                }
                if ($name === 'style') {
                    $clean = $safeStyle($attr->value);
                    if ($clean === '') $child->removeAttribute('style'); else $child->setAttribute('style', $clean);
                    if ($clean !== trim($attr->value)) $warnings['style'] = 'Unsupported inline styles were removed.';
                }
                if (($tag === 'a' && $name === 'href') || ($tag === 'img' && $name === 'src')) {
                    if (!$safeUrl($attr->value, $tag === 'img')) {
                        $child->removeAttribute($name);
                        $warnings['url'] = 'An unsafe URL was removed.';
                    }
                }
            }
            if ($tag === 'a' && strtolower($child->getAttribute('target')) === '_blank') {
                $child->setAttribute('rel', 'noopener noreferrer');
            }
            $walk($child);
        }
    };
    $walk($root);

    $clean = '';
    foreach ($root->childNodes as $child) $clean .= $dom->saveHTML($child);
    return ['html' => trim($clean), 'warnings' => array_values($warnings)];
}
