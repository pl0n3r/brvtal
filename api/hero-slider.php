<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

function brvtal_hero_slider_json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=60, stale-while-revalidate=300');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function brvtal_hero_slider_error(string $error, int $status = 503): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    if ($status === 503) header('Retry-After: 60');
    echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function brvtal_hero_slider_clean_url(mixed $value): string
{
    $value = trim((string)$value);
    if ($value === '') return '';
    if (str_starts_with($value, '#')) return $value;
    if (preg_match('#^https://#i', $value)) return $value;
    if (preg_match('#^[A-Za-z0-9._/-]+$#', $value)) return '/' . ltrim($value, '/');
    return '';
}

function brvtal_hero_slider_text(mixed $value, int $limit): string
{
    $value = trim((string)$value);
    return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
}

function brvtal_hero_slider_layer(mixed $raw, int $index): ?array
{
    if (!is_array($raw)) return null;
    $type = in_array(($raw['type'] ?? ''), ['text','image','logo','cta'], true) ? $raw['type'] : 'text';
    $animation = in_array(($raw['animation'] ?? ''), ['none','fade','slide-up','slide-left','zoom'], true) ? $raw['animation'] : 'fade';
    $align = in_array(($raw['align'] ?? ''), ['left','center','right'], true) ? $raw['align'] : 'left';
    $number = static function (mixed $value, int $min, int $max, ?int $fallback = null): ?int {
        if ($value === '' || $value === null) return $fallback;
        return max($min, min($max, (int)$value));
    };
    return [
        'id' => preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($raw['id'] ?? '')) ?: ('layer-' . ($index + 1)),
        'type' => $type,
        'name' => brvtal_hero_slider_text($raw['name'] ?? ucfirst($type), 80),
        'text' => brvtal_hero_slider_text($raw['text'] ?? '', 220),
        'src' => brvtal_hero_slider_clean_url($raw['src'] ?? ''),
        'mobileSrc' => brvtal_hero_slider_clean_url($raw['mobileSrc'] ?? ''),
        'url' => brvtal_hero_slider_clean_url($raw['url'] ?? ''),
        'x' => $number($raw['x'] ?? 12, 0, 100, 12),
        'y' => $number($raw['y'] ?? 50, 0, 100, 50),
        'width' => $number($raw['width'] ?? 50, 5, 100, 50),
        'mobileX' => $number($raw['mobileX'] ?? null, 0, 100, null),
        'mobileY' => $number($raw['mobileY'] ?? null, 0, 100, null),
        'mobileWidth' => $number($raw['mobileWidth'] ?? null, 5, 100, null),
        'hiddenMobile' => ($raw['hiddenMobile'] ?? false) === true,
        'animation' => $animation,
        'delay' => $number($raw['delay'] ?? 0, 0, 10000, 0),
        'duration' => $number($raw['duration'] ?? 650, 100, 5000, 650),
        'align' => $align,
    ];
}

function brvtal_hero_slider_payload(mixed $raw): array
{
    if (!is_array($raw)) return ['enabled' => false, 'autoplay' => false, 'interval' => 7000, 'slides' => []];
    $slides = [];
    foreach (array_slice(is_array($raw['slides'] ?? null) ? $raw['slides'] : [], 0, 20) as $slide) {
        if (!is_array($slide) || ($slide['enabled'] ?? true) === false) continue;
        $desktop = brvtal_hero_slider_clean_url($slide['desktopSrc'] ?? '');
        if ($desktop === '') continue;
        $layers = [];
        foreach (array_slice(is_array($slide['layers'] ?? null) ? $slide['layers'] : [], 0, 12) as $layerIndex => $layer) {
            $clean = brvtal_hero_slider_layer($layer, (int)$layerIndex);
            if ($clean !== null) $layers[] = $clean;
        }
        $slides[] = [
            'id' => preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($slide['id'] ?? '')) ?: ('slide-' . (count($slides) + 1)),
            'mediaType' => ($slide['mediaType'] ?? '') === 'video' ? 'video' : 'image',
            'desktopSrc' => $desktop,
            'mobileSrc' => brvtal_hero_slider_clean_url($slide['mobileSrc'] ?? ''),
            'poster' => brvtal_hero_slider_clean_url($slide['poster'] ?? ''),
            'kicker' => brvtal_hero_slider_text($slide['kicker'] ?? '', 120),
            'title' => brvtal_hero_slider_text($slide['title'] ?? '', 140),
            'body' => brvtal_hero_slider_text($slide['body'] ?? '', 320),
            'ctaLabel' => brvtal_hero_slider_text($slide['ctaLabel'] ?? '', 80),
            'ctaUrl' => brvtal_hero_slider_clean_url($slide['ctaUrl'] ?? ''),
            'contentAlign' => in_array(($slide['contentAlign'] ?? ''), ['left','center','right'], true) ? $slide['contentAlign'] : 'left',
            'overlay' => max(0, min(85, (int)($slide['overlay'] ?? 35))),
            'transition' => in_array(($slide['transition'] ?? ''), ['fade','slide','zoom'], true) ? $slide['transition'] : 'fade',
            'layers' => $layers,
        ];
    }
    return [
        'enabled' => ($raw['enabled'] ?? false) === true && count($slides) > 0,
        'autoplay' => ($raw['autoplay'] ?? true) !== false,
        'interval' => max(2500, min(30000, (int)($raw['interval'] ?? 7000))),
        'slides' => $slides,
    ];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    brvtal_hero_slider_json(['enabled' => false, 'autoplay' => false, 'interval' => 7000, 'slides' => []], 405);
}

try {
    $pdo = db();
    $statement = $pdo->prepare("SELECT setting_value, is_json FROM settings WHERE setting_key = ? LIMIT 1");
    $statement->execute(['home.hero.slider']);
    $row = $statement->fetch();
    $value = $row && (int)$row['is_json'] === 1 ? json_decode((string)$row['setting_value'], true) : null;
    brvtal_hero_slider_json(brvtal_hero_slider_payload($value));
} catch (Throwable $error) {
    if (function_exists('brvtal_log')) {
        brvtal_log('PUBLIC_HERO_SLIDER_ERROR', 'Hero Slider public endpoint failed', [
            'class' => get_class($error),
            'message' => $error->getMessage(),
        ]);
    }
    brvtal_hero_slider_error('HERO_SLIDER_UNAVAILABLE', 503);
}
